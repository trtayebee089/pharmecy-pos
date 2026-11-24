<?php

namespace App\Services;

use App\Models\AccountTransaction;
use App\Models\Customer;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Completes a POS sale authoritatively (docs/rebuild/rebuild-architecture.md §6):
 * client prices and totals are ignored; the server re-prices every line from
 * the price list, checks discount limits, allocates batches FEFO under lock
 * (expired stock is never sellable), writes stock movements, payments,
 * account and customer ledger entries, all in one transaction.
 *
 * Money is computed in integer paisa to avoid float drift.
 */
class SaleService
{
    public function __construct(
        private Pricing $pricing,
        private StockLedger $stock,
        private DocumentNumbers $numbers,
        private DiscountLimits $limits,
        private RegisterService $registers,
    ) {}

    /** @param  array<string, mixed>  $data  validated request */
    public function complete(User $user, array $data): Sale
    {
        $warehouse = Warehouse::where('is_active', true)->findOrFail($data['warehouseId']);
        if (! $user->canAccessWarehouse($warehouse->id)) {
            throw ValidationException::withMessages(['warehouseId' => 'You are not assigned to this warehouse.']);
        }
        $customer = Customer::where('is_active', true)->find($data['customerId']);
        if (! $customer) {
            throw ValidationException::withMessages(['customerId' => 'Customer not found.']);
        }

        $limit = $this->limits->for($user);
        // Customer group discount: applied to every catalogue line; a manual line discount only counts when it is larger.
        $groupPct = $customer->is_walk_in ? 0.0 : (float) ($customer->group?->discount_pct ?? 0);
        $lines = $this->priceLines($user, $data['lines'], $limit, $groupPct);

        $subtotal = array_sum(array_column($lines, 'gross'));
        $lineDiscounts = array_sum(array_column($lines, 'discount'));
        $orderDiscount = self::paisa($data['orderDiscount'] ?? 0);
        $afterLines = $subtotal - $lineDiscounts;
        if ($orderDiscount > $afterLines) {
            throw ValidationException::withMessages(['orderDiscount' => 'Order discount cannot exceed the bill.']);
        }
        if ($limit['order'] !== null && $afterLines > 0 && $orderDiscount * 100 > $afterLines * $limit['order']) {
            throw ValidationException::withMessages(['orderDiscount' => "Order discount is above your limit of {$limit['order']}%."]);
        }
        $grand = $afterLines - $orderDiscount;

        [$payments, $applied, $change] = $this->settlePayments($data['payments'] ?? [], $grand);
        $due = $grand - $applied;
        if ($due > 0 && $customer->is_walk_in) {
            throw ValidationException::withMessages(['payments' => 'Walk-in customers must pay in full. Select a registered customer to sell on credit.']);
        }
        if ($due > 0 && $customer->credit_limit !== null) {
            $balance = $this->customerBalancePaisa($customer->id);
            if ($balance + $due > self::paisa($customer->credit_limit)) {
                throw ValidationException::withMessages(['payments' => 'This sale would exceed the customer\'s credit limit.']);
            }
        }

        return DB::transaction(function () use ($user, $warehouse, $customer, $lines, $subtotal, $lineDiscounts, $orderDiscount, $grand, $payments, $applied, $change, $due, $groupPct) {
            $session = $this->registers->current($user, $warehouse->id);
            $sale = Sale::create([
                'ulid' => (string) Str::ulid(),
                'number' => $this->numbers->next('sale', $warehouse),
                'channel' => 'pos',
                'warehouse_id' => $warehouse->id,
                'customer_id' => $customer->id,
                'cashier_id' => $user->id,
                'session_id' => $session?->id,
                'status' => 'completed',
                'sale_date' => now(),
                'subtotal' => self::money($subtotal),
                'line_discount_total' => self::money($lineDiscounts),
                'order_discount_total' => self::money($orderDiscount),
                'group_discount_pct' => $groupPct,
                'grand_total' => self::money($grand),
                'paid_total' => self::money($applied),
                'change_total' => self::money($change),
                'due_total' => self::money($due),
                'payment_status' => $due <= 0 ? 'paid' : ($applied > 0 ? 'partial' : 'unpaid'),
            ]);

            $costTotal = 0.0;
            foreach ($lines as $i => $line) {
                $item = SaleItem::create([
                    'sale_id' => $sale->id,
                    'line_type' => $line['product'] ? 'product' : 'misc',
                    'product_id' => $line['product']?->id,
                    'product_unit_id' => $line['unit']?->id,
                    'description' => $line['description'],
                    'misc_code' => $line['miscCode'],
                    'qty' => $line['qty'],
                    'factor' => $line['factor'],
                    'qty_base' => $line['qtyBase'],
                    'unit_price' => $line['unitPrice'],
                    'discount_pct' => $line['discountPct'],
                    'discount_total' => self::money($line['discount']),
                    'line_total' => self::money($line['gross'] - $line['discount']),
                ]);
                if (! $line['product']) {
                    continue;
                }

                $lineCost = 0.0;
                $parts = $this->stock->allocate($warehouse->id, $line['product']->id, $line['qtyBase'], "lines.$i.qty", $line['batchId']);
                foreach ($parts as $part) {
                    $balance = $part['balance'];
                    $unitCost = (float) $balance->avg_unit_cost;
                    SaleItemBatch::create([
                        'sale_item_id' => $item->id, 'batch_id' => $balance->batch_id,
                        'qty_base' => $part['qty'], 'unit_cost' => $unitCost,
                    ]);
                    $this->stock->move([
                        'warehouse_id' => $warehouse->id, 'product_id' => $line['product']->id, 'batch_id' => $balance->batch_id,
                        'qty_base' => -$part['qty'], 'type' => 'sale', 'unit_cost' => $unitCost,
                        'source_type' => 'sale', 'source_id' => $sale->id, 'source_line_id' => $item->id, 'created_by' => $user->id,
                    ]);
                    $lineCost += $part['qty'] * $unitCost;
                }
                $item->update(['cost_total' => round($lineCost, 2)]);
                $costTotal += $lineCost;
            }
            $sale->update(['cost_total' => round($costTotal, 2)]);

            $this->postPayments($sale, $user, $customer, $payments);

            PartyLedgerEntry::create([
                'party_type' => 'customer', 'party_id' => $customer->id, 'entry_date' => now()->toDateString(),
                'type' => 'invoice', 'debit' => self::money($grand), 'credit' => 0,
                'source_type' => 'sale', 'source_id' => $sale->id, 'note' => $sale->number, 'created_by' => $user->id,
            ]);
            if ($applied > 0) {
                PartyLedgerEntry::create([
                    'party_type' => 'customer', 'party_id' => $customer->id, 'entry_date' => now()->toDateString(),
                    'type' => 'payment', 'debit' => 0, 'credit' => self::money($applied),
                    'source_type' => 'sale', 'source_id' => $sale->id, 'note' => $sale->number, 'created_by' => $user->id,
                ]);
            }

            return $sale->fresh(['items.batches.batch', 'customer']);
        });
    }

