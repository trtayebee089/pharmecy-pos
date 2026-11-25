import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:redpharmabd_app/design/components/floating_nav.dart';
import 'package:redpharmabd_app/design/components/product_tile.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/cart.dart';
import 'package:redpharmabd_app/screens/categories_screen.dart';

void main() {
  for (final width in [320.0, 390.0]) {
    testWidgets(
      'pagination is a centered footer below the last row at $width',
      (tester) async {
        SharedPreferences.setMockInitialValues({});
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        final client = MockClient((request) async {
          if (request.url.path.endsWith('/categories')) {
            return http.Response('{"data":[]}', 200);
          }
          final page = int.parse(request.url.queryParameters['page']!);
          return http.Response(
            jsonEncode({
              'products': {
                'data': List.generate(
                  20,
                  (i) => {
                    'id': i + (page - 1) * 20,
                    'name': 'Product ${i + (page - 1) * 20}',
                    'slug': 'p$i',
                    'price': 4.5,
                    'image': '',
                    'qty': 12,
                    'brand': {'title': 'Brand'},
                    'category': {'image': ''},
                  },
                ),
              },
            }),
            200,
          );
        });
        await tester.pumpWidget(
          ChangeNotifierProvider(
            create: (_) => CartProvider(),
            child: MaterialApp(
              theme: DsTheme.light,
              home: CategoryScreen(
                initialSlug: 'diapers',
                initialName: 'Diapers',
                client: client,
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        final button = find.byKey(const ValueKey('category-load-more'));
        await tester.scrollUntilVisible(
          button,
          600,
          scrollable: find.byType(Scrollable).first,
          maxScrolls: 40,
        );
        await tester.pumpAndSettle();
        final footer = tester.getRect(button);
        final lastCard = tester.getRect(find.byType(DsProductTile).last);
        expect(footer.center.dx, closeTo(width / 2, 1));
        expect(footer.height, 52);
        expect(footer.top - lastCard.bottom, inInclusiveRange(12, 24));
        await tester.tap(button);
        await tester.pumpAndSettle();
        expect(find.text('40 products'), findsOneWidget);
        expect(tester.takeException(), isNull);
      },
    );

    testWidgets(
      'Cart checkout stays above the unchanged navigation at $width',
      (tester) async {
        SharedPreferences.setMockInitialValues({
          'cart': jsonEncode({
            '1': {
              'id': '1',
              'name': 'Medicine',
              'price': 4.5,
              'imageUrl': '',
              'brand': 'Brand',
              'unit': 'pcs',
              'quantity': 2,
              'stock_qty': 12,
            },
          }),
        });
        final cart = CartProvider();
        await cart.loadCartFromPrefs();
        tester.view.physicalSize = Size(width, 844);
        tester.view.devicePixelRatio = 1;
        addTearDown(tester.view.resetPhysicalSize);
        addTearDown(tester.view.resetDevicePixelRatio);
        await tester.pumpWidget(
          ChangeNotifierProvider.value(
            value: cart,
            child: MaterialApp(
              theme: DsTheme.light,
              home: Scaffold(
                body: Stack(
                  children: [
                    const CartScreen(inTabShell: true),
                    Positioned(
                      left: 0,
                      right: 0,
                      bottom: 0,
                      child: DsFloatingNav(currentIndex: 2, onSelect: (_) {}),
                    ),
                  ],
                ),
              ),
            ),
          ),
        );
        await tester.pumpAndSettle();
        final nav = tester.getRect(find.byType(DsFloatingNav));
        final checkout = tester.getRect(find.text('Checkout'));
        expect(checkout.bottom, lessThan(nav.top));
        expect(nav.height, lessThan(100));
        expect(tester.takeException(), isNull);
      },
    );
  }
}
