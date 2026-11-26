<?php

namespace App\Http\Controllers\Rider;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OnlineOrder;
use App\Models\Rider;
use App\Services\Integrations;
use App\Services\OnlineOrders;
use App\Services\RiderTracking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The rider app: sign in, go on/off duty, report positions, see the orders
 * assigned to me with the three map markers (store, customer, me), and mark
 * them picked up and delivered. Cash on delivery is booked exactly as when
 * staff mark the order delivered (held by the rider until handover).
 */
class RiderAppController extends Controller
{
    public function __construct(private RiderTracking $tracking, private OnlineOrders $orders) {}

    public function login(Request $request): JsonResponse
    {
        $d = $request->validate(['phone' => ['required', 'string', 'max:20'], 'password' => ['required', 'string', 'max:200'], 'device_name' => ['nullable', 'string', 'max:60']]);
        $rider = $this->tracking->login($d['phone'], $d['password']);
        AuditLog::record(null, 'rider.login', 'rider', $rider->id, ['device' => $d['device_name'] ?? null], $request);

        return $this->ok(['token' => $this->tracking->token($rider, $d['device_name'] ?? 'rider-app'), 'rider' => $this->profile($rider)], 'Signed in.');
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();
        $request->user()->update(['on_duty' => false]);

        return $this->ok(null, 'Signed out.');
    }

    public function me(Request $request): JsonResponse
    {
        return $this->ok($this->profile($request->user()));
    }

    public function duty(Request $request): JsonResponse
    {
        $d = $request->validate(['on_duty' => ['required', 'boolean']]);
        $this->tracking->setDuty($request->user(), (bool) $d['on_duty']);

        return $this->ok($this->profile($request->user()->fresh()), $d['on_duty'] ? 'You are on duty.' : 'You are off duty.');
    }

    /** Positions from the phone, oldest first; up to 500 per call (sent in batches after a weak signal). */
    public function locations(Request $request): JsonResponse
    {
        $d = $request->validate([
            'points' => ['required', 'array', 'min:1', 'max:500'],
            'points.*.lat' => ['required', 'numeric', 'between:-90,90'], 'points.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'points.*.accuracy' => ['nullable', 'numeric', 'min:0'], 'points.*.heading' => ['nullable', 'numeric'],
            'points.*.speed' => ['nullable', 'numeric'], 'points.*.recorded_at' => ['nullable', 'date'],
        ]);
        $rider = $request->user();
        $this->tracking->record($rider, $d['points']);

        return $this->ok(['received' => count($d['points']), 'on_duty' => (bool) $rider->fresh()->on_duty]);
    }

    /** `scope=active` (default: assigned and not closed) or `scope=done` (delivered/cancelled, last 30 days). */
    public function orders(Request $request): JsonResponse
    {
        $done = $request->query('scope') === 'done';
        $orders = OnlineOrder::with('lines', 'customer', 'rider')->where('rider_id', $request->user()->id)->whereNull('courier')
            ->when($done, fn ($q) => $q->whereIn('status', ['delivered', 'cancelled'])->where('updated_at', '>=', now()->subDays(30)), fn ($q) => $q->whereNotIn('status', ['delivered', 'cancelled', 'pending']))
            ->orderByRaw("CASE status WHEN 'in_transit' THEN 0 WHEN 'packed' THEN 1 ELSE 2 END")
            ->when($done, fn ($q) => $q->orderByDesc('updated_at'), fn ($q) => $q->orderBy('placed_at'))
            ->limit(100)->get();

        return $this->ok($orders->map(fn ($o) => $this->present($o))->values());
    }

    public function order(Request $request, OnlineOrder $order): JsonResponse
    {
        return $this->ok($this->present($this->mine($request, $order)));
    }

    /** Parcel collected from the branch: the delivery clock starts and the customer sees the arrival time. */
    public function pickup(Request $request, OnlineOrder $order): JsonResponse
    {
        $rider = $request->user();
        $order = $this->mine($request, $order);
        if ($order->status === 'confirmed') {
            $order = $this->orders->transition(null, $order, 'packed', 'Collected without packing step', $rider);
        }
        if ($order->status !== 'packed') {
            throw ValidationException::withMessages(['status' => $order->status === 'in_transit' ? 'Already picked up.' : 'This order is not ready for pickup.']);
        }
        if (! $rider->on_duty) {
            $rider->update(['on_duty' => true]);
        }
        $order = $this->orders->transition(null, $order, 'in_transit', null, $rider);

        return $this->ok($this->present($order), 'Picked up. Drive safely.');
    }

