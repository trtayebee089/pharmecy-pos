import 'package:flutter/material.dart';

import '../tokens.dart';

/// V2 loading placeholders.
///
/// A single shimmer controller drives a whole screen's skeletons via
/// [DsSkeleton], rather than one animation per placeholder. The sweep is a
/// soft light band travelling across the shapes — subtler than V1's grey
/// pulse, and it stops entirely once content arrives.
class DsSkeleton extends StatefulWidget {
  final Widget child;
  final bool enabled;

  const DsSkeleton({super.key, required this.child, this.enabled = true});

  @override
  State<DsSkeleton> createState() => _DsSkeletonState();
}

class _DsSkeletonState extends State<DsSkeleton>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c;

  @override
  void initState() {
    super.initState();
    _c = AnimationController(
      vsync: this,
      duration: const Duration(milliseconds: 1300),
    );
    if (widget.enabled) _c.repeat();
  }

  @override
  void didUpdateWidget(covariant DsSkeleton old) {
    super.didUpdateWidget(old);
    if (widget.enabled && !_c.isAnimating) {
      _c.repeat();
    } else if (!widget.enabled && _c.isAnimating) {
      _c.stop();
    }
  }

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    if (!widget.enabled) return widget.child;

    return AnimatedBuilder(
      animation: _c,
      builder: (context, child) => ShaderMask(
        blendMode: BlendMode.srcATop,
        shaderCallback: (rect) {
          final travel = _c.value * 2 - 0.5;
          return LinearGradient(
            begin: Alignment(travel - 1, -0.3),
            end: Alignment(travel + 1, 0.3),
            colors: const [
              DsColor.sunken,
              Color(0xFFF7FAF8),
              DsColor.sunken,
            ],
            stops: const [0.25, 0.5, 0.75],
          ).createShader(rect);
        },
        child: child,
      ),
      child: widget.child,
    );
  }
}

/// A single placeholder block. Wrap a tree of these in one [DsSkeleton].
class DsBone extends StatelessWidget {
  final double? width;
  final double? height;
  final BorderRadius radius;

  const DsBone({
    super.key,
    this.width,
    this.height,
    this.radius = DsRadius.rXs,
  });

  /// Placeholder shaped like a line of text.
  const DsBone.text({super.key, this.width, this.height = 12})
    : radius = DsRadius.pill;

  /// Placeholder shaped like a media plate.
  const DsBone.plate({super.key, this.width, this.height})
    : radius = DsRadius.rMd;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: width,
      height: height,
      decoration: BoxDecoration(color: DsColor.sunken, borderRadius: radius),
    );
  }
}
