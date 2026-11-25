<?php

namespace App\Services;

use App\Http\Controllers\Pos\CustomerController;
use App\Models\Account;
use App\Models\AccountTransaction;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\OnlineOrderHistory;
use App\Models\OnlineOrderLine;
use App\Models\OnlinePayment;
use App\Models\PartyLedgerEntry;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentMethod;
use App\Models\ProductUnit;
use App\Models\Rider;
use App\Models\RiderCashEntry;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleItemBatch;
use App\Models\Setting;
use App\Models\ShippingZone;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Online orders (W17), owner decisions 25 Nov 2026:
 *
 * - Orders are placed by staff (phone/WhatsApp) for now; the website and app will
 *   place them through the same `place()` once the customer channel exists.
 *   Prices are fixed at placement from the retail list (server-side), shipping from
 *   the active zone (free at or above its minimum).
 * - Stock leaves once, at **confirm**: a sale (channel = order channel) is posted
 *   with FEFO batch allocation from the **online branch** (setting
 *   `online_orders.warehouseId`). Cancelling before delivery voids that sale and
 *   puts the stock back into the same batches.
 * - Cash on delivery: delivered = a cash payment on the sale with no account yet,
 *   held by the rider (`rider_cash_entries` collect). A **handover** moves the cash
 *   into Cash in hand. Held = collected − handed over.
 *
 * Status flow: pending → confirmed → packed → in_transit → delivered; any open
 * order can be cancelled with a reason. in_transit needs an active rider.
 */
class OnlineOrders
{
    public const ONLINE_METHODS = ['sslcommerz', 'bkash'];

