# RedPharma Rider

Flutter app for RedPharmaBD's in-house delivery riders. It talks to the Laravel
rider API (`/api/rider/v1`, see `../docs/rider-app-and-live-tracking.md`).

## What it does

- **Sign in** with the phone number and password staff set in POS
  (Delivery › Riders). A 401 anywhere (signed out, password changed, rider
  deactivated) drops the session and returns to the login screen.
- **Home**: on/off duty switch, today's stats (delivered, average delivery
  minutes, cash held), **Active** orders and **Done** orders (last 30 days),
  both with pull-to-refresh.
- **Order**: Google Map with three markers (red **Store**, green
  **Customer**, blue **Me**) and a legend, fitted to all three. Customer
  name, address and area, items, note, the **cash to collect**, and the
  arrival time while in transit. Buttons: **Call** (phone dialer),
  **Navigate** (Google Maps directions to the customer's pin, or to the
  address text when there is no pin), **Picked up** and **Delivered** (a
  confirmation shows the exact cash; the app sends
  `cash_collected = cod_amount` and shows the server's message if it is
  rejected).
- **Live location** while on duty: positions every
  `tracking.interval_seconds` / `distance_filter_m` from the profile,
  buffered in memory and in shared preferences, sent to `POST locations` in
  batches of up to 500 and kept for a retry when offline. On Android it runs
  in geolocator's foreground service (an ongoing "You are on duty"
  notification and a wake lock), so it keeps reporting with the screen off.
  Reporting stops when the rider goes off duty or signs out.

## Packages

| Package | Why |
|---|---|
| `provider`, `http`, `shared_preferences`, `google_fonts`, `intl`, `url_launcher` | Same stack as the customer app (`../app`). |
| `google_maps_flutter` | The order map (Maps SDK for Android / iOS). |
| `geolocator` | Permissions, position stream and, through `AndroidSettings.foregroundNotificationConfig`, the Android foreground service. No extra background-task plugin is needed. |

The design tokens, typography and theme in `lib/design/` are copied from the
customer app, so both apps look like the same company.

## API address

The default is the production API, the same host as the customer app:
`https://api.redpharmabd.com/api`. Point it elsewhere at build time:

```sh
# Android emulator -> Laravel on this PC
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
# Phone on the same Wi-Fi
flutter run --dart-define=API_BASE_URL=http://192.168.0.10:8000/api
```

Plain `http://` is allowed in Android debug builds only
(`android/app/src/debug/AndroidManifest.xml`); release builds need HTTPS.

## Google Maps keys

### Google Cloud

In the Google Cloud project used for RedPharma maps, enable:

- **Maps SDK for Android**
- **Maps SDK for iOS**
- Maps JavaScript API and Routes API are used by the website, POS and the
  server (arrival time); the rider app itself only needs the two SDKs.

Create an API key for the apps and restrict it: **Android apps** (package
`com.zonhack.redpharmabd.rider` + the SHA-1 of your debug and release
signing certificates) and **iOS apps** (the bundle identifier). The key the
POS calls `browserKey` can be reused if it carries these restrictions too.

### Android

Put the key in `android/local.properties` (created by Flutter, git-ignored):

```properties
flutter.sdk=C:\\flutter
MAPS_API_KEY=AIza...
```

`android/app/build.gradle.kts` reads it (or the `MAPS_API_KEY` environment
variable on CI) into the manifest placeholder used by the
`com.google.android.geo.API_KEY` meta-data. Without a key the map shows
grey tiles; everything else works.

### iOS

Copy `ios/Flutter/Secrets.xcconfig.example` to `ios/Flutter/Secrets.xcconfig`
(git-ignored) and set `MAPS_API_KEY`. `Debug.xcconfig` / `Release.xcconfig`
include it, `Info.plist` exposes it as `GMSApiKey`, and `AppDelegate.swift`
passes it to `GMSServices.provideAPIKey`.

## First-time setup

The repository holds the Dart code and the hand-written platform files
(Android Gradle/manifest, iOS `Info.plist`, `AppDelegate.swift`, xcconfigs).
Generate the rest once with Flutter:

```sh
cd rider-app
flutter pub get
# iOS only (macOS): creates Runner.xcodeproj, storyboards, Podfile, icons.
# Existing files (Info.plist, AppDelegate.swift, xcconfigs) are kept.
flutter create --platforms=ios --org com.zonhack.redpharmabd --project-name redpharma_rider .
```

After `flutter create` for iOS, open `ios/Runner.xcworkspace` in Xcode and
set the bundle identifier to `com.zonhack.redpharmabd.rider`, the team, and
under *Signing & Capabilities* → *Background Modes* make sure **Location
updates** is ticked (Info.plist already lists it). Raise the iOS deployment
target to what the Google Maps SDK pod asks for (14.0 or newer; recent
versions need 15.0) in Xcode and in the `Podfile` (`platform :ios, '15.0'`).

Do **not** run `flutter create` for Android: the `android/` folder is complete
(Flutter downloads the Gradle wrapper on the first build).

## Run and build

```sh
flutter run                                   # debug, production API
flutter run --dart-define=API_BASE_URL=...    # another API
flutter analyze
flutter test

# Android release (signed with android/key.properties when present,
# otherwise with the debug key)
flutter build apk --release --dart-define=API_BASE_URL=https://api.redpharmabd.com/api
flutter build appbundle --release

# iOS (macOS)
flutter build ipa --release
```

Release signing on Android: create `android/key.properties`
(`storeFile`, `storePassword`, `keyAlias`, `keyPassword`), same format as
the customer app. Never commit it or the keystore.

## Location on Android phones

- The app asks for "While using the app" first, then explains and asks once
  for "Allow all the time". Reporting works with either, because it runs in
  a foreground service started while the app is open.
- On Android 13+ the system may hide the "on duty" notification unless
  notifications are allowed for the app; the service still runs. Riders
  should allow notifications so they can see that sharing is on.
- Some brands (Xiaomi, Oppo, Vivo, Realme, Huawei) kill background apps
  aggressively. On those phones set *Battery* → *No restrictions* /
  *Autostart* for RedPharma Rider.
