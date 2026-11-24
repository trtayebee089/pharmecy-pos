<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\HeldSale;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Present;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Carts parked on the server (F8 at the counter) so any counter in the same
 * warehouse can resume them.
 *
 * - Holding reserves no stock and posts no money. Lines are stored as the
 *   cashier entered them (product, unit, qty, discount, batch override,
 *   custom item); prices are never stored.
 * - Totals shown in the list are at current prices. Resuming returns each
 *   line with the live product (current prices, sellable FEFO batches); lines
 *   whose product or unit no longer exists or is inactive are dropped and
 *   reported. Completing the sale re-checks everything (SaleService).
 * - A held sale is resumed or discarded once, under a row lock: a second
 *   counter gets a clear error instead of a duplicate cart.
 */
class HeldSales
{
    public function __construct(private DocumentNumbers $numbers, private RegisterService $registers, private Pricing $pricing) {}

    public function hold(User $user, array $data): HeldSale
    {
        $warehouse = Warehouse::where('is_active', true)->find($data['warehouseId']);
        if (! $warehouse || ! $user->canAccessWarehouse($warehouse->id)) {
            throw ValidationException::withMessages(['warehouseId' => 'You are not assigned to this warehouse.']);
        }
        if (! empty($data['customerId']) && ! Customer::whereKey($data['customerId'])->exists()) {
            throw ValidationException::withMessages(['customerId' => 'Customer not found.']);
        }
        $lines = [];
        foreach ($data['lines'] as $i => $l) {
            if (! empty($l['custom'])) {
                $lines[] = ['productId' => 0, 'unitId' => 0, 'qty' => (float) $l['qty'], 'discountPct' => (float) ($l['discount'] ?? 0),
                    'custom' => ['name' => $l['custom']['name'], 'code' => (string) ($l['custom']['code'] ?? ''), 'price' => (float) $l['custom']['price']]];

                continue;
            }
            $unit = ProductUnit::find($l['unitId']);
            if (! $unit || $unit->product_id !== (int) $l['productId']) {
                throw ValidationException::withMessages(["lines.$i.unitId" => 'Unit does not belong to the product.']);
            }
            $lines[] = ['productId' => (int) $l['productId'], 'unitId' => (int) $l['unitId'], 'qty' => (float) $l['qty'],
                'discountPct' => (float) ($l['discount'] ?? 0), 'batchId' => ! empty($l['batchId']) ? (int) $l['batchId'] : null];
        }

        return DB::transaction(function () use ($user, $warehouse, $data, $lines) {
            $held = HeldSale::create([
                'number' => $this->numbers->next('held_sale', $warehouse), 'warehouse_id' => $warehouse->id,
                'customer_id' => $data['customerId'] ?? null, 'session_id' => $this->registers->current($user, $warehouse->id)?->id,
                'order_discount' => $data['orderDiscount'] ?? 0, 'lines' => $lines, 'note' => $data['note'] ?? null,
                'status' => 'held', 'held_by' => $user->id,
            ]);
            AuditLog::record($user->id, 'held_sale.held', 'held_sale', $held->id, ['number' => $held->number]);

            return $held;
        });
    }

    /** HeldSaleSummary (pos/app/types/orders.ts), total at current prices. */
    public function summary(HeldSale $h): array
    {
        $h->loadMissing('customer', 'holder', 'session.register');
        $lines = collect($h->lines);
        $prices = $this->pricing->forUnits($lines->pluck('unitId')->filter()->all());
        $total = $lines->sum(function ($l) use ($prices) {
            $price = ! empty($l['custom']) ? (float) $l['custom']['price'] : (float) ($prices[$l['unitId']] ?? 0);

            return (int) round($price * 100 * $l['qty'] * (1 - ($l['discountPct'] ?? 0) / 100));
        });

        return [
            'id' => $h->number, 'heldAt' => $h->created_at->toIso8601String(), 'heldBy' => $h->holder?->name ?? '', 'heldById' => $h->held_by,
            'register' => $h->session?->register?->name ?? '—', 'customer' => $h->customer?->is_walk_in ? null : $h->customer?->name,
            'items' => $lines->count(), 'total' => max(0, $total - SaleService::paisa($h->order_discount)) / 100, 'source' => 'server',
        ];
    }

    /** Takes the held sale back to a counter: returns the cart and closes the hold. */
    public function resume(User $user, HeldSale $held): array
    {
        return DB::transaction(function () use ($user, $held) {
            $held = $this->open($user, $held);
            $held->update(['status' => 'resumed', 'closed_by' => $user->id, 'closed_at' => now()]);
            AuditLog::record($user->id, 'held_sale.resumed', 'held_sale', $held->id, null);

            $products = Product::where('is_active', true)->whereIn('id', collect($held->lines)->pluck('productId')->filter())->get();
            $presented = collect(Present::products($products, $held->warehouse_id))->keyBy('id');
            $lines = [];
            $skipped = [];
            foreach ($held->lines as $l) {
                if (! empty($l['custom'])) {
                    $lines[] = ['product' => null, 'unitId' => 0, 'qty' => $l['qty'], 'discountPct' => $l['discountPct'], 'custom' => $l['custom']];

                    continue;
                }
                $p = $presented->get($l['productId']);
                if (! $p || ! collect($p['units'])->contains('id', $l['unitId'])) {
                    $skipped[] = (string) (Product::withTrashed()->whereKey($l['productId'])->value('name') ?? 'Product #'.$l['productId']);

                    continue;
                }
                $lines[] = ['product' => $p, 'unitId' => $l['unitId'], 'qty' => $l['qty'], 'discountPct' => $l['discountPct'], 'batchId' => $l['batchId'] ?? null];
            }
            $customer = $held->customer_id ? Customer::find($held->customer_id) : null;

            return [
                'id' => $held->number, 'warehouseId' => $held->warehouse_id,
                'customer' => $customer && ! $customer->is_walk_in ? Present::customers(collect([$customer]))[0] : null,
                'orderDiscount' => (float) $held->order_discount, 'lines' => $lines, 'skipped' => $skipped,
            ];
        });
    }

    public function discard(User $user, HeldSale $held): void
    {
        DB::transaction(function () use ($user, $held) {
            $held = $this->open($user, $held);
            $held->update(['status' => 'discarded', 'closed_by' => $user->id, 'closed_at' => now()]);
            AuditLog::record($user->id, 'held_sale.discarded', 'held_sale', $held->id, null);
        });
    }

    private function open(User $user, HeldSale $held): HeldSale
    {
        $held = HeldSale::whereKey($held->id)->lockForUpdate()->firstOrFail();
        if (! $user->canAccessWarehouse($held->warehouse_id)) {
            abort(404);
        }
        if ($held->status !== 'held') {
            $who = $held->closer?->name ?? 'another user';
            throw ValidationException::withMessages(['held' => "{$held->number} was already {$held->status} by {$who} at {$held->closed_at?->format('H:i')}."]);
        }

        return $held;
    }
}
