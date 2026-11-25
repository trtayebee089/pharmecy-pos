import 'package:flutter/widgets.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// RedPharma V2 design tokens.
///
/// A deliberately different language from V1. Where V1 was white cards with
/// hairline borders on a grey canvas, V2 is:
///
///   * **Borderless.** Depth comes from two-layer soft shadows, never outlines.
///   * **Paper, not grey.** A faintly warm canvas so white surfaces read as
///     lifted sheets rather than as panels stamped on a slab.
///   * **Emerald, not red.** Deep health-green carries the interface; the
///     brand crimson is reserved for identity moments and urgency.
///   * **Squircle.** Large, soft radii throughout — nothing is a tight box.
/// ─────────────────────────────────────────────────────────────────────────
class DsColor {
  const DsColor._();

  // ── Ink (text and icons) ────────────────────────────────────────────────
  static const Color ink = Color(0xFF0A1512);
  static const Color inkStrong = Color(0xFF000000);
  static const Color inkMuted = Color(0xFF4C5A56);
  static const Color inkSoft = Color(0xFF7C8A86);
  static const Color inkFaint = Color(0xFFA9B4B0);
  static const Color onDark = Color(0xFFF7FAF9);

  // ── Canvas and surfaces ─────────────────────────────────────────────────
  /// Page background — a hair warm and green-leaning, never pure grey.
  static const Color canvas = Color(0xFFF4F7F5);
  static const Color surface = Color(0xFFFFFFFF);

  /// Recessed wells: inputs, steppers, inline code-like chips.
  static const Color sunken = Color(0xFFEDF1EF);

  /// Full-bleed dark sections (hero overlays, premium banners).
  static const Color obsidian = Color(0xFF08211B);

  // ── Dark brightness ─────────────────────────────────────────────────────
  // Used by surfaces that follow the platform brightness — currently the
  // splash. The hues are the same family as the light palette, just inverted
  // in luminance, so a future app-wide dark theme has a foundation rather
  // than a fresh set of one-off greys.
  static const Color canvasDark = Color(0xFF06100D);
  static const Color surfaceDark = Color(0xFF0D1A16);
  static const Color sunkenDark = Color(0xFF13241F);
  static const Color inkOnDark = Color(0xFFEDF4F1);
  static const Color inkMutedOnDark = Color(0xFF93A69F);

  // ── Primary: deep health emerald ────────────────────────────────────────
  static const Color primary = Color(0xFF0B6B53);
  static const Color primaryBright = Color(0xFF12A67F);
  static const Color primaryDeep = Color(0xFF064838);
  static const Color primarySoft = Color(0xFFE4F3ED);

  // ── Brand crimson: identity and prescription moments ────────────────────
  static const Color brand = Color(0xFFC2183C);
  static const Color brandSoft = Color(0xFFFDEAEE);

  // ── Accent: deals, urgency, countdowns ──────────────────────────────────
  static const Color flame = Color(0xFFFF5A3C);
  static const Color flameSoft = Color(0xFFFFEDE8);

  // ── Semantic ────────────────────────────────────────────────────────────
  static const Color success = Color(0xFF12A67F);
  static const Color successSoft = Color(0xFFE4F3ED);
  static const Color warning = Color(0xFFB77400);
  static const Color warningSoft = Color(0xFFFFF4E0);
  static const Color danger = Color(0xFFD2213F);
  static const Color dangerSoft = Color(0xFFFDEAEE);
  static const Color info = Color(0xFF2563C9);
  static const Color infoSoft = Color(0xFFEAF1FD);

  /// Hairline, used only where a true separator is unavoidable (dividers
  /// inside a single surface). Cards never take a border.
  static const Color hairline = Color(0xFFE4EAE7);
}

/// Layered shadows. Two stops — a tight contact shadow plus a wide ambient
/// one — which reads as a physical sheet instead of a uniform blur.
class DsShadow {
  const DsShadow._();

  static const Color _tint = Color(0xFF06231C);

  /// Resting surfaces.
  static List<BoxShadow> get low => [
    BoxShadow(
      color: _tint.withValues(alpha: 0.04),
      blurRadius: 2,
      offset: const Offset(0, 1),
    ),
    BoxShadow(
      color: _tint.withValues(alpha: 0.05),
      blurRadius: 12,
      offset: const Offset(0, 4),
    ),
  ];

