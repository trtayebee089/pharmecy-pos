<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Services\AppSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Live figures for the dashboard (DashboardSummary in pos/app/types/domain.ts). */
class DashboardController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $data = $request->validate(['warehouse_id' => ['required', 'integer'], 'days' => ['nullable', 'integer', 'min:1', 'max:90']]);
        $wh = (int) $data['warehouse_id'];
        if (! $request->user()->canAccessWarehouse($wh)) {
            throw ValidationException::withMessages(['warehouse_id' => 'You are not assigned to this warehouse.']);
        }
        $days = (int) ($data['days'] ?? 14);
        $today = Carbon::today();
        $sales = fn () => DB::table('sales')->where('warehouse_id', $wh)->where('status', 'completed');

        $day = fn (Carbon $d) => $sales()->whereBetween('sale_date', [$d->copy()->startOfDay(), $d->copy()->endOfDay()])
            ->selectRaw('COUNT(*) AS n, COALESCE(SUM(grand_total),0) AS total, COALESCE(SUM(grand_total - cost_total),0) AS profit')->first();
        $t = $day($today);
        $y = $day($today->copy()->subDay());
        $delta = fn ($a, $b) => (float) $b > 0 ? round(((float) $a - (float) $b) / (float) $b * 100, 1) : 0.0;

        $series = $sales()->where('sale_date', '>=', $today->copy()->subDays($days - 1))
            ->selectRaw('DATE(sale_date) AS d, SUM(grand_total) AS v')->groupBy('d')->pluck('v', 'd');
        $salesSeries = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = $today->copy()->subDays($i);
            $salesSeries[] = ['label' => $d->format('d M'), 'value' => round((float) ($series[$d->toDateString()] ?? 0), 2)];
        }

        $since = $today->copy()->subDays($days - 1);
        $paymentMix = DB::table('payments')->join('payment_methods', 'payment_methods.id', '=', 'payments.payment_method_id')
            ->join('payment_allocations', 'payment_allocations.payment_id', '=', 'payments.id')
            ->join('sales', fn ($j) => $j->on('sales.id', '=', 'payment_allocations.allocatable_id')->where('payment_allocations.allocatable_type', 'sale'))
            ->where('sales.warehouse_id', $wh)->where('payments.direction', 'in')->where('payments.paid_at', '>=', $since)
            ->groupBy('payment_methods.name')->selectRaw('payment_methods.name AS method, SUM(payments.amount) AS value')
            ->get()->map(fn ($r) => ['method' => $r->method, 'value' => round((float) $r->value, 2)])->all();

        $topProducts = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.warehouse_id', $wh)->where('sales.status', 'completed')->where('sales.sale_date', '>=', $since)
            ->where('sale_items.line_type', 'product')
            ->groupBy('sale_items.description')->selectRaw('sale_items.description AS name, SUM(sale_items.qty_base) AS qty, SUM(sale_items.line_total) AS revenue')
            ->orderByDesc('revenue')->limit(5)->get()
            ->map(fn ($r) => ['name' => $r->name, 'qty' => (int) $r->qty, 'revenue' => round((float) $r->revenue, 2)])->all();

        $ledger = fn (string $type) => (float) DB::query()->fromSub(
            DB::table('party_ledger_entries')->where('party_type', $type)->groupBy('party_id')
                ->selectRaw($type === 'customer' ? 'SUM(debit) - SUM(credit) AS bal' : 'SUM(credit) - SUM(debit) AS bal'), 'b')
            ->where('bal', '>', 0)->sum('bal');

        $cashInHand = (float) DB::table('accounts')->where('type', 'cash')->sum('opening_balance')
            + (float) DB::table('account_transactions')->join('accounts', 'accounts.id', '=', 'account_transactions.account_id')
                ->where('accounts.type', 'cash')->selectRaw("COALESCE(SUM(CASE WHEN direction = 'in' THEN amount ELSE -amount END),0) AS v")->value('v');

        $stock = DB::table('stock_balances')->join('batches', 'batches.id', '=', 'stock_balances.batch_id')
            ->where('stock_balances.warehouse_id', $wh)->where('stock_balances.qty_base', '>', 0);
        $todayStr = $today->toDateString();
        $nearExpiry = (clone $stock)->whereBetween('batches.expiry_date', [$todayStr, $today->copy()->addDays(app(AppSettings::class)->general('nearExpiryDays'))->toDateString()])->count();
        $expired = (clone $stock)->where('batches.expiry_date', '<', $todayStr)->count();
        $lowStock = DB::table('products')->where('products.is_active', true)->whereNull('products.deleted_at')->where('reorder_level_base', '>', 0)
            ->whereRaw('reorder_level_base >= (SELECT COALESCE(SUM(sb.qty_base),0) FROM stock_balances sb JOIN batches b ON b.id = sb.batch_id WHERE sb.product_id = products.id AND sb.warehouse_id = ? AND (b.expiry_date IS NULL OR b.expiry_date >= ?))', [$wh, $todayStr])
            ->count();

        return response()->json([
            'todaySales' => round((float) $t->total, 2),
            'todaySalesDelta' => $delta($t->total, $y->total),
            'todayInvoices' => (int) $t->n,
            'todayProfit' => round((float) $t->profit, 2),
            'todayProfitDelta' => $delta($t->profit, $y->profit),
            'cashInHand' => round($cashInHand, 2),
            'customerDue' => round($ledger('customer'), 2),
            'supplierDue' => round($ledger('supplier'), 2),
            'lowStockCount' => $lowStock,
            'nearExpiryCount' => $nearExpiry,
            'expiredCount' => $expired,
            'salesSeries' => $salesSeries,
            'paymentMix' => $paymentMix,
            'topProducts' => $topProducts,
        ]);
    }
}
