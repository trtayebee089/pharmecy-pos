import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/carousel.dart';
import 'package:redpharmabd_app/design/components/floating_nav.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/product_tile.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/components/stepper.dart';
import 'package:redpharmabd_app/design/components/steps.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/widgets/auth/auth_scaffold.dart';
import 'package:redpharmabd_app/widgets/home/how_to_order.dart';

/// Viewports the V2 UI has to survive, in logical pixels.
const _sizes = <String, Size>{
  'small phone': Size(320, 568),
  'compact phone': Size(360, 640),
  'standard phone': Size(390, 844),
  'large phone': Size(430, 932),
  'folded foldable': Size(344, 882),
  'unfolded foldable': Size(690, 830),
  'tablet portrait': Size(768, 1024),
  'tablet landscape': Size(1024, 768),
  'phone landscape': Size(844, 390),
};

/// Text scales spanning the range the app clamps to.
const _scales = <double>[0.85, 1.0, 1.35];

final _sample = <String, dynamic>{
  'id': 1,
  'name': 'Napa Extend 665mg Tablet (Extended Release)',
  'slug': 'napa-extend-665',
  'price': 4.5,
  'promotion_price': 6.0,
  'image': '',
  'qty': 3,
  'available_qty': 3,
  'brand': {'id': 1, 'title': 'Beximco Pharmaceuticals Ltd.'},
  'category': {'id': 1, 'name': 'Tablet', 'slug': 'tablet', 'image': ''},
  'unit': null,
};