  /// Lifted: product tiles, hero cards.
  static List<BoxShadow> get mid => [
    BoxShadow(
      color: _tint.withValues(alpha: 0.05),
      blurRadius: 4,
      offset: const Offset(0, 2),
    ),
    BoxShadow(
      color: _tint.withValues(alpha: 0.07),
      blurRadius: 24,
      offset: const Offset(0, 10),
    ),
  ];

  /// Floating: the pill navigation bar, sticky bars, sheets.
  static List<BoxShadow> get high => [
    BoxShadow(
      color: _tint.withValues(alpha: 0.06),
      blurRadius: 8,
      offset: const Offset(0, 4),
    ),
    BoxShadow(
      color: _tint.withValues(alpha: 0.12),
      blurRadius: 32,
      offset: const Offset(0, 14),
    ),
  ];

  /// Coloured glow beneath primary actions.
  static List<BoxShadow> glow(Color color) => [
    BoxShadow(
      color: color.withValues(alpha: 0.28),
      blurRadius: 20,
      offset: const Offset(0, 8),
    ),
  ];
}

/// 4pt spacing scale. Names describe intent, not pixels, so a density change
/// is a one-file edit.
class DsSpace {
  const DsSpace._();

  static const double x1 = 4;
  static const double x2 = 8;
  static const double x3 = 12;
  static const double x4 = 16;
  static const double x5 = 20;
  static const double x6 = 24;
  static const double x8 = 32;
  static const double x10 = 40;
  static const double x14 = 56;

  /// Vertical rhythm between major page sections.
  static const double section = 32;

  static const Widget v1 = SizedBox(height: x1);
  static const Widget v2 = SizedBox(height: x2);
  static const Widget v3 = SizedBox(height: x3);
  static const Widget v4 = SizedBox(height: x4);
  static const Widget v5 = SizedBox(height: x5);
  static const Widget v6 = SizedBox(height: x6);
  static const Widget v8 = SizedBox(height: x8);
  static const Widget v10 = SizedBox(height: x10);

  static const Widget h1 = SizedBox(width: x1);
  static const Widget h2 = SizedBox(width: x2);
  static const Widget h3 = SizedBox(width: x3);
  static const Widget h4 = SizedBox(width: x4);
  static const Widget h5 = SizedBox(width: x5);
  static const Widget h6 = SizedBox(width: x6);
}

/// Squircle geometry. Nothing in V2 is a tight-cornered box.
class DsRadius {
  const DsRadius._();

  static const double xs = 8;
  static const double sm = 14;
  static const double md = 20;
  static const double lg = 26;
  static const double xl = 34;
  static const double full = 999;

  static const BorderRadius rXs = BorderRadius.all(Radius.circular(xs));
  static const BorderRadius rSm = BorderRadius.all(Radius.circular(sm));
  static const BorderRadius rMd = BorderRadius.all(Radius.circular(md));
  static const BorderRadius rLg = BorderRadius.all(Radius.circular(lg));
  static const BorderRadius rXl = BorderRadius.all(Radius.circular(xl));
  static const BorderRadius pill = BorderRadius.all(Radius.circular(full));

  static const BorderRadius sheet = BorderRadius.vertical(
    top: Radius.circular(xl),
  );
}

/// Motion vocabulary. One place to tune the whole app's feel.
class DsMotion {
  const DsMotion._();

  static const Duration instant = Duration(milliseconds: 120);
  static const Duration quick = Duration(milliseconds: 220);
  static const Duration base = Duration(milliseconds: 320);
  static const Duration slow = Duration(milliseconds: 520);

  /// Default easing — decelerates hard, feels responsive.
  static const Curve ease = Curves.easeOutCubic;

  /// For things that appear: a touch of overshoot.
  static const Curve enter = Curves.easeOutBack;

  static const Curve emphasis = Curves.easeInOutCubic;
}

/// Minimum interactive sizes.
class DsSize {
  const DsSize._();

  static const double tap = 48;
  static const double control = 56;
  static const double iconSm = 16;
  static const double iconMd = 20;
  static const double iconLg = 24;
}
