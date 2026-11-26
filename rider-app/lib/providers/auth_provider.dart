import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../app_navigator.dart';
import '../config/api_config.dart';
import '../models/json.dart';
import '../models/rider_profile.dart';
import '../services/location_reporter.dart';

enum AuthStatus { unknown, signedOut, signedIn }

/// Session (token + profile), duty status, and starting/stopping the
/// location reporter with it.
class AuthProvider extends ChangeNotifier {
  AuthProvider(this._api, this._reporter) {
    _api.onUnauthorized = _handleUnauthorized;
    _reporter.onServerOffDuty = _handleServerOffDuty;
  }

  final ApiClient _api;
  final LocationReporter _reporter;

  static const String _tokenKey = 'rider_token';
  static const String _profileKey = 'rider_profile';

  AuthStatus _status = AuthStatus.unknown;
  RiderProfile? _profile;
  bool _dutyBusy = false;

  /// Called after the session ends (sign-out or 401) to clear other state.
  VoidCallback? onSignedOut;

  AuthStatus get status => _status;
  bool get isSignedIn => _status == AuthStatus.signedIn;
  RiderProfile? get profile => _profile;
  bool get dutyBusy => _dutyBusy;
  bool get onDuty => _profile?.onDuty ?? false;

  /// Restores a saved session. A network failure keeps the session with the
  /// cached profile (the rider may be offline); a 401 ends it.
  Future<void> bootstrap() async {
    final prefs = await SharedPreferences.getInstance();
    final token = prefs.getString(_tokenKey);
    if (token == null || token.isEmpty) {
      _status = AuthStatus.signedOut;
      notifyListeners();
      return;
    }
    _api.token = token;
    final cached = prefs.getString(_profileKey);
    if (cached != null) {
      try {
        final m = asMap(jsonDecode(cached));
        if (m != null) _profile = RiderProfile.fromJson(m);
      } catch (_) {}
    }
    try {
      await refreshProfile();
      _status = AuthStatus.signedIn;
    } on ApiException catch (e) {
      if (e.isUnauthorized) {
        // _handleUnauthorized already cleared the session.
        _status = AuthStatus.signedOut;
      } else {
        _status = AuthStatus.signedIn;
      }
    }
    notifyListeners();
  }

  Future<void> login(String phone, String password) async {
    final result = await _api.post('login', {
      'phone': phone.trim(),
      'password': password,
      'device_name': ApiConfig.deviceName,
    });
    final data = asMap(result.data);
    final token = asString(data?['token']);
    final rider = asMap(data?['rider']);
    if (token == null || rider == null) {
      throw ApiException('Unexpected answer from the server. Try again.');
    }
    _api.token = token;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_tokenKey, token);
    await _setProfile(RiderProfile.fromJson(rider));
    _status = AuthStatus.signedIn;
    notifyListeners();
  }

  /// `GET me`. Throws [ApiException].
  Future<RiderProfile?> refreshProfile() async {
    final result = await _api.get('me');
    final m = asMap(result.data);
    if (m != null) {
      await _setProfile(RiderProfile.fromJson(m));
      notifyListeners();
    }
    return _profile;
  }

  /// Refresh without throwing (pull-to-refresh, app resume).
  Future<void> refreshQuietly() async {
    try {
      await refreshProfile();
    } on ApiException catch (_) {}
  }

  /// `PUT duty`. Going on duty: the caller must have asked for location
  /// permission first; reporting starts once the server agrees. Going off
  /// duty: buffered points are sent first, then reporting stops.
  Future<String?> setDuty(bool on) async {
    if (_dutyBusy) return null;
    _dutyBusy = true;
    notifyListeners();
    try {
      if (!on) {
        await _reporter.flush();
      }
      final result = await _api.put('duty', {'on_duty': on});
      final m = asMap(result.data);
      if (m != null) await _setProfile(RiderProfile.fromJson(m));
      await syncTracking();
      return result.message;
    } finally {
      _dutyBusy = false;
      notifyListeners();
    }
  }

  /// Makes the reporter match the duty flag. Starting needs location
  /// permission already granted (see `ensureLocationPermission`).
  Future<void> syncTracking() async {
    final p = _profile;
    if (p != null && p.onDuty && isSignedInOrRestoring) {
      await _reporter.startReporting(
        intervalSeconds: p.intervalSeconds,
        distanceFilterM: p.distanceFilterM,
      );
    } else if (_reporter.isReporting) {
      await _reporter.stopReporting(flushFirst: true);
    }
  }

  bool get isSignedInOrRestoring =>
      _status == AuthStatus.signedIn || _status == AuthStatus.unknown;

  bool _loggingOut = false;

  Future<void> logout() async {
    if (_loggingOut) return;
    _loggingOut = true;
    try {
      // Send what we have while the token is still valid.
      await _reporter.stopReporting(flushFirst: true);
      try {
        await _api.post('logout');
      } on ApiException catch (_) {
        // Signed out locally either way.
      }
      await _clearSession();
    } finally {
      _loggingOut = false;
    }
  }

  Future<void> _setProfile(RiderProfile p) async {
    _profile = p;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_profileKey, jsonEncode(p.raw));
  }

  Future<void> _clearSession() async {
    _api.token = null;
    _profile = null;
    _status = AuthStatus.signedOut;
    if (_reporter.isReporting) {
      await _reporter.stopReporting(flushFirst: false);
    }
    await _reporter.clearBuffer();
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_tokenKey);
    await prefs.remove(_profileKey);
    onSignedOut?.call();
    navigatorKey.currentState?.popUntil((route) => route.isFirst);
    notifyListeners();
  }

  bool _handlingUnauthorized = false;

  void _handleUnauthorized() {
    if (_handlingUnauthorized ||
        _loggingOut ||
        _status == AuthStatus.signedOut) {
      return;
    }
    _handlingUnauthorized = true;
    _clearSession().whenComplete(() {
      _handlingUnauthorized = false;
      showMessage('Your session ended. Please sign in again.');
    });
  }

  void _handleServerOffDuty() {
    // Staff took the rider off duty: pick up the new profile and stop.
    refreshQuietly().then((_) => syncTracking());
  }
}
