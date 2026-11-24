<?php

namespace App\Reports;

use App\Services\Pricing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Stock snapshots (the date range does not apply). Sellable = active batch,
 * not expired (legacy opening batches without expiry count), as everywhere
 * else. Value at cost uses each warehouse balance's average cost; value at
 * retail uses the current price of a piece.
 */
class StockReports
{
    public const BUCKETS = ['expired' => [PHP_INT_MIN, -1], 'critical' => [0, 30], 'soon' => [31, 90], 'ok' => [91, PHP_INT_MAX]];

    public function __construct(private Pricing $pricing) {}

    public function stock(ReportScope $s): array
    {
        $today = now()->toDateString();
        $products = DB::table('products as p')
            ->leftJoin('manufacturers as m', 'm.id', '=', 'p.manufacturer_id')->leftJoin('dosage_forms as f', 'f.id', '=', 'p.dosage_form_id')
            ->where('p.is_active', true)->whereNull('p.deleted_at')
            ->when($s->filter('manufacturer'), fn ($q, $v) => $q->where('m.name', $v))
            ->when($s->filter('form'), fn ($q, $v) => $q->where('f.name', $v))
            ->orderBy('p.name')->get(['p.id', 'p.name', 'p.strength', 'p.reorder_level_base', 'm.name as manufacturer', 'f.name as form']);
        $ids = $products->pluck('id')->all();
        $stock = DB::table('stock_balances as sb')->join('batches as b', 'b.id', '=', 'sb.batch_id')
            ->whereIn('sb.warehouse_id', $s->warehouseIds)->whereIn('sb.product_id', $ids)->where('sb.qty_base', '>', 0)->where('b.status', 'active')
            ->where(fn ($q) => $q->where('b.expiry_date', '>=', $today)->orWhere(fn ($q) => $q->whereNull('b.expiry_date')->where('b.is_legacy_opening', true)))
            ->groupBy('sb.product_id')
            ->selectRaw('sb.product_id AS pid, COUNT(DISTINCT sb.batch_id) AS batches, SUM(sb.qty_base) AS pieces, SUM(sb.qty_base * sb.avg_unit_cost) AS cost')
            ->get()->keyBy('pid');
        $piecePrice = $this->piecePrices($ids);

        $rows = $products->map(function ($p) use ($stock, $piecePrice) {
            $st = $stock[$p->id] ?? null;
            $pieces = (int) ($st->pieces ?? 0);
            $reorder = (int) $p->reorder_level_base;

            return [
                'productId' => $p->id, 'product' => trim($p->name.' '.$p->strength), 'manufacturer' => (string) $p->manufacturer, 'form' => (string) $p->form,
                'batches' => (int) ($st->batches ?? 0), 'pieces' => $pieces, 'reorder' => $reorder,
                'status' => $pieces <= 0 ? 'out' : ($pieces <= $reorder ? 'low' : 'ok'),
                'costValue' => ReportScope::r2($st->cost ?? 0), 'retailValue' => ReportScope::r2($pieces * ($piecePrice[$p->id] ?? 0)),
            ];
        })->all();
        $cost = ReportScope::sum($rows, 'costValue');
        $retail = ReportScope::sum($rows, 'retailValue');

        return [
            'kpis' => [
                ReportScope::kpi('Stock value at cost', $cost),
                ReportScope::kpi('Stock value at retail', $retail, 'money', 'Potential margin ৳'.number_format($retail - $cost, 2)),
                ReportScope::kpi('Sellable pieces', (int) ReportScope::sum($rows, 'pieces'), 'number'),
                ReportScope::kpi('Low / out of stock', collect($rows)->where('status', '!=', 'ok')->count(), 'number', 'At or below reorder level'),
            ],
            'columns' => [
                ReportScope::col('product', 'Product'), ReportScope::col('manufacturer', 'Manufacturer'), ReportScope::col('form', 'Form'),
                ReportScope::col('batches', 'Batches', 'number'), ReportScope::col('pieces', 'Pieces', 'number'), ReportScope::col('reorder', 'Reorder at', 'number'),
                ReportScope::col('status', 'Status', 'badge', ['tones' => ['ok' => 'success', 'low' => 'warning', 'out' => 'danger']]),
                ReportScope::col('costValue', 'Value at cost', 'money'), ReportScope::col('retailValue', 'Value at retail', 'money'),
            ],
            'rows' => $rows,
            'totals' => ['batches' => ReportScope::sum($rows, 'batches'), 'pieces' => ReportScope::sum($rows, 'pieces'), 'costValue' => $cost, 'retailValue' => $retail],
        ];
    }

