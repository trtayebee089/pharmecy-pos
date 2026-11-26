import 'json.dart';

/// `Profile` from `GET /rider/v1/me`.
class RiderProfile {
  const RiderProfile({
    required this.id,
    required this.name,
    required this.phone,
    required this.onDuty,
    required this.cashHeld,
    required this.todayDelivered,
    required this.todayAvgMinutes,
    required this.activeOrders,
    required this.intervalSeconds,
    required this.distanceFilterM,
    required this.defaultLat,
    required this.defaultLng,
    required this.raw,
  });

  final int id;
  final String name;
  final String phone;
  final bool onDuty;
  final double cashHeld;
  final int todayDelivered;
  final int? todayAvgMinutes;
  final int activeOrders;

  /// How often to report while on duty (`tracking`).
  final int intervalSeconds;
  final int distanceFilterM;

  /// Map centre when nothing else is known (`maps`).
  final double defaultLat;
  final double defaultLng;

  /// The JSON this came from, kept so it can be cached for an offline start.
  final Map<String, dynamic> raw;

  factory RiderProfile.fromJson(Map<String, dynamic> j) {
    final today = asMap(j['today']) ?? const {};
    final tracking = asMap(j['tracking']) ?? const {};
    final maps = asMap(j['maps']) ?? const {};
    final interval = asInt(tracking['interval_seconds']) ?? 15;
    final distance = asInt(tracking['distance_filter_m']) ?? 25;
    return RiderProfile(
      id: asInt(j['id']) ?? 0,
      name: asString(j['name']) ?? 'Rider',
      phone: asString(j['phone']) ?? '',
      onDuty: asBool(j['on_duty']),
      cashHeld: asDouble(j['cash_held']) ?? 0,
      todayDelivered: asInt(today['delivered']) ?? 0,
      todayAvgMinutes: asInt(today['avg_minutes']),
      activeOrders: asInt(j['active_orders']) ?? 0,
      intervalSeconds: interval < 5 ? 5 : interval,
      distanceFilterM: distance < 0 ? 0 : distance,
      defaultLat: asDouble(maps['default_lat']) ?? 23.8103,
      defaultLng: asDouble(maps['default_lng']) ?? 90.4125,
      raw: j,
    );
  }
}
