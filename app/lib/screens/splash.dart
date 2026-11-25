import 'dart:async';
import 'dart:math' as math;

import 'package:flutter/material.dart';

import '../design/components/backdrop.dart';
import '../design/components/loader.dart';
import '../design/text_metrics.dart';
import '../design/tokens.dart';
import '../design/typography.dart';
import '../main.dart';
import '../main_screen.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Launch experience.
///
/// The previous version handed a child widget to `AnimatedSplashScreen`. That
/// package centres its child inside its own sized box and drives its own fade,
/// which is precisely why the mark sat high, the loader read as a detached
/// second element, and the rest of the screen was blank: there was no way to
/// paint behind it or to stage the entrance.
///
/// This drives the same timeline directly, so the screen can be composed:
///
///  * A painted backdrop fills the viewport — base wash, two soft brand glows,
///    and sparse medical motifs — so no area is empty.
///  * Mark, loader and status line form **one optically centred stack** with
///    deliberate spacing, rather than two things drifting apart.
///  * The mark enters over ~700ms: fade, 0.92 → 1.0 scale, and a small rise.
///    The loader and copy follow slightly behind, so the eye lands on the
///    brand first.
///  * Status copy cross-fades through four messages while the app settles.
///
/// **Unchanged:** the 2500ms hold, the fade transition, and the destination —
/// `MainScreen(key: mainScreenKey)`. No initialisation, auth or API work
/// happens here, exactly as before.
/// ─────────────────────────────────────────────────────────────────────────
class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  /// How long the splash holds before handing off. Matches the previous
  /// `AnimatedSplashScreen(duration: 2500)`.
  static const Duration hold = Duration(milliseconds: 2500);

  static const List<String> messages = [
    'Preparing your pharmacy…',
    'Loading medicines…',
    'Checking your account…',
    'Getting everything ready…',
  ];

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen>
    with TickerProviderStateMixin {
  /// Entrance for the brand mark.
  late final AnimationController _intro = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 720),
  );

  /// Entrance for the loader and status line, trailing the mark.
  late final AnimationController _support = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 520),
  );

  Timer? _messageTimer;
  Timer? _exitTimer;
  int _message = 0;

  @override
  void initState() {
    super.initState();

    _intro.forward();
    Future.delayed(const Duration(milliseconds: 320), () {
      if (mounted) _support.forward();
    });

    // Four messages spread across the hold, the first already on screen.
    _messageTimer = Timer.periodic(
      Duration(
        milliseconds:
            SplashScreen.hold.inMilliseconds ~/ SplashScreen.messages.length,
      ),
      (_) {
        if (!mounted) return;
        setState(
          () => _message = (_message + 1) % SplashScreen.messages.length,
        );
      },
    );

    _exitTimer = Timer(SplashScreen.hold, _handOff);
  }

  @override
  void dispose() {
    _messageTimer?.cancel();
    _exitTimer?.cancel();
    _intro.dispose();
    _support.dispose();
    super.dispose();
  }

  /// Same destination and same fade the package produced.
  void _handOff() {
    if (!mounted) return;
    Navigator.of(context).pushReplacement(
      PageRouteBuilder(
        transitionDuration: const Duration(milliseconds: 420),
        pageBuilder: (_, _, _) => MainScreen(key: mainScreenKey),
        transitionsBuilder: (_, animation, _, child) => FadeTransition(
          opacity: CurvedAnimation(parent: animation, curve: DsMotion.ease),
          child: child,
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final dark = MediaQuery.platformBrightnessOf(context) == Brightness.dark;
    final size = MediaQuery.sizeOf(context);

    // Bounded by width *and* height, so a landscape phone or a tablet never
    // gets an oversized mark.
    final markWidth = math.min(
      math.min(size.width * 0.46, size.height * 0.20),
      220.0,
    );

    return Scaffold(
      backgroundColor: dark ? DsColor.canvasDark : DsColor.canvas,
      body: Stack(
        fit: StackFit.expand,
        children: [
          DsBackdrop(dark: dark),

          SafeArea(
            child: Padding(
              padding: const EdgeInsets.symmetric(horizontal: DsSpace.x8),
              child: Stack(
                children: [
                  // The stack is centred as one unit — mark, loader and copy
                  // read as a single composition rather than separate pieces.
                  Center(
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        _mark(markWidth, dark),
                        SizedBox(height: markWidth * 0.34),
                        _statusBlock(dark),
                      ],
                    ),
                  ),
                  Positioned(
                    left: 0,
                    right: 0,
                    bottom: DsSpace.x4,
                    child: _footer(dark),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Brand mark: fade in, ease up from 0.92 scale, and rise a few pixels.
  Widget _mark(double width, bool dark) {
    final fade = CurvedAnimation(parent: _intro, curve: Curves.easeOut);
    final motion = CurvedAnimation(parent: _intro, curve: DsMotion.ease);

    return AnimatedBuilder(
      animation: _intro,
      builder: (context, child) => Opacity(
        opacity: fade.value,
        child: Transform.translate(
          offset: Offset(0, 14 * (1 - motion.value)),
          child: Transform.scale(
            scale: 0.92 + 0.08 * motion.value,
            child: child,
          ),
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          // A soft halo lifts the mark off the wash without a hard card edge.
          Container(
            padding: EdgeInsets.all(width * 0.14),
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: dark
                  ? Colors.white.withValues(alpha: 0.04)
                  : Colors.white.withValues(alpha: 0.7),
              boxShadow: [
                BoxShadow(
                  color: DsColor.primary.withValues(alpha: dark ? 0.18 : 0.10),
                  blurRadius: width * 0.42,
                  spreadRadius: width * 0.02,
                ),
              ],
            ),
            child: Image.asset(
              'assets/images/logo.png',
              width: width,
              fit: BoxFit.contain,
              // A missing asset must not take the launch screen down.
              errorBuilder: (_, _, _) => SizedBox(
                width: width,
                height: width * 0.4,
                child: Center(
                  child: Text(
                    'RedPharma',
                    style: DsType.numeral(
                      width * 0.16,
                      color: DsBackdropInk.primary(dark),
                    ),
                  ),
                ),
              ),
            ),
          ),
          SizedBox(height: width * 0.16),
          Text(
            'YOUR TRUSTED PHARMACY',
            textAlign: TextAlign.center,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: DsType.eyebrow(
              color: DsBackdropInk.accent(dark),
            ).copyWith(letterSpacing: 2),
          ),
        ],
      ),
    );
  }

  /// Loader plus the cross-fading status line, entering just after the mark.
  Widget _statusBlock(bool dark) {
    final fade = CurvedAnimation(parent: _support, curve: Curves.easeOut);
    final style = Theme.of(context).textTheme.bodyMedium!.copyWith(
      color: DsBackdropInk.muted(dark),
    );

    // The line is height-reserved from a real measurement, so swapping
    // messages cannot nudge the composition.
    final lineHeight = DsText.lineHeight(context, style);

    return AnimatedBuilder(
      animation: _support,
      builder: (context, child) => Opacity(
        opacity: fade.value,
        child: Transform.translate(
          offset: Offset(0, 10 * (1 - fade.value)),
          child: child,
        ),
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          DsLoader(
            size: 40,
            color: DsBackdropInk.accent(dark),
            trackColor: DsBackdropInk.accent(dark).withValues(alpha: 0.12),
            strokeWidth: 2.6,
          ),
          const SizedBox(height: DsSpace.x5),
          SizedBox(
            height: lineHeight,
            child: AnimatedSwitcher(
              duration: const Duration(milliseconds: 340),
              switchInCurve: Curves.easeOut,
              switchOutCurve: Curves.easeIn,
              child: Text(
                SplashScreen.messages[_message],
                key: ValueKey(_message),
                textAlign: TextAlign.center,
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: style,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _footer(bool dark) {
    return AnimatedBuilder(
      animation: _support,
      builder: (context, child) =>
          Opacity(opacity: _support.value * 0.9, child: child),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.center,
        children: [
          Icon(
            Icons.verified_user_outlined,
            size: 13,
            color: DsBackdropInk.muted(dark),
          ),
          const SizedBox(width: DsSpace.x2),
          Flexible(
            child: Text(
              'Licensed pharmacy · Bangladesh',
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: DsType.eyebrow(color: DsBackdropInk.muted(dark)),
            ),
          ),
        ],
      ),
    );
  }
}
