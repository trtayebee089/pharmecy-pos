<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\PurchaseOrderDraft;
use App\Models\PurchaseOrderDraftLine;
use App\Models\PurchaseOrderRun;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Purchase order planning per company (manufacturer) and warehouse.
 *
 * - Report: per product, pieces sold in the period (net of returns), sellable
 *   stock now, the unit ladder (pieces per strip, strips per box) and the last
 *   landed cost per piece.
 * - Forecast: a transparent statistical forecast (no external service). Daily
 *   rate over the last 28 days, trend = last 7 days against the 21 before
 *   (clamped to ±50 %), 21 days of cover. Confidence from how many of the 28
 *   days had sales.
 * - Drafts carry no stock or money. Saving for the same company, date and
 *   warehouse updates the open draft (legacy behaviour).
 * - Converting creates an ordered purchase for the chosen supplier; each line
 *   uses the largest unit that divides the quantity.
 */
class PurchaseOrderPlanner
{
    public const HISTORY_DAYS = 28;

    public const COVER_DAYS = 21;

    public function __construct(private DocumentNumbers $numbers, private PurchaseService $purchases) {}

    /** @return array<int, array> OrderRow[] */
    public function report(int $warehouseId, int $manufacturerId, string $from, string $to): array
    {
        $products = Product::where(['manufacturer_id' => $manufacturerId, 'is_active' => true])->with('units', 'dosageForm')->orderBy('name')->get();
        $ids = $products->pluck('id')->all();
        $sold = $this->soldBetween($warehouseId, $ids, Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay());
        $stock = $this->sellable($warehouseId, $ids);
        $costs = $this->lastCosts($warehouseId, $ids);

        return $products->map(function (Product $p) use ($sold, $stock, $costs) {
            [$perStrip, $stripsPerBox] = self::ladder($p->units);

            return [
                'productId' => $p->id, 'code' => $p->sku, 'name' => $p->name, 'strength' => $p->strength,
                'category' => $p->dosageForm?->name ?? '', 'soldQty' => (int) ($sold[$p->id] ?? 0), 'inStock' => (int) ($stock[$p->id] ?? 0),
                'piecesPerStrip' => $perStrip, 'stripsPerBox' => $stripsPerBox,
                'boxQty' => 0, 'stripQty' => 0, 'orderQty' => 0, 'unitCost' => round((float) ($costs[$p->id] ?? 0), 4),
            ];
        })->values()->all();
    }

    /** @return array<int, array{forecast7: int, recommended: int, confidence: string, reason: string}> */
    public function forecast(int $warehouseId, array $productIds): array
    {
        $today = now()->endOfDay();
        $start = now()->subDays(self::HISTORY_DAYS - 1)->startOfDay();
        $daily = DB::table('sale_item_batches as sib')->join('sale_items as si', 'si.id', '=', 'sib.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.warehouse_id', $warehouseId)->where('s.status', 'completed')->whereBetween('s.sale_date', [$start, $today])
            ->whereIn('si.product_id', $productIds)
            ->groupBy('si.product_id', DB::raw('DATE(s.sale_date)'))
            ->selectRaw('si.product_id AS pid, DATE(s.sale_date) AS d, SUM(sib.qty_base - sib.returned_qty_base) AS q')
            ->get()->groupBy('pid');
        $stock = $this->sellable($warehouseId, $productIds);
        $recentFrom = now()->subDays(6)->toDateString();

        $out = [];
        foreach ($productIds as $id) {
            $days = $daily->get($id, collect());
            $total = (float) $days->sum('q');
            $recent = (float) $days->filter(fn ($r) => substr((string) $r->d, 0, 10) >= $recentFrom)->sum('q');
            $rate = $total / self::HISTORY_DAYS;
            $earlierRate = ($total - $recent) / (self::HISTORY_DAYS - 7);
            $trend = $earlierRate > 0 ? max(0.5, min(1.5, ($recent / 7) / $earlierRate)) : 1.0;
            $expected = $rate * $trend;
            $have = (int) ($stock[$id] ?? 0);
            $need = max(0, (int) ceil($expected * self::COVER_DAYS) - $have);
            $saleDays = $days->count();
            $confidence = $saleDays >= 14 ? 'high' : ($saleDays >= 5 ? 'medium' : 'low');
            $perDay = round($expected, 1);
            $trendPct = (int) round(($trend - 1) * 100);
            $out[$id] = [
                'forecast7' => (int) round($expected * 7), 'recommended' => $need, 'confidence' => $confidence,
                'reason' => $total <= 0
                    ? 'No sales in the last '.self::HISTORY_DAYS.' days. No order suggested.'
                    : ($need > 0
                        ? "About {$perDay} pcs/day expected (".($trendPct >= 0 ? '+' : '')."{$trendPct}% trend, sold on {$saleDays} of ".self::HISTORY_DAYS." days). {$have} pcs covers ".(int) floor($have / max($expected, 0.01)).' days; ordering tops up to '.self::COVER_DAYS.' days.'
                        : "Stock of {$have} pcs covers more than ".self::COVER_DAYS." days at ~{$perDay} pcs/day. No order needed."),
            ];
        }

        return $out;
    }

