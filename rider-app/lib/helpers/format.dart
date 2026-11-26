import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../design/tokens.dart';

final NumberFormat _money = NumberFormat('#,##0.00');
final NumberFormat _qty = NumberFormat('#,##0.##');

/// `৳1,250.00`
String taka(double amount) => '৳${_money.format(amount)}';

String qty(double v) => _qty.format(v);

String timeOfDay(DateTime? d) =>
    d == null ? '—' : DateFormat('h:mm a').format(d);

String dateTime(DateTime? d) =>
    d == null ? '—' : DateFormat('d MMM, h:mm a').format(d);

String distance(int? meters) {
  if (meters == null) return '';
  if (meters < 1000) return '$meters m';
  return '${(meters / 1000).toStringAsFixed(1)} km';
}

String statusLabel(String status) {
  switch (status) {
    case 'confirmed':
      return 'Confirmed';
    case 'packed':
      return 'Ready for pickup';
    case 'in_transit':
      return 'On the way';
    case 'delivered':
      return 'Delivered';
    case 'cancelled':
      return 'Cancelled';
    case 'pending':
      return 'Pending';
    default:
      final s = status.replaceAll('_', ' ');
      return s.isEmpty ? s : s[0].toUpperCase() + s.substring(1);
  }
}

/// (foreground, background) for a status chip.
(Color, Color) statusColors(String status) {
  switch (status) {
    case 'in_transit':
      return (DsColor.info, DsColor.infoSoft);
    case 'packed':
      return (DsColor.warning, DsColor.warningSoft);
    case 'delivered':
      return (DsColor.primary, DsColor.primarySoft);
    case 'cancelled':
      return (DsColor.danger, DsColor.dangerSoft);
    default:
      return (DsColor.inkMuted, DsColor.sunken);
  }
}
