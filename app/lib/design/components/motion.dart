import 'dart:async';

import 'package:flutter/material.dart';

import '../tokens.dart';

/// Press feedback used by every tappable surface in V2.
///
/// Instead of Material's ink ripple — which fights borderless, rounded
/// surfaces and looks smeared on large tiles — a tap scales the whole element
/// down a couple of percent and springs back. It reads as physical, costs one
/// transform per frame, and works identically on any shape.
class DsPressable extends StatefulWidget {
  final Widget child;
  final VoidCallback? onTap;
  final VoidCallback? onLongPress;

  /// How far to compress on press. Large tiles want less than small chips.
  final double depth;

  final String? semanticLabel;
  final bool isButton;

  const DsPressable({
    super.key,
    required this.child,
    this.onTap,
    this.onLongPress,
    this.depth = 0.03,
    this.semanticLabel,
    this.isButton = true,
  });

  @override
  State<DsPressable> createState() => _DsPressableState();
}

class _DsPressableState extends State<DsPressable> {
  bool _down = false;

  void _set(bool v) {
    if (_down != v && mounted) setState(() => _down = v);
  }

  @override
  Widget build(BuildContext context) {
    final enabled = widget.onTap != null || widget.onLongPress != null;

    return Semantics(
      button: widget.isButton && enabled,
      label: widget.semanticLabel,
      child: GestureDetector(
        behavior: HitTestBehavior.opaque,
        onTap: widget.onTap,
        onLongPress: widget.onLongPress,
        onTapDown: enabled ? (_) => _set(true) : null,
        onTapUp: enabled ? (_) => _set(false) : null,
        onTapCancel: enabled ? () => _set(false) : null,
        child: AnimatedScale(
          scale: _down ? 1 - widget.depth : 1,
          duration: DsMotion.instant,
          curve: DsMotion.ease,
          child: widget.child,
        ),
      ),
    );
  }
}

/// One-shot entrance: fades up a few pixels.
///
/// Used to stagger a screen's sections on first paint. The animation runs once
/// and then the widget is inert, so it costs nothing during scrolling.
class DsEnter extends StatefulWidget {
  final Widget child;
  final Duration delay;
  final double offset;

  const DsEnter({
    super.key,
    required this.child,
    this.delay = Duration.zero,
    this.offset = 16,
  });

  /// Convenience for staggering a list: index 0 starts immediately, each
  /// subsequent item trails by 60ms up to a sensible ceiling.
  factory DsEnter.staggered({
    Key? key,
    required int index,
    required Widget child,
    double offset = 16,
  }) => DsEnter(
    key: key,
    delay: Duration(milliseconds: (index.clamp(0, 8)) * 60),
    offset: offset,
    child: child,
  );

  @override
  State<DsEnter> createState() => _DsEnterState();
}

class _DsEnterState extends State<DsEnter>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: DsMotion.base,
  );

  /// Held so the stagger can be cancelled. A bare `Future.delayed` keeps the
  /// State alive until it fires and leaves a pending timer behind when the
  /// widget is disposed early — which is exactly what happens when a screen is
  /// replaced mid-stagger.
  Timer? _stagger;

  @override
  void initState() {
    super.initState();
    if (widget.delay == Duration.zero) {
      _c.forward();
    } else {
      _stagger = Timer(widget.delay, () {
        if (mounted) _c.forward();
      });
    }
  }

  @override
  void dispose() {
    _stagger?.cancel();
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final curved = CurvedAnimation(parent: _c, curve: DsMotion.ease);

    return AnimatedBuilder(
      animation: curved,
      builder: (context, child) => Opacity(
        opacity: curved.value,
        child: Transform.translate(
          offset: Offset(0, widget.offset * (1 - curved.value)),
          child: child,
        ),
      ),
      child: widget.child,
    );
  }
}

/// Cross-fades between children, sized to the largest. Used for swapping
/// loading / empty / content states without the layout jumping.
class DsSwap extends StatelessWidget {
  final Widget child;
  final Duration duration;

  const DsSwap({super.key, required this.child, this.duration = DsMotion.base});

  @override
  Widget build(BuildContext context) {
    return AnimatedSwitcher(
      duration: duration,
      switchInCurve: DsMotion.ease,
      switchOutCurve: DsMotion.ease,
      layoutBuilder: (current, previous) => Stack(
        alignment: Alignment.topCenter,
        children: [...previous, if (current != null) current],
      ),
      child: child,
    );
  }
}
