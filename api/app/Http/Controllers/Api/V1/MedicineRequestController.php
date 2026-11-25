<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Pos\CustomerController;
use App\Models\Customer;
use App\Models\MedicineRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Request a medicine" (website): medicine names and/or up to 5 prescription
 * images / PDFs. Files go to the private disk; staff open them from the POS
 * (Online orders › Medicine requests) through short-lived signed links.
 */
class MedicineRequestController extends V1Controller
{
    public const MAX_FILES = 5;

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:120'], 'phone' => ['required', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:255'], 'medicines' => ['nullable', 'string', 'max:2000'],
            'files' => ['nullable', 'array', 'max:'.self::MAX_FILES],
            'files.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,pdf', 'max:5120'],
        ], ['files.*.mimes' => 'Upload photos (JPG, PNG, WebP, HEIC) or PDF files.', 'files.*.max' => 'Each file can be up to 5 MB.']);
        $phone = CustomerController::normalizePhone($d['phone']);
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }
        if (trim((string) ($d['medicines'] ?? '')) === '' && ! $request->hasFile('files')) {
            throw ValidationException::withMessages(['medicines' => 'Write the medicine names or upload a prescription.']);
        }

        $files = [];
        foreach ($request->file('files', []) as $f) {
            $files[] = ['path' => $f->store('medicine-requests', 'local'), 'name' => mb_substr($f->getClientOriginalName(), 0, 120), 'mime' => $f->getMimeType(), 'size' => $f->getSize()];
        }
        // A signed-in customer's request is linked to the account (a bad token is simply ignored here).
        $customer = $request->bearerToken() ? Auth::guard('sanctum')->user() : null;

        $r = DB::transaction(function () use ($d, $phone, $files, $customer) {
            $r = MedicineRequest::create([
                'number' => 'TMP-'.uniqid(), 'customer_id' => $customer instanceof Customer ? $customer->id : null,
                'name' => trim($d['name']), 'phone' => $phone, 'address' => $d['address'] ?? null,
                'medicines' => trim((string) ($d['medicines'] ?? '')), 'files' => $files,
            ]);
            $r->update(['number' => 'MR-'.now()->format('y').'-'.str_pad((string) $r->id, 6, '0', STR_PAD_LEFT)]);

            return $r;
        });

        return $this->ok(['number' => $r->number, 'files' => count($files)], 'Request received. Our pharmacist will call you.', 201);
    }
}
