# Rider app and live delivery tracking

Owner decision 26 Nov 2026: in-house riders deliver inside the city with their own app
(`rider-app/`, Flutter, same stack as the customer app `app/`). Maps are **Google Maps**.

What it gives:

- **Rider app** — sign in (phone + password set by staff), go on/off duty, live position
  reporting, list of my assigned orders, a map with **three markers** (store, customer, me),
  "Navigate" (opens Google Maps), call the customer, **Picked up** and **Delivered** (with the
  exact cash to collect).
- **Customers** (website and app) drop a pin at checkout (exact location) and, while the
  parcel is on its way, see the rider on a map with an **estimated time of arrival**.
- **Staff** (POS) see a live map of riders and open orders, each rider's average delivery
  time (picked up → delivered) and the route of each delivery.

## Database (`api/database/migrations/2026_11_26_120000_add_rider_tracking.php`)

| Table | New columns |
|---|---|
| `warehouses` | `latitude`, `longitude` — the branch's map position (the **store** marker). Set in Administration › Branches. |
| `riders` | `password` (hashed, null = no app access), `last_login_at`, `on_duty`, `last_lat`, `last_lng`, `last_accuracy` (m), `last_heading` (°), `last_speed` (m/s), `last_seen_at` |
| `online_orders` | `latitude`, `longitude` (customer's pin), `picked_up_at`, `eta_at`, `eta_seconds`, `eta_distance_m`, `eta_source` (`google`/`estimate`), `eta_updated_at` |
| `rider_locations` (new) | `rider_id`, `online_order_id` (the order being carried, if any), `lat`, `lng`, `accuracy`, `heading`, `speed`, `recorded_at` |

Delivery time = `picked_up_at` → `delivered_at`.

## Arrival time (ETA)

`App\Services\RiderTracking`. For an order **in transit** with a customer pin and a rider
position: Google **Routes API** (`computeRoutes`, `TWO_WHEELER` then `DRIVE`, traffic-aware)
with the server key; without a key, straight-line distance × 1.35 at the average city speed
(default 18 km/h). Refreshed at most once a minute per order (each refresh is one paid call),
when the rider reports a position, picks up, or a customer polls the live endpoint.

## Settings: Administration › Integrations › Maps (`integration.maps`)

| Field | Meaning |
|---|---|
| `enabled` | Maps on/off |
| `browserKey` | **Public** key for Maps JavaScript API / Maps SDK for Android & iOS. Restrict it in Google Cloud (HTTP referrers for the website and POS, Android package + SHA-1 for the apps). Sent to the website in `GET /api/v1/site-config` → `data.maps`. |
| `serverKey` | **Secret** (write-only) key for the Routes API, used only by the server. |
| `defaultLat`, `defaultLng` | Map centre when nothing else is known (Dhaka: 23.8103, 90.4125) |
| `averageSpeedKmh` | Speed for the estimate when Google is not used |

Google Cloud APIs to enable: Maps JavaScript API, Maps SDK for Android, Maps SDK for iOS,
Routes API (and Geocoding API if the address should be filled from the pin).

The **mobile apps** take their Maps SDK key at build time (Android manifest meta-data
`com.google.android.geo.API_KEY` from `local.properties` → `MAPS_API_KEY`; iOS `AppDelegate`).

## Rider API — `/api/rider/v1` (bearer token, ability `rider`)

Envelope `{success, message, data}`. Validation errors are Laravel's 422 `{message, errors}`.
401 `{success:false, message:"Please sign in."}` means the token is gone (signed out, password
changed, rider deactivated) — go back to the login screen.

| Method & path | Body | `data` |
|---|---|---|
| `POST login` | `phone`, `password`, `device_name?` | `{token, rider: Profile}` |
| `POST logout` | – | null (also sets off duty) |
| `GET me` | – | `Profile` |
| `PUT duty` | `on_duty: bool` | `Profile` |
| `POST locations` | `points: [{lat, lng, accuracy?, heading?, speed?, recorded_at? (ISO 8601)}]` (1–500, oldest first) | `{received, on_duty}` |
| `GET orders?scope=active\|done` | – | `RiderOrder[]` (active: confirmed / packed / in transit, in-transit first; done: last 30 days) |
| `GET orders/{id}` | – | `RiderOrder` |
| `POST orders/{id}/pickup` | – | `RiderOrder` (confirmed → packed → in transit; sets on duty) |
| `POST orders/{id}/deliver` | `cash_collected?` (must equal `cod_amount`), `note?` | `RiderOrder` |

```jsonc
// Profile
{ "id": 3, "name": "Rasel", "phone": "01711000001", "on_duty": true, "cash_held": 100.0,
  "today": { "delivered": 4, "avg_minutes": 22 }, "active_orders": 2,
  "tracking": { "interval_seconds": 15, "distance_filter_m": 25 },
  "maps": { "default_lat": 23.8103, "default_lng": 90.4125 } }

// RiderOrder
{ "id": 41, "number": "OO-DHK1-2026-00041", "status": "in_transit", "placed_at": "…",
  "customer": { "name": "…", "phone": "01812345678", "address": "House 1, Road 2", "area": "Dhanmondi, Dhaka" },
  "note": null, "cod_amount": 100.0, "payment_method": "cod", "payment_status": "cod",
  "items": [{ "name": "Napa 500mg", "qty": 20.0 }],
  "markers": {
    "store":    { "name": "Dhanmondi branch", "address": "…", "lat": 23.7465, "lng": 90.376 } | null,
    "customer": { "lat": 23.7509, "lng": 90.3935 } | null,
    "rider":    { "lat": …, "lng": …, "heading": 90, "accuracy": 8, "updated_at": "…", "is_live": true } | null
  },
  "eta": { "at": "…", "seconds": 754, "minutes": 12, "distance_m": 3120, "source": "google", "updated_at": "…" } | null,
  "picked_up_at": "…" | null, "delivered_at": null, "delivery_minutes": null,
  "can_pickup": false, "can_deliver": true }
```

The app should report positions every `tracking.interval_seconds` / `distance_filter_m` while
on duty (Android foreground service so it keeps going with the screen off), buffer points
while offline and send them in one batch later.

## Customer API additions — `/api/v1`

- `POST checkout`: optional `customer.latitude`, `customer.longitude` (both or neither; Bangladesh
  only: lat 20.3–26.8, lng 88.0–92.8).
- `GET site-config` → `data.maps = { enabled, browser_key, default_center: {lat, lng} }`.
- Every order (`orders`, `orders/{number}`, `tracking/{code}`, checkout response) now has
  `picked_up_at`, `delivery_minutes` and `live` (below); owner views also have
  `delivery.latitude` / `delivery.longitude`.
- `GET orders/{number}/live` (owner, bearer) and `GET tracking/{code}/live` (public) return `Live`:

```jsonc
// Live
{ "status": "in_transit",
  "store":    { "name": "…", "address": "…", "lat": 23.7465, "lng": 90.376 } | null,
  "customer": { "lat": 23.7509, "lng": 90.3935 } | null,   // owner only, never on public tracking
  "rider":    { "name": "Rasel", "phone": "017…" | null /* owner only */, "position": { "lat": …, "lng": …, "heading": …, "accuracy": …, "updated_at": "…", "is_live": true } | null } | null, // only while in_transit
  "eta":      { "at": "…", "seconds": 754, "minutes": 12, "distance_m": 3120, "source": "google" | "estimate", "updated_at": "…" } | null,
  "picked_up_at": "…" | null, "delivered_at": "…" | null, "delivery_minutes": 15 | null,
  "poll_seconds": 15 | null }   // poll again after this many seconds; null = stop polling
```

## POS (staff) API additions — `/api/pos/v1`

- `PUT delivery/riders/{id}` / `POST delivery/riders`: optional `password` (min 6, sets app
  access), `revokeAppAccess: true`. A new password, revoked access or a non-active status signs
  the rider app out. Rider shape adds `appAccess`, `lastLoginAt`, `onDuty`, `position` (as above),
  `deliveries30d`, `avgDeliveryMinutes30d`.
- Orders add `latitude`, `longitude`, `pickedUpAt`, `deliveredAt`, `deliveryMinutes`, `eta`.
- `GET delivery/live` (`orders.view`): `{stores: [{id,name,address,lat,lng}], riders: [{id,name,phone,onDuty,position,orders:[number]}], orders: [{id,number,status,customer,address,lat,lng,riderId,riderName,pickedUpAt,eta}], maps: {enabled, browserKey, defaultLat, defaultLng, averageSpeedKmh, serverKeySet}}`.
- `GET orders/{id}/route` (`orders.view`): `{number, store, customer, pickedUpAt, deliveredAt, deliveryMinutes, points: [{lat,lng,at}]}`.
- Warehouses (`admin/warehouses`) take and return `latitude`, `longitude`.
- Integrations: group `maps` (see Settings).

Real-time is by polling (customers every 15 s while in transit, the POS live map every 15–20 s);
no websocket server is needed.
