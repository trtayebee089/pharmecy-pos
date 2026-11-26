import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/foundation.dart';
import 'package:flutter/gestures.dart';
import 'package:flutter/material.dart';
import 'package:geolocator/geolocator.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import '../api/api_client.dart';
import '../app_navigator.dart';
import '../design/tokens.dart';
import '../design/typography.dart';
import '../helpers/format.dart';
import '../models/rider_order.dart';
import '../providers/auth_provider.dart';
import '../providers/orders_provider.dart';
import '../services/location_reporter.dart';
import '../services/tracking_flow.dart';
import '../widgets/common.dart';

/// Marker colours. The map uses Google's default pins tinted with these hues;
/// the legend uses the matching colours.
class _Pin {
  const _Pin(this.hue, this.color, this.icon, this.label);

  final double hue;
  final Color color;
  final IconData icon;
  final String label;

  static const store = _Pin(
    BitmapDescriptor.hueRed,
    Color(0xFFE53935),
    Icons.storefront_rounded,
    'Store',
  );
  static const customer = _Pin(
    BitmapDescriptor.hueGreen,
    Color(0xFF2E9E47),
    Icons.home_rounded,
    'Customer',
  );
  static const me = _Pin(
    BitmapDescriptor.hueAzure,
    Color(0xFF2F8BEA),
    Icons.two_wheeler_rounded,
    'Me',
  );
}

class OrderDetailScreen extends StatefulWidget {
  const OrderDetailScreen({super.key, required this.initial});

  final RiderOrder initial;

  @override
  State<OrderDetailScreen> createState() => _OrderDetailScreenState();
}

class _OrderDetailScreenState extends State<OrderDetailScreen> {
  late RiderOrder _order = widget.initial;
  late final LocationReporter _reporter;

  GoogleMapController? _map;
  int _fittedPoints = 0;
  bool _fitScheduled = false;
  bool _acting = false;
  Timer? _poll;

  /// A phone fix older than this is not "live"; the server's last known
  /// position is used instead when it is newer.
  static const Duration _phoneFixMaxAge = Duration(minutes: 5);

  @override
  void initState() {
    super.initState();
    _reporter = context.read<LocationReporter>();
    _reporter.addWatcher();
    _load(silent: true);
    // Keep the ETA and status fresh while the screen is open.
    _poll = Timer.periodic(const Duration(seconds: 30), (_) {
      _load(silent: true);
    });
  }

  @override
  void dispose() {
    _poll?.cancel();
    _reporter.removeWatcher();
    super.dispose();
  }

  Future<void> _load({bool silent = false}) async {
    try {
      final fresh = await context.read<OrdersProvider>().fetch(_order.id);
      if (mounted) setState(() => _order = fresh);
    } on ApiException catch (e) {
      if (!silent) showMessage(e.message, error: true);
    }
  }

  // ------------------------------------------------------------------ map

  LatLng? _mePosition(Position? phone) {
    final server = _order.riderPosition;
    if (phone != null) {
      final age = DateTime.now().difference(phone.timestamp.toLocal());
      final serverIsNewer = server?.updatedAt != null &&
          server!.updatedAt!.isAfter(phone.timestamp.toLocal());
      if (age <= _phoneFixMaxAge || server == null || !serverIsNewer) {
        return LatLng(phone.latitude, phone.longitude);
      }
    }
    if (server != null) return LatLng(server.lat, server.lng);
    return null;
  }

  List<LatLng> _points(LatLng? me) => [
    if (_order.store != null) LatLng(_order.store!.lat, _order.store!.lng),
    if (_order.customerPin != null)
      LatLng(_order.customerPin!.lat, _order.customerPin!.lng),
    if (me != null) me,
  ];

  Set<Marker> _markers(LatLng? me) {
    final store = _order.store;
    final customer = _order.customerPin;
    return {
      if (store != null)
        Marker(
          markerId: const MarkerId('store'),
          position: LatLng(store.lat, store.lng),
          icon: BitmapDescriptor.defaultMarkerWithHue(_Pin.store.hue),
          infoWindow: InfoWindow(
            title: store.name ?? 'Store',
            snippet: store.address,
          ),
        ),
      if (customer != null)
        Marker(
          markerId: const MarkerId('customer'),
          position: LatLng(customer.lat, customer.lng),
          icon: BitmapDescriptor.defaultMarkerWithHue(_Pin.customer.hue),
          infoWindow: InfoWindow(
            title: _order.customerName ?? 'Customer',
            snippet: _order.fullAddress.isEmpty ? null : _order.fullAddress,
          ),
        ),
      if (me != null)
        Marker(
          markerId: const MarkerId('me'),
          position: me,
          icon: BitmapDescriptor.defaultMarkerWithHue(_Pin.me.hue),
          infoWindow: const InfoWindow(title: 'Me'),
        ),
    };
  }

