<?php

namespace App\Services;

use App\Http\Controllers\Pos\CustomerController;
use App\Models\OnlineOrder;
use App\Models\Rider;
use App\Models\RiderLocation;
use App\Models\Warehouse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * In-house rider tracking (owner decision 26 Nov 2026, Google Maps):
 *
 * - Riders sign in to the rider app with phone + password (set by staff in Delivery › Riders).
 *   Tokens carry the `rider` ability only and last 30 days.
 * - While on duty the app reports positions (batched, so a weak signal loses nothing). The last
 *   one is kept on the rider; every point goes to `rider_locations`, tagged with the order the
 *   rider is carrying, which gives each delivery's route.
 * - Arrival time (ETA) is worked out for orders in transit that have the customer's pin:
 *   Google Routes API (traffic-aware) with the server key when Maps is switched on, otherwise
 *   straight-line distance × 1.35 at the average city speed. It is refreshed at most once a
 *   minute per order (each refresh is one paid Google call).
 * - Delivery time = picked up → delivered (both recorded on the order).
 */
class RiderTracking
{
    public const TOKEN_DAYS = 30;

    public const ETA_REFRESH_SECONDS = 60;

    /** A rider seen within this many minutes counts as live on the map. */
    public const LIVE_MINUTES = 5;

    public function __construct(private Integrations $integrations) {}

    // ---------------------------------------------------------------- sign-in

    public function login(string $phone, string $password): Rider
    {
        $normalized = CustomerController::normalizePhone($phone) ?? trim($phone);
        $rider = Rider::whereIn('phone', array_unique([trim($phone), $normalized]))->first()
            ?? Rider::all(['id', 'phone'])->first(fn ($r) => CustomerController::normalizePhone((string) $r->phone) === $normalized)?->fresh();
        if (! $rider || ! $rider->password || ! Hash::check($password, $rider->password)) {
            throw ValidationException::withMessages(['phone' => 'The phone number or password is incorrect.']);
        }
        if ($rider->status !== 'active') {
            throw ValidationException::withMessages(['phone' => 'Your rider account is not active. Please contact the pharmacy.']);
        }
        $rider->update(['last_login_at' => now()]);

        return $rider;
    }

    public function token(Rider $rider, string $device): string
    {
        return $rider->createToken(mb_substr($device ?: 'rider-app', 0, 60), ['rider'], now()->addDays(self::TOKEN_DAYS))->plainTextToken;
    }

    // ---------------------------------------------------------------- positions

    /**
     * Stores the reported points (oldest first) and refreshes the arrival time of the
     * orders the rider is carrying.
     *
     * @param  array<int, array{lat: float, lng: float, accuracy?: ?float, heading?: ?float, speed?: ?float, recorded_at?: ?string}>  $points
     */
    public function record(Rider $rider, array $points): void
    {
        $points = collect($points)
            ->map(fn ($p) => $p + ['recorded_at' => null])
            ->map(function ($p) {
                // Phones send UTC ("…Z"); store in the app's timezone like every other timestamp
                // (a raw insert writes the wall-clock time as given, without converting).
                $at = $p['recorded_at'] ? Carbon::parse($p['recorded_at'])->setTimezone(config('app.timezone')) : now();

                // Phone clocks drift; never in the future, never more than a day back.
                return $p + ['at' => $at->isFuture() ? now() : ($at->lt(now()->subDay()) ? now()->subDay() : $at)];
            })
            ->sortBy('at')->values();
        if ($points->isEmpty()) {
            return;
        }
        $carrying = OnlineOrder::where('rider_id', $rider->id)->where('status', 'in_transit')->orderBy('picked_up_at')->pluck('id');

        DB::transaction(function () use ($rider, $points, $carrying) {
            foreach ($points->chunk(200) as $chunk) {
                RiderLocation::insert($chunk->map(fn ($p) => [
                    'rider_id' => $rider->id, 'online_order_id' => $carrying->first(),
                    'lat' => round((float) $p['lat'], 7), 'lng' => round((float) $p['lng'], 7),
                    'accuracy' => isset($p['accuracy']) ? min(65535, (int) round($p['accuracy'])) : null,
                    'heading' => isset($p['heading']) && $p['heading'] >= 0 ? ((int) round($p['heading'])) % 360 : null,
                    'speed' => isset($p['speed']) && $p['speed'] >= 0 ? min(9999, round((float) $p['speed'], 2)) : null,
                    'recorded_at' => $p['at'],
                ])->all());
            }
            $last = $points->last();
            // An older batch (sent late) must not move the rider back in time.
            if (! $rider->last_seen_at || $last['at']->gte($rider->last_seen_at)) {
                $rider->update([
                    'last_lat' => round((float) $last['lat'], 7), 'last_lng' => round((float) $last['lng'], 7),
                    'last_accuracy' => isset($last['accuracy']) ? min(65535, (int) round($last['accuracy'])) : null,
                    'last_heading' => isset($last['heading']) && $last['heading'] >= 0 ? ((int) round($last['heading'])) % 360 : null,
                    'last_speed' => isset($last['speed']) && $last['speed'] >= 0 ? min(9999, round((float) $last['speed'], 2)) : null,
                    'last_seen_at' => $last['at'], 'on_duty' => true,
                ]);
            }
        });

        foreach (OnlineOrder::whereIn('id', $carrying)->get() as $order) {
            $this->refreshEta($order, $rider->fresh());
        }
    }

    public function setDuty(Rider $rider, bool $onDuty): void
    {
        $rider->update(['on_duty' => $onDuty]);
    }

    // ---------------------------------------------------------------- arrival time

