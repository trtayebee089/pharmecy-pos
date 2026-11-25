import 'package:flutter/material.dart';

import '../tokens.dart';
import '../typography.dart';

/// Formats a taka amount.
///
/// Whole amounts drop their decimals (`৳450`, not `৳450.00`) so price rows stay
/// scannable; anything with paisa keeps two places. Callers that need columns
/// to align — order summaries, totals — pass [exact].
String taka(double amount, {bool exact = false}) {
  final whole = amount == amount.roundToDouble();
  return '৳${(whole && !exact) ? amount.toStringAsFixed(0) : amount.toStringAsFixed(2)}';
}

/// Price lockup: current amount, optional struck-through original.
///
/// Uses the monospaced numeral face so digits are tabular — totals do not
/// shuffle sideways as quantities change.
class DsPrice extends StatelessWidget {
  final double amount;
  final double? was;
  final double size;
  final Color color;

  /// Lets the "was" price drop to a second line when space is tight instead of
  /// forcing the row to overflow.
  final bool wrap;

  const DsPrice({
    super.key,
    required this.amount,
    this.was,
    this.size = 17,
    this.color = DsColor.ink,
    this.wrap = true,
  });

  @override
  Widget build(BuildContext context) {
    final now = Text(
      taka(amount),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: DsType.numeral(size, color: color),
    );

    if (was == null) return now;

    final before = Text(
      taka(was!),
      maxLines: 1,
      overflow: TextOverflow.ellipsis,
      style: DsType.numeral(
        size * 0.72,
        weight: FontWeight.w500,
        color: DsColor.inkFaint,
      ).copyWith(
        decoration: TextDecoration.lineThrough,
        decorationColor: DsColor.inkFaint,
      ),
    );

    if (wrap) {
      return Wrap(
        crossAxisAlignment: WrapCrossAlignment.center,
        spacing: DsSpace.x2,
        runSpacing: 0,
        children: [now, before],
      );
    }

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Flexible(child: now),
        const SizedBox(width: DsSpace.x2),
        Flexible(child: before),
      ],
    );
  }
}

/// Soft tinted tag. The workhorse status label — stock, category, delivery.
class DsTag extends StatelessWidget {
  final String label;
  final Color color;
  final IconData? icon;

  /// Solid tags are for high-emphasis callouts over imagery; soft tags tint
  /// their background and sit inline with text.
  final bool solid;

  const DsTag({
    super.key,
    required this.label,
    this.color = DsColor.primary,
    this.icon,
    this.solid = false,
  });

  @override
  Widget build(BuildContext context) {
    final fg = solid ? Colors.white : color;

    return Container(
      padding: EdgeInsets.symmetric(
        horizontal: icon == null ? DsSpace.x2 + 2 : DsSpace.x2,
        vertical: DsSpace.x1 + 1,
      ),
      decoration: BoxDecoration(
        color: solid ? color : color.withValues(alpha: 0.10),
        borderRadius: DsRadius.pill,
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          if (icon != null) ...[
            Icon(icon, size: 11, color: fg),
            const SizedBox(width: DsSpace.x1),
          ],
          Flexible(
            child: Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: DsType.eyebrow(color: fg).copyWith(letterSpacing: 0.4),
            ),
          ),
        ],
      ),
    );
  }
}

/// Corner ribbon for discounts.
///
/// A notched pill anchored to the plate rather than a floating badge, so it
/// reads as part of the product presentation.
class DsRibbon extends StatelessWidget {
  final String label;
  final Color color;

  const DsRibbon({super.key, required this.label, this.color = DsColor.flame});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(
        horizontal: DsSpace.x2 + 2,
        vertical: DsSpace.x1 + 2,
      ),
      decoration: BoxDecoration(
        color: color,
        borderRadius: const BorderRadius.only(
          topLeft: Radius.circular(DsRadius.md),
          bottomRight: Radius.circular(DsRadius.sm),
          topRight: Radius.circular(DsRadius.xs),
          bottomLeft: Radius.circular(DsRadius.xs),
        ),
        boxShadow: DsShadow.glow(color),
      ),
      child: Text(
        label,
        style: DsType.numeral(11, color: Colors.white),
      ),
    );
  }
}

/// Count bubble for icons (cart, notifications).
class DsCount extends StatelessWidget {
  final int count;
  final Color color;
  final Color ring;

  const DsCount({
    super.key,
    required this.count,
    this.color = DsColor.flame,
    this.ring = DsColor.surface,
  });

  @override
  Widget build(BuildContext context) {
    if (count <= 0) return const SizedBox.shrink();

    return AnimatedSwitcher(
      duration: DsMotion.quick,
      switchInCurve: DsMotion.enter,
      transitionBuilder: (child, a) => ScaleTransition(scale: a, child: child),
      child: Container(
        key: ValueKey(count),
        constraints: const BoxConstraints(minWidth: 18, minHeight: 18),
        padding: const EdgeInsets.symmetric(horizontal: 5, vertical: 1),
        decoration: BoxDecoration(
          color: color,
          borderRadius: DsRadius.pill,
          border: Border.all(color: ring, width: 2),
        ),
        child: Center(
          widthFactor: 1,
          child: Text(
            count > 99 ? '99+' : '$count',
            textAlign: TextAlign.center,
            style: DsType.numeral(9.5, color: Colors.white),
          ),
        ),
      ),
    );
  }
}

/// Carousel indicator. The active dot stretches into a bar rather than only
/// changing colour, so position is legible at a glance and without relying on
/// hue alone.
class DsDots extends StatelessWidget {
  final int count;
  final int active;
  final Color color;
  final Color idle;

  const DsDots({
    super.key,
    required this.count,
    required this.active,
    this.color = DsColor.primary,
    this.idle = DsColor.inkFaint,
  });

  @override
  Widget build(BuildContext context) {
    if (count <= 1) return const SizedBox.shrink();

    return Row(
      mainAxisAlignment: MainAxisAlignment.center,
      children: List.generate(count, (i) {
        final on = i == active;
        return AnimatedContainer(
          duration: DsMotion.base,
          curve: DsMotion.ease,
          width: on ? 20 : 6,
          height: 6,
          margin: const EdgeInsets.symmetric(horizontal: 3),
          decoration: BoxDecoration(
            color: on ? color : idle.withValues(alpha: 0.45),
            borderRadius: DsRadius.pill,
          ),
        );
      }),
    );
  }
}

/// Circular icon button on a tinted disc — the V2 icon affordance.
class DsIconDisc extends StatelessWidget {
  final IconData icon;
  final VoidCallback? onTap;
  final String tooltip;
  final Color background;
  final Color foreground;
  final double size;
  final List<BoxShadow>? shadow;

  const DsIconDisc({
    super.key,
    required this.icon,
    required this.tooltip,
    this.onTap,
    this.background = DsColor.surface,
    this.foreground = DsColor.ink,
    this.size = 44,
    this.shadow,
  });

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Semantics(
        button: true,
        label: tooltip,
        child: DecoratedBox(
          decoration: BoxDecoration(shape: BoxShape.circle, boxShadow: shadow),
          child: Material(
            color: background,
            shape: const CircleBorder(),
            clipBehavior: Clip.antiAlias,
            child: InkWell(
              onTap: onTap,
              child: SizedBox(
                width: size,
                height: size,
                child: Icon(icon, size: size * 0.44, color: foreground),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
