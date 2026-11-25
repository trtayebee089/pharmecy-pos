import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/home.dart';

Future<void> pumpHome(WidgetTester tester, http.Client client) async {
  SharedPreferences.setMockInitialValues({});
  await tester.pumpWidget(
    MultiProvider(
      providers: [
        ChangeNotifierProvider(create: (_) => AuthProvider()),
        ChangeNotifierProvider(create: (_) => CartProvider()),
      ],
      child: MaterialApp(
        theme: DsTheme.light,
        home: HomeScreen(
          client: client,
          requestTimeout: const Duration(seconds: 1),
        ),
      ),
    ),
  );
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 300));
}

void main() {
  testWidgets('failed requests stop loading and retry can load categories', (
    tester,
  ) async {
    var recover = false;
    final client = MockClient((request) async {
      expect(request.url.host, 'api.redpharmabd.com');
      if (!recover) throw http.ClientException('Connection reset by peer');
      return http.Response(
        jsonEncode({
          'data': [
            {'name': 'Recovered category', 'slug': 'recovered', 'image': ''},
          ],
        }),
        200,
      );
    });
    await pumpHome(tester, client);
    expect(find.byKey(const ValueKey('categories-error')), findsOneWidget);
    expect(find.byKey(const ValueKey('categories-retry')), findsOneWidget);
    recover = true;
    await tester.ensureVisible(find.byKey(const ValueKey('categories-retry')));
    await tester.tap(find.byKey(const ValueKey('categories-retry')));
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 300));
    expect(find.byKey(const ValueKey('categories-error')), findsNothing);
    expect(find.text('Recovered category'), findsOneWidget);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('empty successful responses show an empty state', (tester) async {
    await pumpHome(
      tester,
      MockClient((_) async => http.Response('{"data":[]}', 200)),
    );
    expect(find.byKey(const ValueKey('categories-empty')), findsOneWidget);
    expect(find.byKey(const ValueKey('categories-error')), findsNothing);
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('a hanging request times out and exposes retry', (tester) async {
    final pending = Completer<http.Response>();
    await pumpHome(tester, MockClient((_) => pending.future));
    await tester.pump(const Duration(seconds: 2));
    expect(find.byKey(const ValueKey('categories-error')), findsOneWidget);
    expect(
      find.text('The connection timed out. Please try again.'),
      findsWidgets,
    );
    await tester.pumpWidget(const SizedBox());
  });

  testWidgets('HTTP and malformed payload errors do not appear as empty data', (
    tester,
  ) async {
    for (final response in [
      http.Response('unavailable', 503),
      http.Response('<html>error</html>', 200),
    ]) {
      await pumpHome(tester, MockClient((_) async => response));
      expect(find.byKey(const ValueKey('categories-error')), findsOneWidget);
      expect(find.byKey(const ValueKey('categories-empty')), findsNothing);
      await tester.pumpWidget(const SizedBox());
    }
  });
}
