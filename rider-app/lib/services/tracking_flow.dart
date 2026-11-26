import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../providers/auth_provider.dart';
import 'location_permission.dart';
import 'location_reporter.dart';

bool _inProgress = false;

/// If the rider is on duty but nothing is being reported (app restart,
/// pickup put them on duty, permission granted later), ask for permission
/// and start reporting.
///
/// Guarded: the permission prompts themselves pause and resume the app,
/// which would otherwise start a second run on top of the first.
Future<void> ensureTracking(BuildContext context) async {
  if (_inProgress) return;
  final auth = context.read<AuthProvider>();
  final reporter = context.read<LocationReporter>();
  if (!auth.isSignedIn || !auth.onDuty || reporter.isReporting) return;
  _inProgress = true;
  try {
    final ok = await ensureLocationPermission(context);
    if (!ok) return;
    await auth.syncTracking();
  } finally {
    _inProgress = false;
  }
}
