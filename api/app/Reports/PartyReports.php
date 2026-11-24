<?php

namespace App\Reports;

use App\Models\Customer;
use App\Models\Purchase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Purchases, customers, suppliers, payments and cashier reports. Balances come
 * from the party ledgers; loyalty (membership, points) is not built yet, so
 * every customer has membership "none" and 0 points.
 */
class PartyReports
{
    public function purchases(ReportScope $s): array
    {
        $list = Purchase::where('status', 'received')->whereIn('warehouse_id', $s->warehouseIds)->whereBetween('received_at', $s->range())
            ->when($s->filter('supplier'), fn ($q, $v) => $q->whereHas('supplier', fn ($x) => $x->where('name', $v)))
            ->with('supplier')->withCount('items')->orderByDesc('received_at')->get();
        $invoiceRows = $list->map(fn ($p) => [
            'id' => $p->id, 'number' => $p->number, 'date' => $p->received_at->toDateString(), 'supplier' => $p->supplier?->name ?? '',
            'invoiceNo' => (string) $p->supplier_invoice_no, 'lines' => (int) $p->items_count, 'grandTotal' => ReportScope::r2($p->grand_total),
            'paidTotal' => ReportScope::r2($p->paid_total), 'dueTotal' => ReportScope::r2($p->due_total),
        ]);
        $money = [ReportScope::col('grandTotal', 'Total', 'money'), ReportScope::col('paidTotal', 'Paid', 'money'), ReportScope::col('dueTotal', 'Due', 'money')];

        if ($s->filter('view', 'invoice') === 'supplier') {
            $rows = $invoiceRows->groupBy('supplier')->map(fn ($g, $name) => [
                'supplier' => $name, 'invoices' => $g->count(), 'lines' => (int) $g->sum('lines'), 'grandTotal' => ReportScope::sum($g, 'grandTotal'),
                'paidTotal' => ReportScope::sum($g, 'paidTotal'), 'dueTotal' => ReportScope::sum($g, 'dueTotal'),
            ])->sortByDesc('grandTotal')->values()->all();
            $columns = [ReportScope::col('supplier', 'Supplier'), ReportScope::col('invoices', 'Invoices', 'number'), ReportScope::col('lines', 'Lines', 'number'), ...$money];
        } else {
            $rows = $invoiceRows->all();
            $columns = [ReportScope::col('number', 'Purchase', 'mono'), ReportScope::col('date', 'Received', 'date'), ReportScope::col('supplier', 'Supplier'),
                ReportScope::col('invoiceNo', 'Supplier inv.'), ReportScope::col('lines', 'Lines', 'number'), ...$money];
        }

        return [
            'kpis' => [
                ReportScope::kpi('Purchased', ReportScope::sum($invoiceRows, 'grandTotal')), ReportScope::kpi('Invoices', $invoiceRows->count(), 'number'),
                ReportScope::kpi('Paid', ReportScope::sum($invoiceRows, 'paidTotal')), ReportScope::kpi('Supplier due', ReportScope::sum($invoiceRows, 'dueTotal')),
            ],
            'series' => ReportScope::perDay($invoiceRows, 'date', fn ($r) => $r['grandTotal']), 'seriesTitle' => 'Purchases per day',
            'columns' => $columns, 'rows' => $rows,
            'totals' => ['invoices' => $invoiceRows->count(), 'lines' => ReportScope::sum($invoiceRows, 'lines'), 'grandTotal' => ReportScope::sum($invoiceRows, 'grandTotal'),
                'paidTotal' => ReportScope::sum($invoiceRows, 'paidTotal'), 'dueTotal' => ReportScope::sum($invoiceRows, 'dueTotal')],
        ];
    }

