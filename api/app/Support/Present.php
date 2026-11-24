<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Pricing;
use App\Services\StockLedger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Maps models to the camelCase shapes in pos/app/types/domain.ts.
 */
class Present
{
    public static function me(User $user): array
    {
        $user->loadMissing('roles', 'warehouses');
        $warehouses = $user->isAdmin()
            ? Warehouse::where('is_active', true)->orderBy('id')->get()
            : $user->warehouses->where('is_active', true)->values();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'role' => $user->roles->first()?->name ?? 'No role',
            'permissions' => $user->isAdmin() ? ['*'] : $user->getAllPermissions()->pluck('name')->values()->all(),
            'warehouses' => $warehouses->map(fn ($w) => ['id' => $w->id, 'name' => $w->name])->all(),
            'defaultWarehouseId' => $user->default_warehouse_id,
            'mustChangePassword' => $user->must_change_password,
        ];
    }

    /** @param  Collection<int, Product>  $products */
    public static function products(Collection $products, int $warehouseId): array
    {
        $products = new EloquentCollection($products->all());
        $products->loadMissing('units', 'generic', 'manufacturer', 'dosageForm', 'category');
        $unitIds = $products->flatMap(fn ($p) => $p->units->pluck('id'))->all();
        $prices = app(Pricing::class)->forUnits($unitIds);

        $batches = collect();
        foreach ($products as $p) {
            $batches[$p->id] = StockLedger::sellableQuery($warehouseId, $p->id)->get();
        }

        return $products->map(function (Product $p) use ($prices, $batches) {
            $rows = $batches[$p->id];

            return [
                'id' => $p->id,
                'sku' => $p->sku,
                'name' => $p->name,
                'strength' => $p->strength,
                'generic' => $p->generic?->name,
                'manufacturer' => $p->manufacturer?->name ?? '',
                'dosageForm' => $p->dosageForm?->name ?? '',
                'category' => $p->category?->name ?? '',
                'requiresPrescription' => $p->requires_prescription,
                'isActive' => $p->is_active,
                'reorderLevelBase' => $p->reorder_level_base,
                'units' => $p->units->filter(fn ($u) => $prices->has($u->id))->map(fn ($u) => [
                    'id' => $u->id, 'code' => $u->unit_code, 'label' => $u->label, 'factor' => $u->factor,
                    'price' => (float) $prices->get($u->id), 'isSaleDefault' => $u->is_sale_default,
                ])->values()->all(),
                'batches' => $rows->map(fn ($b) => [
                    'batchId' => $b->batch_id, 'batchNo' => $b->batch_no,
                    'expiryDate' => $b->expiry_date ? substr((string) $b->expiry_date, 0, 10) : '9999-12-31',
                    'qtyBase' => $b->qty_base, 'unitCost' => (float) $b->avg_unit_cost,
                ])->values()->all(),
                'stockBase' => (int) $rows->sum('qty_base'),
            ];
        })->values()->all();
    }

    public static function customers(Collection $customers): array
    {
        $ids = $customers->pluck('id');
        $ledger = DB::table('party_ledger_entries')->where('party_type', 'customer')->whereIn('party_id', $ids)
            ->groupBy('party_id')->selectRaw('party_id, SUM(debit) - SUM(credit) AS bal')->pluck('bal', 'party_id');
        $sales = DB::table('sales')->where('status', 'completed')->whereIn('customer_id', $ids)
            ->groupBy('customer_id')->selectRaw('customer_id, SUM(grand_total) AS spent, MAX(sale_date) AS last')->get()->keyBy('customer_id');
        $customers = new EloquentCollection($customers->all());
        $customers->loadMissing('group');

        return $customers->map(fn (Customer $c) => [
            'id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'phone' => $c->phone,
            'group' => $c->group?->name,
            'dueBalance' => max(0, round((float) ($ledger[$c->id] ?? 0), 2)),
            'points' => 0, // reward ledger arrives with the loyalty module
            'membership' => null,
            'isWalkIn' => $c->is_walk_in,
            'totalSpent' => round((float) ($sales[$c->id]->spent ?? 0), 2),
            'lastVisit' => isset($sales[$c->id]) ? substr((string) $sales[$c->id]->last, 0, 10) : null,
        ])->values()->all();
    }

    public static function saleSummary(Sale $s): array
    {
        return [
            'id' => $s->id, 'number' => $s->number, 'date' => $s->sale_date->toIso8601String(),
            'customer' => $s->customer?->name ?? '', 'channel' => $s->channel,
            'items' => (int) ($s->items_count ?? $s->items()->count()),
            'grandTotal' => (float) $s->grand_total, 'paidTotal' => (float) $s->paid_total, 'dueTotal' => (float) $s->due_total,
            'status' => $s->status, 'paymentStatus' => $s->payment_status, 'cashier' => $s->cashier?->name ?? '',
        ];
    }

    public static function saleReceipt(Sale $s): array
    {
        return [
            'id' => $s->id, 'number' => $s->number, 'date' => $s->sale_date->toIso8601String(),
            'customer' => $s->customer?->name,
            'subtotal' => (float) $s->subtotal,
            'discountTotal' => round((float) $s->line_discount_total + (float) $s->order_discount_total, 2),
            'grandTotal' => (float) $s->grand_total, 'paidTotal' => (float) $s->paid_total,
            'change' => (float) $s->change_total, 'due' => (float) $s->due_total, 'paymentStatus' => $s->payment_status,
            'lines' => $s->items->map(fn ($i) => [
                'description' => $i->description, 'qty' => (float) $i->qty, 'unitPrice' => (float) $i->unit_price,
                'discountPct' => (float) $i->discount_pct, 'lineTotal' => (float) $i->line_total,
                'batches' => $i->batches->map(fn ($b) => [
                    'batchNo' => $b->batch?->batch_no, 'expiryDate' => $b->batch?->expiry_date?->toDateString(), 'qtyBase' => $b->qty_base,
                ])->all(),
            ])->all(),
        ];
    }

    public static function paginated(LengthAwarePaginator $page, array $data): array
    {
        return [
            'data' => $data,
            'meta' => ['page' => $page->currentPage(), 'perPage' => $page->perPage(), 'total' => $page->total(), 'lastPage' => $page->lastPage()],
        ];
    }
}
