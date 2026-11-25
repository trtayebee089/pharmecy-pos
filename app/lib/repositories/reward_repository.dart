import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/models/reward_wallet.dart';

class RewardRepository {
  Map<String, String> _headers(String token) => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'Authorization': 'Bearer $token',
  };
  Future<Map<String, dynamic>> profile(String token) async {
    final response = await http.get(
      Uri.parse(ApiEndpoints.rewardProfile),
      headers: _headers(token),
    );
    final body = jsonDecode(response.body);
    if (response.statusCode != 200) {
      throw Exception(body['message'] ?? 'Unable to load rewards.');
    }
    return Map<String, dynamic>.from(body['data']);
  }

  Future<List<RewardLedgerEntry>> transactions(String token) async {
    final response = await http.get(
      Uri.parse(ApiEndpoints.rewardTransactions),
      headers: _headers(token),
    );
    final body = jsonDecode(response.body);
    if (response.statusCode != 200) {
      throw Exception(body['message'] ?? 'Unable to load reward history.');
    }
    final items = body['data'] as List? ?? [];
    return items
        .map(
          (item) => RewardLedgerEntry.fromJson(Map<String, dynamic>.from(item)),
        )
        .toList();
  }

  Future<Map<String, dynamic>> redemptionQuote(
    String token,
    double points,
    double orderTotal,
  ) async {
    final response = await http.post(
      Uri.parse(ApiEndpoints.rewardRedeem),
      headers: _headers(token),
      body: jsonEncode({'points': points, 'order_total': orderTotal}),
    );
    final body = jsonDecode(response.body);
    if (response.statusCode != 200) {
      throw Exception(
        body['message'] ??
            (body['errors'] as Map?)?.values.first?.first ??
            'Redemption is not available.',
      );
    }
    return Map<String, dynamic>.from(body['data']);
  }
}