    public function customers(ReportScope $s): array
    {
        $membership = $s->filter('membership');
        $customers = Customer::where('is_walk_in', false)->get(['id', 'name', 'phone']);
        $period = $this->salesBy($s, true);
        $lifetime = $this->salesBy($s, false);
        $due = $this->ledger('customer', 'debit - credit');
        $rows = $customers->map(fn ($c) => [
            'customerId' => $c->id, 'name' => $c->name, 'phone' => (string) $c->phone, 'membership' => 'none',
            'invoices' => (int) ($period[$c->id]->n ?? 0), 'periodSpend' => ReportScope::r2($period[$c->id]->spent ?? 0),
            'lifetime' => ReportScope::r2($lifetime[$c->id]->spent ?? 0), 'points' => 0, 'due' => max(0, ReportScope::r2($due[$c->id] ?? 0)),
            'lastVisit' => isset($lifetime[$c->id]) ? substr((string) $lifetime[$c->id]->last, 0, 10) : null,
        ])
            ->filter(fn ($r) => ! $membership || $membership === 'none') // no memberships exist yet
            ->filter(fn ($r) => $s->filter('due') !== 'yes' || $r['due'] > 0)
            ->sortByDesc('lifetime')->values()->all();

        return [
            'kpis' => [
                ReportScope::kpi('Customers', count($rows), 'number'),
                ReportScope::kpi('Spend in period', ReportScope::sum($rows, 'periodSpend'), 'money', 'Named customers only'),
                ReportScope::kpi('Outstanding due', ReportScope::sum($rows, 'due'), 'money', collect($rows)->where('due', '>', 0)->count().' customers owe'),
                ReportScope::kpi('Points liability', 0, 'number', 'Unredeemed points'),
            ],
            'columns' => [
                ReportScope::col('name', 'Customer'), ReportScope::col('phone', 'Phone'),
                ReportScope::col('membership', 'Membership', 'badge', ['tones' => ['Gold' => 'warning', 'Silver' => 'neutral', 'none' => 'neutral']]),
                ReportScope::col('invoices', 'Invoices', 'number'), ReportScope::col('periodSpend', 'Spend (period)', 'money'), ReportScope::col('lifetime', 'Lifetime', 'money'),
                ReportScope::col('points', 'Points', 'number'), ReportScope::col('due', 'Due', 'money'), ReportScope::col('lastVisit', 'Last visit', 'date'),
            ],
            'rows' => $rows,
            'totals' => ['invoices' => ReportScope::sum($rows, 'invoices'), 'periodSpend' => ReportScope::sum($rows, 'periodSpend'),
                'lifetime' => ReportScope::sum($rows, 'lifetime'), 'points' => 0, 'due' => ReportScope::sum($rows, 'due')],
        ];
    }

    /** One customer's ledger in the period (with an opening line), or a summary of all named customers. */
    public function customer(ReportScope $s): array
    {
        [$from, $to] = [$s->from, $s->to];
        $type = fn (string $t) => ['invoice' => 'sale', 'payment' => 'payment', 'return' => 'return', 'refund' => 'refund', 'opening' => 'opening'][$t] ?? $t;
        $columns = [ReportScope::col('date', 'Date', 'datetime'),
            ReportScope::col('type', 'Type', 'badge', ['tones' => ['sale' => 'brand', 'payment' => 'success', 'return' => 'warning', 'opening' => 'neutral', 'void' => 'neutral', 'refund' => 'info']]),
            ReportScope::col('reference', 'Reference', 'mono'), ReportScope::col('debit', 'Debit', 'money'), ReportScope::col('credit', 'Credit', 'money'), ReportScope::col('balance', 'Balance', 'money')];

        if ($id = $s->filter('customer')) {
            $c = Customer::whereKey((int) $id)->where('is_walk_in', false)->firstOrFail();
            $entries = DB::table('party_ledger_entries')->where(['party_type' => 'customer', 'party_id' => $c->id]);
            $opening = (float) (clone $entries)->whereDate('entry_date', '<', $from)->selectRaw('COALESCE(SUM(debit - credit), 0) AS v')->value('v');
            $balance = $opening;
            $rows = [['date' => Carbon::parse($from)->toIso8601String(), 'type' => 'opening', 'reference' => 'Opening balance', 'debit' => 0, 'credit' => 0, 'balance' => ReportScope::r2($opening), 'saleId' => null]];
            foreach ((clone $entries)->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)->orderBy('entry_date')->orderBy('id')->get() as $e) {
                $balance += (float) $e->debit - (float) $e->credit;
                $rows[] = [
                    'date' => Carbon::parse($e->created_at ?? $e->entry_date)->toIso8601String(), 'type' => $type($e->type), 'reference' => (string) $e->note,
                    'debit' => ReportScope::r2($e->debit), 'credit' => ReportScope::r2($e->credit), 'balance' => ReportScope::r2($balance),
                    'saleId' => $e->source_type === 'sale' ? $e->source_id : null,
                ];
            }
            $of = fn ($t, $k) => ReportScope::sum(array_filter($rows, fn ($r) => $r['type'] === $t), $k);

            return [
                'kpis' => [
                    ReportScope::kpi('Purchases', $of('sale', 'debit')), ReportScope::kpi('Paid', $of('payment', 'credit')),
                    ReportScope::kpi('Returns', $of('return', 'credit')),
                    ReportScope::kpi('Balance due', ReportScope::r2($balance), 'money', '0 points · no membership'),
                ],
                'columns' => $columns, 'rows' => $rows,
                'totals' => ['debit' => ReportScope::sum($rows, 'debit'), 'credit' => ReportScope::sum($rows, 'credit'), 'balance' => ReportScope::r2($balance)],
            ];
        }

