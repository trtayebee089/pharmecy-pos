<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Services\RewardLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;

/** Customer channel responses: {success, message, data, meta}. */
abstract class V1Controller extends Controller
{
    protected function ok(mixed $data = null, ?string $message = null, int $status = 200, array $extra = []): JsonResponse
    {
        return $this->json(['success' => true, 'message' => $message, 'data' => $data] + $extra, $status);
    }

    /** Floats stay floats in JSON (60.0, not 60): the Android app assigns some of them straight into a Dart double. */
    protected function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }

    protected function page(LengthAwarePaginator $page, array $extra = []): JsonResponse
    {
        return $this->ok($page->items(), null, 200, ['meta' => [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total(),
        ]] + $extra);
    }

    /** The signed-in customer as the website and app read it. */
    protected function customer(Customer $c): array
    {
        return [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone, 'phone_number' => $c->phone,
            'email' => $c->email, 'gender' => $c->gender, 'address' => $c->address,
            'avatar' => $c->avatar_path ? Storage::disk('public')->url($c->avatar_path) : null,
            'points' => app(RewardLedger::class)->balance($c->id),
        ];
    }
}
