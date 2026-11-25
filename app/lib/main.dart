import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'screens/splash.dart';
import 'providers/cart_provider.dart';
import 'providers/auth_provider.dart';
import 'main_screen.dart';
import 'screens/home.dart';
import 'design/theme.dart';

final GlobalKey<MainScreenState> mainScreenKey = GlobalKey<MainScreenState>();
final GlobalKey<HomeScreenState> homeScreenKey = GlobalKey<HomeScreenState>();

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final authProvider = AuthProvider();
  await authProvider.loadToken();

  runApp(
    MultiProvider(
      providers: [
        ChangeNotifierProvider<AuthProvider>.value(value: authProvider),
        ChangeNotifierProvider(create: (_) => CartProvider()),
      ],
      child: const RedPharmaApp(),
    ),
  );
}

class RedPharmaApp extends StatelessWidget {
  const RedPharmaApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      debugShowCheckedModeBanner: false,
      title: 'RedPharma App',
      theme: DsTheme.light,
      // Respect the user's dynamic type setting, but clamp the extremes so
      // fixed-height chrome (steppers, badges, bottom bars) cannot overflow.
      builder: (context, child) => MediaQuery.withClampedTextScaling(
        minScaleFactor: 0.85,
        maxScaleFactor: 1.35,
        child: child ?? const SizedBox.shrink(),
      ),
      home: SplashScreen(),
    );
  }
}