    /**
     * Re-prices lines from the price list and checks per-line rules.
     *
     * Line discount = the larger of the customer's group discount and the
     * cashier's manual discount. Only a manual discount above the group
     * discount is checked against the role limit: the group discount is set by
     * a manager and applies in full. Custom items take the manual discount only.
     */
    private function priceLines(User $user, array $input, array $limit, float $groupPct = 0.0): array
    {
        $unitIds = array_values(array_filter(array_map(fn ($l) => (int) ($l['unitId'] ?? 0), $input)));
        $units = ProductUnit::with('product')->whereIn('id', $unitIds)->get()->keyBy('id');
        $prices = $this->pricing->forUnits($unitIds);
        $out = [];

        foreach ($input as $i => $l) {
            $manual = (float) ($l['discount'] ?? 0);
            $floor = ! empty($l['custom']) ? 0.0 : $groupPct;
            if ($limit['line'] !== null && $manual > $floor && $manual > $limit['line']) {
                throw ValidationException::withMessages(["lines.$i.discount" => "Line discount is above your limit of {$limit['line']}%."]);
            }
            $pct = max($manual, $floor);
            $qty = (float) $l['qty'];

            if (! empty($l['custom'])) {
                if (! $user->can('pos.custom_item')) {
                    throw ValidationException::withMessages(["lines.$i" => 'You are not allowed to sell custom items.']);
                }
                $priceP = self::paisa($l['custom']['price']);
                $gross = (int) round($priceP * $qty);
                $out[] = [
                    'product' => null, 'unit' => null, 'description' => $l['custom']['name'], 'miscCode' => $l['custom']['code'] ?? null,
                    'qty' => $qty, 'factor' => 1, 'qtyBase' => 0, 'unitPrice' => self::money($priceP), 'discountPct' => $pct,
                    'gross' => $gross, 'discount' => (int) round($gross * $pct / 100), 'batchId' => null,
                ];

                continue;
            }

            $unit = $units->get((int) $l['unitId']);
            if (! $unit || $unit->product_id !== (int) $l['productId']) {
                throw ValidationException::withMessages(["lines.$i.unitId" => 'Unit does not belong to the product.']);
            }
            /** @var Product $product */
            $product = $unit->product;
            if (! $product || ! $product->is_active) {
                throw ValidationException::withMessages(["lines.$i.productId" => 'Product is not available for sale.']);
            }
            if (! $prices->has($unit->id)) {
                throw ValidationException::withMessages(["lines.$i.unitId" => "{$product->name} has no selling price for {$unit->label}."]);
            }
            $qtyBase = $qty * $unit->factor;
            if (abs($qtyBase - round($qtyBase)) > 1e-9) {
                throw ValidationException::withMessages(["lines.$i.qty" => 'Quantity must be a whole number of pieces.']);
            }
            if (! empty($l['batchId']) && ! $user->can('pos.override_batch') && ! $this->limits->canOverrideBatch($user)) {
                // Not allowed to pick a batch: fall back to FEFO silently.
                $l['batchId'] = null;
            }

            $priceP = self::paisa($prices->get($unit->id));
            $gross = (int) round($priceP * $qty);
            $out[] = [
                'product' => $product, 'unit' => $unit, 'description' => trim($product->name.' '.$product->strength), 'miscCode' => null,
                'qty' => $qty, 'factor' => $unit->factor, 'qtyBase' => (int) round($qtyBase),
                'unitPrice' => $prices->get($unit->id), 'discountPct' => $pct,
                'gross' => $gross, 'discount' => (int) round($gross * $pct / 100), 'batchId' => $l['batchId'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * Applies tendered payments to the bill. Customer credit is not money taken,
     * it leaves the amount as due. Change is only given back from cash.
     *
     * @return array{0: array<int, array{method: PaymentMethod, amount: int, reference: ?string}>, 1: int, 2: int}
     */
    private function settlePayments(array $input, int $grand): array
    {
        $methods = PaymentMethod::where('is_active', true)->get()->keyBy('code');
        $payments = [];
        $tendered = 0;
        $cash = 0;
        foreach ($input as $i => $p) {
            if ($p['method'] === 'customer_credit') {
                continue;
            }
            $method = $methods->get($p['method']);
            if (! $method) {
                throw ValidationException::withMessages(["payments.$i.method" => 'Payment method is not enabled.']);
            }
            if ($method->requires_reference && empty($p['reference'])) {
                throw ValidationException::withMessages(["payments.$i.reference" => "{$method->name} needs a transaction reference."]);
            }
            $amount = self::paisa($p['amount']);
            if ($amount <= 0) {
                continue;
            }
            $tendered += $amount;
            if ($method->code === 'cash') {
                $cash += $amount;
            }
            $payments[] = ['method' => $method, 'amount' => $amount, 'reference' => $p['reference'] ?? null];
        }

        $change = max(0, $tendered - $grand);
        if ($change > $cash) {
            throw ValidationException::withMessages(['payments' => 'Non-cash payments cannot exceed the bill.']);
        }
        // Take the change out of the cash lines so recorded payments equal money kept.
        $left = $change;
        foreach ($payments as &$p) {
            if ($left > 0 && $p['method']->code === 'cash') {
                $cut = min($left, $p['amount']);
                $p['amount'] -= $cut;
                $left -= $cut;
            }
        }
        unset($p);
        $payments = array_values(array_filter($payments, fn ($p) => $p['amount'] > 0));

        return [$payments, $tendered - $change, $change];
    }

    private function postPayments(Sale $sale, User $user, Customer $customer, array $payments): void
    {
        $warehouse = Warehouse::find($sale->warehouse_id);
        foreach ($payments as $p) {
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(),
                'number' => $this->numbers->next('payment', $warehouse),
                'direction' => 'in', 'party_type' => 'customer', 'party_id' => $customer->id,
                'payment_method_id' => $p['method']->id, 'account_id' => $p['method']->account_id,
                'amount' => self::money($p['amount']), 'reference' => $p['reference'], 'paid_at' => now(),
                'session_id' => $sale->session_id,
                'created_by' => $user->id,
            ]);
            PaymentAllocation::create([
                'payment_id' => $payment->id, 'allocatable_type' => 'sale', 'allocatable_id' => $sale->id, 'amount' => self::money($p['amount']),
            ]);
            if ($p['method']->account_id) {
                AccountTransaction::create([
                    'account_id' => $p['method']->account_id, 'txn_date' => now()->toDateString(), 'direction' => 'in',
                    'amount' => self::money($p['amount']), 'source_type' => 'payment', 'source_id' => $payment->id, 'note' => $sale->number,
                ]);
            }
        }
    }

    private function customerBalancePaisa(int $customerId): int
    {
        $row = PartyLedgerEntry::where(['party_type' => 'customer', 'party_id' => $customerId])
            ->selectRaw('COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) AS bal')->first();

        return self::paisa($row?->bal ?? 0);
    }

    public static function paisa(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function money(int $paisa): string
    {
        return number_format($paisa / 100, 2, '.', '');
    }
}