    /** Recomputes the order's ETA from the rider's last position (at most once a minute unless forced). */
    public function refreshEta(OnlineOrder $order, ?Rider $rider = null, bool $force = false): OnlineOrder
    {
        $rider ??= $order->rider;
        if ($order->status !== 'in_transit' || $order->latitude === null || ! $rider || $rider->last_lat === null) {
            return $order;
        }
        if (! $force && $order->eta_updated_at && $order->eta_updated_at->gt(now()->subSeconds(self::ETA_REFRESH_SECONDS))) {
            return $order;
        }
        [$seconds, $metres, $source] = $this->route($rider->last_lat, $rider->last_lng, $order->latitude, $order->longitude);
        $from = $rider->last_seen_at && $rider->last_seen_at->lt(now()) ? $rider->last_seen_at : now();
        $order->update([
            'eta_seconds' => $seconds, 'eta_distance_m' => $metres, 'eta_source' => $source,
            'eta_at' => $from->copy()->addSeconds($seconds), 'eta_updated_at' => now(),
        ]);

        return $order;
    }

    /**
     * Travel time and distance between two points.
     *
     * @return array{0: int, 1: int, 2: string} [seconds, metres, google|estimate]
     */
    public function route(float $fromLat, float $fromLng, float $toLat, float $toLng): array
    {
        $maps = $this->integrations->config('maps');
        if (($maps['enabled'] ?? false) && ! empty($maps['serverKey'])) {
            foreach (['TWO_WHEELER', 'DRIVE'] as $mode) {
                try {
                    $res = Http::timeout(6)->withHeaders([
                        'X-Goog-Api-Key' => $maps['serverKey'], 'X-Goog-FieldMask' => 'routes.duration,routes.distanceMeters',
                    ])->post('https://routes.googleapis.com/directions/v2:computeRoutes', [
                        'origin' => ['location' => ['latLng' => ['latitude' => $fromLat, 'longitude' => $fromLng]]],
                        'destination' => ['location' => ['latLng' => ['latitude' => $toLat, 'longitude' => $toLng]]],
                        'travelMode' => $mode, 'routingPreference' => 'TRAFFIC_AWARE',
                    ]);
                    $route = $res->successful() ? ($res->json('routes.0') ?? null) : null;
                    if ($route && isset($route['duration'])) {
                        return [(int) rtrim((string) $route['duration'], 's'), (int) ($route['distanceMeters'] ?? 0), 'google'];
                    }
                    // Two-wheeler routing is not offered everywhere: try driving before giving up.
                } catch (Throwable $e) {
                    Log::warning('Google Routes failed: '.$e->getMessage());
                    break;
                }
            }
        }
        $metres = (int) round(self::distance($fromLat, $fromLng, $toLat, $toLng) * 1.35);
        $speed = max(5.0, (float) ($maps['averageSpeedKmh'] ?? 18)) / 3.6;

        return [(int) round($metres / $speed), $metres, 'estimate'];
    }

    /** Great-circle distance in metres. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * $r * asin(min(1, sqrt($a)));
    }

    // ---------------------------------------------------------------- shapes

    /** The branch's map position (the "store" marker). */
    public static function store(OnlineOrder $o): ?array
    {
        $w = Warehouse::find($o->warehouse_id);

        return $w && $w->latitude !== null ? ['name' => $w->name, 'address' => $w->address, 'lat' => $w->latitude, 'lng' => $w->longitude] : null;
    }

    public static function riderPosition(?Rider $r): ?array
    {
        if (! $r || $r->last_lat === null) {
            return null;
        }

        return [
            'lat' => $r->last_lat, 'lng' => $r->last_lng, 'heading' => $r->last_heading, 'accuracy' => $r->last_accuracy,
            'updated_at' => $r->last_seen_at?->toIso8601String(),
            'is_live' => (bool) ($r->last_seen_at && $r->last_seen_at->gt(now()->subMinutes(self::LIVE_MINUTES))),
        ];
    }

    public static function eta(OnlineOrder $o): ?array
    {
        if ($o->status !== 'in_transit' || ! $o->eta_at) {
            return null;
        }

        return [
            'at' => $o->eta_at->toIso8601String(), 'seconds' => $o->eta_seconds,
            'minutes' => (int) max(1, ceil(max(0, now()->diffInSeconds($o->eta_at, false)) / 60)),
            'distance_m' => $o->eta_distance_m, 'source' => $o->eta_source, 'updated_at' => $o->eta_updated_at?->toIso8601String(),
        ];
    }

    /** Minutes from pickup to delivery, or null while unknown. */
    public static function deliveryMinutes(OnlineOrder $o): ?int
    {
        return $o->picked_up_at && $o->delivered_at ? (int) round($o->picked_up_at->diffInSeconds($o->delivered_at) / 60) : null;
    }

    /**
     * Live block for the customer (website / app). The rider's position is shown only while the
     * parcel is on its way; the customer's own pin only to the signed-in owner.
     */
    public static function live(OnlineOrder $o, bool $includeCustomerPin): array
    {
        $o->loadMissing('rider');
        $moving = $o->status === 'in_transit';

        return [
            'status' => $o->status,
            'store' => self::store($o),
            'customer' => $includeCustomerPin && $o->latitude !== null ? ['lat' => $o->latitude, 'lng' => $o->longitude] : null,
            'rider' => $moving && $o->rider ? [
                'name' => $o->rider->name, 'phone' => $includeCustomerPin ? $o->rider->phone : null, 'position' => self::riderPosition($o->rider),
            ] : null,
            'eta' => self::eta($o),
            'picked_up_at' => $o->picked_up_at?->toIso8601String(), 'delivered_at' => $o->delivered_at?->toIso8601String(),
            'delivery_minutes' => self::deliveryMinutes($o),
            'poll_seconds' => $moving ? 15 : null,
        ];
    }
}
