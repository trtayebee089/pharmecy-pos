import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/providers/cart_provider.dart';

/// Stock validation must behave exactly like the website: a quantity can never
/// exceed the stock the API already reported, whichever screen it came from.
void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  Map<String, dynamic> product({
    Object? stockQty,
    Object? availableQty,
    Object? qty,
    int quantity = 1,
    int id = 1,
  }) => {
    'id': id,
    'name': 'Napa Extend 665mg',
    'price': 4.5,
    'image': '',
    'quantity': quantity,
    if (stockQty != null) 'stock_qty': stockQty,
    if (availableQty != null) 'available_qty': availableQty,
    if (qty != null) 'qty': qty,
  };

  group('resolveStock reads whichever key the endpoint used', () {
    test('prefers stock_qty', () {
      expect(
        CartProvider.resolveStock(
          product(stockQty: 5, availableQty: 9, qty: 12),
        ),
        5,
      );
    });

    test('falls back to available_qty', () {
      expect(CartProvider.resolveStock(product(availableQty: 9, qty: 12)), 9);
    });

    test('falls back to qty', () {
      expect(CartProvider.resolveStock(product(qty: 12)), 12);
    });

    test('handles string values from the API', () {
      expect(CartProvider.resolveStock(product(availableQty: '42')), 42);
    });

    test('returns null when the endpoint reports no stock at all', () {
      expect(CartProvider.resolveStock(product()), isNull);
    });
  });

  group('adding from a product card is capped', () {
    // This is the regression: cards pass the raw API map, which carries
    // available_qty / qty but never stock_qty. Before the fix these arrived
    // with a null ceiling and were never validated.
    test('caps at available_qty when adding more than stock', () {
      final cart = CartProvider();
      final capped = cart.addItem(
        product(availableQty: 42, quantity: 100),
      );

      expect(capped, isTrue);
      expect(cart.items['1']!.quantity, 42);
      expect(cart.items['1']!.stockQty, 42);
    });

    test('caps at qty when available_qty is absent', () {
      final cart = CartProvider();
      final capped = cart.addItem(product(qty: 3, quantity: 99));

      expect(capped, isTrue);
      expect(cart.items['1']!.quantity, 3);
    });

    test('does not cap when the request is within stock', () {
      final cart = CartProvider();
      final capped = cart.addItem(product(availableQty: 42, quantity: 5));

      expect(capped, isFalse);
      expect(cart.items['1']!.quantity, 5);
    });

    test('repeated adds accumulate but still stop at the ceiling', () {
      final cart = CartProvider();
      cart.addItem(product(availableQty: 42, quantity: 40));
      final capped = cart.addItem(product(availableQty: 42, quantity: 40));

      expect(capped, isTrue);
      expect(cart.items['1']!.quantity, 42);
    });
  });

  group('updateQuantity is capped for every entry path', () {
    // Covers the "+" button, manual typing and any future caller, since they
    // all funnel through updateQuantity.
    for (final requested in [50, 90, 100, 999]) {
      test('typing $requested with stock 42 clamps to 42', () {
        final cart = CartProvider();
        cart.addItem(product(availableQty: 42, quantity: 1));

        final capped = cart.updateQuantity('1', requested);

        expect(capped, isTrue, reason: 'caller must be told it was clamped');
        expect(cart.items['1']!.quantity, 42);
      });
    }

    test('holds at 1 rather than removing when a lower bound is crossed', () {
      final cart = CartProvider();
      cart.addItem(product(availableQty: 42, quantity: 5));

      cart.updateQuantity('1', 0);

      expect(cart.items['1']!.quantity, 1);
    });

    test('an exact-stock quantity is allowed through unclamped', () {
      final cart = CartProvider();
      cart.addItem(product(availableQty: 42, quantity: 1));

      final capped = cart.updateQuantity('1', 42);

      expect(capped, isFalse);
      expect(cart.items['1']!.quantity, 42);
    });

    test('unknown stock leaves the quantity untouched', () {
      final cart = CartProvider();
      cart.addItem(product(quantity: 1));

      final capped = cart.updateQuantity('1', 500);

      expect(capped, isFalse);
      expect(cart.items['1']!.quantity, 500);
    });
  });

  test('decreasing below one removes the line', () {
    final cart = CartProvider();
    cart.addItem(product(availableQty: 42, quantity: 1));

    cart.decreaseQuantity('1');

    expect(cart.items, isEmpty);
  });
}