Future<void> _pump(
  WidgetTester tester,
  Size size,
  double scale,
  Widget child, {
  Widget Function(Widget body)? scaffold,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(
    MultiProvider(
      providers: [ChangeNotifierProvider(create: (_) => CartProvider())],
      child: MaterialApp(
        theme: DsTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(scale),
          ),
          child: scaffold?.call(child) ?? Scaffold(body: child),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 450));
}

/// Fails if the tree threw during layout or paint — a RenderFlex overflow
/// surfaces here.
void _noOverflow(WidgetTester tester, String label) {
  final e = tester.takeException();
  expect(e, isNull, reason: 'Layout error at $label: $e');
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('category grid is exactly two columns on phones', () {
    for (final entry in _sizes.entries) {
      testWidgets(entry.key, (tester) async {
        late int columns;
        late bool tablet;

        await _pump(
          tester,
          entry.value,
          1.0,
          Builder(
            builder: (context) {
              columns = context.categoryColumns;
              tablet = context.isTablet;
              return const SizedBox.shrink();
            },
          ),
        );

        if (tablet) {
          expect(columns, greaterThanOrEqualTo(2));
        } else {
          expect(
            columns,
            2,
            reason: 'phones must show two columns, not three',
          );
        }
      });
    }
  });

  group('product tile', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pump(
            tester,
            entry.value,
            scale,
            Builder(
              builder: (context) => Align(
                alignment: Alignment.topLeft,
                child: SizedBox(
                  width: 170,
                  height: DsTileSpec.resolve(context).totalHeight(170),
                  child: DsProductTile(product: _sample),
                ),
              ),
            ),
          );
          _noOverflow(tester, '${entry.key} @ ${scale}x');
        });
      }
    }
  });

  group('product row', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pump(
            tester,
            entry.value,
            scale,
            Align(
              alignment: Alignment.topLeft,
              child: DsProductRow(product: _sample),
            ),
          );
          _noOverflow(tester, '${entry.key} @ ${scale}x');
        });
      }
    }
  });

  group('how-to-order carousel', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pump(
            tester,
            entry.value,
            scale,
            const Align(
              alignment: Alignment.topLeft,
              child: HowToOrderCarousel(),
            ),
          );
          _noOverflow(tester, '${entry.key} @ ${scale}x');

          // Full width, one card per slide, and the whole card has to fit on
          // screen at once so nothing is ever half-visible.
          final box = tester.getSize(find.byType(HowToOrderCarousel));
          expect(box.width, closeTo(entry.value.width, 0.5));
          expect(
            box.height,
            lessThanOrEqualTo(entry.value.height),
            reason: 'card taller than the viewport on ${entry.key}',
          );
        });
      }
    }

    testWidgets('advances on swipe and reports position with indicators', (
      tester,
    ) async {
      await _pump(
        tester,
        const Size(390, 844),
        1.0,
        const Align(
          alignment: Alignment.topLeft,
          child: HowToOrderCarousel(),
        ),
      );

      expect(find.text('STEP 1 OF 4'), findsOneWidget);
      expect(find.byType(DsDots), findsOneWidget);

      await tester.drag(find.byType(PageView), const Offset(-400, 0));
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('STEP 2 OF 4'), findsOneWidget);
      _noOverflow(tester, 'carousel swipe');
    });
  });

  group('hero carousel is responsive', () {
    for (final entry in _sizes.entries) {
      testWidgets(entry.key, (tester) async {
        await _pump(
          tester,
          entry.value,
          1.0,
          Builder(
            builder: (context) => Align(
              alignment: Alignment.topLeft,
              child: DsCarousel(
                itemCount: 3,
                aspectRatio: 1400 / 480,
                maxHeight: context.heroMaxHeight,
                itemBuilder: (_, _, _) => const ColoredBox(color: Colors.grey),
              ),
            ),
          ),
        );

        _noOverflow(tester, entry.key);

        final box = tester.getSize(find.byType(DsCarousel));
        expect(box.width, closeTo(entry.value.width, 0.5));
        // Never allowed to dominate a short viewport.
        expect(
          box.height,
          lessThan(entry.value.height * 0.6),
          reason: 'hero dominates the viewport on ${entry.key}',
        );
      });
    }
  });

  group('floating navigation', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pump(
            tester,
            entry.value,
            scale,
            DsFloatingNav(currentIndex: 2, onSelect: (_) {}),
            scaffold: (body) => Scaffold(
              body: const SizedBox.expand(),
              bottomNavigationBar: body,
            ),
          );
          _noOverflow(tester, '${entry.key} @ ${scale}x');

          // Only the active destination shows its label.
          expect(find.text('Cart'), findsOneWidget);
        });
      }
    }
  });

  group('quantity stepper', () {
    testWidgets('reports a blocked increase instead of going dead', (
      tester,
    ) async {
      var blocked = 0;
      var increased = 0;

      await _pump(
        tester,
        const Size(390, 844),
        1.0,
        Center(
          child: DsStepper(
            value: 42,
            atMax: true,
            onDecrease: () {},
            onIncrease: () => increased++,
            onBlocked: () => blocked++,
          ),
        ),
      );

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump(const Duration(milliseconds: 400));

      expect(blocked, 1, reason: 'the cap must be explained, not ignored');
      expect(increased, 0, reason: 'quantity must not pass the ceiling');
      _noOverflow(tester, 'stepper at max');
    });

    for (final scale in _scales) {
      testWidgets('lays out at ${scale}x', (tester) async {
        await _pump(
          tester,
          const Size(320, 568),
          scale,
          Center(
            child: DsStepper(value: 7, onDecrease: () {}, onIncrease: () {}),
          ),
        );
        _noOverflow(tester, 'stepper @ ${scale}x');
      });
    }
  });

  group('states and chrome', () {
    for (final entry in _sizes.entries) {
      testWidgets('empty state on ${entry.key}', (tester) async {
        await _pump(
          tester,
          entry.value,
          1.0,
          DsState.empty(
            icon: Icons.shopping_bag_outlined,
            title: 'Your bag is empty',
            message: 'Browse medicines and health essentials.',
            actionLabel: 'Start shopping',
            onAction: () {},
          ),
        );
        _noOverflow(tester, entry.key);
        expect(find.text('Your bag is empty'), findsOneWidget);
      });

      testWidgets('section header on ${entry.key}', (tester) async {
        await _pump(
          tester,
          entry.value,
          1.35,
          DsSection(
            eyebrow: 'Popular',
            title: 'Best sellers',
            subtitle: 'What people are buying right now',
            onAction: () {},
          ),
        );
        _noOverflow(tester, entry.key);
      });
    }
  });

  group('checkout step rail', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pump(
            tester,
            entry.value,
            scale,
            const Padding(
              padding: EdgeInsets.all(20),
              child: DsSteps(
                labels: ['Delivery details', 'Payment', 'Review order'],
                current: 2,
              ),
            ),
          );
          _noOverflow(tester, '${entry.key} @ ${scale}x');
          expect(find.text('STEP 2 OF 3'), findsOneWidget);
        });
      }
    }
  });

  group('auth shell', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pump(
            tester,
            entry.value,
            scale,
            AuthShell(
              eyebrow: 'Sign in',
              title: 'Welcome back',
              subtitle:
                  'Log in to track orders and use your member discount.',
              showBack: false,
              children: [
                const AuthField(
                  label: 'Mobile number',
                  icon: Icons.phone_iphone_rounded,
                ),
                AuthButton(
                  label: 'Log in',
                  isLoading: false,
                  onPressed: () {},
                ),
              ],
            ),
            scaffold: (body) => body,
          );
          _noOverflow(tester, '${entry.key} @ ${scale}x');
          expect(find.text('Welcome back'), findsOneWidget);
        });
      }
    }
  });

  group('price formatting', () {
    test('drops decimals only on whole amounts', () {
      expect(taka(450), '৳450');
      expect(taka(450, exact: true), '৳450.00');
      expect(taka(4.5), '৳4.50');
    });
  });

  testWidgets('discount shows the struck price and percentage', (tester) async {
    await _pump(
      tester,
      const Size(390, 844),
      1.0,
      Align(alignment: Alignment.topLeft, child: DsProductRow(product: _sample)),
    );
    _noOverflow(tester, 'discount');

    // price 4.50 against promotion_price 6.00 => 25% off.
    expect(find.text('৳4.50'), findsOneWidget);
    expect(find.text('৳6'), findsOneWidget);
    expect(find.text('-25%'), findsOneWidget);
  });
}
