import 'package:flutter/material.dart';

import '../adaptive.dart';
import '../tokens.dart';
import '../typography.dart';
import 'motion.dart';

/// The V2 surface primitive: a borderless white sheet lifted by shadow.
///
/// The absence of an outline is the point. V1 drew a hairline around every
/// card, which turned a busy screen into a grid of boxes; V2 lets elevation and
/// the warm canvas do the separating, so content reads as floating sheets.
class DsSurface extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry? padding;
  final EdgeInsetsGeometry? margin;
  final VoidCallback? onTap;
  final BorderRadius radius;
  final Color color;
  final List<BoxShadow>? shadow;
  final Gradient? gradient;

  /// Press-scale depth when tappable.
  final double pressDepth;

  final String? semanticLabel;

  const DsSurface({
    super.key,
    required this.child,
    this.padding,
    this.margin,
    this.onTap,
    this.radius = DsRadius.rMd,
    this.color = DsColor.surface,
    this.shadow,
    this.gradient,
    this.pressDepth = 0.02,
    this.semanticLabel,
  });

  @override
  Widget build(BuildContext context) {
    final body = Container(
      margin: margin,
      padding: padding,
      decoration: BoxDecoration(
        color: gradient == null ? color : null,
        gradient: gradient,
        borderRadius: radius,
        boxShadow: shadow ?? DsShadow.low,
      ),
      clipBehavior: Clip.antiAlias,
      child: child,
    );

    if (onTap == null) return body;

    return DsPressable(
      onTap: onTap,
      depth: pressDepth,
      semanticLabel: semanticLabel,
      child: body,
    );
  }
}

/// Section heading.
///
/// V2 leads with a small all-caps eyebrow above a display-weight title, which
/// gives every rail a consistent entry point and lets the eye skip between
/// sections without reading them. The action is a bare text button on the
/// right, never a boxed control.
class DsSection extends StatelessWidget {
  final String title;
  final String? eyebrow;
  final String? subtitle;
  final VoidCallback? onAction;
  final String actionLabel;

  /// Tints the eyebrow — used to code a rail (deals vs health vs brand).
  final Color accent;

  const DsSection({
    super.key,
    required this.title,
    this.eyebrow,
    this.subtitle,
    this.onAction,
    this.actionLabel = 'See all',
    this.accent = DsColor.primary,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x6,
        onAction != null ? DsSpace.x2 : context.gutter,
        DsSpace.x4,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.center,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                if (eyebrow != null) ...[
                  Text(
                    eyebrow!.toUpperCase(),
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: DsType.eyebrow(color: accent),
                  ),
                  const SizedBox(height: DsSpace.x1 + 2),
                ],
                Text(
                  title,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: text.headlineSmall,
                ),
                if (subtitle != null) ...[
                  const SizedBox(height: DsSpace.x1),
                  Text(
                    subtitle!,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: text.bodySmall,
                  ),
                ],
              ],
            ),
          ),
          if (onAction != null)
            TextButton(
              onPressed: onAction,
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(actionLabel),
                  const Icon(Icons.arrow_forward_rounded, size: 14),
                ],
              ),
            ),
        ],
      ),
    );
  }
}

/// Full-bleed dark panel for premium / campaign moments.
class DsObsidianPanel extends StatelessWidget {
  final Widget child;
  final EdgeInsetsGeometry padding;
  final BorderRadius radius;

  const DsObsidianPanel({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(DsSpace.x6),
    this.radius = DsRadius.rLg,
  });

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: padding,
      decoration: BoxDecoration(
        borderRadius: radius,
        gradient: const LinearGradient(
          colors: [DsColor.obsidian, DsColor.primaryDeep],
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
        ),
        boxShadow: DsShadow.mid,
      ),
      clipBehavior: Clip.antiAlias,
      child: child,
    );
  }
}
