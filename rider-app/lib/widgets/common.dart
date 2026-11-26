import 'package:flutter/material.dart';

import '../design/tokens.dart';
import '../design/typography.dart';
import '../helpers/format.dart';

/// White sheet with the V2 two-layer shadow (no borders).
class SurfaceCard extends StatelessWidget {
  const SurfaceCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(DsSpace.x4),
    this.color = DsColor.surface,
    this.onTap,
  });

  final Widget child;
  final EdgeInsetsGeometry padding;
  final Color color;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: color,
        borderRadius: DsRadius.rMd,
        boxShadow: DsShadow.low,
      ),
      child: Material(
        color: Colors.transparent,
        borderRadius: DsRadius.rMd,
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: Padding(padding: padding, child: child),
        ),
      ),
    );
  }
}

class StatusChip extends StatelessWidget {
  const StatusChip(this.status, {super.key});

  final String status;

  @override
  Widget build(BuildContext context) {
    final (fg, bg) = statusColors(status);
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
      decoration: BoxDecoration(color: bg, borderRadius: DsRadius.pill),
      child: Text(
        statusLabel(status).toUpperCase(),
        style: DsType.eyebrow(color: fg),
      ),
    );
  }
}

class SectionLabel extends StatelessWidget {
  const SectionLabel(this.text, {super.key});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: DsSpace.x2),
      child: Text(text.toUpperCase(), style: DsType.eyebrow()),
    );
  }
}

/// A big number with a caption (home stats).
class StatTile extends StatelessWidget {
  const StatTile({
    super.key,
    required this.value,
    required this.label,
    required this.icon,
    this.color = DsColor.primary,
  });

  final String value;
  final String label;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return SurfaceCard(
      padding: const EdgeInsets.symmetric(
        horizontal: DsSpace.x3,
        vertical: DsSpace.x3,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: DsSize.iconMd, color: color),
          DsSpace.v2,
          FittedBox(
            fit: BoxFit.scaleDown,
            alignment: Alignment.centerLeft,
            child: Text(value, style: DsType.numeral(20)),
          ),
          DsSpace.v1,
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: Theme.of(context).textTheme.bodySmall,
          ),
        ],
      ),
    );
  }
}

/// Centered message with an optional retry, for empty and error states.
class MessageState extends StatelessWidget {
  const MessageState({
    super.key,
    required this.icon,
    required this.title,
    this.body,
    this.onRetry,
  });

  final IconData icon;
  final String title;
  final String? body;
  final VoidCallback? onRetry;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.all(DsSpace.x8),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 72,
            height: 72,
            decoration: const BoxDecoration(
              color: DsColor.primarySoft,
              shape: BoxShape.circle,
            ),
            child: Icon(icon, size: 34, color: DsColor.primary),
          ),
          DsSpace.v4,
          Text(title, style: text.titleLarge, textAlign: TextAlign.center),
          if (body != null) ...[
            DsSpace.v2,
            Text(body!, style: text.bodyMedium, textAlign: TextAlign.center),
          ],
          if (onRetry != null) ...[
            DsSpace.v4,
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('Try again'),
            ),
          ],
        ],
      ),
    );
  }
}
