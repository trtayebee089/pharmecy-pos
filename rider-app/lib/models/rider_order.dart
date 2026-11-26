import 'json.dart';

/// A point on the map, optionally named (the store marker).
class GeoPoint {
  const GeoPoint({
    required this.lat,
    required this.lng,
    this.name,
    this.address,
  });

  final double lat;
  final double lng;
  final String? name;
  final String? address;

  static GeoPoint? fromJson(dynamic v) {
    final j = asMap(v);
    if (j == null) return null;
    final lat = asDouble(j['lat']);
    final lng = asDouble(j['lng']);
    if (lat == null || lng == null) return null;
    return GeoPoint(
      lat: lat,
      lng: lng,
      name: asString(j['name']),
      address: asString(j['address']),
    );
  }
}

/// `markers.rider`: the last position the server has for me.
class RiderPosition {
  const RiderPosition({
    required this.lat,
    required this.lng,
    this.heading,
    this.accuracy,
    this.updatedAt,
    this.isLive = false,
  });

  final double lat;
  final double lng;
  final double? heading;
  final double? accuracy;
  final DateTime? updatedAt;
  final bool isLive;

  static RiderPosition? fromJson(dynamic v) {
    final j = asMap(v);
    if (j == null) return null;
    final lat = asDouble(j['lat']);
    final lng = asDouble(j['lng']);
    if (lat == null || lng == null) return null;
    return RiderPosition(
      lat: lat,
      lng: lng,
      heading: asDouble(j['heading']),
      accuracy: asDouble(j['accuracy']),
      updatedAt: asDate(j['updated_at']),
      isLive: asBool(j['is_live']),
    );
  }
}

class OrderEta {
  const OrderEta({
    this.at,
    this.seconds,
    this.minutes,
    this.distanceM,
    this.source,
  });

  final DateTime? at;
  final int? seconds;
  final int? minutes;
  final int? distanceM;
  final String? source;

  static OrderEta? fromJson(dynamic v) {
    final j = asMap(v);
    if (j == null) return null;
    return OrderEta(
      at: asDate(j['at']),
      seconds: asInt(j['seconds']),
      minutes: asInt(j['minutes']),
      distanceM: asInt(j['distance_m']),
      source: asString(j['source']),
    );
  }
}

class OrderItem {
  const OrderItem({required this.name, required this.qty});

  final String name;
  final double qty;
}

/// `RiderOrder` from `GET /rider/v1/orders`.
class RiderOrder {
  const RiderOrder({
    required this.id,
    required this.number,
    required this.status,
    required this.placedAt,
    required this.customerName,
    required this.customerPhone,
    required this.address,
    required this.area,
    required this.note,
    required this.codAmount,
    required this.paymentMethod,
    required this.paymentStatus,
    required this.items,
    required this.store,
    required this.customerPin,
    required this.riderPosition,
    required this.eta,
    required this.pickedUpAt,
    required this.deliveredAt,
    required this.deliveryMinutes,
    required this.canPickup,
    required this.canDeliver,
  });

  final int id;
  final String number;
  final String status;
  final DateTime? placedAt;
  final String? customerName;
  final String? customerPhone;
  final String? address;
  final String? area;
  final String? note;
  final double codAmount;
  final String? paymentMethod;
  final String? paymentStatus;
  final List<OrderItem> items;
  final GeoPoint? store;
  final GeoPoint? customerPin;
  final RiderPosition? riderPosition;
  final OrderEta? eta;
  final DateTime? pickedUpAt;
  final DateTime? deliveredAt;
  final int? deliveryMinutes;
  final bool canPickup;
  final bool canDeliver;

  bool get isInTransit => status == 'in_transit';
  bool get isDelivered => status == 'delivered';
  bool get isCancelled => status == 'cancelled';
  bool get isClosed => isDelivered || isCancelled;

  /// Address line plus area, for display and as a Maps search fallback.
  String get fullAddress => [address, area]
      .whereType<String>()
      .where((s) => s.trim().isNotEmpty)
      .join(', ');

  factory RiderOrder.fromJson(Map<String, dynamic> j) {
    final customer = asMap(j['customer']) ?? const {};
    final markers = asMap(j['markers']) ?? const {};
    final items = <OrderItem>[];
    final rawItems = j['items'];
    if (rawItems is List) {
      for (final it in rawItems) {
        final m = asMap(it);
        if (m == null) continue;
        items.add(OrderItem(
          name: asString(m['name']) ?? 'Item',
          qty: asDouble(m['qty']) ?? 0,
        ));
      }
    }
    return RiderOrder(
      id: asInt(j['id']) ?? 0,
      number: asString(j['number']) ?? '#${j['id']}',
      status: asString(j['status']) ?? 'confirmed',
      placedAt: asDate(j['placed_at']),
      customerName: asString(customer['name']),
      customerPhone: asString(customer['phone']),
      address: asString(customer['address']),
      area: asString(customer['area']),
      note: asString(j['note']),
      codAmount: asDouble(j['cod_amount']) ?? 0,
      paymentMethod: asString(j['payment_method']),
      paymentStatus: asString(j['payment_status']),
      items: items,
      store: GeoPoint.fromJson(markers['store']),
      customerPin: GeoPoint.fromJson(markers['customer']),
      riderPosition: RiderPosition.fromJson(markers['rider']),
      eta: OrderEta.fromJson(j['eta']),
      pickedUpAt: asDate(j['picked_up_at']),
      deliveredAt: asDate(j['delivered_at']),
      deliveryMinutes: asInt(j['delivery_minutes']),
      canPickup: asBool(j['can_pickup']),
      canDeliver: asBool(j['can_deliver']),
    );
  }
}