    public function expiry(ReportScope $s): array
    {
        $today = Carbon::today();
        $all = DB::table('stock_balances as sb')->join('batches as b', 'b.id', '=', 'sb.batch_id')->join('products as p', 'p.id', '=', 'sb.product_id')
            ->leftJoin('manufacturers as m', 'm.id', '=', 'p.manufacturer_id')
            ->whereIn('sb.warehouse_id', $s->warehouseIds)->where('sb.qty_base', '>', 0)->whereNotNull('b.expiry_date')
            ->get(['p.id as product_id', 'b.id as batch_id', 'p.name', 'p.strength', 'm.name as manufacturer', 'b.batch_no', 'b.expiry_date', 'sb.qty_base', 'sb.avg_unit_cost'])
            ->map(function ($r) use ($today) {
                $days = (int) $today->diffInDays(Carbon::parse($r->expiry_date)->startOfDay(), false);
                $bucket = collect(self::BUCKETS)->search(fn ($b) => $days >= $b[0] && $days <= $b[1]);

                return [
                    'productId' => $r->product_id, 'batchId' => $r->batch_id, 'product' => trim($r->name.' '.$r->strength), 'manufacturer' => (string) $r->manufacturer,
                    'batchNo' => $r->batch_no, 'expiryDate' => substr((string) $r->expiry_date, 0, 10), 'days' => $days, 'bucket' => $bucket,
                    'qty' => (int) $r->qty_base, 'value' => ReportScope::r2($r->qty_base * $r->avg_unit_cost),
                ];
            });
        $window = $s->filter('window');
        $rows = $all->filter(fn ($r) => $window ? $r['bucket'] === $window : $r['bucket'] !== 'ok')
            ->filter(fn ($r) => ! $s->filter('manufacturer') || $r['manufacturer'] === $s->filter('manufacturer'))
            ->sortBy('days')->values()->all();
        // KPIs cover every batch on hand (filters only narrow the table).
        $in = fn ($b) => $all->where('bucket', $b);
        $risk = ReportScope::r2($in('expired')->sum('value') + $in('critical')->sum('value') + $in('soon')->sum('value'));

        return [
            'kpis' => [
                ReportScope::kpi('Expired (blocked)', ReportScope::r2($in('expired')->sum('value')), 'money', $in('expired')->count().' batches · write off or return'),
                ReportScope::kpi('Expiring ≤ 30 days', ReportScope::r2($in('critical')->sum('value')), 'money', $in('critical')->count().' batches'),
                ReportScope::kpi('Expiring 31–90 days', ReportScope::r2($in('soon')->sum('value')), 'money', $in('soon')->count().' batches'),
                ReportScope::kpi('Value at risk', $risk, 'money', 'At cost'),
            ],
            'columns' => [
                ReportScope::col('product', 'Product'), ReportScope::col('manufacturer', 'Manufacturer'), ReportScope::col('batchNo', 'Batch', 'mono'),
                ReportScope::col('expiryDate', 'Expiry', 'date'), ReportScope::col('days', 'Days left', 'number'),
                ReportScope::col('bucket', 'Window', 'badge', ['tones' => ['expired' => 'danger', 'critical' => 'danger', 'soon' => 'warning', 'ok' => 'success']]),
                ReportScope::col('qty', 'Pieces', 'number'), ReportScope::col('value', 'Value at cost', 'money'),
            ],
            'rows' => $rows,
            'totals' => ['qty' => ReportScope::sum($rows, 'qty'), 'value' => ReportScope::sum($rows, 'value')],
        ];
    }

    /** Current retail price of one piece: the sale-default unit's price ÷ its factor. */
    private function piecePrices(array $productIds): array
    {
        $units = DB::table('product_units')->whereIn('product_id', $productIds)->orderByDesc('is_sale_default')->orderBy('factor')->get()->groupBy('product_id');
        $prices = $this->pricing->forUnits($units->flatten()->pluck('id')->all());
        $out = [];
        foreach ($units as $pid => $list) {
            $u = $list->first(fn ($u) => $prices->has($u->id));
            $out[$pid] = $u ? (float) $prices[$u->id] / max(1, $u->factor) : 0;
        }

        return $out;
    }
}
