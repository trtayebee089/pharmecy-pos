import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../models/json.dart';
import '../models/rider_order.dart';

/// One list of orders (`scope=active` or `scope=done`).
class OrderList {
  List<RiderOrder> items = const [];
  bool loading = false;
  bool loaded = false;
  String? error;
}

/// My assigned orders, and the pickup / deliver actions.
class OrdersProvider extends ChangeNotifier {
  OrdersProvider(this._api);

  final ApiClient _api;

  final OrderList active = OrderList();
  final OrderList done = OrderList();

  OrderList _list(String scope) => scope == 'done' ? done : active;

  /// Loads a list; errors are kept on the list (shown in place), not thrown.
  Future<void> load(String scope) async {
    final list = _list(scope);
    if (list.loading) return;
    list.loading = true;
    list.error = null;
    notifyListeners();
    try {
      final result = await _api.get('orders', query: {'scope': scope});
      final data = result.data;
      list.items = data is List
          ? data
              .map(asMap)
              .whereType<Map<String, dynamic>>()
              .map(RiderOrder.fromJson)
              .toList()
          : const [];
      list.loaded = true;
    } on ApiException catch (e) {
      list.error = e.message;
    } finally {
      list.loading = false;
      notifyListeners();
    }
  }

  /// `GET orders/{id}`. Throws [ApiException].
  Future<RiderOrder> fetch(int id) async {
    final result = await _api.get('orders/$id');
    final order = _parse(result.data);
    _replace(order);
    return order;
  }

  /// `POST orders/{id}/pickup`. Returns the order and the server's message.
  Future<(RiderOrder, String?)> pickup(int id) async {
    final result = await _api.post('orders/$id/pickup');
    final order = _parse(result.data);
    _replace(order);
    return (order, result.message);
  }

  /// `POST orders/{id}/deliver` with the exact cash collected.
  Future<(RiderOrder, String?)> deliver(int id, {required double cashCollected}) async {
    final result = await _api.post('orders/$id/deliver', {
      'cash_collected': cashCollected,
    });
    final order = _parse(result.data);
    _replace(order);
    return (order, result.message);
  }

  void clear() {
    for (final list in [active, done]) {
      list.items = const [];
      list.loaded = false;
      list.error = null;
    }
    notifyListeners();
  }

  RiderOrder _parse(dynamic data) {
    final m = asMap(data);
    if (m == null) {
      throw ApiException('Unexpected answer from the server. Try again.');
    }
    return RiderOrder.fromJson(m);
  }

  void _replace(RiderOrder order) {
    final activeItems = List<RiderOrder>.from(active.items);
    final i = activeItems.indexWhere((o) => o.id == order.id);
    if (order.isClosed) {
      if (i >= 0) activeItems.removeAt(i);
      final doneItems = List<RiderOrder>.from(done.items)
        ..removeWhere((o) => o.id == order.id)
        ..insert(0, order);
      done.items = doneItems;
    } else if (i >= 0) {
      activeItems[i] = order;
    }
    active.items = activeItems;
    notifyListeners();
  }
}
