<?php

namespace App\Reports;

use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Sales-side reports. Revenue is the grand total of completed sales (voids
 * excluded); COGS is the batch cost recorded on each sale (sales.cost_total);
 * expenses and income come from posted finance entries.
 */
class SalesReports
{
    public function sales(ReportScope $s): array
    {
        $rows = $this->completed($s)
            ->when($s->filter('channel'), fn ($q, $c) => $q->where('channel', $c))
            ->when($s->filter('payment'), fn ($q, $p) => $q->where('payment_status', $p))
            ->with('customer', 'cashier')->withCount('items')->orderByDesc('sale_date')->get()
            ->map(fn ($x) => $this->saleRow($x))->all();
        $total = ReportScope::sum($rows, 'grandTotal');

        return [
            'kpis' => [
                ReportScope::kpi('Net sales', $total), ReportScope::kpi('Invoices', count($rows), 'number'),
                ReportScope::kpi('Average basket', count($rows) ? $total / count($rows) : 0),
                ReportScope::kpi('Outstanding due', ReportScope::sum($rows, 'dueTotal')),
            ],
            'series' => ReportScope::perDay($rows, 'date', fn ($r) => $r['grandTotal']), 'seriesTitle' => 'Net sales per day',
            'columns' => [
                ReportScope::col('number', 'Invoice', 'mono'), ReportScope::col('date', 'Date', 'datetime'),
                ReportScope::col('customer', 'Customer'), ReportScope::col('cashier', 'Cashier'),
                ReportScope::col('channel', 'Channel', 'badge', ['tones' => ['pos' => 'brand', 'website' => 'info', 'app' => 'violet']]),
                ReportScope::col('items', 'Items', 'number'), ReportScope::col('grandTotal', 'Total', 'money'),
                ReportScope::col('paidTotal', 'Paid', 'money'), ReportScope::col('dueTotal', 'Due', 'money'),
            ],
            'rows' => $rows,
            'totals' => ['items' => ReportScope::sum($rows, 'items'), 'grandTotal' => $total, 'paidTotal' => ReportScope::sum($rows, 'paidTotal'), 'dueTotal' => ReportScope::sum($rows, 'dueTotal')],
        ];
    }

    public function profit(ReportScope $s): array
    {
        $sales = $this->completed($s)->get(['id', 'sale_date', 'grand_total', 'cost_total']);
        $revenue = ReportScope::r2($sales->sum(fn ($x) => (float) $x->grand_total));
        $cogs = ReportScope::r2($sales->sum(fn ($x) => (float) $x->cost_total));
        $gross = ReportScope::r2($revenue - $cogs);
        $expenses = $this->entries($s, 'expense', ...$s->range());
        $margin = fn ($rev, $cost) => $rev > 0 ? round(($rev - $cost) / $rev * 100, 1) : 0;

        if ($s->filter('view', 'day') === 'product') {
            $rows = DB::table('sale_items as si')->join('sales as x', 'x.id', '=', 'si.sale_id')->join('products as p', 'p.id', '=', 'si.product_id')
                ->whereIn('x.id', $sales->pluck('id'))->where('si.line_type', 'product')
                ->groupBy('p.id', 'p.name', 'p.strength')
                ->selectRaw('p.id, p.name, p.strength, SUM(si.qty_base) AS qty, SUM(si.line_total) AS revenue, SUM(si.cost_total) AS cogs')
                ->get()->map(fn ($r) => [
                    'productId' => $r->id, 'product' => trim($r->name.' '.$r->strength), 'qty' => (int) $r->qty,
                    'revenue' => ReportScope::r2($r->revenue), 'cogs' => ReportScope::r2($r->cogs),
                    'profit' => ReportScope::r2($r->revenue - $r->cogs), 'margin' => $margin((float) $r->revenue, (float) $r->cogs),
                ])->sortByDesc('profit')->values()->all();
            $columns = [ReportScope::col('product', 'Product'), ReportScope::col('qty', 'Pieces sold', 'number')];
        } else {
            $rows = $sales->groupBy(fn ($x) => $x->sale_date->toDateString())->map(function ($day, $d) use ($margin) {
                $rev = ReportScope::r2($day->sum(fn ($x) => (float) $x->grand_total));
                $cost = ReportScope::r2($day->sum(fn ($x) => (float) $x->cost_total));

                return ['day' => $d, 'invoices' => $day->count(), 'revenue' => $rev, 'cogs' => $cost, 'profit' => ReportScope::r2($rev - $cost), 'margin' => $margin($rev, $cost)];
            })->sortKeysDesc()->values()->all();
            $columns = [ReportScope::col('day', 'Date', 'date'), ReportScope::col('invoices', 'Invoices', 'number')];
        }

        return [
            'kpis' => [
                ReportScope::kpi('Revenue', $revenue),
                ReportScope::kpi('Gross profit', $gross, 'money', ($revenue > 0 ? number_format($gross / $revenue * 100, 1) : '0.0').'% margin'),
                ReportScope::kpi('Expenses', $expenses, 'money', 'Posted expenses in period'),
                ReportScope::kpi('Net profit', $gross - $expenses),
            ],
            'series' => ReportScope::perDay($sales->map(fn ($x) => ['d' => $x->sale_date->toDateString(), 'v' => (float) $x->grand_total - (float) $x->cost_total]), 'd', fn ($r) => $r['v']),
            'seriesTitle' => 'Gross profit per day',
            'columns' => [...$columns, ReportScope::col('revenue', 'Revenue', 'money'), ReportScope::col('cogs', 'COGS (batch cost)', 'money'),
                ReportScope::col('profit', 'Gross profit', 'money'), ReportScope::col('margin', 'Margin', 'percent')],
            'rows' => $rows,
            'totals' => ['invoices' => $sales->count(), 'qty' => ReportScope::sum($rows, 'qty'), 'revenue' => ReportScope::sum($rows, 'revenue'),
                'cogs' => ReportScope::sum($rows, 'cogs'), 'profit' => ReportScope::sum($rows, 'profit')],
        ];
    }

