import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:geolocator/geolocator.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_client.dart';
import '../design/tokens.dart';

/// The single owner of the phone's position stream.
///
/// Two modes, which share one platform stream (geolocator only keeps one):
///
/// * **Reporting** (on duty): positions every `tracking.interval_seconds` /
///   `distance_filter_m`, run inside geolocator's Android foreground service
///   (an ongoing "On duty" notification plus a wake lock) so it keeps going
///   with the screen off. Points are buffered in memory and in
///   shared_preferences and POSTed to `locations` in batches of up to 500,
///   oldest first. A failed send keeps the buffer and is retried on the next
///   tick.
/// * **Watching** (a screen wants the "Me" marker while off duty): a plain
///   foreground stream, nothing is sent.
class LocationReporter extends ChangeNotifier {
  LocationReporter(this._api);

  final ApiClient _api;

  static const String _bufferKey = 'rider_location_buffer';
  static const int _batchSize = 500;

  /// Never hold more than this many unsent points (about 2 days at 15 s);
  /// the oldest are dropped first.
  static const int _maxBuffered = 10000;

  final List<Map<String, dynamic>> _buffer = [];
  bool _bufferLoaded = false;

  StreamSubscription<Position>? _sub;
  bool _subIsReporting = false;
  Timer? _timer;
  bool _sending = false;

  bool _reporting = false;
  int _intervalSeconds = 15;
  int _distanceFilterM = 25;
  int _watchers = 0;

  Position? _lastPosition;
  DateTime? _lastSentAt;
  String? _error;

  /// Called when the server answers a batch with `on_duty: false` (staff
  /// took the rider off duty): the app refreshes the profile and stops.
  VoidCallback? onServerOffDuty;

  bool get isReporting => _reporting;
  Position? get lastPosition => _lastPosition;
  DateTime? get lastSentAt => _lastSentAt;
  int get queued => _buffer.length;

  /// Why positions are not coming in (permission, GPS off), or null.
  String? get error => _error;

  // ------------------------------------------------------------ reporting

  /// Starts reporting. Location permission must already be granted (the UI
  /// asks first, see `location_permission.dart`); returns false otherwise.
  Future<bool> startReporting({
    required int intervalSeconds,
    required int distanceFilterM,
  }) async {
    await _loadBuffer();
    final permission = await Geolocator.checkPermission();
    if (permission != LocationPermission.whileInUse &&
        permission != LocationPermission.always) {
      _error = 'Location permission is needed to go on duty.';
      notifyListeners();
      return false;
    }
    final changed = !_reporting ||
        intervalSeconds != _intervalSeconds ||
        distanceFilterM != _distanceFilterM;
    _intervalSeconds = intervalSeconds;
    _distanceFilterM = distanceFilterM;
    _reporting = true;
    _error = null;
    if (changed || _sub == null || !_subIsReporting) {
      await _resubscribe(force: changed);
    }
    _timer?.cancel();
    _timer = Timer.periodic(
      Duration(seconds: _intervalSeconds),
      (_) => flush(),
    );
    notifyListeners();
    unawaited(flush());
    return true;
  }

  /// Stops reporting. With [flushFirst] the buffered points are sent before
  /// returning (best effort; whatever fails stays buffered for next time).
  Future<void> stopReporting({bool flushFirst = true}) async {
    if (flushFirst) {
      await flush();
    }
    _reporting = false;
    _timer?.cancel();
    _timer = null;
    await _resubscribe();
    notifyListeners();
  }

  /// The app came back to the foreground: re-open a stream that ended (GPS
  /// was switched off, permission changed) and send what is buffered.
  Future<void> resume() async {
    if (_reporting || _watchers > 0) {
      if (_sub == null) {
        await _resubscribe();
      }
    }
    if (_reporting) {
      await flush();
    }
  }