    /** Saves (or updates the open draft for the same company, date and warehouse). Lines with qty 0 are dropped. */
    public function saveDraft(?User $user, int $warehouseId, int $manufacturerId, string $date, array $rows, string $source): PurchaseOrderDraft
    {
        $warehouse = Warehouse::findOrFail($warehouseId);
        $rows = $this->validRows($manufacturerId, $rows);
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => 'Enter an order quantity for at least one product.']);
        }

        return DB::transaction(function () use ($user, $warehouse, $manufacturerId, $date, $rows, $source) {
            $draft = PurchaseOrderDraft::where(['warehouse_id' => $warehouse->id, 'manufacturer_id' => $manufacturerId, 'status' => 'draft'])
                ->whereDate('order_date', $date)->lockForUpdate()->first();
            $draft ??= PurchaseOrderDraft::create([
                'number' => $this->numbers->next('purchase_order_draft', $warehouse), 'warehouse_id' => $warehouse->id,
                'manufacturer_id' => $manufacturerId, 'order_date' => $date, 'source' => $source, 'status' => 'draft', 'created_by' => $user?->id,
            ]);
            $draft->update(['source' => $source]);
            $this->replaceLines($draft, $rows);
            AuditLog::record($user?->id, 'po_draft.saved', 'purchase_order_draft', $draft->id, ['lines' => count($rows)]);

            return $draft;
        });
    }

    public function updateDraft(User $user, PurchaseOrderDraft $draft, array $rows): PurchaseOrderDraft
    {
        $this->mustBeDraft($draft);
        $rows = $this->validRows($draft->manufacturer_id, $rows);
        if ($rows === []) {
            throw ValidationException::withMessages(['rows' => 'A draft needs at least one product with a quantity. Delete it instead.']);
        }
        DB::transaction(fn () => $this->replaceLines($draft, $rows));
        AuditLog::record($user->id, 'po_draft.updated', 'purchase_order_draft', $draft->id, ['lines' => count($rows)]);

        return $draft;
    }

    public function deleteDraft(User $user, PurchaseOrderDraft $draft): void
    {
        $this->mustBeDraft($draft);
        $draft->delete();
        AuditLog::record($user->id, 'po_draft.deleted', 'purchase_order_draft', $draft->id, ['number' => $draft->number]);
    }

    /** Creates an ordered purchase (purchase order) for the supplier and closes the draft. */
    public function convert(User $user, PurchaseOrderDraft $draft, int $supplierId): array
    {
        return DB::transaction(function () use ($user, $draft, $supplierId) {
            $draft = PurchaseOrderDraft::whereKey($draft->id)->lockForUpdate()->firstOrFail();
            $this->mustBeDraft($draft);
            if (! Supplier::where('is_active', true)->whereKey($supplierId)->exists()) {
                throw ValidationException::withMessages(['supplier_id' => 'Choose an active supplier.']);
            }
            $lines = $draft->lines()->get()->map(function ($l) {
                $unit = ProductUnit::where('product_id', $l->product_id)->orderByDesc('factor')->get()
                    ->first(fn ($u) => $l->order_qty % $u->factor === 0);

                return ['productId' => $l->product_id, 'unitId' => $unit->id, 'qty' => $l->order_qty / $unit->factor,
                    'unitCost' => round((float) $l->unit_cost * $unit->factor, 4), 'discountPct' => 0];
            })->all();
            $purchase = $this->purchases->create($user, [
                'status' => 'ordered', 'warehouseId' => $draft->warehouse_id, 'supplierId' => $supplierId,
                'note' => 'From draft '.$draft->number, 'otherCharges' => 0, 'lines' => $lines,
            ]);
            $draft->update(['status' => 'converted', 'purchase_id' => $purchase->id]);

            return ['purchaseId' => $purchase->id, 'number' => $purchase->number];
        });
    }

    public function startRun(User $user, int $warehouseId): PurchaseOrderRun
    {
        $queue = Manufacturer::where('is_active', true)->whereHas('products', fn ($q) => $q->where('is_active', true))->orderBy('name')->pluck('id')->all();

        return PurchaseOrderRun::create(['warehouse_id' => $warehouseId, 'queue' => $queue, 'total' => count($queue), 'status' => $queue ? 'running' : 'done', 'created_by' => $user->id]);
    }

    /** Processes the next $batch companies: a draft wherever the forecast recommends an order. */
    public function processRun(PurchaseOrderRun $run, int $batch = 3): PurchaseOrderRun
    {
        return DB::transaction(function () use ($run, $batch) {
            $run = PurchaseOrderRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            $queue = $run->queue;
            $today = now()->toDateString();
            $from = now()->subDays(self::HISTORY_DAYS - 1)->toDateString();
            foreach (array_splice($queue, 0, $batch) as $manufacturerId) {
                $rows = $this->report($run->warehouse_id, $manufacturerId, $from, $today);
                $f = $this->forecast($run->warehouse_id, array_column($rows, 'productId'));
                $filled = array_map(function ($r) use ($f) {
                    $perBox = $r['piecesPerStrip'] * $r['stripsPerBox'];
                    $need = $f[$r['productId']]['recommended'] ?? 0;

                    return ['forecast' => $f[$r['productId']] ?? null, 'orderQty' => $need > 0 ? (int) ceil($need / $perBox) * $perBox : 0] + $r;
                }, $rows);
                if (collect($filled)->contains(fn ($r) => $r['orderQty'] > 0)) {
                    $this->saveDraft(null, $run->warehouse_id, $manufacturerId, $today, $filled, 'ai');
                    $run->created++;
                }
                $run->processed++;
            }
            $run->queue = $queue;
            $run->status = $queue ? 'running' : 'done';
            $run->save();

            return $run;
        });
    }

    /** DraftSummary / DraftDetail (pos/app/types/purchase-orders.ts). */
    public function present(PurchaseOrderDraft $d, bool $withRows = false): array
    {
        $d->loadMissing('manufacturer', 'creator', 'purchase', 'lines.product.units', 'lines.product.dosageForm');
        $summary = [
            'id' => $d->id, 'number' => $d->number, 'companyId' => $d->manufacturer_id, 'company' => $d->manufacturer?->name ?? '',
            'date' => $d->order_date->toDateString(), 'lines' => $d->lines->count(), 'orderQty' => (int) $d->lines->sum('order_qty'),
            'estValue' => round($d->lines->sum(fn ($l) => $l->order_qty * (float) $l->unit_cost), 2),
            'source' => $d->source, 'status' => $d->status, 'purchaseNumber' => $d->purchase?->number,
            'createdBy' => $d->creator?->name ?? 'Automatic draft',
        ];
        if (! $withRows) {
            return $summary;
        }

        return $summary + ['rows' => $d->lines->map(function ($l) {
            [$perStrip, $stripsPerBox] = self::ladder($l->product->units);

            return [
                'productId' => $l->product_id, 'code' => $l->product->sku, 'name' => $l->product->name, 'strength' => $l->product->strength,
                'category' => $l->product->dosageForm?->name ?? '', 'soldQty' => $l->sold_qty, 'inStock' => $l->in_stock,
                'piecesPerStrip' => $perStrip, 'stripsPerBox' => $stripsPerBox,
                'boxQty' => round($l->order_qty / ($perStrip * $stripsPerBox), 2), 'stripQty' => round($l->order_qty / $perStrip, 2),
                'orderQty' => $l->order_qty, 'unitCost' => (float) $l->unit_cost, 'forecast' => $l->forecast,
            ];
        })->values()->all()];
    }

    /** [pieces per strip, strips per box] from the unit ladder (1 when the unit does not exist). */
    public static function ladder(Collection $units): array
    {
        $strip = (int) ($units->firstWhere('unit_code', 'strip')?->factor ?? 1);
        $box = (int) ($units->firstWhere('unit_code', 'box')?->factor ?? $strip);

        return [max(1, $strip), max(1, intdiv($box, max(1, $strip)))];
    }

    private function validRows(int $manufacturerId, array $rows): array
    {
        $rows = array_values(array_filter($rows, fn ($r) => (int) ($r['orderQty'] ?? 0) > 0));
        $ids = array_column($rows, 'productId');
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['rows' => 'Each product can appear once.']);
        }
        $mine = Product::where('manufacturer_id', $manufacturerId)->whereIn('id', $ids)->pluck('id')->all();
        foreach ($rows as $i => $r) {
            if (! in_array((int) $r['productId'], $mine, true)) {
                throw ValidationException::withMessages(["rows.$i.productId" => 'This product does not belong to the company.']);
            }
        }

        return $rows;
    }

    private function replaceLines(PurchaseOrderDraft $draft, array $rows): void
    {
        $draft->lines()->delete();
        foreach ($rows as $r) {
            PurchaseOrderDraftLine::create([
                'draft_id' => $draft->id, 'product_id' => (int) $r['productId'], 'order_qty' => (int) $r['orderQty'],
                'sold_qty' => max(0, (int) ($r['soldQty'] ?? 0)), 'in_stock' => max(0, (int) ($r['inStock'] ?? 0)),
                'unit_cost' => max(0, (float) ($r['unitCost'] ?? 0)), 'forecast' => $r['forecast'] ?? null,
            ]);
        }
        $draft->touch();
    }

    private function mustBeDraft(PurchaseOrderDraft $draft): void
    {
        if ($draft->status !== 'draft') {
            throw ValidationException::withMessages(['draft' => "{$draft->number} was already converted to a purchase order."]);
        }
    }

    private function soldBetween(int $warehouseId, array $ids, Carbon $from, Carbon $to): Collection
    {
        return DB::table('sale_item_batches as sib')->join('sale_items as si', 'si.id', '=', 'sib.sale_item_id')
            ->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.warehouse_id', $warehouseId)->where('s.status', 'completed')->whereBetween('s.sale_date', [$from, $to])
            ->whereIn('si.product_id', $ids)->groupBy('si.product_id')
            ->selectRaw('si.product_id AS pid, SUM(sib.qty_base - sib.returned_qty_base) AS q')->pluck('q', 'pid');
    }

    private function sellable(int $warehouseId, array $ids): Collection
    {
        $today = now()->toDateString();

        return DB::table('stock_balances as sb')->join('batches as b', 'b.id', '=', 'sb.batch_id')
            ->where('sb.warehouse_id', $warehouseId)->whereIn('sb.product_id', $ids)->where('sb.qty_base', '>', 0)->where('b.status', 'active')
            ->where(fn ($q) => $q->where('b.expiry_date', '>=', $today)->orWhere(fn ($q) => $q->whereNull('b.expiry_date')->where('b.is_legacy_opening', true)))
            ->groupBy('sb.product_id')->selectRaw('sb.product_id AS pid, SUM(sb.qty_base) AS q')->pluck('q', 'pid');
    }

    /** Last landed cost per piece from received purchases, else the current average batch cost. */
    private function lastCosts(int $warehouseId, array $ids): Collection
    {
        $last = DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('p.status', 'received')->whereIn('pi.product_id', $ids)
            ->orderByDesc('p.received_at')->orderByDesc('pi.id')
            ->get(['pi.product_id', 'pi.line_total', 'pi.qty_base', 'pi.free_qty_base'])
            ->unique('product_id')->mapWithKeys(fn ($r) => [$r->product_id => (float) $r->line_total / max(1, $r->qty_base + $r->free_qty_base)]);
        $avg = DB::table('stock_balances')->where('warehouse_id', $warehouseId)->whereIn('product_id', $ids)->where('qty_base', '>', 0)
            ->groupBy('product_id')->selectRaw('product_id, SUM(qty_base * avg_unit_cost) / SUM(qty_base) AS c')->pluck('c', 'product_id');

        return collect($ids)->mapWithKeys(fn ($id) => [$id => $last[$id] ?? (float) ($avg[$id] ?? 0)]);
    }
}