    /** A biller is the branch the invoice is issued from (the warehouse). */
    public function billers(ReportScope $s): array
    {
        $names = Warehouse::pluck('name', 'id');
        $rows = $this->completed($s)->when($s->filter('biller'), fn ($q, $b) => $q->where('warehouse_id', (int) $b))
            ->with('customer', 'cashier')->withCount('items')->orderByDesc('sale_date')->get()
            ->map(fn ($x) => ['biller' => $names[$x->warehouse_id] ?? '', 'billerId' => $x->warehouse_id] + $this->saleRow($x))->all();
        $byBiller = collect($rows)->groupBy('biller')->map(fn ($g) => ReportScope::sum($g, 'grandTotal'))->sortDesc();

        return [
            'kpis' => [
                ReportScope::kpi('Sales', ReportScope::sum($rows, 'grandTotal')), ReportScope::kpi('Invoices', count($rows), 'number'),
                ReportScope::kpi('Collected', ReportScope::sum($rows, 'paidTotal')),
                ReportScope::kpi('Outstanding due', ReportScope::sum($rows, 'dueTotal'), 'money', $byBiller->isNotEmpty() ? 'Top biller: '.$byBiller->keys()->first() : null),
            ],
            'series' => $byBiller->map(fn ($v, $name) => ['label' => preg_replace('/^Red Pharma\s*·?\s*/', '', $name), 'value' => $v])->values()->all(),
            'seriesTitle' => 'Sales by biller',
            'columns' => [
                ReportScope::col('biller', 'Biller'), ReportScope::col('number', 'Invoice', 'mono'), ReportScope::col('date', 'Date', 'datetime'),
                ReportScope::col('customer', 'Customer'), ReportScope::col('cashier', 'Cashier'), ReportScope::col('items', 'Items', 'number'),
                ReportScope::col('grandTotal', 'Total', 'money'), ReportScope::col('paidTotal', 'Paid', 'money'), ReportScope::col('dueTotal', 'Due', 'money'),
            ],
            'rows' => $rows,
            'totals' => ['items' => ReportScope::sum($rows, 'items'), 'grandTotal' => ReportScope::sum($rows, 'grandTotal'),
                'paidTotal' => ReportScope::sum($rows, 'paidTotal'), 'dueTotal' => ReportScope::sum($rows, 'dueTotal')],
        ];
    }