  /// Drops every unsent point (on sign-out: they belong to that session).
  Future<void> clearBuffer() async {
    _buffer.clear();
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_bufferKey);
    notifyListeners();
  }

  /// Sends buffered points, oldest first, in batches of up to 500.
  Future<void> flush() async {
    if (_sending) return;
    await _loadBuffer();
    if (_buffer.isEmpty) return;
    if (_api.token == null) return;
    _sending = true;
    try {
      // A few batches per call at most: the server allows 30 calls a minute.
      for (var round = 0; round < 4 && _buffer.isNotEmpty; round++) {
        final count = _buffer.length < _batchSize ? _buffer.length : _batchSize;
        final batch = List<Map<String, dynamic>>.from(_buffer.take(count));
        try {
          final result = await _api.post('locations', {'points': batch});
          _removeSent(batch);
          _lastSentAt = DateTime.now();
          await _persist();
          final data = result.data;
          if (data is Map && data['on_duty'] == false && _reporting) {
            onServerOffDuty?.call();
            break;
          }
        } on ApiException catch (e) {
          if (e.isValidation) {
            // The server will never accept this batch; don't retry it forever.
            _removeSent(batch);
            await _persist();
            continue;
          }
          // Offline, timeout, 5xx, 429, 401: keep the points and retry later.
          break;
        }
      }
    } finally {
      _sending = false;
      notifyListeners();
    }
  }

  void _removeSent(List<Map<String, dynamic>> batch) {
    // New points are only ever appended, so the batch is still the head of
    // the buffer unless it was cleared meanwhile (sign-out).
    if (_buffer.length >= batch.length &&
        identical(_buffer.first, batch.first)) {
      _buffer.removeRange(0, batch.length);
    }
  }

  // ------------------------------------------------------------- watching

  /// A screen wants live positions (the "Me" marker). Does nothing without
  /// permission; never asks for it.
  Future<void> addWatcher() async {
    _watchers++;
    if (_sub == null) {
      await _resubscribe();
    }
  }

  Future<void> removeWatcher() async {
    if (_watchers > 0) _watchers--;
    if (_watchers == 0 && !_reporting) {
      await _resubscribe();
    }
  }

  // ---------------------------------------------------------------- stream

  Future<void> _streamLock = Future<void>.value();

  /// Serialised so two callers can never open two platform streams.
  Future<void> _resubscribe({bool force = false}) {
    final next = _streamLock.then((_) => _doResubscribe(force: force));
    _streamLock = next.catchError((Object _) {});
    return next;
  }

  Future<void> _doResubscribe({bool force = false}) async {
    final wantReporting = _reporting;
    final wantStream = wantReporting || _watchers > 0;

    if (_sub != null &&
        (force || !wantStream || _subIsReporting != wantReporting)) {
      await _sub!.cancel();
      _sub = null;
    }
    if (!wantStream || _sub != null) return;

    final permission = await Geolocator.checkPermission();
    if (permission != LocationPermission.whileInUse &&
        permission != LocationPermission.always) {
      return;
    }

    _subIsReporting = wantReporting;
    late final StreamSubscription<Position> sub;
    sub = Geolocator.getPositionStream(
      locationSettings: _settings(reporting: wantReporting),
    ).listen(
      _onPosition,
      onError: (Object e) {
        if (e is LocationServiceDisabledException) {
          _error = 'GPS is turned off. Turn on location to keep reporting.';
        } else if (e is PermissionDeniedException) {
          _error = 'Location permission was removed.';
        } else {
          _error = 'Location unavailable: $e';
        }
        notifyListeners();
      },
      onDone: () {
        if (identical(_sub, sub)) _sub = null;
      },
      cancelOnError: false,
    );
    _sub = sub;
  }

  LocationSettings _settings({required bool reporting}) {
    final distance = reporting ? _distanceFilterM : 10;
    if (defaultTargetPlatform == TargetPlatform.android) {
      return AndroidSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: distance,
        intervalDuration: Duration(seconds: reporting ? _intervalSeconds : 5),
        foregroundNotificationConfig: reporting
            ? const ForegroundNotificationConfig(
                notificationTitle: 'You are on duty',
                notificationText:
                    'RedPharma Rider is sharing your location with the pharmacy.',
                notificationChannelName: 'On duty location',
                notificationIcon: AndroidResource(
                  name: 'ic_stat_rider',
                  defType: 'drawable',
                ),
                enableWakeLock: true,
                enableWifiLock: true,
                setOngoing: true,
                color: DsColor.primary,
              )
            : null,
      );
    }
    if (defaultTargetPlatform == TargetPlatform.iOS ||
        defaultTargetPlatform == TargetPlatform.macOS) {
      return AppleSettings(
        accuracy: LocationAccuracy.high,
        distanceFilter: distance,
        activityType: ActivityType.automotiveNavigation,
        pauseLocationUpdatesAutomatically: false,
        allowBackgroundLocationUpdates: reporting,
        showBackgroundLocationIndicator: reporting,
      );
    }
    return LocationSettings(
      accuracy: LocationAccuracy.high,
      distanceFilter: distance,
    );
  }

  void _onPosition(Position p) {
    _lastPosition = p;
    _error = null;
    if (_reporting) {
      _buffer.add({
        'lat': p.latitude,
        'lng': p.longitude,
        if (p.accuracy >= 0) 'accuracy': p.accuracy,
        if (p.heading >= 0) 'heading': p.heading,
        if (p.speed >= 0) 'speed': p.speed,
        'recorded_at': p.timestamp.toUtc().toIso8601String(),
      });
      if (_buffer.length > _maxBuffered) {
        _buffer.removeRange(0, _buffer.length - _maxBuffered);
      }
      unawaited(_persist());
    }
    notifyListeners();
  }

  // --------------------------------------------------------------- storage

  Future<void> _loadBuffer() async {
    if (_bufferLoaded) return;
    _bufferLoaded = true;
    try {
      final prefs = await SharedPreferences.getInstance();
      final raw = prefs.getString(_bufferKey);
      if (raw == null || raw.isEmpty) return;
      final decoded = jsonDecode(raw);
      if (decoded is List) {
        final saved = decoded
            .whereType<Map>()
            .map((m) => m.map((k, v) => MapEntry(k.toString(), v)))
            .toList();
        // Saved points are older than anything collected since start-up.
        _buffer.insertAll(0, saved);
      }
    } catch (_) {
      // A corrupt buffer is not worth crashing for.
    }
  }

  Future<void> _persist() async {
    try {
      final prefs = await SharedPreferences.getInstance();
      if (_buffer.isEmpty) {
        await prefs.remove(_bufferKey);
      } else {
        await prefs.setString(_bufferKey, jsonEncode(_buffer));
      }
    } catch (_) {}
  }

  @override
  void dispose() {
    _timer?.cancel();
    _sub?.cancel();
    super.dispose();
  }
}
