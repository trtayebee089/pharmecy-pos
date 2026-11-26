import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';

import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';

/// Map centre when nothing better is known (Dhaka).
const LatLng kDefaultMapCenter = LatLng(23.8103, 90.4125);

/// The server accepts pins inside Bangladesh only.
bool isInsideBangladesh(LatLng p) =>
    p.latitude >= 20.3 &&
    p.latitude <= 26.8 &&
    p.longitude >= 88.0 &&
    p.longitude <= 92.8;

/// ─────────────────────────────────────────────────────────────────────────
/// Full-screen "pin your exact location" picker.
///
/// The pin is fixed in the middle of the screen and the customer drags the
/// map underneath it — far easier on a phone than dragging a tiny marker.
/// Pops with the chosen [LatLng], or null when the customer backs out.
/// ─────────────────────────────────────────────────────────────────────────
class LocationPickerScreen extends StatefulWidget {
  /// The previously pinned point, when changing an existing pin.
  final LatLng? initial;

  const LocationPickerScreen({super.key, this.initial});

  @override
  State<LocationPickerScreen> createState() => _LocationPickerScreenState();
}

class _LocationPickerScreenState extends State<LocationPickerScreen> {
  GoogleMapController? _map;
  late LatLng _center;
  bool _moving = false;
  bool _locating = false;
  bool _hasPermission = false;

  @override
  void initState() {
    super.initState();
    _center = widget.initial ?? kDefaultMapCenter;
    if (widget.initial == null) {
      // Centre on the customer once the first frame is up (a dialog may be
      // shown to explain the permission first).
      WidgetsBinding.instance.addPostFrameCallback((_) => _locate(quiet: true));
    } else {
      _checkPermissionSilently();
    }
  }

  Future<void> _checkPermissionSilently() async {
    try {
      final p = await Geolocator.checkPermission();
      if (!mounted) return;
      setState(
        () => _hasPermission =
            p == LocationPermission.always ||
            p == LocationPermission.whileInUse,
      );
    } catch (_) {}
  }

