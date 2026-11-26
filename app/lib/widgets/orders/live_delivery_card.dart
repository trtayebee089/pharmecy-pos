import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:http/http.dart' as http;
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Live delivery card.
///
/// Loads `GET /api/v1/orders/{number}/live` (owner, bearer) — or the public
/// `tracking/{code}/live` when only a tracking code is known — and, while the
/// parcel is in transit, shows the store, the customer's pin and the rider on
/// a Google Map with the arrival time. It polls again after `poll_seconds`
/// and stops when the server sends null or the card leaves the screen.
///
/// Renders nothing until there is something live to show (in transit, or a
/// delivery time once delivered), so it is safe to drop onto any order view.
/// ─────────────────────────────────────────────────────────────────────────
class LiveDeliveryCard extends StatefulWidget {
  final String? orderNumber;
  final String? trackingCode;
  final String? token;

  /// Called once when a delivery the card was following is delivered, so the
  /// host can refresh its own order data.
  final VoidCallback? onDelivered;

  const LiveDeliveryCard({
    super.key,
    this.orderNumber,
    this.trackingCode,
    this.token,
    this.onDelivered,
  }) : assert(orderNumber != null || trackingCode != null);

  @override
  State<LiveDeliveryCard> createState() => _LiveDeliveryCardState();
}

