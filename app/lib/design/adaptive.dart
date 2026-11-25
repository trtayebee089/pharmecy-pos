import 'dart:math' as math;

import 'package:flutter/widgets.dart';

import 'tokens.dart';

/// Layout buckets, keyed off the **shortest side** of the window.
///
/// Shortest side rather than width, because that is what actually tells you
/// how much room a layout has: a phone rotated to landscape is still a phone
/// and should not suddenly get tablet chrome, while an unfolded foldable
/// genuinely has tablet-class room in both orientations.
enum Bp {
  /// < 340dp — the smallest phones still in service.
  compact,

  /// < 400dp — mainstream phones.
  regular,

  /// < 600dp — large phones, folded foldables.
  large,

  /// < 900dp — small tablets, unfolded foldables.
  expanded,

  /// >= 900dp — tablets and desktop-class windows.
  wide,
}

extension DsAdaptive on BuildContext {
  Size get vp => MediaQuery.sizeOf(this);
  double get vpWidth => vp.width;
  double get vpHeight => vp.height;
  bool get isLandscape => vp.width > vp.height;
  double get _short => vp.shortestSide;

  Bp get bp {
    final s = _short;
    if (s < 340) return Bp.compact;
    if (s < 400) return Bp.regular;
    if (s < 600) return Bp.large;
    if (s < 900) return Bp.expanded;
    return Bp.wide;
  }

  bool get isTablet => _short >= 600;

  /// Resolves a value for the current bucket, cascading down so callers only
  /// declare the breakpoints they actually care about.
  T pick<T>({
    required T base,
    T? compact,
    T? regular,
    T? large,
    T? expanded,
    T? wide,
  }) {
    switch (bp) {
      case Bp.compact:
        return compact ?? base;
      case Bp.regular:
        return regular ?? compact ?? base;
      case Bp.large:
        return large ?? regular ?? base;
      case Bp.expanded:
        return expanded ?? large ?? regular ?? base;
      case Bp.wide:
        return wide ?? expanded ?? large ?? base;
    }
  }

  /// Horizontal page inset.
  double get gutter =>
      pick<double>(base: 20, compact: 16, expanded: 28, wide: 40);

  EdgeInsets get pagePad => EdgeInsets.symmetric(horizontal: gutter);

  /// Caps line length on wide screens so a tablet does not stretch content
  /// into an unreadable ribbon.
  double get readableWidth => isTablet ? 760 : double.infinity;

  /// Product grid columns. Two on every phone, more only when there is genuine
  /// room, so cards stay large enough to show a real product image.
  int get productColumns =>
      pick<int>(base: 2, expanded: 3, wide: 4);

  /// Category grid columns.
  ///
  /// Pinned to **two** on every phone size. Three columns left each tile too
  /// narrow for its artwork, which is what made category images look like
  /// stamps in the corner of an empty card.
  int get categoryColumns => pick<int>(base: 2, expanded: 3, wide: 4);

  /// Space available inside the page gutters.
  double get contentWidth => math.min(vpWidth - gutter * 2, readableWidth);

  /// Width of one cell in an [n]-column grid at the current gutter.
  double gridCellWidth(int n, {double gap = DsSpace.x3}) =>
      (contentWidth - gap * (n - 1)) / n;

  /// Height ceiling for full-bleed hero media, so it can never swallow a short
  /// viewport (landscape phone, split screen, small foldable).
  ///
  /// The landscape allowance is deliberately much tighter: in landscape the
  /// viewport is short, and a hero sized as a fraction of it plus its
  /// indicators would leave almost no room for the feed beneath.
  double get heroMaxHeight =>
      math.min(vpHeight * (isLandscape ? 0.42 : 0.34), 320);

  /// Bottom inset that content must clear to sit above the floating nav pill.
  double get floatingNavInset =>
      DsSize.control + DsSpace.x6 + MediaQuery.viewPaddingOf(this).bottom;
}

/// Centres and width-caps a page body. Every V2 screen wraps its scroll view
/// in this so tablet layouts are composed, not stretched.
class DsPage extends StatelessWidget {
  final Widget child;

  const DsPage({super.key, required this.child});

  @override
  Widget build(BuildContext context) {
    return Center(
      child: ConstrainedBox(
        constraints: BoxConstraints(maxWidth: context.readableWidth),
        child: child,
      ),
    );
  }
}
