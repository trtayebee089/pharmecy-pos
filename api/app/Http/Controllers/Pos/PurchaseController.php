<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\PurchaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function store(Request $request, PurchaseService $purchases): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'in:received,ordered'],
            'warehouseId' => ['required', 'integer'],
            'supplierId' => ['required', 'integer'],
            'supplierInvoiceNo' => ['nullable', 'string', 'max:60'],
            'invoiceDate' => ['nullable', 'date'],
            'otherCharges' => ['nullable', 'numeric', 'min:0'],
            'paidAmount' => ['nullable', 'numeric', 'min:0'],
            'paymentMethod' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:1000'],
            'lines' => ['required', 'array', 'min:1', 'max:300'],
            'lines.*.productId' => ['required', 'integer'],
            'lines.*.unitId' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0'],
            'lines.*.freeQty' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batchNo' => ['nullable', 'string', 'max:60'],
            'lines.*.expiryDate' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.unitCost' => ['required', 'numeric', 'min:0'],
            'lines.*.discountPct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.updateRetailPrice' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $purchase = $purchases->create($request->user(), $data);

        return response()->json([
            'id' => $purchase->id, 'number' => $purchase->number, 'status' => $purchase->status,
            'grandTotal' => (float) $purchase->grand_total, 'dueTotal' => (float) $purchase->due_total,
        ], 201);
    }
}