class _LiveDeliveryCardState extends State<LiveDeliveryCard> {
  Map<String, dynamic>? _live;
  Timer? _poll;
  GoogleMapController? _map;
  bool _disposed = false;
  bool _wasInTransit = false;
  bool _fittedOnce = false;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _disposed = true;
    _poll?.cancel();
    super.dispose();
  }

  Uri get _uri {
    final number = widget.orderNumber;
    final token = widget.token;
    if (number != null && token != null && token.isNotEmpty) {
      return Uri.parse(ApiEndpoints.orderLive(number));
    }
    return Uri.parse(ApiEndpoints.trackingLive(widget.trackingCode ?? ''));
  }

  Future<void> _load() async {
    _poll?.cancel();
    int? next;
    try {
      final token = widget.token;
      final res = await http.get(
        _uri,
        headers: {
          'Accept': 'application/json',
          if (token != null && token.isNotEmpty)
            'Authorization': 'Bearer $token',
        },
      );
      if (_disposed) return;
      if (res.statusCode == 200) {
        final body = json.decode(res.body);
        if (body is Map && body['success'] == true && body['data'] is Map) {
          final live = Map<String, dynamic>.from(body['data'] as Map);
          final status = live['status']?.toString();
          if (_wasInTransit && status == 'delivered') {
            widget.onDelivered?.call();
          }
          _wasInTransit = status == 'in_transit';
          setState(() => _live = live);
          _fit();
          next = _int(live['poll_seconds']);
        }
      } else if (res.statusCode >= 500 || res.statusCode == 429) {
        // Transient: try again shortly while we were following a delivery.
        if (_wasInTransit) next = 30;
      }
    } catch (e) {
      debugPrint('Live delivery error: $e');
      if (_wasInTransit) next = 30;
    }
    if (_disposed || next == null || next <= 0) return;
    _poll = Timer(Duration(seconds: next.clamp(5, 300).toInt()), _load);
  }

  // ── Parsing helpers ──────────────────────────────────────────────────────

  static int? _int(dynamic v) =>
      v is num ? v.toInt() : int.tryParse('${v ?? ''}');

  static double? _double(dynamic v) =>
      v is num ? v.toDouble() : double.tryParse('${v ?? ''}');

  static LatLng? _point(dynamic m) {
    if (m is! Map) return null;
    final lat = _double(m['lat']);
    final lng = _double(m['lng']);
    if (lat == null || lng == null) return null;
    return LatLng(lat, lng);
  }

  Map<String, dynamic>? get _rider => _live?['rider'] is Map
      ? Map<String, dynamic>.from(_live!['rider'] as Map)
      : null;

  LatLng? get _storePoint => _point(_live?['store']);
  LatLng? get _customerPoint => _point(_live?['customer']);
  LatLng? get _riderPoint => _point(_rider?['position']);

  List<LatLng> get _points {
    final store = _storePoint;
    final customer = _customerPoint;
    final rider = _riderPoint;
    return [
      if (store != null) store,
      if (customer != null) customer,
      if (rider != null) rider,
    ];
  }

  // ── Map ──────────────────────────────────────────────────────────────────

  Set<Marker> _markers() {
    final store = _storePoint;
    final customer = _customerPoint;
    final rider = _riderPoint;
    final riderName = _rider?['name']?.toString();

    return {
      if (store != null)
        Marker(
          markerId: const MarkerId('store'),
          position: store,
          icon: BitmapDescriptor.defaultMarkerWithHue(
            BitmapDescriptor.hueGreen,
          ),
          infoWindow: InfoWindow(
            title: _live?['store']?['name']?.toString() ?? 'Store',
          ),
        ),
      if (customer != null)
        Marker(
          markerId: const MarkerId('customer'),
          position: customer,
          icon: BitmapDescriptor.defaultMarkerWithHue(BitmapDescriptor.hueRed),
          infoWindow: const InfoWindow(title: 'You'),
        ),
      if (rider != null)
        Marker(
          markerId: const MarkerId('rider'),
          position: rider,
          icon: BitmapDescriptor.defaultMarkerWithHue(
            BitmapDescriptor.hueAzure,
          ),
          infoWindow: InfoWindow(title: riderName ?? 'Rider'),
        ),
    };
  }

  /// Keeps every marker in view. Only re-fits automatically until the
  /// customer has the first fit; later polls just move the markers, so a
  /// customer who zoomed in is not yanked back out every 15 seconds.
  void _fit({bool force = false}) {
    final map = _map;
    final points = _points;
    if (map == null || points.isEmpty || (_fittedOnce && !force)) return;
    _fittedOnce = true;

    if (points.length == 1) {
      map.animateCamera(CameraUpdate.newLatLngZoom(points.first, 15));
      return;
    }
    var south = points.first.latitude, north = south;
    var west = points.first.longitude, east = west;
    for (final p in points) {
      if (p.latitude < south) south = p.latitude;
      if (p.latitude > north) north = p.latitude;
      if (p.longitude < west) west = p.longitude;
      if (p.longitude > east) east = p.longitude;
    }
    // Points on top of each other: a bounds of zero size cannot be fitted.
    if ((north - south).abs() < 0.0005 && (east - west).abs() < 0.0005) {
      map.animateCamera(CameraUpdate.newLatLngZoom(points.first, 16));
      return;
    }
    map.animateCamera(
      CameraUpdate.newLatLngBounds(
        LatLngBounds(
          southwest: LatLng(south, west),
          northeast: LatLng(north, east),
        ),
        48,
      ),
    );
  }

  // ── Actions ──────────────────────────────────────────────────────────────

  Future<void> _call(String phone) async {
    final uri = Uri(scheme: 'tel', path: phone.replaceAll(' ', ''));
    try {
      await launchUrl(uri);
    } catch (e) {
      debugPrint('Could not start a call: $e');
    }
  }

  // ── Composition ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final live = _live;
    if (live == null) return const SizedBox.shrink();

    final status = live['status']?.toString();
    if (status == 'in_transit') return _inTransit(live);

    final minutes = _int(live['delivery_minutes']);
    if (status == 'delivered' && minutes != null) return _delivered(minutes);

    return const SizedBox.shrink();
  }

  Widget _inTransit(Map<String, dynamic> live) {
    final text = Theme.of(context).textTheme;
    final eta = live['eta'] is Map
        ? Map<String, dynamic>.from(live['eta'] as Map)
        : null;
    final etaMinutes = _int(eta?['minutes']);
    final etaAt = DateTime.tryParse('${eta?['at'] ?? ''}')?.toLocal();
    final rider = _rider;
    final riderName = rider?['name']?.toString();
    final riderPhone = rider?['phone']?.toString();
    final points = _points;

    return Padding(
      padding: const EdgeInsets.only(bottom: DsSpace.x4),
      child: DsSurface(
        shadow: DsShadow.mid,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // ── Arrival headline ──
            Container(
              padding: const EdgeInsets.all(DsSpace.x5),
              decoration: const BoxDecoration(
                gradient: LinearGradient(
                  colors: [DsColor.obsidian, DsColor.primaryDeep],
                  begin: Alignment.topLeft,
                  end: Alignment.bottomRight,
                ),
              ),
              child: Row(
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Row(
                          children: [
                            const _PulseDot(),
                            const SizedBox(width: DsSpace.x2),
                            Text(
                              'ON THE WAY · LIVE',
                              style: DsType.eyebrow(
                                color: DsColor.primaryBright,
                              ),
                            ),
                          ],
                        ),
                        const SizedBox(height: DsSpace.x2),
                        Text(
                          etaMinutes != null
                              ? (etaMinutes <= 1
                                    ? 'Arriving now'
                                    : 'Arriving in ~$etaMinutes min')
                              : 'Your rider is on the way',
                          style: text.headlineMedium?.copyWith(
                            color: DsColor.onDark,
                          ),
                        ),
                        if (etaAt != null) ...[
                          const SizedBox(height: DsSpace.x1),
                          Text(
                            'Expected around ${DateFormat('h:mm a').format(etaAt)}',
                            style: text.bodySmall?.copyWith(
                              color: DsColor.onDark.withValues(alpha: 0.65),
                            ),
                          ),
                        ],
                      ],
                    ),
                  ),
                  const Icon(
                    Icons.delivery_dining_rounded,
                    size: 40,
                    color: DsColor.primaryBright,
                  ),
                ],
              ),
            ),

            // ── Map ──
            if (points.isNotEmpty)
              SizedBox(
                height: 240,
                child: Stack(
                  children: [
                    Positioned.fill(
                      child: GoogleMap(
                        initialCameraPosition: CameraPosition(
                          target: _riderPoint ?? points.first,
                          zoom: 14,
                        ),
                        markers: _markers(),
                        onMapCreated: (c) {
                          _map = c;
                          _fit();
                        },
                        // The card sits in a scrolling list: let the map have
                        // the gestures that start on it.
                        gestureRecognizers:
                            <Factory<OneSequenceGestureRecognizer>>{
                              Factory<OneSequenceGestureRecognizer>(
                                () => EagerGestureRecognizer(),
                              ),
                            },
                        zoomControlsEnabled: false,
                        mapToolbarEnabled: false,
                        myLocationButtonEnabled: false,
                        compassEnabled: false,
                      ),
                    ),
                    Positioned(
                      right: DsSpace.x3,
                      bottom: DsSpace.x3,
                      child: Material(
                        color: DsColor.surface,
                        shape: const CircleBorder(),
                        elevation: 2,
                        child: IconButton(
                          tooltip: 'Show everyone',
                          icon: const Icon(
                            Icons.center_focus_strong_rounded,
                            size: 20,
                          ),
                          onPressed: () => _fit(force: true),
                        ),
                      ),
                    ),
                  ],
                ),
              ),

            if (_riderPoint == null)
              Padding(
                padding: const EdgeInsets.fromLTRB(
                  DsSpace.x5,
                  DsSpace.x3,
                  DsSpace.x5,
                  0,
                ),
                child: Text(
                  "Waiting for the rider's location…",
                  style: text.bodySmall,
                ),
              ),

            // ── Rider ──
            Padding(
              padding: const EdgeInsets.all(DsSpace.x4),
              child: Row(
                children: [
                  Container(
                    width: 44,
                    height: 44,
                    decoration: BoxDecoration(
                      color: DsColor.primarySoft,
                      borderRadius: DsRadius.rSm,
                    ),
                    child: const Icon(
                      Icons.two_wheeler_rounded,
                      color: DsColor.primary,
                      size: 22,
                    ),
                  ),
                  const SizedBox(width: DsSpace.x3),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text('YOUR RIDER', style: DsType.eyebrow()),
                        const SizedBox(height: 2),
                        Text(
                          riderName ?? 'Assigned rider',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: text.titleMedium,
                        ),
                      ],
                    ),
                  ),
                  if (riderPhone != null && riderPhone.isNotEmpty)
                    FilledButton.icon(
                      onPressed: () => _call(riderPhone),
                      style: FilledButton.styleFrom(
                        backgroundColor: DsColor.primary,
                        foregroundColor: Colors.white,
                      ),
                      icon: const Icon(Icons.call_rounded, size: 18),
                      label: const Text('Call'),
                    ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _delivered(int minutes) {
    final text = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.only(bottom: DsSpace.x4),
      child: DsSurface(
        color: DsColor.successSoft,
        shadow: const [],
        padding: const EdgeInsets.all(DsSpace.x4),
        child: Row(
          children: [
            const Icon(
              Icons.check_circle_rounded,
              color: DsColor.success,
              size: 26,
            ),
            const SizedBox(width: DsSpace.x3),
            Expanded(
              child: Text(
                'Delivered in $minutes min',
                style: text.titleMedium?.copyWith(color: DsColor.primaryDeep),
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// A small breathing dot that says "this is live".
class _PulseDot extends StatefulWidget {
  const _PulseDot();

  @override
  State<_PulseDot> createState() => _PulseDotState();
}

class _PulseDotState extends State<_PulseDot>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1100),
  )..repeat(reverse: true);

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: Tween<double>(begin: 0.35, end: 1).animate(_c),
      child: Container(
        width: 8,
        height: 8,
        decoration: const BoxDecoration(
          color: DsColor.primaryBright,
          shape: BoxShape.circle,
        ),
      ),
    );
  }
}
