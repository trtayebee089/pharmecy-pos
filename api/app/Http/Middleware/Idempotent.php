<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes document-creating POSTs safe to retry (double click, flaky network).
 * The client sends an Idempotency-Key header. The first successful response is
 * stored and replayed for the same key; the same key with a different body is
 * rejected; failed attempts release the key so the cashier can fix and retry.
 */
class Idempotent
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = trim((string) $request->header('Idempotency-Key'));
        if ($key === '' || strlen($key) > 100) {
            return response()->json(['message' => 'An Idempotency-Key header (max 100 chars) is required.'], 428);
        }
        $userId = $request->user()->id;
        $endpoint = $request->method().' '.$request->path();
        $hash = hash('sha256', $endpoint.'|'.json_encode($request->all()));

        $existing = IdempotencyKey::where(['user_id' => $userId, 'key' => $key])->first();
        if ($existing) {
            return $this->replay($existing, $hash);
        }

        try {
            $row = IdempotencyKey::create(['user_id' => $userId, 'key' => $key, 'endpoint' => $endpoint, 'request_hash' => $hash]);
        } catch (UniqueConstraintViolationException) {
            return $this->replay(IdempotencyKey::where(['user_id' => $userId, 'key' => $key])->firstOrFail(), $hash);
        }

        $response = $next($request);

        if ($response->isSuccessful()) {
            $row->update(['response_code' => $response->getStatusCode(), 'response_body' => json_decode($response->getContent(), true)]);
        } else {
            $row->delete();
        }

        return $response;
    }

    private function replay(IdempotencyKey $row, string $hash): Response
    {
        if (! hash_equals($row->request_hash, $hash)) {
            return response()->json(['message' => 'This Idempotency-Key was already used for a different request.'], 422);
        }
        if ($row->response_code === null) {
            return response()->json(['message' => 'The original request is still being processed.'], 409);
        }

        return response()->json($row->response_body, $row->response_code)->header('Idempotent-Replayed', 'true');
    }
}
