<?php

namespace App\Support;

use App\Models\StockAdjustment;
use App\Models\StockBalance;
use App\Models\StockCount;
use App\Models\StockTransfer;
use App\Models\Warehouse;
use App\Services\StockCountService;

/**
 * Inventory document shapes for the POS: AdjustmentSummary (types/domain.ts),
 * CountSession, CountLine and TransferSummary (types/inventory.ts).
 */
class PresentInventory
{
    public static function adjustment(StockAdjustment $a): array
    {
        $a->loadMissing('creator');

        return [
            'id' => $a->id, 'number' => $a->number, 'date' => $a->created_at->toIso8601String(), 'reason' => $a->reason,
            'note' => $a->note, 'lines' => (int) ($a->items_count ?? $a->items()->count()),
            'qtyIn' => (int) $a->qty_in, 'qtyOut' => (int) $a->qty_out, 'value' => (float) $a->value,
            'status' => $a->status, 'createdBy' => $a->creator?->name ?? '',
        ];
    }

    public static function count(StockCount $c): array
    {
        $c->loadMissing('creator');
        $items = $c->items()->get();
        $counted = $items->whereNotNull('counted_qty_base');
        if ($c->status === 'posted') {
            [$varQty, $varValue] = [(int) $c->variance_qty, (float) $c->variance_value];
        } else {
            // Live preview against the current system quantity.
            $bal = StockBalance::where('warehouse_id', $c->warehouse_id)->whereIn('batch_id', $counted->pluck('batch_id'))->get()->keyBy('batch_id');
            $varQty = 0;
            $varPaisa = 0;
            foreach ($counted as $i) {
                $diff = $i->counted_qty_base - (int) ($bal[$i->batch_id]->qty_base ?? 0);
                $varQty += $diff;
                $varPaisa += (int) round($diff * (float) ($bal[$i->batch_id]->avg_unit_cost ?? 0) * 100);
            }
            $varValue = $varPaisa / 100;
        }

        return [
            'id' => $c->id, 'number' => $c->number, 'name' => $c->name,
            'warehouse' => $c->warehouse_id ? (string) Warehouse::whereKey($c->warehouse_id)->value('name') : '',
            'scope' => $c->scope_type === 'all' ? StockCountService::SCOPES['all'] : StockCountService::SCOPES[$c->scope_type].': '.$c->scope_value,
            'status' => $c->status, 'startedBy' => $c->creator?->name ?? '', 'startedAt' => $c->created_at->toIso8601String(),
            'postedAt' => $c->posted_at?->toIso8601String(), 'lines' => $items->count(), 'counted' => $counted->count(),
            'varianceQty' => $varQty, 'varianceValue' => round($varValue, 2),
        ];
    }

    public static function countLines(StockCount $c): array
    {
        $items = $c->items()->with('product.manufacturer', 'batch')->get();
        $bal = StockBalance::where('warehouse_id', $c->warehouse_id)->whereIn('batch_id', $items->pluck('batch_id'))->get()->keyBy('batch_id');

        return $items->map(fn ($i) => [
            'key' => (string) $i->id, 'productId' => $i->product_id,
            'productName' => trim(($i->product?->name ?? '').' '.($i->product?->strength ?? '')),
            'manufacturer' => $i->product?->manufacturer?->name ?? '',
            'batchId' => $i->batch_id, 'batchNo' => $i->batch?->batch_no ?? '',
            'expiryDate' => $i->batch?->expiry_date?->toDateString() ?? '9999-12-31',
            // Posted counts show the quantity they were compared with; open counts the live one.
            'systemQty' => $c->status === 'posted' ? (int) $i->system_qty_base : (int) ($bal[$i->batch_id]->qty_base ?? 0),
            'countedQty' => $i->counted_qty_base,
            'unitCost' => $c->status === 'posted' ? (float) $i->unit_cost : (float) ($bal[$i->batch_id]->avg_unit_cost ?? 0),
        ])->values()->all();
    }

    public static function transfer(StockTransfer $t): array
    {
        $t->loadMissing('from', 'to', 'creator', 'items.product', 'items.batch');

        return [
            'id' => $t->id, 'number' => $t->number, 'date' => $t->created_at->toIso8601String(),
            'from' => $t->from?->name ?? '', 'to' => $t->to?->name ?? '',
            'fromWarehouseId' => $t->from_warehouse_id, 'toWarehouseId' => $t->to_warehouse_id,
            'lines' => $t->items->map(fn ($i) => [
                'productId' => $i->product_id, 'productName' => trim(($i->product?->name ?? '').' '.($i->product?->strength ?? '')),
                'batchId' => $i->batch_id, 'batchNo' => $i->batch?->batch_no ?? '',
                'expiryDate' => $i->batch?->expiry_date?->toDateString() ?? '', 'qtyBase' => (int) $i->qty_base,
            ])->values()->all(),
            'qty' => (int) $t->qty_base, 'status' => $t->status, 'createdBy' => $t->creator?->name ?? '',
            'note' => $t->status === 'void' ? (string) $t->void_reason : (string) $t->note,
        ];
    }
}
