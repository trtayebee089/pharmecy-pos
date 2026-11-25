import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../design/components/feedback.dart';

class URLHelper {
  /// Opens a URL in the external browser.
  static Future<void> openURL(BuildContext context, String url) async {
    final uri = Uri.parse(url);
    try {
      if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
        // Guarded, since the widget can be disposed during the await.
        if (!context.mounted) return;
        DsToast.error(context, 'Could not open link', detail: url);
      }
    } catch (e) {
      debugPrint('Error launching URL: $e');
      if (!context.mounted) return;
      DsToast.error(context, 'Error opening link', detail: url);
    }
  }
}
