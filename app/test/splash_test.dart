import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/components/backdrop.dart';
import 'package:redpharmabd_app/design/components/loader.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/splash.dart';

/// The splash has to survive every viewport and brightness, hold for exactly
/// as long as it did before, and hand off to the same destination.
const _sizes = <String, Size>{
  'small phone': Size(320, 568),
  'compact phone': Size(360, 640),
  'standard phone': Size(390, 844),
  'large phone': Size(430, 932),
  'phone landscape': Size(844, 390),
  'tablet portrait': Size(768, 1024),
  'tablet landscape': Size(1024, 768),
};

const _scales = <double>[1.0, 1.25, 1.35];

Future<void> _pumpSplash(
  WidgetTester tester, {
  Size size = const Size(390, 844),
  double scale = 1.0,
  Brightness brightness = Brightness.light,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider(create: (_) => CartProvider()),
      ],
      child: MaterialApp(
        theme: DsTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(scale),
            platformBrightness: brightness,
          ),
          child: const SplashScreen(),
        ),
      ),
    ),
  );
  // One frame plus a little, so the entrance is mid-flight rather than settled.
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

/// Unmounts the tree so the splash cancels its timers.
///
/// `pumpAndSettle` cannot be used here: once the splash hands off, MainScreen
/// runs continuously-repeating loader and carousel animations, so the frame
/// scheduler never goes quiet.
Future<void> _teardown(WidgetTester tester) async {
  await tester.pumpWidget(const SizedBox.shrink());
  await tester.pump();
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('composition', () {
    testWidgets('paints a backdrop, a custom loader and status copy', (
      tester,
    ) async {
      await _pumpSplash(tester);

      expect(find.byType(DsBackdrop), findsOneWidget);
      expect(find.byType(DsLoader), findsOneWidget);

      // The default Material spinner must not appear anywhere.
      expect(find.byType(CircularProgressIndicator), findsNothing);
      expect(find.byType(LinearProgressIndicator), findsNothing);

      expect(find.text(SplashScreen.messages.first), findsOneWidget);
      expect(find.text('YOUR TRUSTED PHARMACY'), findsOneWidget);

      await _teardown(tester);
    });

    testWidgets('the mark is optically centred', (tester) async {
      await _pumpSplash(tester);
      // Let the entrance finish so the transform has settled.
      await tester.pump(const Duration(milliseconds: 800));

      final logo = tester.getRect(find.byType(Image));
      final screen = tester.getRect(find.byType(Scaffold));

      expect(
        logo.center.dx,
        closeTo(screen.center.dx, 1.0),
        reason: 'mark is not horizontally centred',
      );

      await _teardown(tester);
    });

    testWidgets('status copy advances', (tester) async {
      await _pumpSplash(tester);
      expect(find.text(SplashScreen.messages[0]), findsOneWidget);

      await tester.pump(const Duration(milliseconds: 700));
      expect(find.text(SplashScreen.messages[1]), findsOneWidget);

      await _teardown(tester);
    });
  });

  group('hand-off is unchanged', () {
    testWidgets('holds for 2500ms, then replaces with MainScreen', (
      tester,
    ) async {
      await _pumpSplash(tester);

      // Still on the splash just before the hold elapses.
      await tester.pump(const Duration(milliseconds: 1900));
      expect(find.byType(SplashScreen), findsOneWidget);

      // Cross the boundary and let the fade run.
      await tester.pump(const Duration(milliseconds: 400));
      await tester.pump(const Duration(milliseconds: 600));

      expect(
        find.byType(SplashScreen),
        findsNothing,
        reason: 'splash should have been replaced, not stacked',
      );

      await _teardown(tester);
    });
  });

  group('responsive and brightness', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        for (final brightness in Brightness.values) {
          testWidgets(
            '${entry.key} @ ${scale}x ${brightness.name}',
            (tester) async {
              await _pumpSplash(
                tester,
                size: entry.value,
                scale: scale,
                brightness: brightness,
              );

              expect(
                tester.takeException(),
                isNull,
                reason:
                    'overflow at ${entry.key} @ ${scale}x ${brightness.name}',
              );

              // Everything essential is on screen and within bounds.
              final screen = tester.getRect(find.byType(Scaffold));
              final loader = tester.getRect(find.byType(DsLoader));

              expect(
                screen.contains(loader.topLeft) &&
                    screen.contains(loader.bottomRight - const Offset(1, 1)),
                isTrue,
                reason: 'loader fell outside the viewport on ${entry.key}',
              );

              await _teardown(tester);
            },
          );
        }
      }
    }
  });

  group('loader', () {
    testWidgets('animates without throwing and cleans up', (tester) async {
      await tester.pumpWidget(
        const MaterialApp(
          home: Scaffold(body: Center(child: DsLoader())),
        ),
      );
      await tester.pump(const Duration(milliseconds: 300));
      await tester.pump(const Duration(milliseconds: 300));
      expect(tester.takeException(), isNull);

      // Replacing it must dispose the controller cleanly.
      await tester.pumpWidget(
        const MaterialApp(home: Scaffold(body: SizedBox())),
      );
      expect(tester.takeException(), isNull);
    });
  });
}
