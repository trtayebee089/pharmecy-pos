<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OnlineOrder;
use App\Models\Rider;
use App\Models\RiderCashEntry;
use App\Models\SaleItemBatch;
use App\Models\ShippingZone;
use App\Services\OnlineOrders;
use App\Support\Present;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Online orders, riders (with COD cash) and shipping zones for the POS. */
class OnlineOrderController extends Controller
{
    public function __construct(private OnlineOrders $orders) {}

    // ---------------------------------------------------------------- orders

    public function index(Request $request): JsonResponse
    {
        $q = OnlineOrder::with('lines.unit', 'history.user', 'customer', 'rider', 'zone')
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('placed_at')->orderByDesc('id');
        if (filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json(self::presentMany($q->limit(1000)->get()));
        }
        $page = $q->paginate((int) $request->query('perPage', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json(Present::paginated($page, self::presentMany(collect($page->items()))));
    }

    public function show(OnlineOrder $order): JsonResponse
    {
        return response()->json(self::presentMany(collect([$order->load('lines.unit', 'history.user', 'customer', 'rider', 'zone')]))[0]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'channel' => ['nullable', 'in:phone'],
            'customerId' => ['nullable', 'integer'],
            'name' => ['nullable', 'string', 'max:120'],
            'phone' => ['required_without:customerId', 'nullable', 'string', 'max:20'],
            'address' => ['required', 'string', 'max:255'],
            'zoneId' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.productId' => ['required', 'integer'],
            'lines.*.unitId' => ['required', 'integer'],
            'lines.*.qty' => ['required', 'numeric', 'gt:0', 'max:10000'],
        ]);
        $order = $this->orders->place($request->user(), $data + ['channel' => 'phone']);
        AuditLog::record($request->user()->id, 'order.placed', 'online_order', $order->id, ['number' => $order->number], $request);

        return $this->show($order)->setStatusCode(201);
    }

    public function status(Request $request, OnlineOrder $order): JsonResponse
    {
        $d = $request->validate(['status' => ['required', 'in:confirmed,packed,in_transit,delivered,cancelled'], 'note' => ['nullable', 'string', 'max:255']]);
        $order = $this->orders->transition($request->user(), $order, $d['status'], $d['note'] ?? null);

        return $this->show($order);
    }

    public function rider(Request $request, OnlineOrder $order): JsonResponse
    {
        $d = $request->validate(['rider_id' => ['required', 'integer']]);

        return $this->show($this->orders->assignRider($request->user(), $order, (int) $d['rider_id']));
    }

    public function settings(): JsonResponse
    {
        return response()->json(['warehouseId' => $this->orders->warehouseId()]);
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $d = $request->validate(['warehouseId' => ['required', 'integer']]);
        $this->orders->setWarehouse((int) $d['warehouseId']);
        AuditLog::record($request->user()->id, 'settings.online_orders', 'settings', null, $d, $request);

        return response()->json(['warehouseId' => $this->orders->warehouseId()]);
    }

    // ---------------------------------------------------------------- riders

    public function riders(Request $request): JsonResponse
    {
        $q = Rider::query()->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', "%$s%")->orWhere('phone', 'like', "%$s%")))
            ->when($request->query('status'), fn ($q, $s) => $q->where('status', $s))->orderBy('name');
        if (filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($q->get()->map(fn ($r) => self::riderShape($r))->values());
        }
        $page = $q->paginate((int) $request->query('perPage', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($r) => self::riderShape($r))->all()));
    }

    public function riderShow(Rider $rider): JsonResponse
    {
        return response()->json(self::riderShape($rider));
    }

