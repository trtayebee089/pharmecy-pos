import 'package:flutter/material.dart';
import 'package:google_fonts/google_fonts.dart';

import 'tokens.dart';

/// V2 type system.
///
/// Two families, each with a job:
///
///   * **Manrope** for everything you read — a geometric grotesque with open
///     counters that stays legible at 11px on a cheap phone.
///   * **Space Grotesk** for display headings and every number. Its slightly
///     mechanical figures give prices and quantities the precise, clinical
///     character a pharmacy wants, and it is what makes V2 feel unlike V1's
///     uniform Poppins.
///
/// The scale is tighter at the top and looser at the bottom than V1: big
/// headings get negative tracking and 1.1 leading so they read as one shape,
/// body copy gets 1.55 leading so long medicine descriptions stay scannable.
class DsType {
  const DsType._();

  static TextStyle _display(double size, {FontWeight weight = FontWeight.w700}) =>
      GoogleFonts.spaceGrotesk(
        fontSize: size,
        height: 1.1,
        fontWeight: weight,
        letterSpacing: size * -0.022,
        color: DsColor.ink,
      );

  static TextStyle _ui(
    double size, {
    FontWeight weight = FontWeight.w500,
    double height = 1.4,
    Color color = DsColor.ink,
    double tracking = 0,
  }) => GoogleFonts.manrope(
    fontSize: size,
    height: height,
    fontWeight: weight,
    letterSpacing: tracking,
    color: color,
  );

  /// Numerals: monospaced figures so prices and totals never jitter as digits
  /// change, and columns of money line up.
  static TextStyle numeral(
    double size, {
    FontWeight weight = FontWeight.w700,
    Color color = DsColor.ink,
  }) => GoogleFonts.spaceGrotesk(
    fontSize: size,
    height: 1.15,
    fontWeight: weight,
    letterSpacing: -0.2,
    color: color,
    fontFeatures: const [FontFeature.tabularFigures()],
  );

  static TextTheme textTheme() => TextTheme(
    // Hero numbers, campaign headlines, empty-state titles.
    displayLarge: _display(40),
    displayMedium: _display(32),
    displaySmall: _display(26),

    // Screen titles.
    headlineLarge: _display(24),
    headlineMedium: _display(21),
    headlineSmall: _display(18),

    // Section headers and card titles.
    titleLarge: _ui(17, weight: FontWeight.w800, height: 1.25),
    titleMedium: _ui(15, weight: FontWeight.w700, height: 1.3),
    titleSmall: _ui(13.5, weight: FontWeight.w700, height: 1.3),

    // Reading copy.
    bodyLarge: _ui(15, height: 1.55, color: DsColor.inkMuted),
    bodyMedium: _ui(13.5, height: 1.55, color: DsColor.inkMuted),
    bodySmall: _ui(12, height: 1.45, color: DsColor.inkSoft),

    // Buttons, chips, meta.
    labelLarge: _ui(15, weight: FontWeight.w800, tracking: 0.1),
    labelMedium: _ui(12.5, weight: FontWeight.w700, color: DsColor.inkMuted),
    labelSmall: _ui(
      10.5,
      weight: FontWeight.w800,
      color: DsColor.inkSoft,
      tracking: 0.6,
    ),
  );

  /// All-caps micro label used above sections and inside tags.
  static TextStyle eyebrow({Color color = DsColor.inkSoft}) =>
      _ui(10.5, weight: FontWeight.w800, color: color, tracking: 1.1);
}