  Future<void> _fit(List<LatLng> points) async {
    final map = _map;
    if (map == null || points.isEmpty) return;
    _fittedPoints = points.length;
    var minLat = points.first.latitude, maxLat = minLat;
    var minLng = points.first.longitude, maxLng = minLng;
    for (final p in points) {
      minLat = math.min(minLat, p.latitude);
      maxLat = math.max(maxLat, p.latitude);
      minLng = math.min(minLng, p.longitude);
      maxLng = math.max(maxLng, p.longitude);
    }
    try {
      if ((maxLat - minLat).abs() < 0.0005 && (maxLng - minLng).abs() < 0.0005) {
        await map.animateCamera(CameraUpdate.newLatLngZoom(points.first, 15));
      } else {
        await map.animateCamera(
          CameraUpdate.newLatLngBounds(
            LatLngBounds(
              southwest: LatLng(minLat, minLng),
              northeast: LatLng(maxLat, maxLng),
            ),
            56,
          ),
        );
      }
    } catch (_) {
      // The map may not be laid out yet; the next fit will do it.
      _fittedPoints = 0;
    }
  }

  void _scheduleFit(List<LatLng> points) {
    if (_fitScheduled || _map == null || points.length <= _fittedPoints) return;
    _fitScheduled = true;
    Future<void>.delayed(const Duration(milliseconds: 350), () async {
      _fitScheduled = false;
      if (!mounted) return;
      await _fit(points);
    });
  }

  // -------------------------------------------------------------- actions

  Future<void> _call() async {
    final phone = _order.customerPhone;
    if (phone == null) return;
    final ok = await launchUrl(Uri(scheme: 'tel', path: phone));
    if (!ok) showMessage('Could not open the phone app.', error: true);
  }

  Future<void> _navigate() async {
    final pin = _order.customerPin;
    final String destination;
    if (pin != null) {
      destination = '${pin.lat},${pin.lng}';
    } else if (_order.fullAddress.isNotEmpty) {
      destination = _order.fullAddress;
    } else {
      showMessage('This order has no address or map pin.', error: true);
      return;
    }
    final uri = Uri.https('www.google.com', '/maps/dir/', {
      'api': '1',
      'destination': destination,
      'travelmode': 'two-wheeler',
      'dir_action': 'navigate',
    });
    final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
    if (!ok) showMessage('Could not open Google Maps.', error: true);
  }

