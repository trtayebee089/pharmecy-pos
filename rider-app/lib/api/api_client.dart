import 'dart:async';
import 'dart:convert';
import 'dart:io';

import 'package:http/http.dart' as http;

import '../config/api_config.dart';

/// An error the user should see: the server's own message when there is one
/// (422 validation errors, 404s, 401 "Please sign in.") or a plain network
/// message.
class ApiException implements Exception {
  ApiException(
    this.message, {
    this.statusCode,
    this.errors = const {},
  });

  final String message;

  /// HTTP status, or null when the request never reached the server.
  final int? statusCode;

  /// Laravel validation errors (field => messages) from a 422.
  final Map<String, List<String>> errors;

  bool get isUnauthorized => statusCode == 401;
  bool get isValidation => statusCode == 422;
  bool get isNetwork => statusCode == null;

  @override
  String toString() => message;
}

/// A successful envelope `{success, message, data}`.
class ApiResult {
  const ApiResult(this.data, this.message);

  final dynamic data;
  final String? message;
}

/// Thin JSON client for `/api/rider/v1` with the bearer token.
///
/// Any 401 on an authenticated call fires [onUnauthorized] once per token so
/// the app can drop the session and go back to the login screen.
class ApiClient {
  ApiClient({http.Client? client}) : _client = client ?? http.Client();

  final http.Client _client;

  String? token;

  void Function()? onUnauthorized;

  static const Duration _timeout = Duration(seconds: 25);

  Future<ApiResult> get(String path, {Map<String, String>? query}) =>
      _send('GET', path, query: query);

  Future<ApiResult> post(String path, [Object? body]) =>
      _send('POST', path, body: body);

  Future<ApiResult> put(String path, [Object? body]) =>
      _send('PUT', path, body: body);

  Future<ApiResult> _send(
    String method,
    String path, {
    Object? body,
    Map<String, String>? query,
  }) async {
    var uri = Uri.parse('${ApiConfig.riderBase}/$path');
    if (query != null && query.isNotEmpty) {
      uri = uri.replace(queryParameters: query);
    }

    final request = http.Request(method, uri);
    request.headers['Accept'] = 'application/json';
    final sentToken = token;
    if (sentToken != null && sentToken.isNotEmpty) {
      request.headers['Authorization'] = 'Bearer $sentToken';
    }
    if (body != null) {
      request.headers['Content-Type'] = 'application/json';
      request.body = jsonEncode(body);
    }

    final http.Response response;
    try {
      final streamed = await _client.send(request).timeout(_timeout);
      response = await http.Response.fromStream(streamed).timeout(_timeout);
    } on TimeoutException {
      throw ApiException('The server took too long to answer. Try again.');
    } on SocketException {
      throw ApiException('No internet connection. Check your data and try again.');
    } on http.ClientException {
      throw ApiException('Could not reach the server. Try again.');
    } on HandshakeException {
      throw ApiException('Secure connection failed. Try again.');
    }

    final decoded = _decode(response.body);
    final status = response.statusCode;

    if (status >= 200 && status < 300) {
      if (decoded is Map && decoded['success'] == false) {
        throw ApiException(
          _string(decoded['message']) ?? 'Something went wrong.',
          statusCode: status,
        );
      }
      if (decoded is Map) {
        return ApiResult(decoded['data'], _string(decoded['message']));
      }
      return ApiResult(decoded, null);
    }

    if (status == 401) {
      // Only an authenticated call means "your session is gone"; a 401 on
      // the login itself is just a failed sign-in.
      if (sentToken != null && sentToken.isNotEmpty && sentToken == token) {
        onUnauthorized?.call();
      }
      throw ApiException(
        _messageFrom(decoded) ?? 'Please sign in.',
        statusCode: status,
      );
    }

    if (status == 422) {
      final errors = _errorsFrom(decoded);
      final first = errors.values
          .expand((messages) => messages)
          .where((m) => m.isNotEmpty)
          .cast<String?>()
          .firstWhere((_) => true, orElse: () => null);
      throw ApiException(
        first ?? _messageFrom(decoded) ?? 'Please check the details and try again.',
        statusCode: status,
        errors: errors,
      );
    }

    if (status == 429) {
      throw ApiException(
        'Too many requests. Wait a moment and try again.',
        statusCode: status,
      );
    }

    throw ApiException(
      _messageFrom(decoded) ?? 'Something went wrong ($status).',
      statusCode: status,
    );
  }

  static dynamic _decode(String body) {
    if (body.isEmpty) return null;
    try {
      return jsonDecode(body);
    } catch (_) {
      return null;
    }
  }

  static String? _string(dynamic v) =>
      v is String && v.trim().isNotEmpty ? v : null;

  static String? _messageFrom(dynamic decoded) {
    if (decoded is Map) {
      return _string(decoded['message']) ?? _string(decoded['error']);
    }
    return null;
  }

  static Map<String, List<String>> _errorsFrom(dynamic decoded) {
    final out = <String, List<String>>{};
    if (decoded is Map && decoded['errors'] is Map) {
      (decoded['errors'] as Map).forEach((key, value) {
        if (value is List) {
          out[key.toString()] = value.map((e) => e.toString()).toList();
        } else if (value != null) {
          out[key.toString()] = [value.toString()];
        }
      });
    }
    return out;
  }
}
