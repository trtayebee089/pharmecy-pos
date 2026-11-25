import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/providers/cart_provider.dart';

import '../adaptive.dart';
import '../text_metrics.dart';
import '../tokens.dart';
import 'bits.dart';
import 'motion.dart';

/// Floating pill navigation.
///
/// Content runs underneath it, the active destination is marked by a tinted
/// indicator, and it sits inset from the bottom edge where thumbs land.
///
/// ── Layout contract ──────────────────────────────────────────────────────
/// The bar's geometry is fixed and identical for every selection:
///
///  * Destinations are `Expanded`, so all four cells are the same width by
///    construction — selection cannot widen one.
///  * The indicator has an explicit width and height. It never sizes to its
///    child, so an icon or a badge cannot grow it.
///  * Labels are always present and always one line, with the line box
///    reserved from a real text measurement. Selection changes colour and
///    weight only, never size.
///  * The badge is a `Positioned` child of a `Stack` whose size is pinned to
///    the icon. It is a pure overlay and contributes nothing to layout.
///
/// The net effect: switching tabs animates colour alone. Nothing moves
/// vertically, and no destination is ever taller or wider than its neighbours.
class DsFloatingNav extends StatelessWidget {
  final int currentIndex;
  final ValueChanged<int> onSelect;

  const DsFloatingNav({
    super.key,
    required this.currentIndex,
    required this.onSelect,
  });

  /// Space occupied above the system safe area by the floating navigation.
  static double contentHeight(BuildContext context) =>
      _NavMetrics.resolve(context).cellHeight + DsSpace.x2 * 2 + DsSpace.x3;

  static const List<_Dest> _dests = [
    _Dest(Icons.home_outlined, Icons.home_rounded, 'Home'),
    _Dest(Icons.grid_view_outlined, Icons.grid_view_rounded, 'Browse'),
    _Dest(Icons.shopping_bag_outlined, Icons.shopping_bag_rounded, 'Cart'),
    _Dest(Icons.person_outline_rounded, Icons.person_rounded, 'Account'),
  ];

  @override
  Widget build(BuildContext context) {
    final cartCount = context.select<CartProvider, int>(
      (c) => c.totalUniqueItems,
    );

    final metrics = _NavMetrics.resolve(context);

    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          context.gutter,
          0,
          context.gutter,
          DsSpace.x3,
        ),
        // `heightFactor: 1` is load-bearing. This widget is mounted in a
        // bottom-anchored slot whose constraints allow the full screen height;
        // a plain `Center` would size to `constraints.biggest` and the bar
        // would claim the whole viewport.
        child: Align(
          alignment: Alignment.bottomCenter,
          heightFactor: 1,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 460),
            child: Container(
              padding: const EdgeInsets.symmetric(
                horizontal: DsSpace.x2,
                vertical: DsSpace.x2,
              ),
              decoration: BoxDecoration(
                color: DsColor.obsidian,
                borderRadius: DsRadius.rXl,
                boxShadow: DsShadow.high,
              ),
              child: Row(
                children: [
                  for (var i = 0; i < _dests.length; i++)
                    // Equal-width cells: selection cannot make one wider.
                    Expanded(
                      child: _NavCell(
                        dest: _dests[i],
                        active: i == currentIndex,
                        badge: i == 2 ? cartCount : 0,
                        metrics: metrics,
                        onTap: () => onSelect(i),
                      ),
                    ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _Dest {
  final IconData icon;
  final IconData activeIcon;
  final String label;

  const _Dest(this.icon, this.activeIcon, this.label);
}

/// Fixed geometry for every cell, measured once per build of the bar.
class _NavMetrics {
  final double indicatorWidth;
  final double indicatorHeight;
  final double iconSize;
  final double labelHeight;
  final TextStyle labelStyle;

  const _NavMetrics({
    required this.indicatorWidth,
    required this.indicatorHeight,
    required this.iconSize,
    required this.labelHeight,
    required this.labelStyle,
  });

  static const double gap = DsSpace.x1;

  static TextStyle _style(BuildContext context) => Theme.of(context)
      .textTheme
      .labelSmall!
      .copyWith(fontSize: 10.5, height: 1.2, letterSpacing: 0.1);

  factory _NavMetrics.resolve(BuildContext context) {
    final style = _style(context);
    return _NavMetrics(
      indicatorWidth: context.pick<double>(base: 56, compact: 46),
      indicatorHeight: context.pick<double>(base: 32, compact: 30),
      iconSize: context.pick<double>(base: 21, compact: 19),
      // Measured, so a larger OS text size grows every cell by the same
      // amount rather than only the ones whose label happens to wrap.
      labelHeight: DsText.lineHeight(context, style),
      labelStyle: style,
    );
  }

  /// Total cell height. Identical for all four destinations.
  double get cellHeight => indicatorHeight + gap + labelHeight;
}

class _NavCell extends StatelessWidget {
  final _Dest dest;
  final bool active;
  final int badge;
  final _NavMetrics metrics;
  final VoidCallback onTap;

  const _NavCell({
    required this.dest,
    required this.active,
    required this.badge,
    required this.metrics,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final foreground = active
        ? Colors.white
        : DsColor.onDark.withValues(alpha: 0.58);

    return DsPressable(
      onTap: onTap,
      depth: 0.06,
      semanticLabel: badge > 0 ? '${dest.label}, $badge items' : dest.label,
      child: SizedBox(
        // Explicit height: the cell cannot grow, whichever child it holds.
        height: metrics.cellHeight,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            _indicator(foreground),
            const SizedBox(height: _NavMetrics.gap),
            SizedBox(
              height: metrics.labelHeight,
              child: AnimatedDefaultTextStyle(
                duration: DsMotion.base,
                curve: DsMotion.ease,
                style: metrics.labelStyle.copyWith(
                  color: foreground,
                  fontWeight: active ? FontWeight.w800 : FontWeight.w600,
                ),
                child: Text(
                  dest.label,
                  maxLines: 1,
                  softWrap: false,
                  overflow: TextOverflow.ellipsis,
                  textAlign: TextAlign.center,
                ),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// The selected indicator.
  ///
  /// Explicit width and height, so it is the same box on every destination and
  /// only its fill animates. Nothing inside it can change its size.
  Widget _indicator(Color foreground) {
    return AnimatedContainer(
      duration: DsMotion.base,
      curve: DsMotion.ease,
      width: metrics.indicatorWidth,
      height: metrics.indicatorHeight,
      decoration: BoxDecoration(
        color: active ? DsColor.primaryBright : Colors.transparent,
        borderRadius: DsRadius.pill,
      ),
      child: Center(
        child: SizedBox(
          // Pins the stack to the icon, so the badge has a stable anchor and
          // no way to influence the measured size.
          width: metrics.iconSize,
          height: metrics.iconSize,
          child: Stack(
            clipBehavior: Clip.none,
            children: [
              Positioned.fill(
                child: AnimatedSwitcher(
                  duration: DsMotion.quick,
                  transitionBuilder: (child, a) =>
                      ScaleTransition(scale: a, child: child),
                  child: Icon(
                    active ? dest.activeIcon : dest.icon,
                    key: ValueKey(active),
                    size: metrics.iconSize,
                    color: foreground,
                  ),
                ),
              ),
              // Overlay only: `Positioned` children are excluded from a
              // Stack's size, so the count can never lift the indicator.
              if (badge > 0)
                Positioned(
                  top: -6,
                  right: -9,
                  child: DsCount(
                    count: badge,
                    ring: active ? DsColor.primaryBright : DsColor.obsidian,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}