  Future<void> _pickup() async {
    if (_acting) return;
    final orders = context.read<OrdersProvider>();
    final auth = context.read<AuthProvider>();
    setState(() => _acting = true);
    try {
      final (order, message) = await orders.pickup(_order.id);
      if (!mounted) return;
      setState(() => _order = order);
      showMessage(message ?? 'Picked up.');
      // Picking up puts the rider on duty: make sure we report.
      await auth.refreshQuietly();
      if (mounted) await ensureTracking(context);
    } on ApiException catch (e) {
      showMessage(e.message, error: true);
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  Future<void> _deliver() async {
    if (_acting) return;
    final cod = _order.codAmount;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => _DeliverDialog(order: _order),
    );
    if (confirmed != true || !mounted) return;

    final orders = context.read<OrdersProvider>();
    final auth = context.read<AuthProvider>();
    setState(() => _acting = true);
    try {
      final (_, message) = await orders.deliver(_order.id, cashCollected: cod);
      showMessage(message ?? 'Delivered.');
      unawaited(auth.refreshQuietly());
      unawaited(orders.load('active'));
      if (mounted) Navigator.of(context).pop();
    } on ApiException catch (e) {
      showMessage(e.message, error: true);
    } finally {
      if (mounted) setState(() => _acting = false);
    }
  }

  // ------------------------------------------------------------------- ui

  @override
  Widget build(BuildContext context) {
    final phoneFix = context.select<LocationReporter, Position?>(
      (r) => r.lastPosition,
    );
    final profile = context.select<AuthProvider, (double, double)>(
      (a) => (a.profile?.defaultLat ?? 23.8103, a.profile?.defaultLng ?? 90.4125),
    );
    final me = _mePosition(phoneFix);
    final points = _points(me);
    _scheduleFit(points);

    final order = _order;
    final text = Theme.of(context).textTheme;
    final showActions = order.canPickup || order.canDeliver;

    return Scaffold(
      appBar: AppBar(
        title: Text(order.number, style: text.titleLarge),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            icon: const Icon(Icons.refresh_rounded),
            onPressed: () => _load(),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(),
        child: ListView(
          padding: const EdgeInsets.fromLTRB(
            DsSpace.x4,
            DsSpace.x2,
            DsSpace.x4,
            DsSpace.x8,
          ),
          children: [
            Row(
              children: [
                StatusChip(order.status),
                DsSpace.h2,
                if (order.placedAt != null)
                  Expanded(
                    child: Text(
                      'Placed ${dateTime(order.placedAt)}',
                      style: text.bodySmall,
                      overflow: TextOverflow.ellipsis,
                    ),
                  ),
              ],
            ),
            if (order.eta != null) ...[
              DsSpace.v3,
              _EtaBanner(eta: order.eta!),
            ],
            DsSpace.v3,
            _mapCard(points, me, profile),
            DsSpace.v3,
            _CashCard(order: order),
            DsSpace.v3,
            _customerCard(order, text),
            if (order.note != null) ...[
              DsSpace.v3,
              SurfaceCard(
                color: DsColor.warningSoft,
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    const Icon(Icons.sticky_note_2_outlined,
                        color: DsColor.warning),
                    DsSpace.h3,
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text('Note', style: text.titleSmall),
                          DsSpace.v1,
                          Text(order.note!, style: text.bodyMedium),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
            if (order.store != null) ...[
              DsSpace.v3,
              SurfaceCard(
                child: Row(
                  children: [
                    Icon(_Pin.store.icon, color: _Pin.store.color),
                    DsSpace.h3,
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Text(
                            'Pick up from ${order.store!.name ?? 'the store'}',
                            style: text.titleSmall,
                          ),
                          if (order.store!.address != null)
                            Text(order.store!.address!, style: text.bodySmall),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ],
            DsSpace.v3,
            _itemsCard(order, text),
            if (order.pickedUpAt != null || order.deliveredAt != null) ...[
              DsSpace.v3,
              SurfaceCard(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    if (order.pickedUpAt != null)
                      Text('Picked up ${dateTime(order.pickedUpAt)}',
                          style: text.bodyMedium),
                    if (order.deliveredAt != null)
                      Text(
                        'Delivered ${dateTime(order.deliveredAt)}'
                        '${order.deliveryMinutes != null ? ' · ${order.deliveryMinutes} min' : ''}',
                        style: text.bodyMedium,
                      ),
                  ],
                ),
              ),
            ],
          ],
        ),
      ),
      bottomNavigationBar: showActions ? _actionBar(order) : null,
    );
  }

  Widget _mapCard(List<LatLng> points, LatLng? me, (double, double) fallback) {
    final initialTarget =
        points.isNotEmpty ? points.first : LatLng(fallback.$1, fallback.$2);
    return Container(
      decoration: BoxDecoration(
        color: DsColor.surface,
        borderRadius: DsRadius.rMd,
        boxShadow: DsShadow.low,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        children: [
          SizedBox(
            height: 300,
            child: Stack(
              children: [
                GoogleMap(
                  initialCameraPosition: CameraPosition(
                    target: initialTarget,
                    zoom: 13,
                  ),
                  markers: _markers(me),
                  myLocationEnabled: false,
                  myLocationButtonEnabled: false,
                  zoomControlsEnabled: false,
                  mapToolbarEnabled: false,
                  compassEnabled: false,
                  // Let the map take pans and zooms inside the scroll view.
                  gestureRecognizers: <Factory<OneSequenceGestureRecognizer>>{
                    Factory<OneSequenceGestureRecognizer>(
                      () => EagerGestureRecognizer(),
                    ),
                  },
                  onMapCreated: (controller) {
                    _map = controller;
                    _fittedPoints = 0;
                    _scheduleFit(_points(_mePosition(_reporter.lastPosition)));
                  },
                ),
                Positioned(
                  right: DsSpace.x3,
                  bottom: DsSpace.x3,
                  child: Material(
                    color: DsColor.surface,
                    shape: const CircleBorder(),
                    elevation: 2,
                    child: IconButton(
                      tooltip: 'Show all',
                      icon: const Icon(Icons.fit_screen_rounded),
                      onPressed: () => _fit(points),
                    ),
                  ),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: DsSpace.x4,
              vertical: DsSpace.x3,
            ),
            child: Wrap(
              spacing: DsSpace.x4,
              runSpacing: DsSpace.x2,
              children: [
                _LegendItem(pin: _Pin.store, present: _order.store != null),
                _LegendItem(
                  pin: _Pin.customer,
                  present: _order.customerPin != null,
                  missingNote: 'no pin',
                ),
                _LegendItem(pin: _Pin.me, present: me != null),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _customerCard(RiderOrder order, TextTheme text) {
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const SectionLabel('Deliver to'),
          Text(order.customerName ?? 'Customer', style: text.titleLarge),
          if (order.address != null) ...[
            DsSpace.v1,
            Text(order.address!, style: text.bodyLarge),
          ],
          if (order.area != null)
            Text(order.area!, style: text.bodyMedium),
          if (order.customerPhone != null) ...[
            DsSpace.v1,
            Text(order.customerPhone!, style: DsType.numeral(15)),
          ],
          if (order.customerPin == null) ...[
            DsSpace.v2,
            Text(
              'No map pin for this address: Navigate searches the address text.',
              style: text.bodySmall?.copyWith(color: DsColor.warning),
            ),
          ],
          DsSpace.v4,
          Row(
            children: [
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: order.customerPhone == null ? null : _call,
                  icon: const Icon(Icons.call_rounded),
                  label: const Text('Call'),
                ),
              ),
              DsSpace.h3,
              Expanded(
                child: OutlinedButton.icon(
                  onPressed: _navigate,
                  icon: const Icon(Icons.navigation_rounded),
                  label: const Text('Navigate'),
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _itemsCard(RiderOrder order, TextTheme text) {
    return SurfaceCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SectionLabel('Items (${order.items.length})'),
          if (order.items.isEmpty)
            Text('No items listed.', style: text.bodyMedium)
          else
            for (final item in order.items)
              Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    SizedBox(
                      width: 52,
                      child: Text(
                        '${qty(item.qty)} ×',
                        style: DsType.numeral(14, color: DsColor.inkMuted),
                      ),
                    ),
                    Expanded(child: Text(item.name, style: text.titleSmall)),
                  ],
                ),
              ),
        ],
      ),
    );
  }

  Widget _actionBar(RiderOrder order) {
    final busy = _acting;
    Widget spinner() => const SizedBox(
      width: 22,
      height: 22,
      child: CircularProgressIndicator(strokeWidth: 2.5, color: DsColor.onDark),
    );
    return Container(
      decoration: BoxDecoration(
        color: DsColor.surface,
        boxShadow: DsShadow.high,
      ),
      padding: const EdgeInsets.fromLTRB(
        DsSpace.x4,
        DsSpace.x3,
        DsSpace.x4,
        DsSpace.x3,
      ),
      child: SafeArea(
        top: false,
        child: Row(
          children: [
            if (order.canPickup)
              Expanded(
                child: ElevatedButton.icon(
                  onPressed: busy ? null : _pickup,
                  icon: busy
                      ? spinner()
                      : const Icon(Icons.inventory_2_rounded),
                  label: const Text('Picked up'),
                ),
              ),
            if (order.canPickup && order.canDeliver) DsSpace.h3,
            if (order.canDeliver)
              Expanded(
                child: ElevatedButton.icon(
                  style: ElevatedButton.styleFrom(
                    backgroundColor: DsColor.brand,
                    foregroundColor: Colors.white,
                  ),
                  onPressed: busy ? null : _deliver,
                  icon: busy
                      ? spinner()
                      : const Icon(Icons.task_alt_rounded),
                  label: Text(
                    order.codAmount > 0
                        ? 'Delivered · ${taka(order.codAmount)}'
                        : 'Delivered',
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _LegendItem extends StatelessWidget {
  const _LegendItem({
    required this.pin,
    required this.present,
    this.missingNote = 'unknown',
  });

  final _Pin pin;
  final bool present;
  final String missingNote;

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme.labelMedium;
    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 22,
          height: 22,
          decoration: BoxDecoration(
            color: present ? pin.color : DsColor.sunken,
            shape: BoxShape.circle,
          ),
          child: Icon(
            pin.icon,
            size: 13,
            color: present ? Colors.white : DsColor.inkFaint,
          ),
        ),
        const SizedBox(width: 6),
        Text(present ? pin.label : '${pin.label} ($missingNote)', style: style),
      ],
    );
  }
}

class _EtaBanner extends StatelessWidget {
  const _EtaBanner({required this.eta});

  final OrderEta eta;

  @override
  Widget build(BuildContext context) {
    final parts = <String>[
      if (eta.distanceM != null) distance(eta.distanceM),
      if (eta.at != null) 'arrive by ${timeOfDay(eta.at)}',
    ];
    return SurfaceCard(
      color: DsColor.infoSoft,
      child: Row(
        children: [
          const Icon(Icons.schedule_rounded, color: DsColor.info),
          DsSpace.h3,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  eta.minutes != null
                      ? 'About ${eta.minutes} min to the customer'
                      : 'On the way',
                  style: DsType.numeral(17, color: DsColor.info),
                ),
                if (parts.isNotEmpty)
                  Text(
                    parts.join(' · '),
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// The amount to collect, impossible to miss.
class _CashCard extends StatelessWidget {
  const _CashCard({required this.order});

  final RiderOrder order;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final cod = order.codAmount;
    if (cod <= 0) {
      return SurfaceCard(
        color: DsColor.primarySoft,
        child: Row(
          children: [
            const Icon(Icons.verified_rounded, color: DsColor.primary, size: 30),
            DsSpace.h3,
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text('Prepaid', style: DsType.numeral(22, color: DsColor.primary)),
                  Text('Do not collect any cash.', style: text.bodyMedium),
                ],
              ),
            ),
          ],
        ),
      );
    }
    return Container(
      padding: const EdgeInsets.all(DsSpace.x5),
      decoration: BoxDecoration(
        color: DsColor.brand,
        borderRadius: DsRadius.rMd,
        boxShadow: DsShadow.glow(DsColor.brand),
      ),
      child: Row(
        children: [
          const Icon(Icons.payments_rounded, color: Colors.white, size: 34),
          DsSpace.h4,
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'COLLECT CASH',
                  style: DsType.eyebrow(color: Colors.white.withValues(alpha: 0.85)),
                ),
                DsSpace.v1,
                FittedBox(
                  fit: BoxFit.scaleDown,
                  alignment: Alignment.centerLeft,
                  child: Text(
                    taka(cod),
                    style: DsType.numeral(32, color: Colors.white),
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

class _DeliverDialog extends StatelessWidget {
  const _DeliverDialog({required this.order});

  final RiderOrder order;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final cod = order.codAmount;
    final name = order.customerName ?? 'the customer';
    return AlertDialog(
      title: const Text('Confirm delivery'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          if (cod > 0) ...[
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(DsSpace.x4),
              decoration: const BoxDecoration(
                color: DsColor.brandSoft,
                borderRadius: DsRadius.rSm,
              ),
              child: Column(
                children: [
                  Text('Collect in cash', style: DsType.eyebrow(color: DsColor.brand)),
                  DsSpace.v1,
                  FittedBox(
                    fit: BoxFit.scaleDown,
                    child: Text(
                      taka(cod),
                      style: DsType.numeral(34, color: DsColor.brand),
                    ),
                  ),
                ],
              ),
            ),
            DsSpace.v4,
            Text(
              'Hand the parcel to $name and collect exactly ${taka(cod)}. '
              'If the customer cannot pay, do not mark it delivered: call the pharmacy.',
              style: text.bodyMedium,
            ),
          ] else
            Text(
              'This order is prepaid. Hand the parcel to $name; do not collect any cash.',
              style: text.bodyMedium,
            ),
        ],
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(false),
          child: const Text('Cancel'),
        ),
        TextButton(
          onPressed: () => Navigator.of(context).pop(true),
          child: Text(cod > 0 ? 'Collected ${taka(cod)}' : 'Delivered'),
        ),
      ],
    );
  }
}
