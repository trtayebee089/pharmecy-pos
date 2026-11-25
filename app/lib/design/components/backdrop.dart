import 'package:flutter/material.dart';

import '../tokens.dart';

/// Painted brand backdrop.
///
/// Three layers, all extremely low contrast, so the surface has depth without
/// competing with the content sitting on it:
///
///  1. A vertical base wash from canvas to a barely-tinted emerald.
///  2. Two soft radial glows — emerald high on one side, crimson low on the
///     other — which is what stops a large empty area reading as blank.
///  3. Sparse medical motifs: outlined crosses and capsule silhouettes at
///     around 4% opacity, rotated slightly so they feel scattered rather than
///     placed.
///
/// Painted rather than composed from widgets: it is a handful of draw calls on
/// one layer, and it scales to any viewport without a stack of `Positioned`
/// boxes that would need their own responsive rules.
class DsBackdrop extends StatelessWidget {
  final bool dark;

  /// Overall strength. Lower it when the backdrop sits behind dense content.
  final double intensity;

  const DsBackdrop({super.key, this.dark = false, this.intensity = 1});

  @override
  Widget build(BuildContext context) {
    return RepaintBoundary(
      child: CustomPaint(
        painter: _BackdropPainter(dark: dark, intensity: intensity),
        size: Size.infinite,
      ),
    );
  }
}

class _BackdropPainter extends CustomPainter {
  final bool dark;
  final double intensity;

  const _BackdropPainter({required this.dark, required this.intensity});

  @override
  void paint(Canvas canvas, Size size) {
    final rect = Offset.zero & size;

    // 1 ─ base wash
    canvas.drawRect(
      rect,
      Paint()
        ..shader = LinearGradient(
          begin: Alignment.topCenter,
          end: Alignment.bottomCenter,
          colors: dark
              ? const [DsColor.canvasDark, Color(0xFF081813), DsColor.canvasDark]
              : const [Color(0xFFFBFDFC), DsColor.canvas, Color(0xFFEFF5F2)],
        ).createShader(rect),
    );

    // 2 ─ radial glows
    _glow(
      canvas,
      centre: Offset(size.width * 0.82, size.height * 0.16),
      radius: size.shortestSide * 0.85,
      color: DsColor.primaryBright,
      alpha: (dark ? 0.16 : 0.13) * intensity,
    );
    _glow(
      canvas,
      centre: Offset(size.width * 0.10, size.height * 0.88),
      radius: size.shortestSide * 0.75,
      color: DsColor.brand,
      alpha: (dark ? 0.10 : 0.07) * intensity,
    );

    // 3 ─ motifs
    final motif = Paint()
      ..style = PaintingStyle.stroke
      ..strokeWidth = 1.6
      ..color = (dark ? DsColor.primaryBright : DsColor.primary).withValues(
        alpha: (dark ? 0.09 : 0.055) * intensity,
      );

    _cross(canvas, Offset(size.width * 0.14, size.height * 0.20), 26, -0.22, motif);
    _cross(canvas, Offset(size.width * 0.88, size.height * 0.63), 18, 0.35, motif);
    _capsule(canvas, Offset(size.width * 0.24, size.height * 0.74), 54, 22, 0.5, motif);
    _capsule(canvas, Offset(size.width * 0.78, size.height * 0.33), 38, 16, -0.35, motif);
  }

  void _glow(
    Canvas canvas, {
    required Offset centre,
    required double radius,
    required Color color,
    required double alpha,
  }) {
    canvas.drawCircle(
      centre,
      radius,
      Paint()
        ..shader = RadialGradient(
          colors: [color.withValues(alpha: alpha), color.withValues(alpha: 0)],
        ).createShader(Rect.fromCircle(center: centre, radius: radius)),
    );
  }

  void _cross(
    Canvas canvas,
    Offset centre,
    double arm,
    double angle,
    Paint paint,
  ) {
    canvas.save();
    canvas.translate(centre.dx, centre.dy);
    canvas.rotate(angle);

    final t = arm * 0.36;
    final r = Radius.circular(t / 2);
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset.zero, width: arm, height: t),
        r,
      ),
      paint,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(
        Rect.fromCenter(center: Offset.zero, width: t, height: arm),
        r,
      ),
      paint,
    );
    canvas.restore();
  }

  void _capsule(
    Canvas canvas,
    Offset centre,
    double length,
    double width,
    double angle,
    Paint paint,
  ) {
    canvas.save();
    canvas.translate(centre.dx, centre.dy);
    canvas.rotate(angle);

    final body = Rect.fromCenter(
      center: Offset.zero,
      width: length,
      height: width,
    );
    canvas.drawRRect(
      RRect.fromRectAndRadius(body, Radius.circular(width / 2)),
      paint,
    );
    // The seam line that makes it read as a capsule rather than a pill shape.
    canvas.drawLine(
      Offset(0, -width / 2),
      Offset(0, width / 2),
      paint,
    );
    canvas.restore();
  }

  @override
  bool shouldRepaint(_BackdropPainter old) =>
      old.dark != dark || old.intensity != intensity;
}

/// Small helper so callers can pick ink that reads on the current backdrop.
class DsBackdropInk {
  const DsBackdropInk._();

  static Color primary(bool dark) =>
      dark ? DsColor.inkOnDark : DsColor.ink;

  static Color muted(bool dark) =>
      dark ? DsColor.inkMutedOnDark : DsColor.inkMuted;

  static Color accent(bool dark) =>
      dark ? DsColor.primaryBright : DsColor.primary;

  /// Surface tint for cards floating on the backdrop.
  static Color surface(bool dark) =>
      dark ? DsColor.surfaceDark : DsColor.surface;
}
