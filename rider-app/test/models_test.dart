import 'package:flutter_test/flutter_test.dart';
import 'package:redpharma_rider/models/rider_order.dart';
import 'package:redpharma_rider/models/rider_profile.dart';

void main() {
  test('parses a Profile', () {
    final p = RiderProfile.fromJson({
      'id': 3,
      'name': 'Rasel',
      'phone': '01711000001',
      'on_duty': true,
      'cash_held': 100.0,
      'today': {'delivered': 4, 'avg_minutes': 22},
      'active_orders': 2,
      'tracking': {'interval_seconds': 15, 'distance_filter_m': 25},
      'maps': {'default_lat': 23.8103, 'default_lng': 90.4125},
    });
    expect(p.onDuty, isTrue);
    expect(p.cashHeld, 100.0);
    expect(p.todayAvgMinutes, 22);
    expect(p.intervalSeconds, 15);
    expect(p.distanceFilterM, 25);
  });

  test('parses a RiderOrder with the three markers', () {
    final o = RiderOrder.fromJson({
      'id': 41,
      'number': 'OO-DHK1-2026-00041',
      'status': 'in_transit',
      'customer': {
        'name': 'Karim',
        'phone': '01812345678',
        'address': 'House 1, Road 2',
        'area': 'Dhanmondi, Dhaka',
      },
      'note': null,
      'cod_amount': 100.0,
      'items': [
        {'name': 'Napa 500mg', 'qty': 20.0},
      ],
      'markers': {
        'store': {'name': 'Dhanmondi branch', 'address': 'x', 'lat': 23.7465, 'lng': 90.376},
        'customer': {'lat': '23.7509', 'lng': '90.3935'},
        'rider': null,
      },
      'eta': {'minutes': 12, 'distance_m': 3120, 'source': 'google'},
      'can_pickup': false,
      'can_deliver': true,
    });
    expect(o.store?.name, 'Dhanmondi branch');
    expect(o.customerPin?.lat, closeTo(23.7509, 1e-9));
    expect(o.riderPosition, isNull);
    expect(o.eta?.minutes, 12);
    expect(o.canDeliver, isTrue);
    expect(o.fullAddress, 'House 1, Road 2, Dhanmondi, Dhaka');
    expect(o.items.single.qty, 20.0);
  });
}