    /** Free-text items sold at the counter (no catalogue product, no stock). */
    public function customProducts(ReportScope $s): array
    {
        $rows = DB::table('sale_items as si')->join('sales as x', 'x.id', '=', 'si.sale_id')
            ->join('customers as c', 'c.id', '=', 'x.customer_id')->join('users as u', 'u.id', '=', 'x.cashier_id')
            ->where('si.line_type', 'misc')->where('x.status', 'completed')->whereIn('x.warehouse_id', $s->warehouseIds)
            ->whereBetween('x.sale_date', $s->range())
            ->when($s->filter('product'), fn ($q, $p) => $q->where('si.description', $p))
            ->orderByDesc('x.sale_date')
            ->get(['x.id as sale_id', 'x.number', 'x.sale_date', 'si.description', 'si.misc_code', 'si.qty', 'si.unit_price', 'si.line_total', 'u.name as cashier', 'c.name as customer'])
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->sale_date)->toIso8601String(), 'number' => $r->number, 'saleId' => $r->sale_id,
                'productName' => $r->description, 'productCode' => (string) $r->misc_code, 'qty' => (float) $r->qty,
                'unitPrice' => ReportScope::r2($r->unit_price), 'total' => ReportScope::r2($r->line_total), 'cashier' => $r->cashier, 'customer' => $r->customer,
            ])->all();
        $byItem = collect($rows)->groupBy('productName')->map(fn ($g) => ReportScope::sum($g, 'total'))->sortDesc();

        return [
            'kpis' => [
                ReportScope::kpi('Custom item sales', ReportScope::sum($rows, 'total')), ReportScope::kpi('Lines', count($rows), 'number'),
                ReportScope::kpi('Quantity sold', ReportScope::sum($rows, 'qty'), 'number'),
                ReportScope::kpi('Distinct items', $byItem->count(), 'number', $byItem->isNotEmpty() ? 'Most sold: '.$byItem->keys()->first() : null),
            ],
            'series' => $byItem->take(8)->map(fn ($v, $name) => ['label' => implode(' ', array_slice(explode(' ', (string) $name), 0, 2)), 'value' => $v])->values()->all(),
            'seriesTitle' => 'Top custom items by value',
            'columns' => [
                ReportScope::col('date', 'Date', 'datetime'), ReportScope::col('number', 'Invoice', 'mono'), ReportScope::col('productName', 'Item (typed at counter)'),
                ReportScope::col('productCode', 'Code', 'mono'), ReportScope::col('qty', 'Qty', 'number'), ReportScope::col('unitPrice', 'Unit price', 'money'),
                ReportScope::col('total', 'Total', 'money'), ReportScope::col('cashier', 'Cashier'), ReportScope::col('customer', 'Customer'),
            ],
            'rows' => $rows,
            'totals' => ['qty' => ReportScope::sum($rows, 'qty'), 'total' => ReportScope::sum($rows, 'total')],
        ];
    }

    /** One row per day of the month (up to today for the current month). */
    public function monthlySummary(array $warehouseIds, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->min(now()->endOfDay());
        if ($end->lt($start)) {
            return [];
        }
        $range = [$start, $end];
        $byDay = fn ($q, string $col, string $expr) => $q->groupBy(DB::raw("DATE($col)"))->selectRaw("DATE($col) AS d, $expr")->get()->keyBy('d');

        $sales = $byDay(DB::table('sales')->where('status', 'completed')->whereIn('warehouse_id', $warehouseIds)->whereBetween('sale_date', $range), 'sale_date',
            'COUNT(*) AS invoices, SUM(subtotal) AS gross, SUM(line_discount_total + order_discount_total) AS discounts, SUM(cost_total) AS cogs');
        $returns = $byDay(DB::table('sale_returns')->whereIn('warehouse_id', $warehouseIds)->whereBetween('created_at', $range), 'created_at', 'SUM(total) AS v');
        $restocked = $byDay(DB::table('sale_return_items as ri')->join('sale_returns as r', 'r.id', '=', 'ri.sale_return_id')
            ->join('sale_item_batches as sib', 'sib.id', '=', 'ri.sale_item_batch_id')->whereIn('r.warehouse_id', $warehouseIds)
            ->whereBetween('r.created_at', $range)->where('ri.restock', true), 'r.created_at', 'SUM(ri.qty_base * sib.unit_cost) AS v');
        $purchases = $byDay(DB::table('purchases')->where('status', 'received')->whereIn('warehouse_id', $warehouseIds)->whereBetween('received_at', $range), 'received_at', 'SUM(grand_total) AS v');
        $entries = DB::table('finance_entries')->where('status', 'posted')->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('entry_date', [$start->toDateString(), $end->toDateString()])
            ->groupBy('entry_date', 'kind')->selectRaw('entry_date AS d, kind, SUM(amount) AS v')->get()
            ->groupBy(fn ($r) => substr((string) $r->d, 0, 10));
        $mix = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')
            ->join('payment_allocations as a', 'a.payment_id', '=', 'p.id')->join('sales as x', fn ($j) => $j->on('x.id', '=', 'a.allocatable_id')->where('a.allocatable_type', 'sale'))
            ->where(['p.direction' => 'in', 'p.status' => 'posted'])->whereIn('x.warehouse_id', $warehouseIds)->whereBetween('p.paid_at', $range)
            ->groupBy(DB::raw('DATE(p.paid_at)'), 'm.code')->selectRaw('DATE(p.paid_at) AS d, m.code, SUM(a.amount) AS v')->get()
            ->groupBy(fn ($r) => substr((string) $r->d, 0, 10));

        $days = [];
        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            $k = $d->toDateString();
            $sale = $sales[$k] ?? null;
            $gross = ReportScope::r2($sale->gross ?? 0);
            $ret = ReportScope::r2($returns[$k]->v ?? 0);
            $disc = ReportScope::r2($sale->discounts ?? 0);
            $net = ReportScope::r2($gross - $ret - $disc);
            $cogs = ReportScope::r2(($sale->cogs ?? 0) - ($restocked[$k]->v ?? 0));
            $exp = ReportScope::r2(($entries[$k] ?? collect())->where('kind', 'expense')->sum('v'));
            $inc = ReportScope::r2(($entries[$k] ?? collect())->where('kind', 'income')->sum('v'));
            $paid = fn ($code) => ReportScope::r2(($mix[$k] ?? collect())->where('code', $code)->sum('v'));
            $days[] = [
                'date' => $k, 'invoices' => (int) ($sale->invoices ?? 0), 'sales' => $gross, 'returns' => $ret, 'discounts' => $disc,
                'netSales' => $net, 'cogs' => $cogs, 'grossProfit' => ReportScope::r2($net - $cogs),
                'purchases' => ReportScope::r2($purchases[$k]->v ?? 0), 'expenses' => $exp, 'income' => $inc,
                'netProfit' => ReportScope::r2($net - $cogs - $exp + $inc),
                'cash' => $paid('cash'), 'bkash' => $paid('bkash'), 'card' => $paid('card'), 'nagad' => $paid('nagad'),
            ];
        }

        return $days;
    }

    private function completed(ReportScope $s)
    {
        return Sale::where('status', 'completed')->whereIn('warehouse_id', $s->warehouseIds)->whereBetween('sale_date', $s->range());
    }

    private function saleRow(Sale $x): array
    {
        return [
            'id' => $x->id, 'number' => $x->number, 'date' => $x->sale_date->toIso8601String(), 'customer' => $x->customer?->name ?? '',
            'cashier' => $x->cashier?->name ?? '', 'channel' => $x->channel, 'items' => (int) $x->items_count,
            'grandTotal' => ReportScope::r2($x->grand_total), 'paidTotal' => ReportScope::r2($x->paid_total), 'dueTotal' => ReportScope::r2($x->due_total),
        ];
    }

    private function entries(ReportScope $s, string $kind, Carbon $from, Carbon $to): float
    {
        return ReportScope::r2(DB::table('finance_entries')->where(['kind' => $kind, 'status' => 'posted'])->whereIn('warehouse_id', $s->warehouseIds)
            ->whereBetween('entry_date', [$from->toDateString(), $to->toDateString()])->sum('amount'));
    }
}