  /// Explains why we ask before the system prompt appears.
  Future<bool> _askPolitely() async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Use your location?'),
        content: const Text(
          'We use it only to centre the map on where you are, so pinning '
          'your door is quick. You can also just drag the map.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Not now'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Allow'),
          ),
        ],
      ),
    );
    return ok ?? false;
  }

  /// Moves the map to the device position. [quiet] is the automatic attempt
  /// on opening: it never nags with errors, only the explicit button does.
  Future<void> _locate({bool quiet = false}) async {
    if (_locating) return;
    setState(() => _locating = true);
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        if (!quiet && mounted) {
          DsToast.info(
            context,
            'Location is turned off',
            detail: 'Turn on location, or drag the map to your door.',
          );
        }
        return;
      }

      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied) {
        if (!mounted || !await _askPolitely()) return;
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.deniedForever) {
        if (!quiet && mounted) {
          DsToast.info(
            context,
            'Location permission is off',
            detail: 'Allow it in Settings, or drag the map to your door.',
          );
          await Geolocator.openAppSettings();
        }
        return;
      }
      if (permission != LocationPermission.always &&
          permission != LocationPermission.whileInUse) {
        return;
      }
      if (mounted) setState(() => _hasPermission = true);

      // A recent fix moves the map at once; the precise one follows.
      final last = await Geolocator.getLastKnownPosition();
      if (last != null) _moveTo(LatLng(last.latitude, last.longitude));

      final pos = await Geolocator.getCurrentPosition(
        locationSettings: const LocationSettings(
          accuracy: LocationAccuracy.high,
          timeLimit: Duration(seconds: 15),
        ),
      );
      _moveTo(LatLng(pos.latitude, pos.longitude));
    } catch (e) {
      debugPrint('Location error: $e');
      if (!quiet && mounted) {
        DsToast.warning(
          context,
          'Could not find your location',
          detail: 'Drag the map to your door instead.',
        );
      }
    } finally {
      if (mounted) setState(() => _locating = false);
    }
  }

  void _moveTo(LatLng p) {
    if (!mounted) return;
    setState(() => _center = p);
    _map?.animateCamera(CameraUpdate.newLatLngZoom(p, 17));
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final valid = isInsideBangladesh(_center);

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: Stack(
        children: [
          Positioned.fill(
            child: GoogleMap(
              initialCameraPosition: CameraPosition(target: _center, zoom: 16),
              onMapCreated: (c) => _map = c,
              onCameraMoveStarted: () => setState(() => _moving = true),
              onCameraMove: (p) => _center = p.target,
              onCameraIdle: () {
                if (mounted) setState(() => _moving = false);
              },
              myLocationEnabled: _hasPermission,
              myLocationButtonEnabled: false,
              zoomControlsEnabled: false,
              mapToolbarEnabled: false,
              compassEnabled: false,
            ),
          ),

          // Fixed centre pin: its tip marks the map centre.
          IgnorePointer(
            child: Center(
              child: Padding(
                padding: const EdgeInsets.only(bottom: 46),
                child: AnimatedSlide(
                  duration: DsMotion.quick,
                  curve: DsMotion.ease,
                  offset: _moving ? const Offset(0, -0.18) : Offset.zero,
                  child: const Icon(
                    Icons.location_on_rounded,
                    size: 48,
                    color: DsColor.brand,
                    shadows: [
                      Shadow(
                        color: Color(0x55000000),
                        blurRadius: 8,
                        offset: Offset(0, 3),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),

          // Top bar.
          SafeArea(
            child: Padding(
              padding: const EdgeInsets.all(DsSpace.x4),
              child: Row(
                children: [
                  DsIconDisc(
                    icon: Icons.arrow_back_rounded,
                    tooltip: 'Back',
                    background: DsColor.surface,
                    shadow: DsShadow.mid,
                    onTap: () => Navigator.pop(context),
                  ),
                  const SizedBox(width: DsSpace.x3),
                  Expanded(
                    child: DsSurface(
                      shadow: DsShadow.mid,
                      radius: DsRadius.pill,
                      padding: const EdgeInsets.symmetric(
                        horizontal: DsSpace.x4,
                        vertical: DsSpace.x3,
                      ),
                      child: Text(
                        'Drag the map to put the pin on your door',
                        maxLines: 2,
                        style: text.bodyMedium?.copyWith(color: DsColor.ink),
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ),

          // Bottom sheet with the actions.
          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: Container(
              decoration: BoxDecoration(
                color: DsColor.surface,
                borderRadius: DsRadius.sheet,
                boxShadow: DsShadow.high,
              ),
              child: SafeArea(
                top: false,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(
                    DsSpace.x5,
                    DsSpace.x5,
                    DsSpace.x5,
                    DsSpace.x4,
                  ),
                  child: Column(
                    mainAxisSize: MainAxisSize.min,
                    crossAxisAlignment: CrossAxisAlignment.stretch,
                    children: [
                      Text(
                        'DELIVERY PIN',
                        style: DsType.eyebrow(color: DsColor.primary),
                      ),
                      const SizedBox(height: DsSpace.x1),
                      Text(
                        valid
                            ? 'The rider will come to this point'
                            : 'We deliver inside Bangladesh only',
                        style: text.titleMedium,
                      ),
                      const SizedBox(height: DsSpace.x1),
                      Text(
                        '${_center.latitude.toStringAsFixed(5)}, '
                        '${_center.longitude.toStringAsFixed(5)}',
                        style: DsType.numeral(
                          12,
                          weight: FontWeight.w500,
                          color: DsColor.inkSoft,
                        ),
                      ),
                      const SizedBox(height: DsSpace.x4),
                      Row(
                        children: [
                          Expanded(
                            child: OutlinedButton.icon(
                              onPressed: _locating ? null : () => _locate(),
                              icon: _locating
                                  ? const SizedBox.square(
                                      dimension: 16,
                                      child: CircularProgressIndicator(
                                        strokeWidth: 2,
                                      ),
                                    )
                                  : const Icon(
                                      Icons.my_location_rounded,
                                      size: 18,
                                    ),
                              label: const Text('Use my location'),
                            ),
                          ),
                          const SizedBox(width: DsSpace.x3),
                          Expanded(
                            child: ElevatedButton.icon(
                              onPressed: valid && !_moving
                                  ? () => Navigator.pop(context, _center)
                                  : null,
                              icon: const Icon(Icons.check_rounded, size: 18),
                              label: const Text('Confirm'),
                            ),
                          ),
                        ],
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
