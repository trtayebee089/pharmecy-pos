import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/components/stepper.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/cart.dart';

/// Guards the cart screen against the regression where the item list rendered
/// into a zero-height viewport.
///
/// The cause was `Center` inside `Scaffold.bottomNavigationBar`: Scaffold
/// measures that slot with a maxHeight of the whole screen, and a `Center`
/// (Align with null size factors) sizes to `constraints.biggest`, so the bar
/// took all 844px and the body got none. Every symptom followed from that —
/// correct badge, correct subtotal, working checkout button, invisible items.
///
/// These tests assert the observable contract rather than the widget tree, so
/// they stay valid if the screen is restyled again.

const _sizes = <String, Size>{
  'small phone': Size(320, 568),
  'standard phone': Size(390, 844),
  'large phone': Size(430, 932),
  'tablet portrait': Size(768, 1024),
};

Map<String, dynamic> _line({
  required String id,
  required String name,
  required String brand,
  required double price,
  required int quantity,
  int? stock,
}) => {
  'id': id,
  'name': name,
  'price': price,
  'imageUrl': '',
  'brand': brand,
  'unit': 'pcs',
  'quantity': quantity,
  'stock_qty': stock,
};

Future<CartProvider> _seed(Map<String, dynamic> cart) async {
  SharedPreferences.setMockInitialValues({'cart': jsonEncode(cart)});
  final provider = CartProvider();
  await provider.loadCartFromPrefs();
  return provider;
}