    public function saveRider(Request $request, ?Rider $rider = null): JsonResponse
    {
        if ($rider?->exists) {
            // Partial updates (e.g. only the status) keep the other fields.
            $request->merge(array_merge(['name' => $rider->name, 'phone' => $rider->phone, 'nid' => $rider->nid, 'status' => $rider->status], $request->all()));
        }
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['required', 'string', 'max:20', Rule::unique('riders', 'phone')->ignore($rider?->id)],
            'nid' => ['nullable', 'string', 'max:30'], 'status' => ['nullable', 'in:active,on_leave,inactive'],
        ]);
        $attrs = ['name' => trim($d['name']), 'phone' => $d['phone'], 'nid' => $d['nid'] ?? null, 'status' => $d['status'] ?? 'active'];
        $rider = $rider?->exists ? tap($rider)->update($attrs) : Rider::create($attrs);
        AuditLog::record($request->user()->id, 'rider.saved', 'rider', $rider->id, $attrs, $request);

        return response()->json(self::riderShape($rider->fresh()), $rider->wasRecentlyCreated ? 201 : 200);
    }

    /** Riders with orders or cash history are deactivated, never deleted; riders on open orders cannot be removed. */
    public function deleteRider(Request $request, Rider $rider): JsonResponse
    {
        if (OnlineOrder::where('rider_id', $rider->id)->whereNotIn('status', ['delivered', 'cancelled'])->exists()) {
            abort(422, 'This rider is assigned to open orders. Reassign them first.');
        }
        if (OnlineOrders::cashHeld($rider->id) > 0) {
            abort(422, 'This rider still holds cash. Record the handover first.');
        }
        if (OnlineOrder::where('rider_id', $rider->id)->exists() || RiderCashEntry::where('rider_id', $rider->id)->exists()) {
            $rider->update(['status' => 'inactive']);
            $message = 'Rider has delivery history, so it was deactivated instead of deleted.';
        } else {
            $rider->delete();
            $message = 'Rider deleted.';
        }
        AuditLog::record($request->user()->id, 'rider.removed', 'rider', $rider->id, ['result' => $message], $request);

        return response()->json(['message' => $message]);
    }

    public function handover(Request $request, Rider $rider): JsonResponse
    {
        $d = $request->validate(['amount' => ['required', 'numeric', 'gt:0'], 'note' => ['nullable', 'string', 'max:255']]);
        $this->orders->handover($request->user(), $rider, (float) $d['amount'], $d['note'] ?? null);

        return response()->json(self::riderShape($rider->fresh()), 201);
    }

    public function riderCash(Rider $rider): JsonResponse
    {
        return response()->json(RiderCashEntry::where('rider_id', $rider->id)->orderByDesc('id')->limit(200)->get()
            ->map(fn ($e) => ['id' => $e->id, 'date' => $e->created_at->toIso8601String(), 'type' => $e->type, 'amount' => (float) $e->amount, 'note' => (string) $e->note])->values());
    }

    // ---------------------------------------------------------------- zones

    public function zones(Request $request): JsonResponse
    {
        $q = ShippingZone::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(fn ($q) => $q->where('thana', 'like', "%$s%")->orWhere('district', 'like', "%$s%")->orWhere('division', 'like', "%$s%")))
            ->when($request->query('division'), fn ($q, $s) => $q->where('division', $s))
            ->orderBy('division')->orderBy('district')->orderBy('thana');
        if (filter_var($request->query('all'), FILTER_VALIDATE_BOOLEAN)) {
            return response()->json($q->get()->map(fn ($z) => self::zoneShape($z))->values());
        }
        $page = $q->paginate((int) $request->query('perPage', 25), ['*'], 'page', (int) $request->query('page', 1));

        return response()->json(Present::paginated($page, collect($page->items())->map(fn ($z) => self::zoneShape($z))->all()));
    }

    public function zoneShow(ShippingZone $zone): JsonResponse
    {
        return response()->json(self::zoneShape($zone));
    }

    public function saveZone(Request $request, ?ShippingZone $zone = null): JsonResponse
    {
        if ($zone?->exists) {
            // Partial updates (e.g. only isActive) keep the other fields.
            $request->merge(array_merge(self::zoneShape($zone), $request->all()));
        }
        $d = $request->validate([
            'division' => ['required', 'string', 'max:60'], 'district' => ['required', 'string', 'max:60'], 'thana' => ['required', 'string', 'max:80'],
            'rate' => ['required', 'numeric', 'min:0'], 'freeShippingMin' => ['nullable', 'numeric', 'min:0'],
            'partner' => ['required', 'in:own_rider,pathao,steadfast,redx'], 'isActive' => ['nullable', 'boolean'],
        ]);
        $request->validate(['thana' => [Rule::unique('shipping_zones')->where('division', $d['division'])->where('district', $d['district'])->ignore($zone?->id)]],
            ['thana.unique' => 'This area already has a zone.']);
        $attrs = ['division' => trim($d['division']), 'district' => trim($d['district']), 'thana' => trim($d['thana']), 'rate' => $d['rate'],
            'free_shipping_min' => $d['freeShippingMin'] ?? null, 'partner' => $d['partner'], 'is_active' => $d['isActive'] ?? true];
        $zone = $zone?->exists ? tap($zone)->update($attrs) : ShippingZone::create($attrs);
        AuditLog::record($request->user()->id, 'zone.saved', 'shipping_zone', $zone->id, $attrs, $request);

        return response()->json(self::zoneShape($zone->fresh()), $zone->wasRecentlyCreated ? 201 : 200);
    }

    public function deleteZone(Request $request, ShippingZone $zone): JsonResponse
    {
        if (OnlineOrder::where('zone_id', $zone->id)->exists()) {
            $zone->update(['is_active' => false]);
            $message = 'Zone has orders, so it was deactivated instead of deleted.';
        } else {
            $zone->delete();
            $message = 'Zone deleted.';
        }
        AuditLog::record($request->user()->id, 'zone.removed', 'shipping_zone', $zone->id, ['result' => $message], $request);

        return response()->json(['message' => $message]);
    }

    // ---------------------------------------------------------------- shapes

    /** OnlineOrder in pos/app/types/orders.ts; batch numbers come from the sale once confirmed. */
    public static function presentMany($orders): array
    {
        $saleIds = $orders->pluck('sale_id')->filter()->all();
        $batches = SaleItemBatch::query()->join('sale_items', 'sale_items.id', '=', 'sale_item_batches.sale_item_id')
            ->join('batches', 'batches.id', '=', 'sale_item_batches.batch_id')->whereIn('sale_items.sale_id', $saleIds)
            ->get(['sale_items.sale_id', 'sale_items.product_unit_id', 'batches.batch_no'])
            ->groupBy(fn ($r) => $r->sale_id.'-'.$r->product_unit_id);

        return $orders->map(fn (OnlineOrder $o) => [
            'id' => $o->id, 'number' => $o->number, 'legacyRef' => (string) $o->legacy_ref, 'trackingCode' => $o->tracking_code,
            'channel' => $o->channel, 'placedAt' => $o->placed_at->toIso8601String(), 'status' => $o->status,
            'customerId' => $o->customer_id, 'customer' => $o->customer?->name ?? '', 'phone' => $o->phone, 'address' => $o->address,
            'division' => $o->division, 'district' => $o->district, 'thana' => $o->thana,
            'zone' => trim($o->thana.', '.$o->district, ', '), 'zoneId' => $o->zone_id,
            'items' => $o->lines->count(), 'subtotal' => (float) $o->subtotal, 'shipping' => (float) $o->shipping,
            'codAmount' => (float) $o->grand_total, // cash to collect on delivery (all orders are COD)
            'grandTotal' => (float) $o->grand_total, 'saleId' => $o->sale_id,
            'riderId' => $o->rider_id, 'riderName' => $o->rider?->name,
            'lines' => $o->lines->map(fn ($l) => [
                'productName' => $l->description, 'qty' => (float) $l->qty, 'unitLabel' => $l->unit?->label ?? '',
                'unitPrice' => (float) $l->unit_price, 'lineTotal' => (float) $l->line_total,
                'batchNo' => ($b = $batches->get($o->sale_id.'-'.$l->product_unit_id)) ? $b->pluck('batch_no')->unique()->join(', ') : null,
            ])->all(),
            'history' => $o->history->map(fn ($h) => [
                'status' => $h->status, 'at' => $h->created_at->toIso8601String(), 'by' => $h->user?->name ?? 'System', 'note' => $h->note ?: null,
            ])->all(),
        ])->values()->all();
    }

    private static function riderShape(Rider $r): array
    {
        return [
            'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'nid' => (string) $r->nid, 'status' => $r->status,
            'assignedOrders' => OnlineOrder::where('rider_id', $r->id)->whereNotIn('status', ['delivered', 'cancelled'])->count(),
            'cashHeld' => OnlineOrders::cashHeld($r->id),
        ];
    }

    private static function zoneShape(ShippingZone $z): array
    {
        return [
            'id' => $z->id, 'division' => $z->division, 'district' => $z->district, 'thana' => $z->thana, 'rate' => (float) $z->rate,
            'freeShippingMin' => $z->free_shipping_min !== null ? (float) $z->free_shipping_min : null, 'partner' => $z->partner, 'isActive' => $z->is_active,
        ];
    }
}
