import 'dart:convert';
import 'package:shared_preferences/shared_preferences.dart';

class RecentlyViewedRepository {
  static const key = 'recently_viewed_v1', limit = 30;
  Future<List<Map<String, dynamic>>> list() async {
    final prefs = await SharedPreferences.getInstance();
    final raw = jsonDecode(prefs.getString(key) ?? '[]') as List;
    final items = raw.map((e) => Map<String, dynamic>.from(e)).toList()
      ..sort((a, b) => '${b['last_viewed']}'.compareTo('${a['last_viewed']}'));
    return items.take(limit).toList();
  }

  Future<void> record(Map<String, dynamic> product) async {
    if (product['id'] == null) return;
    final items = await list();
    final now = DateTime.now().toUtc().toIso8601String();
    Map<String, dynamic>? previous;
    for (final item in items) {
      if ('${item['product_id']}' == '${product['id']}') {
        previous = item;
        break;
      }
    }
    items.removeWhere((item) => '${item['product_id']}' == '${product['id']}');
    items.insert(0, {
      'product_id': product['id'],
      'slug': product['slug'] ?? product['id'],
      'viewed_time': previous?['viewed_time'] ?? now,
      'last_viewed': now,
    });
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(key, jsonEncode(items.take(limit).toList()));
  }

  Future<void> clear() async {
    (await SharedPreferences.getInstance()).remove(key);
  }

  Future<Map<String, dynamic>> syncPayload() async => {
    'version': 1,
    'items': (await list())
        .map(
          (item) => {
            'product_id': item['product_id'],
            'viewed_time': item['viewed_time'],
            'last_viewed': item['last_viewed'],
          },
        )
        .toList(),
  };
}
