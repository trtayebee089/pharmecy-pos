import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../tokens.dart';

/// The V2 loading indicator.
///
/// Replaces `CircularProgressIndicator`, whose Material styling reads as
/// "generic Flutter app" the moment it appears. This is a single tapering arc
/// sweeping a faint track, with an optional medical cross resting at the
/// centre — motion in one element only, so it stays calm rather than busy.
///
/// The arc is painted with a sweep gradient that fades to nothing at its tail,
/// which is what gives it the soft, premium quality a hard-edged stroke lacks.
class DsLoader extends StatefulWidget {
  final double size;
  final Color color;

  /// Faint ring behind the arc. Pass `null` to omit it.
  final Color? trackColor;

  final double strokeWidth;

  /// Shows a small medical cross at the centre. Static — the arc supplies all
  /// the motion.
  final bool showMark;

  final Duration period;

  const DsLoader({
    super.key,
    this.size = 44,
    this.color = DsColor.primary,
    this.trackColor,
    this.strokeWidth = 3,
    this.showMark = true,
    this.period = const Duration(milliseconds: 1400),
  });

  @override
  State<DsLoader> createState() => _DsLoaderState();
}

class _DsLoaderState extends State<DsLoader>
    with SingleTickerProviderStateMixin {
  late final AnimationController _c = AnimationController(
    vsync: this,
    duration: widget.period,
  )..repeat();

  @override
  void dispose() {
    _c.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: 'Loading',
      liveRegion: true,
      child: SizedBox(
        width: widget.size,
        height: widget.size,
        child: RepaintBoundary(
          child: AnimatedBuilder(
            animation: _c,
            builder: (context, _) => CustomPaint(
              painter: _ArcPainter(
                progress: _c.value,
                color: widget.color,
                track:
                    widget.trackColor ?? widget.color.withValues(alpha: 0.12),
                stroke: widget.strokeWidth,
                showMark: widget.showMark,
              ),
            ),
          ),
        ),
      ),
    );
  }
}

class _ArcPainter extends CustomPainter {
  final double progress;
  final Color color;
  final Color track;
  final double stroke;
  final bool showMark;

  const _ArcPainter({
    required this.progress,
    required this.color,
    required this.track,
    required this.stroke,
    required this.showMark,
  });

  @override
  void paint(Canvas canvas, Size size) {
    final rect = (Offset.zero & size).deflate(stroke / 2);
    final rotation = progress * 2 * math.pi;

    // Faint full ring so the arc reads as travelling around a path.
    canvas.drawArc(
      rect,
      0,
      2 * math.pi,
      false,
      Paint()
        ..style = PaintingStyle.stroke
        ..strokeWidth = stroke
        ..color = track,
    );

    // The sweep gradient starts transparent and ends opaque, so the arc has a
    // bright head and a tail that dissolves — no hard stroke ends.
    final arc = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = stroke
      ..strokeCap = StrokeCap.round
      ..shader = SweepGradient(
        colors: [color.withValues(alpha: 0), color],
        stops: const [0.0, 1.0],
        transform: GradientRotation(rotation),
      ).createShader(Offset.zero & size);

    canvas.drawArc(rect, rotation, math.pi * 1.35, false, arc);

    if (showMark) _paintCross(canvas, size);
  }

  /// A small rounded medical cross — the healthcare cue, held perfectly still.
  void _paintCross(Canvas canvas, Size size) {
    final centre = size.center(Offset.zero);
    final arm = size.width * 0.16;
    final thickness = size.width * 0.055;
    final paint = Paint()..color = color.withValues(alpha: 0.9);
    final radius = Radius.circular(thickness / 2);

    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: centre, width: arm * 2, height: thickness),
        radius,
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: centre, width: thickness, height: arm * 2),
        radius,
      ),
      paint,
    );
  }

  @override
  bool shouldRepaint(_ArcPainter old) =>
      old.progress != progress ||
      old.color != color ||
      old.track != track ||
      old.stroke != stroke ||
      old.showMark != showMark;
}
