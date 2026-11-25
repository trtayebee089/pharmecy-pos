import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:redpharmabd_app/constants/api_endpoints.dart';

class ReorderRepository {
  Future<Map<String, dynamic>> build(String token, int orderId) async {
    final response = await http.post(
      Uri.parse('${ApiEndpoints.reorder}/$orderId/reorder'),
      headers: {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        'Authorization': 'Bearer $token',
      },
      body: jsonEncode({'shipping_cost': 0}),
    );
    final body = jsonDecode(response.body);
    if (response.statusCode != 200) {
      throw Exception(body['message'] ?? 'Unable to reorder.');
    }
    return Map<String, dynamic>.from(body['data']);
  }
}
