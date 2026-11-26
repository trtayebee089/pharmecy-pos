import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

const String _askedBackgroundKey = 'asked_background_location';

/// Walks the rider through location access before going on duty:
///
/// 1. GPS must be on.
/// 2. "While using the app" (explained first, then the system prompt).
/// 3. "Allow all the time", explained and asked once per install. Not
///    required: on Android the on-duty foreground service keeps reporting
///    with only "while using", but some phones are stricter without it.
///
/// Returns true when the app can read the position.
Future<bool> ensureLocationPermission(BuildContext context) async {
  if (!await Geolocator.isLocationServiceEnabled()) {
    if (!context.mounted) return false;
    final open = await _explain(
      context,
      icon: Icons.location_off_rounded,
      title: 'Turn on location',
      body:
          'Your phone\'s location (GPS) is off. Turn it on so the pharmacy and '
          'your customers can see where their order is.',
      action: 'Open settings',
    );
    if (open) await Geolocator.openLocationSettings();
    return false;
  }

  var permission = await Geolocator.checkPermission();

  if (permission == LocationPermission.denied) {
    if (!context.mounted) return false;
    final go = await _explain(
      context,
      icon: Icons.my_location_rounded,
      title: 'Share your location',
      body:
          'While you are on duty, RedPharma Rider sends your position to the '
          'pharmacy every few seconds. Customers see you on the map with an '
          'arrival time, and staff can assign orders near you.\n\n'
          'Sharing stops as soon as you go off duty or sign out.',
      action: 'Continue',
    );
    if (!go) return false;
    permission = await Geolocator.requestPermission();
  }

  if (permission == LocationPermission.deniedForever) {
    if (!context.mounted) return false;
    final open = await _explain(
      context,
      icon: Icons.lock_outline_rounded,
      title: 'Location is blocked',
      body:
          'Location access was turned off for RedPharma Rider. Open the app '
          'settings, tap Permissions › Location and choose "Allow all the '
          'time" (or "While using the app").',
      action: 'Open app settings',
    );
    if (open) await Geolocator.openAppSettings();
    return false;
  }

  if (permission == LocationPermission.denied ||
      permission == LocationPermission.unableToDetermine) {
    return false;
  }

  if (permission == LocationPermission.whileInUse) {
    final prefs = await SharedPreferences.getInstance();
    final asked = prefs.getBool(_askedBackgroundKey) ?? false;
    if (!asked) {
      await prefs.setBool(_askedBackgroundKey, true);
      if (!context.mounted) return true;
      final go = await _explain(
        context,
        icon: Icons.screen_lock_portrait_rounded,
        title: 'Keep sharing with the screen off',
        body:
            'You will ride with the phone in your pocket. To keep your '
            'position updating with the screen off, choose "Allow all the '
            'time" on the next screen.\n\n'
            'RedPharma Rider only uses your location while you are on duty, '
            'and shows a notification the whole time.',
        action: 'Continue',
        cancel: 'Not now',
      );
      if (go) {
        // On Android 10+ geolocator now asks for ACCESS_BACKGROUND_LOCATION
        // (Android 11+ opens the settings page); on iOS it asks for "Always".
        await Geolocator.requestPermission();
      }
    }
    return true;
  }

  return permission == LocationPermission.always;
}

Future<bool> _explain(
  BuildContext context, {
  required IconData icon,
  required String title,
  required String body,
  required String action,
  String cancel = 'Cancel',
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) => AlertDialog(
      icon: Icon(icon, size: 36),
      title: Text(title, textAlign: TextAlign.center),
      content: Text(body),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(ctx).pop(false),
          child: Text(cancel),
        ),
        TextButton(
          onPressed: () => Navigator.of(ctx).pop(true),
          child: Text(action),
        ),
      ],
    ),
  );
  return result ?? false;
}