    public const NEXT = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['packed', 'cancelled'],
        'packed' => ['in_transit', 'cancelled'],
        'in_transit' => ['delivered', 'cancelled'],
    ];

    public function __construct(
        private Pricing $pricing,
        private StockLedger $stock,
        private DocumentNumbers $numbers,
        private SaleReversalService $reversals,
        private RewardLedger $rewards,
        private LoyaltySettings $loyalty,
    ) {}

    /**
     * Points a signed-in customer may redeem on an online order, with the same
     * rules as the counter: reward points switched on, whole points, the
     * minimum balance and bill, at most maxRedeemPercent of the goods after
     * discount, and never more than the wallet holds.
     *
     * @return array{0: int, 1: int} [points, value in paisa]
     */
    public function pointsToRedeem(Customer $customer, int $points, int $goodsPaisa): array
    {
        if ($points <= 0) {
            return [0, 0];
        }
        $s = $this->loyalty->all();
        $fail = fn (string $m) => throw ValidationException::withMessages(['reward_points' => $m]);
        if (! $s['active']) {
            $fail('Reward points are switched off.');
        }
        $valuePaisa = SaleService::paisa($s['pointValue']);
        $balance = $this->rewards->balance($customer->id);
        if ($balance < (int) $s['minRedeemPoints']) {
            $fail("You need at least {$s['minRedeemPoints']} points to redeem (you have {$balance}).");
        }
        if ($goodsPaisa < SaleService::paisa($s['minRedeemSaleAmount'])) {
            $fail('Points can be used on orders of at least ৳'.SaleService::money(SaleService::paisa($s['minRedeemSaleAmount'])).'.');
        }
        if ($points > $balance) {
            $fail("You have only {$balance} points.");
        }
        $maxPaisa = (int) floor($goodsPaisa * (float) $s['maxRedeemPercent'] / 100);
        if ($points * $valuePaisa > $maxPaisa) {
            $fail('You can pay at most '.(float) $s['maxRedeemPercent'].'% of this order with points ('.intdiv($maxPaisa, max(1, $valuePaisa)).' points).');
        }

        return [$points, $points * $valuePaisa];
    }

    // ---------------------------------------------------------------- settings

    public function warehouseId(): int
    {
        $stored = Setting::where(['scope' => 'global', 'scope_id' => 0, 'key' => 'online_orders'])->value('value');
        $stored = is_string($stored) ? json_decode($stored, true) : (array) $stored;
        $id = (int) ($stored['warehouseId'] ?? 0);

        return Warehouse::where('is_active', true)->whereKey($id)->exists()
            ? $id
            : (int) Warehouse::where('is_active', true)->orderBy('id')->value('id');
    }

    public function setWarehouse(int $warehouseId): void
    {
        if (! Warehouse::where('is_active', true)->whereKey($warehouseId)->exists()) {
            throw ValidationException::withMessages(['warehouseId' => 'Choose an active branch.']);
        }
        Setting::updateOrCreate(['scope' => 'global', 'scope_id' => 0, 'key' => 'online_orders'], ['value' => ['warehouseId' => $warehouseId]]);
    }

    // ---------------------------------------------------------------- placing

    /** @param  array<string, mixed>  $data  validated */
    public function place(?User $user, array $data): OnlineOrder
    {
        $warehouse = Warehouse::findOrFail($this->warehouseId());
        $zone = ShippingZone::where('is_active', true)->find($data['zoneId']);
        if (! $zone) {
            throw ValidationException::withMessages(['zoneId' => 'Choose an active delivery zone.']);
        }
        $customer = $this->customer($data);

        $units = ProductUnit::with('product')->whereIn('id', array_column($data['lines'], 'unitId'))->get()->keyBy('id');
        $prices = $this->pricing->forUnits($units->keys()->all());
        $lines = [];
        $need = [];
        foreach ($data['lines'] as $i => $l) {
            $unit = $units->get((int) $l['unitId']);
            if (! $unit || $unit->product_id !== (int) $l['productId'] || ! $unit->product?->is_active) {
                throw ValidationException::withMessages(["lines.$i.unitId" => 'This product or unit is not available.']);
            }
            if (! $prices->has($unit->id)) {
                throw ValidationException::withMessages(["lines.$i.unitId" => "{$unit->product->name} has no selling price for {$unit->label}."]);
            }
            $qtyBase = (float) $l['qty'] * $unit->factor;
            if (abs($qtyBase - round($qtyBase)) > 1e-9) {
                throw ValidationException::withMessages(["lines.$i.qty" => 'Quantity must be a whole number of pieces.']);
            }
            $priceP = SaleService::paisa($prices->get($unit->id));
            $lines[] = ['unit' => $unit, 'qty' => (float) $l['qty'], 'qtyBase' => (int) round($qtyBase), 'price' => $prices->get($unit->id), 'total' => (int) round($priceP * (float) $l['qty'])];
            $need[$unit->product_id] = ($need[$unit->product_id] ?? 0) + (int) round($qtyBase);
        }
        foreach (app(AppSettings::class)->general('allowNegativeStock') ? [] : $need as $productId => $qty) {
            $available = (int) StockLedger::sellableQuery($warehouse->id, $productId)->sum('stock_balances.qty_base');
            if ($available < $qty) {
                $name = $units->firstWhere('product_id', $productId)->product->name;
                throw ValidationException::withMessages(['lines' => "{$name}: only {$available} pieces in stock at {$warehouse->name}."]);
            }
        }

        // Customer channel, signed-in customer only (owner decision 26 Nov 2026): the customer discount
        // (group %, and membership % per the reward settings) on every line, and points redeemed.
        $loyalty = ! empty($data['loyalty']) && ! $customer->is_walk_in;
        $pct = $loyalty ? (float) app(SaleService::class)->customerDiscount($customer)[0] : 0.0;
        foreach ($lines as &$l) {
            $l['net'] = (int) round($l['total'] * (1 - $pct / 100));
        }
        unset($l);
        $subtotal = array_sum(array_column($lines, 'total'));
        $afterDiscount = array_sum(array_column($lines, 'net'));
        $discount = $subtotal - $afterDiscount;
        $shipping = $zone->free_shipping_min !== null && $afterDiscount >= SaleService::paisa($zone->free_shipping_min) ? 0 : SaleService::paisa($zone->rate);
        [$points, $pointsPaisa] = $loyalty ? $this->pointsToRedeem($customer, (int) ($data['redeemPoints'] ?? 0), $afterDiscount) : [0, 0];
        // Paid through SSLCommerz or bKash before staff confirm it (see OnlinePayments); otherwise cash on delivery.
        $online = in_array($data['paymentMethod'] ?? 'cod', self::ONLINE_METHODS, true);

        return DB::transaction(function () use ($user, $data, $warehouse, $zone, $customer, $lines, $subtotal, $shipping, $pct, $discount, $afterDiscount, $points, $pointsPaisa, $online) {
            $order = OnlineOrder::create([
                'number' => $this->numbers->next('online_order', $warehouse),
                'tracking_code' => $this->trackingCode(),
                'legacy_ref' => $data['legacyRef'] ?? null,
                'channel' => $data['channel'] ?? 'phone',
                'customer_id' => $customer->id, 'warehouse_id' => $warehouse->id,
                'phone' => $customer->phone ?? $data['phone'], 'address' => $data['address'],
                'zone_id' => $zone->id, 'division' => $zone->division, 'district' => $zone->district, 'thana' => $zone->thana,
                'status' => 'pending', 'subtotal' => SaleService::money($subtotal),
                'payment_method' => $online ? $data['paymentMethod'] : 'cod', 'payment_status' => $online ? 'pending' : 'cod',
                'discount_pct' => $pct, 'discount_total' => SaleService::money($discount), 'shipping' => SaleService::money($shipping),
                'grand_total' => SaleService::money($afterDiscount + $shipping), // before points; COD = grand − points value
                'points_redeemed' => $points, 'points_value' => SaleService::money($pointsPaisa),
                'client_key' => $data['clientKey'] ?? null, 'placed_at' => now(),
                'note' => $data['note'] ?? null, 'created_by' => $user?->id,
            ]);
            foreach ($lines as $l) {
                OnlineOrderLine::create([
                    'online_order_id' => $order->id, 'product_id' => $l['unit']->product_id, 'product_unit_id' => $l['unit']->id,
                    'description' => trim($l['unit']->product->name.' '.$l['unit']->product->strength), 'qty' => $l['qty'],
                    'qty_base' => $l['qtyBase'], 'unit_price' => $l['price'], 'line_total' => SaleService::money($l['net']),
                ]);
            }
            // Points leave the wallet now so they cannot be spent twice; a cancel gives them back.
            if ($points > 0) {
                $this->rewards->debit($customer->id, 'redeem', $points, ['online_order', $order->id], $order->number, $user?->id, (float) SaleService::money($pointsPaisa));
            }
            $this->history($order, 'pending', 'Order placed by '.($order->channel === 'phone' ? 'phone' : $order->channel).($online ? ', waiting for payment by '.$data['paymentMethod'] : ''), $user);

            return $order;
        });
    }

    // ---------------------------------------------------------------- status

    /** `$user` is null only when a courier webhook reports in_transit / delivered. */
    public function transition(?User $user, OnlineOrder $order, string $to, ?string $note): OnlineOrder
    {
        return DB::transaction(function () use ($user, $order, $to, $note) {
            $order = OnlineOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $user && ! in_array($to, ['in_transit', 'delivered'], true)) {
                throw ValidationException::withMessages(['status' => 'Staff must make this change.']);
            }
            if (! in_array($to, self::NEXT[$order->status] ?? [], true)) {
                throw ValidationException::withMessages(['status' => in_array($order->status, ['delivered', 'cancelled'], true)
                    ? 'This order is already closed.'
                    : "An order that is {$order->status} cannot be marked {$to}."]);
            }
            if ($to === 'confirmed' && $order->payment_status === 'pending') {
                throw ValidationException::withMessages(['status' => 'Waiting for the online payment. Confirm it once paid, or ask the customer to switch to cash on delivery.']);
            }

            match ($to) {
                'confirmed' => $this->confirm($user, $order),
                'in_transit' => $order->courier ? null : $this->assertRider($order),
                'delivered' => $this->deliver($user, $order),
                'cancelled' => $this->cancel($user, $order, $note),
                default => null,
            };
            $order->update(['status' => $to]);
            $this->history($order, $to, $note, $user);
            // Customer SMS (when switched on), after the response so a slow gateway never holds the order.
            $notifier = app(OrderNotifier::class);
            if ($notifier->wants($to)) {
                $id = $order->id;
                defer(fn () => $notifier->statusChanged(OnlineOrder::findOrFail($id)));
            }

            return $order->fresh();
        });
    }

    public function assignRider(User $user, OnlineOrder $order, int $riderId): OnlineOrder
    {
        if (in_array($order->status, ['delivered', 'cancelled'], true)) {
            throw ValidationException::withMessages(['rider_id' => 'This order is already closed.']);
        }
        $rider = Rider::where('status', 'active')->find($riderId);
        if (! $rider) {
            throw ValidationException::withMessages(['rider_id' => 'Choose an active rider.']);
        }
        $order->update(['rider_id' => $rider->id]);
        $this->history($order, $order->status, 'Rider: '.$rider->name, $user);

        return $order->fresh();
    }

    /** Cash still with the rider (collected on delivery minus handed over). */
    /** Cash the rider collects: grand total less reward points and anything paid online. */
    public static function codAmount(OnlineOrder $o): float
    {
        return SaleService::money(max(0, SaleService::paisa($o->grand_total) - SaleService::paisa($o->points_value) - SaleService::paisa($o->paid_online))) + 0;
    }

    /** What an online payment must cover: the total less reward points. */
    public static function payable(OnlineOrder $o): float
    {
        return SaleService::money(max(0, SaleService::paisa($o->grand_total) - SaleService::paisa($o->points_value))) + 0;
    }

    public static function cashHeld(int $riderId): float
    {
        $rows = RiderCashEntry::where('rider_id', $riderId)->groupBy('type')->pluck(DB::raw('SUM(amount)'), 'type');

        return round((float) ($rows['collect'] ?? 0) - (float) ($rows['handover'] ?? 0), 2);
    }

    public function handover(User $user, Rider $rider, float $amount, ?string $note): RiderCashEntry
    {
        return DB::transaction(function () use ($user, $rider, $amount, $note) {
            Rider::whereKey($rider->id)->lockForUpdate()->first();
            $held = self::cashHeld($rider->id);
            if ($amount <= 0 || SaleService::paisa($amount) > SaleService::paisa($held)) {
                throw ValidationException::withMessages(['amount' => 'The rider holds '.SaleService::money(SaleService::paisa($held)).'.']);
            }
            $cash = Account::where('type', 'cash')->orderByDesc('is_default')->firstOrFail();
            $entry = RiderCashEntry::create([
                'rider_id' => $rider->id, 'type' => 'handover', 'amount' => round($amount, 2), 'account_id' => $cash->id,
                'note' => $note, 'created_by' => $user->id, 'created_at' => now(),
            ]);
            AccountTransaction::create([
                'account_id' => $cash->id, 'txn_date' => now()->toDateString(), 'direction' => 'in', 'amount' => round($amount, 2),
                'source_type' => 'rider_handover', 'source_id' => $entry->id, 'note' => 'COD handover: '.$rider->name,
            ]);
            AuditLog::record($user->id, 'rider.handover', 'rider', $rider->id, ['amount' => round($amount, 2)]);

            return $entry;
        });
    }

    // ---------------------------------------------------------------- internals

    /** Posts the order as a sale and takes the stock (FEFO) from the online branch. */
    private function confirm(User $user, OnlineOrder $order): void
    {
        $order->loadMissing('lines.unit');
        $grand = SaleService::paisa($order->grand_total);
        $sale = Sale::create([
            'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('sale', Warehouse::findOrFail($order->warehouse_id)),
            'channel' => $order->channel, 'warehouse_id' => $order->warehouse_id, 'customer_id' => $order->customer_id,
            'cashier_id' => $user->id, 'status' => 'completed', 'sale_date' => now(),
            'subtotal' => $order->subtotal, 'line_discount_total' => $order->discount_total, 'shipping_total' => $order->shipping, 'grand_total' => $order->grand_total,
            'paid_total' => 0, 'due_total' => $order->grand_total, 'payment_status' => $grand > 0 ? 'unpaid' : 'paid',
            // Points were taken from the wallet at placement; on the sale they make a void give them back once.
            'points_redeemed' => $order->points_redeemed, 'points_value' => $order->points_value,
            'note' => 'Online order '.$order->number,
        ]);
        $costTotal = 0.0;
        foreach ($order->lines as $i => $l) {
            $gross = (int) round(SaleService::paisa($l->unit_price) * (float) $l->qty);
            $item = SaleItem::create([
                'sale_id' => $sale->id, 'line_type' => 'product', 'product_id' => $l->product_id, 'product_unit_id' => $l->product_unit_id,
                'description' => $l->description, 'qty' => $l->qty, 'factor' => $l->unit?->factor ?? 1, 'qty_base' => $l->qty_base,
                'unit_price' => $l->unit_price, 'discount_pct' => $order->discount_pct,
                'discount_total' => SaleService::money($gross - SaleService::paisa($l->line_total)), 'line_total' => $l->line_total,
            ]);
            $lineCost = 0.0;
            foreach ($this->stock->allocate($order->warehouse_id, $l->product_id, $l->qty_base, "lines.$i.qty") as $part) {
                $b = $part['balance'];
                $unitCost = (float) $b->avg_unit_cost;
                SaleItemBatch::create(['sale_item_id' => $item->id, 'batch_id' => $b->batch_id, 'qty_base' => $part['qty'], 'unit_cost' => $unitCost]);
                $this->stock->move([
                    'warehouse_id' => $order->warehouse_id, 'product_id' => $l->product_id, 'batch_id' => $b->batch_id,
                    'qty_base' => -$part['qty'], 'type' => 'sale', 'unit_cost' => $unitCost,
                    'source_type' => 'sale', 'source_id' => $sale->id, 'source_line_id' => $item->id, 'created_by' => $user->id,
                    'allow_negative' => $part['negative'] ?? false,
                ]);
                $lineCost += $part['qty'] * $unitCost;
            }
            $item->update(['cost_total' => round($lineCost, 2)]);
            $costTotal += $lineCost;
        }
        $sale->update(['cost_total' => round($costTotal, 2)]);
        PartyLedgerEntry::create([
            'party_type' => 'customer', 'party_id' => $order->customer_id, 'entry_date' => now()->toDateString(), 'type' => 'invoice',
            'debit' => $order->grand_total, 'credit' => 0, 'source_type' => 'sale', 'source_id' => $sale->id, 'note' => $sale->number, 'created_by' => $user->id,
        ]);
        // The points part is paid now (like a points payment at the counter); the rider collects the rest.
        $pointsPaisa = SaleService::paisa($order->points_value);
        if ($pointsPaisa > 0) {
            $method = PaymentMethod::where('code', 'points')->firstOrFail();
            $money = SaleService::money($pointsPaisa);
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', Warehouse::findOrFail($order->warehouse_id)),
                'direction' => 'in', 'party_type' => 'customer', 'party_id' => $order->customer_id,
                'payment_method_id' => $method->id, 'account_id' => $method->account_id, 'amount' => $money, 'paid_at' => now(),
                'note' => $order->points_redeemed.' points · '.$order->number, 'created_by' => $user->id,
            ]);
            PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'sale', 'allocatable_id' => $sale->id, 'amount' => $money]);
            PartyLedgerEntry::create([
                'party_type' => 'customer', 'party_id' => $order->customer_id, 'entry_date' => now()->toDateString(), 'type' => 'payment',
                'debit' => 0, 'credit' => $money, 'source_type' => 'sale', 'source_id' => $sale->id, 'note' => $sale->number.' points', 'created_by' => $user->id,
            ]);
            $due = $grand - $pointsPaisa;
            $sale->update(['paid_total' => $money, 'due_total' => SaleService::money($due), 'payment_status' => $due > 0 ? 'partial' : 'paid']);
        }
        // Paid online (SSLCommerz / bKash): booked to that method's account; nothing left for the rider.
        $onlinePaisa = SaleService::paisa($order->paid_online);
        if ($onlinePaisa > 0) {
            $method = PaymentMethod::where('code', $order->payment_method)->firstOrFail();
            $money = SaleService::money($onlinePaisa);
            $txn = OnlinePayment::where(['online_order_id' => $order->id, 'status' => 'paid'])->latest('id')->value('transaction_id');
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', Warehouse::findOrFail($order->warehouse_id)),
                'direction' => 'in', 'party_type' => 'customer', 'party_id' => $order->customer_id,
                'payment_method_id' => $method->id, 'account_id' => $method->account_id, 'amount' => $money, 'paid_at' => now(),
                'reference' => $txn, 'note' => 'Paid online · '.$order->number, 'created_by' => $user->id,
            ]);
            PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'sale', 'allocatable_id' => $sale->id, 'amount' => $money]);
            PartyLedgerEntry::create([
                'party_type' => 'customer', 'party_id' => $order->customer_id, 'entry_date' => now()->toDateString(), 'type' => 'payment',
                'debit' => 0, 'credit' => $money, 'source_type' => 'sale', 'source_id' => $sale->id, 'note' => $sale->number.' online', 'created_by' => $user->id,
            ]);
            $paid = SaleService::paisa($sale->fresh()->paid_total) + $onlinePaisa;
            $due = max(0, $grand - $paid);
            $sale->update(['paid_total' => SaleService::money($paid), 'due_total' => SaleService::money($due), 'payment_status' => $due > 0 ? 'partial' : 'paid']);
        }
        $order->update(['sale_id' => $sale->id]);
    }

    /**
     * Cash on delivery. With a rider: paid on the sale, held by the rider until
     * handover. With a courier (Steadfast / Pathao): booked to "Courier COD
     * receivable" until the courier pays out. `$user` is null when a courier
     * webhook reports the delivery.
     */
    private function deliver(?User $user, OnlineOrder $order): void
    {
        if (! $order->courier) {
            $this->assertRider($order);
        }
        $sale = Sale::whereKey($order->sale_id)->lockForUpdate()->firstOrFail();
        $due = SaleService::paisa($sale->due_total);
        if ($due > 0) {
            $method = PaymentMethod::where('code', $order->courier ? 'courier_cod' : 'cash')->firstOrFail();
            $money = SaleService::money($due);
            $by = $order->courier ? ucfirst($order->courier).' '.$order->courier_consignment_id : $order->rider->name;
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(), 'number' => $this->numbers->next('payment', Warehouse::findOrFail($order->warehouse_id)),
                'direction' => 'in', 'party_type' => 'customer', 'party_id' => $order->customer_id,
                // A rider's cash has no account until handover; a courier's is owed on its receivable account.
                'payment_method_id' => $method->id, 'account_id' => $order->courier ? $method->account_id : null,
                'reference' => $order->courier_consignment_id,
                'amount' => $money, 'paid_at' => now(), 'note' => 'COD '.$order->number.' via '.$by, 'created_by' => $user?->id,
            ]);
            PaymentAllocation::create(['payment_id' => $payment->id, 'allocatable_type' => 'sale', 'allocatable_id' => $sale->id, 'amount' => $money]);
            PartyLedgerEntry::create([
                'party_type' => 'customer', 'party_id' => $order->customer_id, 'entry_date' => now()->toDateString(), 'type' => 'payment',
                'debit' => 0, 'credit' => $money, 'source_type' => 'sale', 'source_id' => $sale->id, 'note' => $sale->number.' COD', 'created_by' => $user?->id,
            ]);
            $sale->update(['paid_total' => SaleService::money(SaleService::paisa($sale->paid_total) + $due), 'due_total' => 0, 'payment_status' => 'paid']);
            if (! $order->courier) {
                RiderCashEntry::create([
                    'rider_id' => $order->rider_id, 'type' => 'collect', 'amount' => $money, 'online_order_id' => $order->id,
                    'note' => $order->number, 'created_by' => $user?->id, 'created_at' => now(),
                ]);
            }
        }
        $order->update(['delivered_at' => now()]);
    }

    private function cancel(User $user, OnlineOrder $order, ?string $note): void
    {
        if (! $note || mb_strlen(trim($note)) < 3) {
            throw ValidationException::withMessages(['note' => 'A reason is required to cancel an order.']);
        }
        if ($order->sale_id) {
            // The void gives the redeemed points back (they are on the sale).
            $this->reversals->void($user, Sale::findOrFail($order->sale_id), 'Online order cancelled: '.trim($note), forOrder: true);
        } elseif ($order->points_redeemed > 0) {
            $this->rewards->credit($order->customer_id, 'reverse', (int) $order->points_redeemed, ['online_order', $order->id], 'Cancelled '.$order->number, $user->id);
        }
        // Money paid online goes back through the gateway's merchant panel; the order shows it is owed.
        $order->update(['cancel_reason' => trim($note)] + ($order->payment_status === 'paid' ? ['payment_status' => 'refund_due'] : []));
    }

    private function assertRider(OnlineOrder $order): void
    {
        if (! $order->rider_id || Rider::whereKey($order->rider_id)->value('status') !== 'active') {
            throw ValidationException::withMessages(['status' => 'Assign an active rider first.']);
        }
    }

    private function customer(array $data): Customer
    {
        if (! empty($data['customerId'])) {
            $c = Customer::where('is_active', true)->where('is_walk_in', false)->find($data['customerId']);
            if (! $c) {
                throw ValidationException::withMessages(['customerId' => 'Customer not found.']);
            }

            return $c;
        }
        $phone = CustomerController::normalizePhone((string) ($data['phone'] ?? ''));
        if (! $phone) {
            throw ValidationException::withMessages(['phone' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }
        $c = Customer::where('phone_normalized', $phone)->first();
        if ($c) {
            return $c;
        }
        if (empty($data['name'])) {
            throw ValidationException::withMessages(['name' => 'New customer: enter a name.']);
        }
        $c = Customer::create(['code' => 'TMP-'.uniqid(), 'name' => trim($data['name']), 'phone' => $phone, 'phone_normalized' => $phone, 'address' => $data['address'] ?? null]);
        $c->update(['code' => 'C-'.str_pad((string) $c->id, 4, '0', STR_PAD_LEFT)]);

        return $c;
    }

    public function history(OnlineOrder $order, string $status, ?string $note, ?User $user): void
    {
        OnlineOrderHistory::create(['online_order_id' => $order->id, 'status' => $status, 'note' => $note, 'user_id' => $user?->id, 'created_at' => now()]);
    }

    private function trackingCode(): string
    {
        do {
            $code = 'RP'.strtoupper(Str::random(8));
        } while (OnlineOrder::where('tracking_code', $code)->exists());

        return $code;
    }
}