Future<void> _pumpCart(
  WidgetTester tester,
  CartProvider provider, {
  Size size = const Size(390, 844),
  double scale = 1.0,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(
    ChangeNotifierProvider<CartProvider>.value(
      value: provider,
      child: MaterialApp(
        theme: DsTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(scale),
          ),
          child: const CartScreen(),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 800));
}

void main() {
  final twoItems = {
    '1': _line(
      id: '1',
      name: 'Napa Extend 665mg',
      brand: 'Beximco',
      price: 4.5,
      quantity: 2,
      stock: 42,
    ),
    '2': _line(
      id: '2',
      name: 'Sergel 20mg',
      brand: 'Healthcare',
      price: 8,
      quantity: 1,
      stock: 5,
    ),
  };

  group('the item list is actually on screen', () {
    testWidgets('renders one row per item in the provider', (tester) async {
      final provider = await _seed(twoItems);
      await _pumpCart(tester, provider);

      expect(provider.items.length, 2);
      expect(find.text('Napa Extend 665mg'), findsOneWidget);
      expect(find.text('Sergel 20mg'), findsOneWidget);

      // One stepper per line — the count must track the provider exactly.
      expect(
        find.byType(DsStepper),
        findsNWidgets(provider.items.length),
      );
      expect(tester.takeException(), isNull);
    });

    testWidgets('shows brand, unit price, remove control and line total', (
      tester,
    ) async {
      final provider = await _seed(twoItems);
      await _pumpCart(tester, provider);

      expect(find.text('Beximco'), findsOneWidget);
      expect(find.text('৳4.50 / pcs'), findsOneWidget);
      // 4.50 x 2
      expect(find.text('৳9.00'), findsOneWidget);
      expect(find.text('LINE TOTAL'), findsNWidgets(2));
      expect(find.byIcon(Icons.close_rounded), findsNWidgets(2));
    });

    testWidgets('the body gets real height and the bar stays a bar', (
      tester,
    ) async {
      // The direct regression guard.
      final provider = await _seed(twoItems);
      await _pumpCart(tester, provider);

      final scaffold = tester
          .renderObject<RenderBox>(find.byType(Scaffold))
          .size;
      final scroller = tester
          .renderObject<RenderBox>(find.byType(CustomScrollView))
          .size;

      expect(
        scroller.height,
        greaterThan(scaffold.height * 0.5),
        reason: 'the item list was squeezed out of the layout',
      );
    });

    testWidgets('subtotal equals the sum of the displayed lines', (
      tester,
    ) async {
      final provider = await _seed(twoItems);
      await _pumpCart(tester, provider);

      // 4.50 x 2 + 8.00 x 1
      expect(provider.totalAmount, 17.0);
      expect(find.text('৳17.00'), findsWidgets);
    });
  });

  group('empty state appears only when the provider is truly empty', () {
    testWidgets('no empty state while items exist', (tester) async {
      final provider = await _seed(twoItems);
      await _pumpCart(tester, provider);

      expect(find.text('Your bag is empty'), findsNothing);
      expect(find.text('Checkout'), findsOneWidget);
    });

    testWidgets('empty state with no items, and no checkout bar', (
      tester,
    ) async {
      SharedPreferences.setMockInitialValues({});
      final provider = CartProvider();
      await _pumpCart(tester, provider);

      expect(provider.items, isEmpty);
      expect(find.text('Your bag is empty'), findsOneWidget);
      expect(find.text('Checkout'), findsNothing);
    });
  });

  group('quantity controls still drive the provider', () {
    testWidgets('increase', (tester) async {
      final provider = await _seed({
        '1': _line(
          id: '1',
          name: 'Napa',
          brand: 'Beximco',
          price: 4.5,
          quantity: 2,
          stock: 42,
        ),
      });
      await _pumpCart(tester, provider);

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump(const Duration(milliseconds: 400));

      expect(provider.items['1']!.quantity, 3);
      // 4.50 x 3 shows in the line total, the summary and the sticky bar.
      expect(find.text('৳13.50'), findsWidgets);
    });

    testWidgets('decrease', (tester) async {
      final provider = await _seed({
        '1': _line(
          id: '1',
          name: 'Napa',
          brand: 'Beximco',
          price: 4.5,
          quantity: 3,
          stock: 42,
        ),
      });
      await _pumpCart(tester, provider);

      await tester.tap(find.byIcon(Icons.remove_rounded));
      await tester.pump(const Duration(milliseconds: 400));

      expect(provider.items['1']!.quantity, 2);
    });

    testWidgets('remove', (tester) async {
      final provider = await _seed(twoItems);
      await _pumpCart(tester, provider);

      await tester.tap(find.byIcon(Icons.close_rounded).first);
      await tester.pump(const Duration(milliseconds: 400));

      expect(provider.items.length, 1);
      expect(provider.items.containsKey('1'), isFalse);
      // One row left. Asserting on the row count rather than the name, since
      // the "Removed" toast still displays the deleted item's name.
      expect(find.byType(DsStepper), findsOneWidget);
    });

    testWidgets('stock ceiling holds and is explained', (tester) async {
      final provider = await _seed({
        '1': _line(
          id: '1',
          name: 'Napa',
          brand: 'Beximco',
          price: 4.5,
          quantity: 42,
          stock: 42,
        ),
      });
      await _pumpCart(tester, provider);

      // At the ceiling the card says so.
      expect(find.text('Max 42 available'), findsOneWidget);

      await tester.tap(find.byIcon(Icons.add_rounded));
      await tester.pump(const Duration(milliseconds: 400));

      expect(
        provider.items['1']!.quantity,
        42,
        reason: 'quantity must not pass available stock',
      );
      expect(
        find.textContaining('Only 42 items'),
        findsOneWidget,
        reason: 'the cap must be explained, not silently ignored',
      );
    });
  });

  group('responsive', () {
    for (final entry in _sizes.entries) {
      for (final scale in [1.0, 1.25, 1.35]) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          final provider = await _seed(twoItems);
          await _pumpCart(
            tester,
            provider,
            size: entry.value,
            scale: scale,
          );

          expect(
            tester.takeException(),
            isNull,
            reason: 'overflow at ${entry.key} @ ${scale}x',
          );
          expect(find.text('Napa Extend 665mg'), findsOneWidget);

          final scroller = tester
              .renderObject<RenderBox>(find.byType(CustomScrollView))
              .size;
          expect(
            scroller.height,
            greaterThan(0),
            reason: 'list viewport collapsed at ${entry.key} @ ${scale}x',
          );
        });
      }
    }
  });
}
