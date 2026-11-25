import 'package:flutter/material.dart';

import '../tokens.dart';
import '../typography.dart';

/// Toast-style feedback.
///
/// V2 replaces V1's coloured full-width snackbar with a compact dark card that
/// floats clear of the navigation pill. The colour lives in a small leading
/// disc rather than flooding the whole bar, which keeps urgent messages
/// readable without making the screen flash red or green.
class DsToast {
  const DsToast._();

  static void _show(
    BuildContext context, {
    required IconData icon,
    required String message,
    required Color accent,
    String? detail,
    Duration duration = const Duration(seconds: 3),
    SnackBarAction? action,
  }) {
    final messenger = ScaffoldMessenger.maybeOf(context);
    if (messenger == null) return;

    messenger
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          duration: duration,
          behavior: SnackBarBehavior.floating,
          backgroundColor: DsColor.obsidian,
          elevation: 0,
          padding: const EdgeInsets.symmetric(
            horizontal: DsSpace.x4,
            vertical: DsSpace.x3,
          ),
          shape: const RoundedRectangleBorder(borderRadius: DsRadius.rSm),
          // Clears the floating navigation pill.
          margin: const EdgeInsets.fromLTRB(
            DsSpace.x4,
            0,
            DsSpace.x4,
            DsSpace.x10 + DsSpace.x6,
          ),
          action: action,
          content: Row(
            children: [
              Container(
                width: 32,
                height: 32,
                decoration: BoxDecoration(
                  color: accent.withValues(alpha: 0.18),
                  borderRadius: DsRadius.rXs,
                ),
                child: Icon(icon, size: 17, color: accent),
              ),
              const SizedBox(width: DsSpace.x3),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      message,
                      style: DsType.textTheme().titleSmall?.copyWith(
                        color: DsColor.onDark,
                      ),
                    ),
                    if (detail != null) ...[
                      const SizedBox(height: 1),
                      Text(
                        detail,
                        style: DsType.textTheme().bodySmall?.copyWith(
                          color: DsColor.onDark.withValues(alpha: 0.72),
                          fontSize: 11.5,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),
        ),
      );
  }

  static void success(
    BuildContext context,
    String message, {
    String? detail,
    SnackBarAction? action,
  }) => _show(
    context,
    icon: Icons.check_rounded,
    message: message,
    detail: detail,
    accent: DsColor.success,
    action: action,
  );

  static void info(BuildContext context, String message, {String? detail}) =>
      _show(
        context,
        icon: Icons.info_outline_rounded,
        message: message,
        detail: detail,
        accent: DsColor.info,
      );

  static void warning(BuildContext context, String message, {String? detail}) =>
      _show(
        context,
        icon: Icons.warning_amber_rounded,
        message: message,
        detail: detail,
        accent: DsColor.warning,
        duration: const Duration(seconds: 4),
      );

  static void error(BuildContext context, String message, {String? detail}) =>
      _show(
        context,
        icon: Icons.error_outline_rounded,
        message: message,
        detail: detail,
        accent: DsColor.danger,
        duration: const Duration(seconds: 4),
      );

  /// The stock-ceiling notice.
  ///
  /// One helper so the wording is identical everywhere a quantity can be
  /// clamped — plus, minus, typed input, or add-to-cart — which is what makes
  /// the behaviour feel like a rule rather than a series of one-off errors.
  static void stockLimit(
    BuildContext context, {
    required int available,
    String? productName,
  }) => warning(
    context,
    available == 1
        ? 'Only 1 item is currently available in stock.'
        : 'Only $available items are currently available in stock.',
    detail: productName == null
        ? 'Quantity set to the maximum available.'
        : '$productName — quantity set to $available.',
  );
}
