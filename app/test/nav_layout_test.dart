import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/components/floating_nav.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';

/// The navigation bar's geometry must be invariant under selection.
///
/// Two regressions are guarded here:
///
///  1. The selected indicator used to be a label-hugging pill, so its width
///     changed per destination (112 / 137 / 112 / 149.5px) and the cart badge
///     drifted over the label text.
///  2. The bar wrapped itself in `Center`, which in a bottom-anchored slot
///     sizes to `constraints.biggest` — the bar reported the full 844px screen
///     height instead of its own.
const _sizes = <String, Size>{
  'small phone': Size(320, 568),
  'compact phone': Size(360, 640),
  'standard phone': Size(390, 844),
  'large phone': Size(430, 932),
  'tablet portrait': Size(768, 1024),
};

const _scales = <double>[1.0, 1.25, 1.35];

Future<CartProvider> _cartWith(int lines) async {
  final map = <String, dynamic>{};
  for (var i = 0; i < lines; i++) {
    map['$i'] = {
      'id': '$i',
      'name': 'Item $i',
      'price': 4.5,
      'imageUrl': '',
      'brand': 'B',
      'unit': 'pcs',
      'quantity': 1,
      'stock_qty': 10,
    };
  }
  SharedPreferences.setMockInitialValues({'cart': jsonEncode(map)});
  final provider = CartProvider();
  await provider.loadCartFromPrefs();
  return provider;
}

Future<void> _pumpNav(
  WidgetTester tester,
  CartProvider cart,
  int index, {
  Size size = const Size(390, 844),
  double scale = 1.0,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(
    ChangeNotifierProvider<CartProvider>.value(
      value: cart,
      child: MaterialApp(
        theme: DsTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(scale),
          ),
          child: Scaffold(
            body: const SizedBox.expand(),
            bottomNavigationBar: DsFloatingNav(
              currentIndex: index,
              onSelect: (_) {},
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 600));
}

/// The four selected-indicator boxes, in destination order.
List<Size> _indicators(WidgetTester tester) => tester
    .renderObjectList<RenderBox>(find.byType(AnimatedContainer))
    .map((b) => b.size)
    .toList();

/// Vertical centre of each indicator, in global coordinates.
List<double> _indicatorCentres(WidgetTester tester) => tester
    .renderObjectList<RenderBox>(find.byType(AnimatedContainer))
    .map(
      (b) => double.parse(
        (b.localToGlobal(Offset.zero).dy + b.size.height / 2).toStringAsFixed(1),
      ),
    )
    .toList();

Size _barSize(WidgetTester tester) =>
    tester.renderObject<RenderBox>(find.byType(DsFloatingNav)).size;

void main() {
  group('the bar is bar-sized, not screen-sized', () {
    for (final entry in _sizes.entries) {
      testWidgets(entry.key, (tester) async {
        final cart = await _cartWith(3);
        await _pumpNav(tester, cart, 2, size: entry.value);

        final bar = _barSize(tester);
        expect(
          bar.height,
          lessThan(entry.value.height * 0.25),
          reason: 'the bar claimed $bar of a ${entry.value} viewport',
        );
      });
    }
  });

  group('geometry is identical for every selection', () {
    for (final scale in _scales) {
      testWidgets('with a cart badge @ ${scale}x', (tester) async {
        final cart = await _cartWith(3);
        final barHeights = <double>{};

        for (var i = 0; i < 4; i++) {
          await _pumpNav(tester, cart, i, scale: scale);
          barHeights.add(_barSize(tester).height);

          final sizes = _indicators(tester);
          expect(sizes.length, 4);

          // Same height AND same width, whichever destination is selected.
          expect(
            sizes.toSet().length,
            1,
            reason: 'indicators differ when index $i is selected: $sizes',
          );

          // Same vertical alignment.
          expect(
            _indicatorCentres(tester).toSet().length,
            1,
            reason: 'indicators are not vertically aligned at index $i',
          );
        }

        expect(
          barHeights.length,
          1,
          reason: 'bar height changed with selection: $barHeights',
        );
      });
    }

    testWidgets('cells are equal width and equal height', (tester) async {
      await _pumpNav(tester, await _cartWith(3), 2);

      // One DsPressable per destination — these are the cells themselves.
      final cells = tester
          .renderObjectList<RenderBox>(find.byType(DsPressable))
          .map((b) => b.size)
          .toList();

      expect(cells.length, 4);
      expect(
        cells.toSet().length,
        1,
        reason: 'destination cells are not uniform: $cells',
      );
    });
  });

  group('the badge is a pure overlay', () {
    testWidgets('indicator geometry is unchanged by the badge', (tester) async {
      await _pumpNav(tester, await _cartWith(0), 2);
      final without = _indicators(tester);
      final barWithout = _barSize(tester);

      await _pumpNav(tester, await _cartWith(5), 2);
      final withBadge = _indicators(tester);

      expect(
        withBadge,
        equals(without),
        reason: 'the badge changed the indicator geometry',
      );
      expect(_barSize(tester), barWithout);
    });

    testWidgets('a three-digit count changes nothing', (tester) async {
      await _pumpNav(tester, await _cartWith(0), 2);
      final baseline = _barSize(tester);
      final indicators = _indicators(tester);

      await _pumpNav(tester, await _cartWith(120), 2);
      expect(_barSize(tester), baseline);
      expect(_indicators(tester), indicators);
    });

    testWidgets('the badge renders and stays inside the indicator', (
      tester,
    ) async {
      await _pumpNav(tester, await _cartWith(7), 2);

      expect(find.text('7'), findsOneWidget);

      final badge = tester.getRect(find.text('7'));
      final cartIndicator = tester
          .renderObjectList<RenderBox>(find.byType(AnimatedContainer))
          .elementAt(2);
      final rect =
          cartIndicator.localToGlobal(Offset.zero) & cartIndicator.size;

      // Anchored to the icon's top-right, and contained by the indicator so it
      // cannot read as extra height.
      expect(badge.center.dx, greaterThan(rect.center.dx));
      expect(badge.center.dy, lessThan(rect.center.dy));
      expect(
        rect.inflate(1).contains(badge.center),
        isTrue,
        reason: 'badge escaped the indicator: $badge vs $rect',
      );
    });
  });

  group('no movement when switching tabs', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          final cart = await _cartWith(3);
          final geometry = <List<Size>>[];
          final centres = <List<double>>[];

          for (var i = 0; i < 4; i++) {
            await _pumpNav(
              tester,
              cart,
              i,
              size: entry.value,
              scale: scale,
            );
            expect(tester.takeException(), isNull);
            geometry.add(_indicators(tester));
            centres.add(_indicatorCentres(tester));
          }

          // Every selection produces the identical set of boxes at the
          // identical offsets — nothing jumps.
          for (var i = 1; i < geometry.length; i++) {
            expect(
              geometry[i],
              equals(geometry[0]),
              reason: 'geometry moved between selections on ${entry.key}',
            );
            expect(
              centres[i],
              equals(centres[0]),
              reason: 'vertical position moved on ${entry.key}',
            );
          }
        });
      }
    }
  });

  group('labels', () {
    testWidgets('all four are always visible', (tester) async {
      await _pumpNav(tester, await _cartWith(0), 0);

      for (final label in ['Home', 'Browse', 'Cart', 'Account']) {
        expect(find.text(label), findsOneWidget, reason: '$label missing');
      }
    });
  });
}
