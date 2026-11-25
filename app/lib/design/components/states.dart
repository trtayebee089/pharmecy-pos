import 'package:flutter/material.dart';

import '../adaptive.dart';
import '../tokens.dart';
import 'motion.dart';

/// Empty / error / success states.
///
/// V2 builds its illustration from layered geometry rather than shipping an
/// asset per state: concentric tinted discs behind a single glyph. It weighs
/// nothing, recolours per state, and scales cleanly to any density.
class DsState extends StatelessWidget {
  final IconData icon;
  final String title;
  final String message;
  final String? actionLabel;
  final VoidCallback? onAction;
  final String? secondaryLabel;
  final VoidCallback? onSecondary;
  final Color accent;

  /// Scrollable by default so it still works inside a RefreshIndicator — an
  /// empty screen is exactly where people reach to pull down.
  final bool scrollable;

  const DsState({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.secondaryLabel,
    this.onSecondary,
    this.accent = DsColor.primary,
    this.scrollable = true,
  });

  const DsState.empty({
    super.key,
    required this.icon,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.secondaryLabel,
    this.onSecondary,
    this.scrollable = true,
  }) : accent = DsColor.primary;

  const DsState.error({
    super.key,
    required this.message,
    this.title = 'Something went wrong',
    this.actionLabel = 'Try again',
    this.onAction,
    this.secondaryLabel,
    this.onSecondary,
    this.scrollable = true,
  }) : icon = Icons.cloud_off_rounded,
       accent = DsColor.danger;

  const DsState.success({
    super.key,
    required this.title,
    required this.message,
    this.actionLabel,
    this.onAction,
    this.secondaryLabel,
    this.onSecondary,
    this.scrollable = true,
  }) : icon = Icons.check_rounded,
       accent = DsColor.success;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    final body = Center(
      child: ConstrainedBox(
        constraints: const BoxConstraints(maxWidth: 400),
        child: Padding(
          padding: EdgeInsets.symmetric(
            horizontal: context.gutter,
            vertical: DsSpace.x10,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DsEnter(child: _glyph()),
              const SizedBox(height: DsSpace.x8),
              DsEnter(
                delay: const Duration(milliseconds: 70),
                child: Text(
                  title,
                  textAlign: TextAlign.center,
                  style: text.headlineMedium,
                ),
              ),
              const SizedBox(height: DsSpace.x3),
              DsEnter(
                delay: const Duration(milliseconds: 120),
                child: Text(
                  message,
                  textAlign: TextAlign.center,
                  style: text.bodyMedium,
                ),
              ),
              if (actionLabel != null && onAction != null) ...[
                const SizedBox(height: DsSpace.x8),
                DsEnter(
                  delay: const Duration(milliseconds: 170),
                  child: SizedBox(
                    width: double.infinity,
                    child: ElevatedButton(
                      onPressed: onAction,
                      style: ElevatedButton.styleFrom(
                        backgroundColor: accent,
                      ),
                      child: Text(actionLabel!),
                    ),
                  ),
                ),
              ],
              if (secondaryLabel != null && onSecondary != null) ...[
                const SizedBox(height: DsSpace.x2),
                TextButton(
                  onPressed: onSecondary,
                  child: Text(secondaryLabel!),
                ),
              ],
            ],
          ),
        ),
      ),
    );

    if (!scrollable) return body;

    return LayoutBuilder(
      builder: (context, c) => SingleChildScrollView(
        physics: const AlwaysScrollableScrollPhysics(),
        child: ConstrainedBox(
          constraints: BoxConstraints(minHeight: c.maxHeight),
          child: body,
        ),
      ),
    );
  }

  Widget _glyph() {
    return SizedBox(
      width: 148,
      height: 148,
      child: Stack(
        alignment: Alignment.center,
        children: [
          _disc(148, 0.05),
          _disc(112, 0.08),
          _disc(76, 0.12),
          Icon(icon, size: 34, color: accent),
        ],
      ),
    );
  }

  Widget _disc(double size, double alpha) => Container(
    width: size,
    height: size,
    decoration: BoxDecoration(
      shape: BoxShape.circle,
      color: accent.withValues(alpha: alpha),
    ),
  );
}
