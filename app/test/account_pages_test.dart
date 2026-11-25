import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/screens/settings.dart';
import 'package:redpharmabd_app/screens/support_page.dart';
import 'package:redpharmabd_app/screens/app_information.dart';

void main() {
  testWidgets(
    'all five Account information pages open natively and return to Account',
    (tester) async {
      SharedPreferences.setMockInitialValues({});
      PackageInfo.setMockInitialValues(
        appName: 'RedPharma BD',
        packageName: 'com.zonhack.redpharmabd',
        version: '1.0.3',
        buildNumber: '8',
        buildSignature: '',
      );
      tester.view.physicalSize = const Size(320, 700);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.resetPhysicalSize);
      addTearDown(tester.view.resetDevicePixelRatio);
      await tester.pumpWidget(
        ChangeNotifierProvider(
          create: (_) => AuthProvider(),
          child: MaterialApp(
            theme: DsTheme.light,
            home: const SettingsScreen(),
          ),
        ),
      );
      await tester.pumpAndSettle();
      for (final entry in {
        'About us': 'About Us',
        'Contact us': 'Contact Us',
        'Privacy policy': 'Privacy Policy',
        'Refund & returns': 'Refund & Returns',
        'App information': 'App information',
      }.entries) {
        await tester.scrollUntilVisible(
          find.text(entry.key),
          250,
          scrollable: find.byType(Scrollable).first,
        );
        await tester.tap(find.text(entry.key));
        await tester.pumpAndSettle();
        expect(find.widgetWithText(AppBar, entry.value), findsOneWidget);
        expect(
          find.byType(
            entry.key == 'App information' ? AppInfoScreen : SupportPageScreen,
          ),
          findsOneWidget,
        );
        if (entry.key == 'App information') {
          expect(find.text('v1.0.3 (8)'), findsOneWidget);
        }
        expect(tester.takeException(), isNull);
        await tester.pageBack();
        await tester.pumpAndSettle();
        expect(find.byType(SettingsScreen), findsOneWidget);
      }
    },
  );
}
