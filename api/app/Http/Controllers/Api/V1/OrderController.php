<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Pos\CustomerController;
use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ShippingZone;
use App\Services\ConversionTracker;
use App\Services\Integrations;
use App\Services\OnlineOrders;
use App\Services\OnlinePayments;
use App\Services\RiderTracking;
use App\Services\Storefront;
use App\Support\PresentStorefront;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

/**
 * Checkout, order history, tracking and reorder for the website and app.
 * Every order goes through OnlineOrders::place (server prices, zone shipping,
 * stock check at the online branch). Cash on delivery only.
 */
class OrderController extends V1Controller
{
    public function __construct(private OnlineOrders $orders, private Storefront $store) {}

    /**
     * Guest or signed-in checkout. A signed-in customer gets the customer
     * discount and may redeem points; a guest never does, even with the phone of
     * an existing account (the order is linked to that customer, nothing more).
     */
    public function checkout(Request $request): JsonResponse
    {
        $customer = $this->optionalCustomer($request);
        $data = $request->validate([
            'customer.name' => [$customer ? 'nullable' : 'required', 'string', 'max:120'],
            'customer.phone' => [$customer ? 'nullable' : 'required', 'string', 'max:20'],
            'customer.email' => ['nullable', 'email', 'max:120'],
            'customer.address' => ['required', 'string', 'max:255'],
            'zone_id' => ['nullable', 'integer'],
            'customer.division' => ['required_without:zone_id', 'nullable', 'string', 'max:60'],
            'customer.district' => ['required_without:zone_id', 'nullable', 'string', 'max:60'],
            'customer.thana' => ['nullable', 'string', 'max:80'],
            // Exact delivery point picked on the map (optional): Bangladesh only.
            'customer.latitude' => ['nullable', 'required_with:customer.longitude', 'numeric', 'between:20.3,26.8'],
            'customer.longitude' => ['nullable', 'required_with:customer.latitude', 'numeric', 'between:88.0,92.8'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:10000'],
            'payment_method' => ['nullable', 'in:cod,cash_on_delivery,sslcommerz,bkash'],
            'notes' => ['nullable', 'string', 'max:500'],
            'reward_points' => ['nullable', 'integer', 'min:0'],
            // Browser ids for server-side conversion tracking (all optional).
            'tracking' => ['nullable', 'array'], 'tracking.event_id' => ['nullable', 'string', 'max:64'],
            'tracking.fbp' => ['nullable', 'string', 'max:255'], 'tracking.fbc' => ['nullable', 'string', 'max:255'],
            'tracking.ga_client_id' => ['nullable', 'string', 'max:64'], 'tracking.page_url' => ['nullable', 'string', 'max:500'],
        ]);
        $phone = $customer?->phone ?? CustomerController::normalizePhone((string) data_get($data, 'customer.phone'));
        if (! $phone) {
            throw ValidationException::withMessages(['customer.phone' => 'Enter a valid Bangladeshi mobile number (01XXXXXXXXX).']);
        }
        if (! $customer && (int) ($data['reward_points'] ?? 0) > 0) {
            throw ValidationException::withMessages(['reward_points' => 'Sign in to use reward points.']);
        }

        // Retry-safe: the same Idempotency-Key from the same phone returns the first order.
        $key = trim((string) $request->header('Idempotency-Key'));
        $clientKey = $key !== '' ? hash('sha256', $phone.'|'.$key) : null;
        if ($clientKey && ($existing = OnlineOrder::where('client_key', $clientKey)->first())) {
            return $this->ok(['order' => PresentStorefront::order($existing), 'is_new_customer' => false], 'Order already placed.', 200);
        }

        $isNew = ! $customer && ! Customer::where('phone_normalized', $phone)->exists();
        try {
            $order = $this->place($data, $customer, $phone, $clientKey, $isNew);
        } catch (UniqueConstraintViolationException $e) {
            // Two retries raced: the other one placed the order.
            $existing = $clientKey ? OnlineOrder::where('client_key', $clientKey)->first() : null;
            if (! $existing) {
                throw $e;
            }

            return $this->ok(['order' => PresentStorefront::order($existing), 'is_new_customer' => false], 'Order already placed.', 200);
        }

        // Server-side purchase events go out after the response (Facebook CAPI, GA4), with the browser's event id.
        $ctx = ($data['tracking'] ?? []) + ['ip' => $request->ip(), 'user_agent' => (string) $request->userAgent()];
        $tracked = $order->fresh();
        defer(fn () => app(ConversionTracker::class)->purchase($tracked, $ctx));

        // Online payment: the order exists either way; the customer is sent to the gateway (or can retry / pay cash).
        $payment = null;
        if ($order->payment_status === 'pending') {
            try {
                $payment = ['gateway' => $order->payment_method, 'redirect_url' => app(OnlinePayments::class)->start($order, $order->payment_method), 'error' => null];
            } catch (ValidationException $e) {
                $payment = ['gateway' => $order->payment_method, 'redirect_url' => null, 'error' => collect($e->errors())->flatten()->first()];
            }
        }

        return $this->ok([
            'order' => PresentStorefront::order($order->fresh()) + ['event_id' => ConversionTracker::eventId($order, $ctx['event_id'] ?? null)],
            'payment' => $payment,
            'is_new_customer' => $isNew,
            'password_setup' => $isNew && $order->customer?->email ? 'Use "Forgot password" with the email you gave to create a password and see your orders.' : null,
        ], 'Order placed. We will call to confirm.', 201);
    }

    private function place(array $data, ?Customer $customer, string $phone, ?string $clientKey, bool $isNew): OnlineOrder
    {
        return DB::transaction(function () use ($data, $customer, $phone, $clientKey, $isNew) {
            $order = $this->orders->place(null, [
                'channel' => $this->channel(request()), 'customerId' => $customer?->id, 'loyalty' => (bool) $customer,
                'phone' => $phone, 'name' => data_get($data, 'customer.name') ?: $customer?->name,
                'address' => data_get($data, 'customer.address'), 'zoneId' => $this->zone($data)->id,
                'latitude' => data_get($data, 'customer.latitude') !== null ? (float) data_get($data, 'customer.latitude') : null,
                'longitude' => data_get($data, 'customer.longitude') !== null ? (float) data_get($data, 'customer.longitude') : null,
                'lines' => $this->lines($data['items']), 'note' => $data['notes'] ?? null,
                'redeemPoints' => (int) ($data['reward_points'] ?? 0), 'clientKey' => $clientKey, 'paymentMethod' => $this->method($data),
            ]);
            // A brand-new guest may leave an email: it lets them create a password later ("forgot password").
            if ($isNew && ($email = data_get($data, 'customer.email')) && ! Customer::whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
                Customer::whereKey($order->customer_id)->update(['email' => mb_strtolower($email)]);
            }

            return $order;
        });
    }

    public function index(Request $request): JsonResponse
    {
        $page = OnlineOrder::where('customer_id', $request->user()->id)->with('lines', 'history', 'rider')->latest('placed_at')->latest('id')
            ->paginate(min(50, (int) $request->query('per_page', 20)));
        $page->setCollection($page->getCollection()->map(fn ($o) => PresentStorefront::order($o)));

        return $this->page($page);
    }

    public function show(Request $request, string $number): JsonResponse
    {
        return $this->ok(PresentStorefront::order($this->own($request, $number)));
    }

    /** Public tracking by the random tracking code: status only, nothing personal. */
    public function track(string $code): JsonResponse
    {
        $o = OnlineOrder::where('tracking_code', strtoupper(trim($code)))->first();
        abort_unless($o, 404, 'No order with that tracking code.');

        return $this->ok(PresentStorefront::order($o, forTracking: true));
    }

    /**
     * Live delivery for the owner: store, their pin, the rider's position and the arrival time.
     * Poll every `poll_seconds` while the parcel is on its way.
     */
    public function live(Request $request, string $number): JsonResponse
    {
        $o = $this->own($request, $number);

        return $this->ok(RiderTracking::live(app(RiderTracking::class)->refreshEta($o), includeCustomerPin: true));
    }

    /** Live delivery by tracking code: rider position and arrival time, never the customer's pin. */
    public function trackLive(string $code): JsonResponse
    {
        $o = OnlineOrder::where('tracking_code', strtoupper(trim($code)))->first();
        abort_unless($o, 404, 'No order with that tracking code.');

        return $this->ok(RiderTracking::live(app(RiderTracking::class)->refreshEta($o), includeCustomerPin: false));
    }

    /** The order's items at today's prices and stock, for refilling the cart. */
    public function reorder(Request $request, string $number): JsonResponse
    {
        $o = $this->own($request, $number);
        $products = collect($this->store->present(Product::whereIn('id', $o->lines->pluck('product_id'))->where('is_active', true)->get()))->keyBy('id');
        $items = [];
        $warnings = [];
        foreach ($o->lines as $l) {
            $p = $products->get($l->product_id);
            $want = (int) $l->qty_base;
            if (! $p || $p['price'] === null) {
                $warnings[] = ['product_id' => $l->product_id, 'type' => 'unavailable', 'name' => $l->description, 'message' => "{$l->description} is no longer sold online."];

                continue;
            }
            if ($p['stock'] <= 0) {
                $warnings[] = ['product_id' => $l->product_id, 'type' => 'out_of_stock', 'name' => $p['name'], 'message' => "{$p['name']} is out of stock."];

                continue;
            }
            $qty = min($want, $p['stock']);
            if ($qty < $want) {
                $warnings[] = ['product_id' => $l->product_id, 'type' => 'reduced', 'name' => $p['name'], 'message' => "Only {$qty} of {$p['name']} available."];
            }
            if ($p['price'] !== round((float) $l->unit_price / max(1, $l->qty_base / max(1, (float) $l->qty)), 2)) {
                $warnings[] = ['product_id' => $l->product_id, 'type' => 'price_changed', 'name' => $p['name'], 'message' => "The price of {$p['name']} has changed."];
            }
            $items[] = ['product' => $p, 'quantity' => $qty, 'unit_price' => $p['price']];
        }

        return $this->ok(['items' => $items, 'warnings' => $warnings]);
    }

    private function own(Request $request, string $number): OnlineOrder
    {
        $o = OnlineOrder::where('number', $number)->where('customer_id', $request->user()->id)->with('lines', 'history', 'rider')->first();
        abort_unless($o, 404, 'Order not found.');

        return $o;
    }

    /** Signed-in customer when a valid customer token is sent; a bad token is an error, not a guest. */
    private function optionalCustomer(Request $request): ?Customer
    {
        if (! $request->bearerToken()) {
            return null;
        }
        $c = Auth::guard('sanctum')->user();
        if (! $c instanceof Customer || ! $c->tokenCan('customer') || ! $c->is_active) {
            abort(response()->json(['success' => false, 'message' => 'Please sign in again.'], 401));
        }

        return $c;
    }

    /** Pieces of each product: the base unit (factor 1). */
    private function lines(array $items): array
    {
        $units = ProductUnit::where('factor', 1)->whereIn('product_id', array_column($items, 'product_id'))->get()->keyBy('product_id');

        return collect($items)->map(function ($i, $k) use ($units) {
            $u = $units->get((int) $i['product_id']);
            if (! $u) {
                throw ValidationException::withMessages(["items.$k.product_id" => 'This product is not available.']);
            }

            return ['productId' => (int) $i['product_id'], 'unitId' => $u->id, 'qty' => (int) $i['qty']];
        })->all();
    }

    /** cod, sslcommerz or bkash, refused when switched off in Administration › Integrations. */
    private function method(array $data): string
    {
        $m = ($data['payment_method'] ?? 'cod') === 'cash_on_delivery' ? 'cod' : ($data['payment_method'] ?? 'cod');
        $i = app(Integrations::class);
        $available = $m === 'cod' ? ($i->public('storefront')['codEnabled'] ?? true) : $i->enabled($m);
        if (! $available) {
            throw ValidationException::withMessages(['payment_method' => 'This payment method is not available. Choose another one.']);
        }

        return $m;
    }

    private function zone(array $data): ShippingZone
    {
        $zone = ! empty($data['zone_id'])
            ? ShippingZone::where('is_active', true)->find($data['zone_id'])
            : ShippingZone::where('is_active', true)->where('division', data_get($data, 'customer.division'))->where('district', data_get($data, 'customer.district'))
                ->when(data_get($data, 'customer.thana'), fn ($q, $t) => $q->where('thana', $t))->orderBy('thana')->first();
        if (! $zone) {
            throw ValidationException::withMessages(['zone_id' => 'We do not deliver to this area yet. Choose a delivery area from the list.']);
        }

        return $zone;
    }

    private function channel(Request $request): string
    {
        return str_contains(strtolower((string) $request->header('X-Client', $request->userAgent())), 'flutter') || $request->header('X-Client') === 'app' ? 'app' : 'website';
    }
}
