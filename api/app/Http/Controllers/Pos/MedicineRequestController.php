<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\MedicineRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Online orders › Medicine requests: requests from the website with their prescription files. */
class MedicineRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $rows = MedicineRequest::with('handler')->when($status && $status !== 'all', fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(300)->get();

        return response()->json([
            'requests' => $rows->map(fn ($r) => $this->present($r))->all(),
            'counts' => MedicineRequest::groupBy('status')->selectRaw('status, COUNT(*) AS n')->pluck('n', 'status'),
        ]);
    }

    public function update(Request $request, MedicineRequest $medicineRequest): JsonResponse
    {
        $d = $request->validate(['status' => ['required', Rule::in(MedicineRequest::STATUSES)], 'staff_note' => ['nullable', 'string', 'max:500']]);
        $medicineRequest->update($d + ['handled_by' => $request->user()->id, 'handled_at' => now()]);

        return response()->json($this->present($medicineRequest->fresh('handler')));
    }

    /** Opens one file through a 5-minute signed link (the files are private). */
    public function fileUrl(MedicineRequest $medicineRequest, int $index): JsonResponse
    {
        abort_unless(isset($medicineRequest->files[$index]), 404);

        return response()->json(['url' => URL::temporarySignedRoute('medicine-request.file', now()->addMinutes(5), ['medicineRequest' => $medicineRequest->id, 'index' => $index])]);
    }

    public function file(MedicineRequest $medicineRequest, int $index): Response
    {
        $f = $medicineRequest->files[$index] ?? null;
        abort_unless($f && Storage::disk('local')->exists($f['path']), 404);

        return Storage::disk('local')->response($f['path'], $medicineRequest->number.'-'.($index + 1).'.'.pathinfo($f['path'], PATHINFO_EXTENSION));
    }

    private function present(MedicineRequest $r): array
    {
        return [
            'id' => $r->id, 'number' => $r->number, 'name' => $r->name, 'phone' => $r->phone, 'address' => $r->address,
            'medicines' => $r->medicines, 'status' => $r->status, 'staffNote' => $r->staff_note, 'customerId' => $r->customer_id,
            'files' => collect($r->files ?? [])->map(fn ($f, $i) => ['index' => $i, 'name' => $f['name'], 'mime' => $f['mime'], 'size' => $f['size']])->values()->all(),
            'createdAt' => $r->created_at->toIso8601String(), 'handledBy' => $r->handler?->name, 'handledAt' => $r->handled_at?->toIso8601String(),
        ];
    }
}
