import 'package:flutter/widgets.dart';

/// Measures text with Flutter's own layout engine.
///
/// This exists to kill a whole class of bug. Estimating a line box as
/// `fontSize * someMultiplier * textScale` is guesswork: it ignores the font's
/// real ascent/descent, the style's `height` factor, and how `TextScaler`
/// actually transforms sizes on a given platform. Estimates that look right at
/// 1.0x drift at 1.35x, which shows up as either dead space under a card or
/// text overflowing out of it.
///
/// Everything here instead lays the text out with a [TextPainter] using the
/// same style and scaler the widget will render with, and reports what Flutter
/// will genuinely produce.
///
/// Results are memoised, because a lazy grid would otherwise re-measure for
/// every tile on every frame.
class DsText {
  const DsText._();

  static final Map<String, double> _cache = {};

  /// Bounded so a long-lived session measuring many distinct strings cannot
  /// grow the cache without limit.
  static void _store(String key, double value) {
    if (_cache.length > 400) _cache.clear();
    _cache[key] = value;
  }

  /// Height of exactly one line rendered in [style] at the current scale.
  ///
  /// The probe string carries both an ascender and a descender so the measured
  /// box matches a real line of mixed-case text.
  static double lineHeight(BuildContext context, TextStyle style) {
    final scaler = MediaQuery.textScalerOf(context);
    final key =
        'l|${style.fontFamily}|${style.fontSize}|${style.height}|'
        '${style.fontWeight}|${scaler.scale(100)}';

    final cached = _cache[key];
    if (cached != null) return cached;

    final painter = TextPainter(
      text: TextSpan(text: 'Ag', style: style),
      textDirection: TextDirection.ltr,
      textScaler: scaler,
      maxLines: 1,
    )..layout();

    _store(key, painter.height);
    return painter.height;
  }

  /// Height of [lines] stacked lines in [style].
  ///
  /// Used to *reserve* a slot: a `Text` with `maxLines: 2` occupies one line
  /// when the string is short, so a card whose height assumed two lines would
  /// show dead space. Reserving the two-line box up front makes every card in
  /// a grid the same height regardless of how long its title happens to be.
  static double linesHeight(
    BuildContext context,
    TextStyle style,
    int lines,
  ) => lineHeight(context, style) * lines;

  /// Height [text] will actually occupy when wrapped to [maxWidth].
  ///
  /// Use where content must not be cropped — a tutorial card whose body has to
  /// be fully visible — so the container can be sized to the text rather than
  /// the text truncated to the container.
  static double wrappedHeight(
    BuildContext context, {
    required String text,
    required TextStyle style,
    required double maxWidth,
    int? maxLines,
  }) {
    final scaler = MediaQuery.textScalerOf(context);
    final key =
        'w|${text.hashCode}|${style.fontSize}|${style.height}|'
        '${style.fontWeight}|${maxWidth.round()}|$maxLines|${scaler.scale(100)}';

    final cached = _cache[key];
    if (cached != null) return cached;

    final painter = TextPainter(
      text: TextSpan(text: text, style: style),
      textDirection: TextDirection.ltr,
      textScaler: scaler,
      maxLines: maxLines,
    )..layout(maxWidth: maxWidth);

    _store(key, painter.height);
    return painter.height;
  }
}
