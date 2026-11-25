import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../adaptive.dart';
import '../tokens.dart';
import 'bits.dart';

/// Auto-advancing carousel with peeking neighbours.
///
/// One component now serves both the hero and the How-To-Order rail, so their
/// timing, easing, drag-pause behaviour and indicators are identical.
///
/// Sizing is fully derived: the caller supplies an aspect ratio and an optional
/// height ceiling, and the carousel measures its own width to produce a height.
/// Nothing is hard-coded, which is what makes it survive landscape, foldables
/// and split screen.
class DsCarousel extends StatefulWidget {
  final int itemCount;
  final Widget Function(BuildContext context, int index, bool isActive)
  itemBuilder;

  /// Width ÷ height of each slide. Ignored when [height] is supplied.
  final double aspectRatio;

  /// Explicit slide height, overriding [aspectRatio].
  ///
  /// Media slides want a ratio — they should keep their artwork's proportions.
  /// Text slides want a height, because their content size is driven by the
  /// live text scale, and a ratio that fits at 1.0x will clip at 1.35x.
  final double? height;

  /// Hard height ceiling, so a tall slide can never dominate a short viewport.
  final double? maxHeight;

  /// How much of the neighbouring slide peeks in. 1.0 shows one slide only.
  final double viewportFraction;

  final Duration interval;
  final bool autoPlay;

  /// Shrinks inactive slides slightly, giving the rail depth.
  final bool scaleInactive;

  final EdgeInsetsGeometry? padding;
  final Color dotColor;

  const DsCarousel({
    super.key,
    required this.itemCount,
    required this.itemBuilder,
    required this.aspectRatio,
    this.height,
    this.maxHeight,
    this.viewportFraction = 1.0,
    this.interval = const Duration(seconds: 5),
    this.autoPlay = true,
    this.scaleInactive = true,
    this.padding,
    this.dotColor = DsColor.primary,
  });

  @override
  State<DsCarousel> createState() => _DsCarouselState();
}

class _DsCarouselState extends State<DsCarousel> {
  late final PageController _controller = PageController(
    viewportFraction: widget.viewportFraction,
  );
  Timer? _timer;
  int _index = 0;
  bool _dragging = false;

  @override
  void initState() {
    super.initState();
    _startAutoPlay();
  }

  void _startAutoPlay() {
    _timer?.cancel();
    if (!widget.autoPlay || widget.itemCount < 2) return;

    _timer = Timer.periodic(widget.interval, (_) {
      if (!mounted || _dragging || !_controller.hasClients) return;
      _controller.animateToPage(
        (_index + 1) % widget.itemCount,
        duration: DsMotion.slow,
        curve: DsMotion.emphasis,
      );
    });
  }

  @override
  void didUpdateWidget(covariant DsCarousel old) {
    super.didUpdateWidget(old);
    if (old.itemCount != widget.itemCount || old.autoPlay != widget.autoPlay) {
      _index = _index.clamp(0, math.max(0, widget.itemCount - 1));
      _startAutoPlay();
    }
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (widget.itemCount == 0) return const SizedBox.shrink();

    return Padding(
      padding: widget.padding ?? context.pagePad,
      child: LayoutBuilder(
        builder: (context, c) {
          final slideWidth = c.maxWidth * widget.viewportFraction;
          var height =
              widget.height ?? (slideWidth / widget.aspectRatio);
          if (widget.maxHeight != null) {
            height = math.min(height, widget.maxHeight!);
          }

          return Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                height: height,
                child: NotificationListener<ScrollNotification>(
                  onNotification: (n) {
                    if (n is ScrollStartNotification && n.dragDetails != null) {
                      _dragging = true;
                    } else if (n is ScrollEndNotification) {
                      _dragging = false;
                    }
                    return false;
                  },
                  child: PageView.builder(
                    controller: _controller,
                    itemCount: widget.itemCount,
                    physics: const BouncingScrollPhysics(),
                    onPageChanged: (i) => setState(() => _index = i),
                    itemBuilder: (context, i) {
                      final active = i == _index;
                      final slide = widget.itemBuilder(context, i, active);

                      if (!widget.scaleInactive) return slide;

                      return AnimatedScale(
                        scale: active ? 1 : 0.94,
                        duration: DsMotion.base,
                        curve: DsMotion.ease,
                        child: AnimatedOpacity(
                          opacity: active ? 1 : 0.72,
                          duration: DsMotion.base,
                          child: slide,
                        ),
                      );
                    },
                  ),
                ),
              ),
              const SizedBox(height: DsSpace.x4),
              DsDots(
                count: widget.itemCount,
                active: _index,
                color: widget.dotColor,
              ),
            ],
          );
        },
      ),
    );
  }
}