    /** Handed to the customer; cash on delivery is now held by the rider. */
    public function deliver(Request $request, OnlineOrder $order): JsonResponse
    {
        $d = $request->validate(['note' => ['nullable', 'string', 'max:255'], 'cash_collected' => ['nullable', 'numeric', 'min:0']]);
        $order = $this->mine($request, $order);
        $cod = OnlineOrders::codAmount($order);
        if (array_key_exists('cash_collected', $d) && $d['cash_collected'] !== null && abs((float) $d['cash_collected'] - $cod) > 0.009) {
            throw ValidationException::withMessages(['cash_collected' => 'Collect exactly ৳'.number_format($cod, 2).'. If the customer cannot pay, call the pharmacy.']);
        }
        $order = $this->orders->transition(null, $order, 'delivered', $d['note'] ?? null, $request->user());

        return $this->ok($this->present($order), $cod > 0 ? 'Delivered. Cash collected: ৳'.number_format($cod, 2) : 'Delivered.');
    }

    // ---------------------------------------------------------------- shapes

    private function mine(Request $request, OnlineOrder $order): OnlineOrder
    {
        abort_unless($order->rider_id === $request->user()->id && ! $order->courier, 404, 'Order not found.');

        return $order;
    }

    private function profile(Rider $r): array
    {
        $today = OnlineOrder::where('rider_id', $r->id)->where('status', 'delivered')->where('delivered_at', '>=', now()->startOfDay())->get(['picked_up_at', 'delivered_at']);
        $minutes = $today->map(fn ($o) => RiderTracking::deliveryMinutes($o))->filter(fn ($m) => $m !== null);
        $maps = app(Integrations::class)->public('maps');

        return [
            'id' => $r->id, 'name' => $r->name, 'phone' => $r->phone, 'on_duty' => (bool) $r->on_duty,
            'cash_held' => OnlineOrders::cashHeld($r->id),
            'today' => ['delivered' => $today->count(), 'avg_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg())],
            'active_orders' => OnlineOrder::where('rider_id', $r->id)->whereNull('courier')->whereIn('status', ['confirmed', 'packed', 'in_transit'])->count(),
            // How often the app should report while on duty.
            'tracking' => ['interval_seconds' => 15, 'distance_filter_m' => 25],
            'maps' => ['default_lat' => (float) $maps['defaultLat'], 'default_lng' => (float) $maps['defaultLng']],
        ];
    }

    private function present(OnlineOrder $o): array
    {
        $o->loadMissing('lines', 'customer', 'rider');

        return [
            'id' => $o->id, 'number' => $o->number, 'status' => $o->status, 'placed_at' => $o->placed_at?->toIso8601String(),
            'customer' => ['name' => $o->customer?->name, 'phone' => $o->phone, 'address' => $o->address, 'area' => trim($o->thana.', '.$o->district, ', ')],
            'note' => $o->note,
            'cod_amount' => OnlineOrders::codAmount($o), 'payment_method' => $o->payment_method, 'payment_status' => $o->payment_status,
            'items' => $o->lines->map(fn ($l) => ['name' => $l->description, 'qty' => (float) $l->qty])->values()->all(),
            // The three markers: store, customer and the rider's own last reported position.
            'markers' => [
                'store' => RiderTracking::store($o),
                'customer' => $o->latitude !== null ? ['lat' => $o->latitude, 'lng' => $o->longitude] : null,
                'rider' => RiderTracking::riderPosition($o->rider),
            ],
            'eta' => RiderTracking::eta($o),
            'picked_up_at' => $o->picked_up_at?->toIso8601String(), 'delivered_at' => $o->delivered_at?->toIso8601String(),
            'delivery_minutes' => RiderTracking::deliveryMinutes($o),
            'can_pickup' => in_array($o->status, ['confirmed', 'packed'], true), 'can_deliver' => $o->status === 'in_transit',
        ];
    }

    private function ok(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        return response()->json(['success' => true, 'message' => $message, 'data' => $data], $status, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}
