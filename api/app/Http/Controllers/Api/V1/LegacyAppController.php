<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Customer;
use App\Models\OnlineOrder;
use App\Models\Product;
use App\Models\ShippingZone;
use App\Services\CustomerAuth;
use App\Services\Memberships;
use App\Services\SaleService;
use App\Services\Storefront;
use App\Support\PresentStorefront;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rules\Password;

/**
 * The paths and response shapes the INSTALLED Android app (1.x) calls, served
 * from the same services as /api/v1 (same rules: server prices, no guest
 * discount or points, owner-only orders). Shapes follow app/lib exactly; see
 * docs in api/README.md "Customer API". Remove once the app on the store uses
 * /api/v1 and old installs have aged out.
 *
 * Differences an old install will notice: guest checkout no longer gets the
 * 5% the app shows (the confirmation shows the real total), and the delivery
 * zone is matched by the shipping rate the app sends (it does not send the area).
 */
class LegacyAppController extends V1Controller
{
    private const STATUS = ['pending' => 'Pending', 'confirmed' => 'Processing', 'packed' => 'Processing', 'in_transit' => 'Processing', 'delivered' => 'Completed', 'cancelled' => 'Cancelled'];

    public function __construct(private Storefront $store, private CustomerAuth $auth) {}

    // ---------------------------------------------------------------- account

    public function login(Request $request, AccountController $v1): JsonResponse
    {
        $res = $v1->login($request);

        return $this->session(Customer::findOrFail($res->getData(true)['user']['id']), $res->getData(true)['token']);
    }

    public function register(Request $request, AccountController $v1): JsonResponse
    {
        $request->validate(['image' => ['nullable', 'image', 'max:2048']]);
        $res = $v1->register($request);
        $c = Customer::findOrFail($res->getData(true)['user']['id']);
        $this->storeAvatar($request, $c, 'image');

        return $this->session($c, $res->getData(true)['token'], 201);
    }

    public function profile(Request $request): JsonResponse
    {
        $c = $request->user();

        return $this->json(['success' => true, 'user' => $this->user($c), 'reward_tiers' => [], 'membership' => $this->membership($c), 'orders' => $this->orderRows($c)]);
    }