        $sums = DB::table('party_ledger_entries')->where('party_type', 'customer')->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)
            ->groupBy('party_id', 'type')->selectRaw('party_id, type, SUM(debit) AS d, SUM(credit) AS c')->get()->groupBy('party_id');
        $due = $this->ledger('customer', 'debit - credit');
        $invoices = $this->salesBy($s, true);
        $rows = Customer::where('is_walk_in', false)->get(['id', 'name', 'phone'])->map(function ($c) use ($sums, $due, $invoices) {
            $g = $sums[$c->id] ?? collect();

            return [
                'customerId' => $c->id, 'name' => $c->name, 'phone' => (string) $c->phone, 'membership' => '',
                'invoices' => (int) ($invoices[$c->id]->n ?? 0), 'purchases' => ReportScope::r2($g->where('type', 'invoice')->sum('d')),
                'paid' => ReportScope::r2($g->where('type', 'payment')->sum('c')), 'returns' => ReportScope::r2($g->where('type', 'return')->sum('c')),
                'due' => max(0, ReportScope::r2($due[$c->id] ?? 0)), 'points' => 0,
            ];
        })->sortByDesc('purchases')->values()->all();

        return [
            'kpis' => [
                ReportScope::kpi('Customers', count($rows), 'number'), ReportScope::kpi('Purchases', ReportScope::sum($rows, 'purchases')),
                ReportScope::kpi('Returns', ReportScope::sum($rows, 'returns')),
                ReportScope::kpi('Total due', ReportScope::sum($rows, 'due'), 'money', 'Choose a customer for their statement'),
            ],
            'columns' => [
                ReportScope::col('name', 'Customer'), ReportScope::col('phone', 'Phone'), ReportScope::col('membership', 'Membership'),
                ReportScope::col('invoices', 'Invoices', 'number'), ReportScope::col('purchases', 'Purchases', 'money'), ReportScope::col('paid', 'Paid', 'money'),
                ReportScope::col('returns', 'Returns', 'money'), ReportScope::col('due', 'Due', 'money'), ReportScope::col('points', 'Points', 'number'),
            ],
            'rows' => $rows,
            'totals' => ['invoices' => ReportScope::sum($rows, 'invoices'), 'purchases' => ReportScope::sum($rows, 'purchases'), 'paid' => ReportScope::sum($rows, 'paid'),
                'returns' => ReportScope::sum($rows, 'returns'), 'due' => ReportScope::sum($rows, 'due')],
        ];
    }

    public function suppliers(ReportScope $s): array
    {
        $purchases = DB::table('purchases')->where('status', 'received')->whereIn('warehouse_id', $s->warehouseIds)->whereBetween('received_at', $s->range())
            ->groupBy('supplier_id')->selectRaw('supplier_id, COUNT(*) AS n, SUM(grand_total) AS total, SUM(paid_total) AS paid, SUM(due_total) AS due')->get()->keyBy('supplier_id');
        $returns = DB::table('purchase_returns')->where('status', 'posted')->whereIn('warehouse_id', $s->warehouseIds)->whereBetween('created_at', $s->range())
            ->groupBy('supplier_id')->selectRaw('supplier_id, SUM(total) AS v')->pluck('v', 'supplier_id');
        $balance = $this->ledger('supplier', 'credit - debit');
        $rows = DB::table('suppliers')->orderBy('name')->get(['id', 'name', 'phone'])->map(fn ($x) => [
            'supplierId' => $x->id, 'supplier' => $x->name, 'phone' => $x->phone,
            'invoices' => (int) ($purchases[$x->id]->n ?? 0), 'purchased' => ReportScope::r2($purchases[$x->id]->total ?? 0),
            'returns' => ReportScope::r2($returns[$x->id] ?? 0), 'paid' => ReportScope::r2($purchases[$x->id]->paid ?? 0),
            'periodDue' => ReportScope::r2($purchases[$x->id]->due ?? 0), 'balance' => ReportScope::r2($balance[$x->id] ?? 0),
        ])->filter(fn ($r) => $s->filter('due') !== 'yes' || $r['balance'] > 0)->sortByDesc('purchased')->values()->all();

        return [
            'kpis' => [
                ReportScope::kpi('Purchased', ReportScope::sum($rows, 'purchased')), ReportScope::kpi('Returned to suppliers', ReportScope::sum($rows, 'returns')),
                ReportScope::kpi('Paid', ReportScope::sum($rows, 'paid')),
                ReportScope::kpi('Total supplier balance', ReportScope::sum($rows, 'balance'), 'money', 'From the supplier ledger'),
            ],
            'columns' => [
                ReportScope::col('supplier', 'Supplier'), ReportScope::col('phone', 'Phone'), ReportScope::col('invoices', 'Invoices', 'number'),
                ReportScope::col('purchased', 'Purchased', 'money'), ReportScope::col('returns', 'Returns', 'money'), ReportScope::col('paid', 'Paid', 'money'),
                ReportScope::col('periodDue', 'Due (period)', 'money'), ReportScope::col('balance', 'Ledger balance', 'money'),
            ],
            'rows' => $rows,
            'totals' => collect(['invoices', 'purchased', 'returns', 'paid', 'periodDue', 'balance'])->mapWithKeys(fn ($k) => [$k => ReportScope::sum($rows, $k)])->all(),
        ];
    }

    /** Money received from customers and suppliers, and paid out to them, in the period. */
    public function payments(ReportScope $s): array
    {
        $list = DB::table('payments as p')->join('payment_methods as m', 'm.id', '=', 'p.payment_method_id')->leftJoin('accounts as a', 'a.id', '=', 'p.account_id')
            ->leftJoin('customers as c', fn ($j) => $j->on('c.id', '=', 'p.party_id')->where('p.party_type', 'customer'))
            ->leftJoin('suppliers as su', fn ($j) => $j->on('su.id', '=', 'p.party_id')->where('p.party_type', 'supplier'))
            ->where('p.status', 'posted')->whereBetween('p.paid_at', $s->range())
            ->when($s->filter('direction'), fn ($q, $v) => $q->where('p.direction', $v))
            ->when($s->filter('method'), fn ($q, $v) => $q->where('m.code', $v))
            ->orderByDesc('p.paid_at')
            ->get(['p.id', 'p.number', 'p.paid_at', 'p.direction', 'p.amount', 'm.code as method', 'a.name as account', 'c.name as customer', 'su.name as supplier']);
        $allocs = DB::table('payment_allocations')->whereIn('payment_id', $list->pluck('id'))->orderBy('id')->get()->groupBy('payment_id');
        $docs = $this->documents($allocs->flatten());
        $whSales = DB::table('sales')->whereIn('warehouse_id', $s->warehouseIds)->pluck('id')->flip();
        $whPurchases = DB::table('purchases')->whereIn('warehouse_id', $s->warehouseIds)->pluck('id')->flip();

        $rows = $list->map(function ($p) use ($allocs, $docs) {
            $first = $allocs[$p->id][0] ?? null;
            $doc = $first ? ($docs[$first->allocatable_type.':'.$first->allocatable_id] ?? null) : null;

            return [
                'docType' => $doc['type'] ?? null, 'docId' => $doc['id'] ?? null, 'ref' => $p->number,
                'date' => Carbon::parse($p->paid_at)->toIso8601String(), 'direction' => $p->direction,
                'party' => (string) ($p->customer ?? $p->supplier), 'document' => $doc['number'] ?? '', 'method' => $p->method,
                'account' => (string) $p->account, 'amount' => ReportScope::r2($p->amount), '_scope' => $doc['scope'] ?? null,
            ];
        })
            // Only documents of the warehouses in scope (payments without a document are kept).
            ->filter(fn ($r) => ! $r['_scope'] || ($r['_scope'][0] === 'sale' ? $whSales->has($r['_scope'][1]) : $whPurchases->has($r['_scope'][1])))
            ->map(fn ($r) => array_diff_key($r, ['_scope' => 1]))->values()->all();
        $in = ReportScope::sum(array_filter($rows, fn ($r) => $r['direction'] === 'in'), 'amount');
        $out = ReportScope::sum(array_filter($rows, fn ($r) => $r['direction'] === 'out'), 'amount');
        $digital = ReportScope::sum(array_filter($rows, fn ($r) => $r['direction'] === 'in' && $r['method'] !== 'cash'), 'amount');

        return [
            'kpis' => [
                ReportScope::kpi('Received', $in), ReportScope::kpi('Paid out', $out), ReportScope::kpi('Net cash flow', $in - $out),
                ReportScope::kpi('Digital share', $in > 0 ? round($digital / $in * 100, 1) : 0, 'percent', 'bKash, Nagad and card'),
            ],
            'series' => ReportScope::perDay(array_filter($rows, fn ($r) => $r['direction'] === 'in'), 'date', fn ($r) => $r['amount']),
            'seriesTitle' => 'Money received per day',
            'columns' => [
                ReportScope::col('ref', 'Payment', 'mono'), ReportScope::col('date', 'Date', 'datetime'),
                ReportScope::col('direction', 'Direction', 'badge', ['tones' => ['in' => 'success', 'out' => 'danger']]),
                ReportScope::col('party', 'Customer / supplier'), ReportScope::col('document', 'Document', 'mono'),
                ReportScope::col('method', 'Method', 'badge', ['tones' => ['cash' => 'neutral', 'bkash' => 'violet', 'nagad' => 'warning', 'card' => 'info', 'bank' => 'brand', 'rocket' => 'violet']]),
                ReportScope::col('account', 'Account'), ReportScope::col('amount', 'Amount', 'money'),
            ],
            'rows' => $rows,
            'totals' => ['amount' => ReportScope::r2($in - $out)],
        ];
    }

    public function cashiers(ReportScope $s): array
    {
        $register = $s->filter('register');
        $registers = DB::table('cash_register_sessions as cs')->join('cash_registers as r', 'r.id', '=', 'cs.cash_register_id')->pluck('r.name', 'cs.id');
        $sales = DB::table('sales as x')->join('users as u', 'u.id', '=', 'x.cashier_id')->whereIn('x.warehouse_id', $s->warehouseIds)
            ->whereBetween('x.sale_date', $s->range())->whereIn('x.status', ['completed', 'void'])
            ->get(['x.cashier_id', 'u.name', 'x.status', 'x.grand_total', 'x.session_id'])
            ->filter(fn ($x) => ! $register || ($registers[$x->session_id] ?? null) === $register);
        $returns = DB::table('sale_returns')->whereIn('warehouse_id', $s->warehouseIds)->whereBetween('created_at', $s->range())
            ->groupBy('created_by')->selectRaw('created_by, COUNT(*) AS n')->pluck('n', 'created_by');
        $sessions = DB::table('cash_register_sessions as cs')->join('cash_registers as r', 'r.id', '=', 'cs.cash_register_id')
            ->whereIn('r.warehouse_id', $s->warehouseIds)->where('cs.status', 'closed')->whereBetween('cs.closed_at', $s->range())
            ->when($register, fn ($q, $v) => $q->where('r.name', $v))
            ->groupBy('cs.opened_by')->selectRaw('cs.opened_by, COUNT(*) AS n, SUM(cs.difference) AS diff')->get()->keyBy('opened_by');

        $rows = $sales->groupBy('cashier_id')->map(function ($g, $id) use ($registers, $returns, $sessions) {
            $done = $g->where('status', 'completed');
            $total = ReportScope::r2($done->sum(fn ($x) => (float) $x->grand_total));
            $usual = $g->map(fn ($x) => $registers[$x->session_id] ?? null)->filter()->countBy()->sortDesc()->keys()->first();

            return [
                'cashier' => $g->first()->name, 'register' => $usual ?? '—', 'invoices' => $done->count(), 'sales' => $total,
                'avg' => $done->count() ? ReportScope::r2($total / $done->count()) : 0, 'voids' => $g->where('status', 'void')->count(),
                'returns' => (int) ($returns[$id] ?? 0), 'sessions' => (int) ($sessions[$id]->n ?? 0), 'cashDiff' => ReportScope::r2($sessions[$id]->diff ?? 0),
            ];
        })->sortByDesc('sales')->values()->all();

        return [
            'kpis' => [
                ReportScope::kpi('Sales handled', ReportScope::sum($rows, 'sales')), ReportScope::kpi('Invoices', (int) ReportScope::sum($rows, 'invoices'), 'number'),
                ReportScope::kpi('Voids', (int) ReportScope::sum($rows, 'voids'), 'number', 'Same-session cancellations'),
                ReportScope::kpi('Net cash difference', ReportScope::sum($rows, 'cashDiff'), 'money', 'Over (+) / short (−) at close'),
            ],
            'columns' => [
                ReportScope::col('cashier', 'Cashier'), ReportScope::col('register', 'Register'), ReportScope::col('invoices', 'Invoices', 'number'),
                ReportScope::col('sales', 'Sales', 'money'), ReportScope::col('avg', 'Avg basket', 'money'), ReportScope::col('voids', 'Voids', 'number'),
                ReportScope::col('returns', 'Returns', 'number'), ReportScope::col('sessions', 'Sessions', 'number'), ReportScope::col('cashDiff', 'Cash difference', 'money'),
            ],
            'rows' => $rows,
            'totals' => collect(['invoices', 'sales', 'voids', 'returns', 'sessions', 'cashDiff'])->mapWithKeys(fn ($k) => [$k => ReportScope::sum($rows, $k)])->all(),
        ];
    }

    /** Completed sales per customer: in the period, or all time. */
    private function salesBy(ReportScope $s, bool $inPeriod)
    {
        return DB::table('sales')->where('status', 'completed')->whereIn('warehouse_id', $s->warehouseIds)
            ->when($inPeriod, fn ($q) => $q->whereBetween('sale_date', $s->range()))
            ->groupBy('customer_id')->selectRaw('customer_id, COUNT(*) AS n, SUM(grand_total) AS spent, MAX(sale_date) AS last')->get()->keyBy('customer_id');
    }

    private function ledger(string $type, string $expr)
    {
        return DB::table('party_ledger_entries')->where('party_type', $type)->groupBy('party_id')->selectRaw("party_id, SUM($expr) AS bal")->pluck('bal', 'party_id');
    }

    /** "type:id" => [type (sale|purchase|null), id, number, scope] for allocated documents. */
    private function documents($allocs): array
    {
        $ids = fn ($t) => $allocs->where('allocatable_type', $t)->pluck('allocatable_id')->unique()->all();
        $out = [];
        foreach (DB::table('sales')->whereIn('id', $ids('sale'))->get(['id', 'number']) as $x) {
            $out["sale:$x->id"] = ['type' => 'sale', 'id' => $x->id, 'number' => $x->number, 'scope' => ['sale', $x->id]];
        }
        foreach (DB::table('purchases')->whereIn('id', $ids('purchase'))->get(['id', 'number']) as $x) {
            $out["purchase:$x->id"] = ['type' => 'purchase', 'id' => $x->id, 'number' => $x->number, 'scope' => ['purchase', $x->id]];
        }
        foreach (DB::table('sale_returns')->whereIn('id', $ids('sale_return'))->get(['id', 'number', 'sale_id']) as $x) {
            $out["sale_return:$x->id"] = ['type' => 'sale', 'id' => $x->sale_id, 'number' => $x->number, 'scope' => ['sale', $x->sale_id]];
        }
        foreach (DB::table('purchase_returns')->whereIn('id', $ids('purchase_return'))->get(['id', 'number']) as $x) {
            $out["purchase_return:$x->id"] = ['type' => null, 'id' => null, 'number' => $x->number, 'scope' => null];
        }

        return $out;
    }
}