    /** Multipart: id, name, phone_number (ignored: not changeable), email, gender, address, avatar. */
    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:120'], 'email' => ['sometimes', 'nullable', 'email', 'max:120'],
            'gender' => ['sometimes', 'nullable', 'string'], 'address' => ['sometimes', 'nullable', 'string', 'max:255'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ]);
        $data['gender'] = in_array(strtolower((string) ($data['gender'] ?? '')), ['male', 'female', 'other'], true) ? strtolower($data['gender']) : null;
        $c = $this->auth->updateProfile($request->user(), array_intersect_key($data, array_flip(['name', 'email', 'gender', 'address'])));
        $this->storeAvatar($request, $c, 'avatar');

        return $this->json(['success' => true, 'message' => 'Profile updated.', 'data' => $this->user($c->fresh())]);
    }

    /**
     * The old app sends no current password. Accepted on this path only (the
     * request is token-authenticated); every other session is signed out so a
     * leaked token cannot quietly keep access. /api/v1 requires the current password.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $data = $request->validate(['newPassword' => ['required', 'confirmed', Password::min(8)->letters()->numbers()]]);
        $c = $request->user();
        $c->update(['password' => $data['newPassword']]);
        $c->tokens()->where('id', '!=', $c->currentAccessToken()->id)->delete();

        return $this->json(['success' => true, 'message' => 'Password updated.']);
    }

    // ---------------------------------------------------------------- catalogue

    public function category(Request $request, string $slug, CatalogController $v1): JsonResponse
    {
        $request->merge(['per_page' => 20]);

        return $v1->category($request, $slug);
    }

    /** A bare list of {name, slug}. */
    public function suggestions(Request $request): JsonResponse
    {
        return $this->json($this->store->suggestions((string) $request->query('query', '')));
    }

    public function search(Request $request, string $q): JsonResponse
    {
        $page = $this->store->products(['search' => $q, 'page' => $request->query('page', 1), 'per_page' => 20]);

        return $this->json(['success' => true, 'data' => $page->items()]);
    }

    /** `saleCount` at the top level, as an int. */
    public function product(string $slug): JsonResponse
    {
        $p = $this->store->product($slug);
        abort_unless($p, 404, 'Product not found.');

        return $this->json(['success' => true, 'data' => $p, 'saleCount' => (int) ($p['saleCount'] ?? 0)]);
    }

    // ---------------------------------------------------------------- orders

    /**
     * The old checkout body (prices, discount and user_id in it are ignored).
     * Success is 200 with {success, sale, tracking, is_new_customer,
     * temporary_password}; a stock shortage is 422 with available_stock and
     * product_id so the app can lower the cart quantity.
     */
    public function checkout(Request $request, OrderController $v1): JsonResponse
    {
        $body = $request->validate([
            'customer' => ['required', 'array'], 'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required'], 'items.*.qty' => ['required', 'numeric', 'min:1'],
            'shipping_cost' => ['nullable', 'numeric'], 'reward_points' => ['nullable', 'numeric', 'min:0'],
        ]);
        $items = collect($body['items'])->map(fn ($i) => ['product_id' => (int) $i['product_id'], 'qty' => (int) $i['qty']])->all();
        if ($short = $this->shortage($items)) {
            return $short;
        }
        $zone = $this->zone($body['customer'], $request->input('shipping_cost'));
        if (! $zone) {
            return $this->json(['success' => false, 'message' => 'We could not match your delivery area. Please update the Red Pharma app or call us to order.'], 422);
        }

        $request->replace([
            'customer' => array_intersect_key($body['customer'], array_flip(['name', 'phone', 'email', 'address'])),
            'zone_id' => $zone->id, 'items' => $items, 'payment_method' => 'cod', 'notes' => $request->input('notes'),
            'reward_points' => (int) ($body['reward_points'] ?? 0),
        ]);
        $request->headers->set('X-Client', 'app');
        $res = $v1->checkout($request);
        $data = $res->getData(true)['data'];
        $order = OnlineOrder::where('number', $data['order']['number'])->with('customer')->firstOrFail();

        return $this->json([
            'success' => true, 'message' => 'Order placed. We will call to confirm.',
            'sale' => ['id' => $order->id, 'reference_no' => $order->number, 'grand_total' => $data['order']['cod_amount'], 'customer' => ['name' => $order->customer?->name, 'email' => $order->customer?->email]],
            'tracking' => ['tracking_no' => $order->tracking_code],
            'is_new_customer' => (bool) $data['is_new_customer'], 'temporary_password' => null,
        ]);
    }

    public function orders(Request $request): JsonResponse
    {
        return $this->json(['success' => true, 'data' => $this->orderRows($request->user())]);
    }

    public function order(Request $request, int $id): JsonResponse
    {
        $o = $this->own($request, $id);
        $v = PresentStorefront::order($o);

        return $this->json(['success' => true, 'data' => [
            'id' => $o->id, 'number' => $o->number, 'date' => $o->placed_at?->toDateTimeString(), 'status' => self::STATUS[$o->status] ?? $o->status,
            'grand_total' => $v['cod_amount'], 'total_price' => $v['subtotal'], 'shipping_cost' => $v['shipping'],
            'discount' => ['amount' => round($v['discount_total'] + $v['points_value'], 2)],
            'items' => collect($v['items'])->map(fn ($i) => ['name' => $i['name'], 'quantity' => $i['quantity'], 'price' => $i['unit_price'], 'subtotal' => $i['subtotal']])->all(),
            'tracking' => [
                'current_status' => $v['status_label'], 'tracking_number' => $o->tracking_code,
                'histories' => collect($v['history'])->map(fn ($h) => ['status' => $h['label'], 'notes' => $h['note'], 'changed_at' => $h['at']])->all(),
            ],
        ]]);
    }

    public function reorder(Request $request, int $id, OrderController $v1): JsonResponse
    {
        return $v1->reorder($request, $this->own($request, $id)->number);
    }

    // ---------------------------------------------------------------- helpers

    private function session(Customer $c, string $token, int $status = 200): JsonResponse
    {
        return $this->json([
            'success' => true, 'token' => $token, 'user' => $this->user($c),
            'reward_tiers' => [], 'membership' => $this->membership($c), 'orders' => $this->orderRows($c),
        ], $status);
    }

    private function user(Customer $c): array
    {
        $u = $this->customer($c);

        return $u + ['avator' => $u['avatar']];
    }

    /** The app shows this discount at checkout; it is the one the server applies. */
    private function membership(Customer $c): array
    {
        [$pct] = app(SaleService::class)->customerDiscount($c);
        $m = app(Memberships::class)->active($c->id);

        return ['name' => $m?->plan->name ?? '', 'color_code' => '', 'discount' => (float) $pct];
    }

    private function orderRows(Customer $c): array
    {
        return OnlineOrder::where('customer_id', $c->id)->latest('placed_at')->latest('id')->limit(100)->get()
            ->map(fn ($o) => [
                'id' => $o->id, 'number' => $o->number, 'status' => self::STATUS[$o->status] ?? $o->status, 'date' => $o->placed_at?->toDateTimeString(),
                'total' => round((float) $o->grand_total - (float) $o->points_value, 2),
                'discount' => ['amount' => round((float) $o->discount_total + (float) $o->points_value, 2)],
            ])->all();
    }

    private function own(Request $request, int $id): OnlineOrder
    {
        $o = OnlineOrder::whereKey($id)->where('customer_id', $request->user()->id)->with('lines', 'history', 'rider')->first();
        abort_unless($o, 404, 'Order not found.');

        return $o;
    }

    /** The first product short of stock at the online branch, in the shape the app reads. */
    private function shortage(array $items): ?JsonResponse
    {
        $want = [];
        foreach ($items as $i) {
            $want[$i['product_id']] = ($want[$i['product_id']] ?? 0) + $i['qty'];
        }
        $stock = collect($this->store->present(Product::whereIn('id', array_keys($want))->get()))->keyBy('id');
        foreach ($want as $id => $qty) {
            $p = $stock->get($id);
            $have = (int) ($p['stock'] ?? 0);
            if ($qty > $have) {
                return $this->json([
                    'success' => false, 'product_id' => $id, 'available_stock' => $have,
                    'message' => $p ? "Only {$have} of {$p['name']} available." : 'A product in your cart is no longer available.',
                ], 422);
            }
        }

        return null;
    }

    /** By area when sent (newer builds), else the active zone with the rate the app charged. */
    private function zone(array $customer, mixed $rate): ?ShippingZone
    {
        $q = ShippingZone::where('is_active', true);
        if (! empty($customer['division']) && ! empty($customer['district'])) {
            return (clone $q)->where(['division' => $customer['division'], 'district' => $customer['district']])->orderBy('thana')->first();
        }

        if (! is_numeric($rate)) {
            return null;
        }
        // Several districts can share a rate: prefer the one named in the address.
        $zones = $q->where('rate', (float) $rate)->orderBy('id')->get();
        $address = mb_strtolower((string) ($customer['address'] ?? ''));

        return $zones->first(fn ($z) => $address !== '' && str_contains($address, mb_strtolower($z->district))) ?? $zones->first();
    }

    private function storeAvatar(Request $request, Customer $c, string $field): void
    {
        if (! $request->hasFile($field)) {
            return;
        }
        $old = $c->avatar_path;
        $c->update(['avatar_path' => $request->file($field)->store('avatars', 'public')]);
        if ($old) {
            Storage::disk('public')->delete($old);
        }
    }
}
