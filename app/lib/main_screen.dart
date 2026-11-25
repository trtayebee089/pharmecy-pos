import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'design/components/floating_nav.dart';
import 'design/tokens.dart';
import 'screens/cart.dart';
import 'screens/categories_screen.dart';
import 'screens/home.dart';
import 'screens/settings.dart';

/// Application shell.
///
/// V2 drops the global AppBar entirely — each screen composes its own header,
/// which is what lets Home open with a greeting and Categories open with a
/// display title instead of every page wearing the same grey bar.
///
/// The shell keeps its public contract exactly: the same four tab indices, the
/// same [switchToTab] / [switchToHomeTab] / [setBottomNavVisible] methods, and
/// the same `mainScreenKey` handle other screens navigate through.
class MainScreen extends StatefulWidget {
  const MainScreen({super.key});

  @override
  MainScreenState createState() => MainScreenState();
}

class MainScreenState extends State<MainScreen> {
  int _selectedIndex = 0;
  bool _isBottomNavVisible = true;

  late final List<Widget> _screens;

  Future<void> _checkInternetConnection() async {
    try {
      final result = await InternetAddress.lookup('google.com');
      if (result.isEmpty || result[0].rawAddress.isEmpty) {
        _showNoInternetDialog();
      }
    } on SocketException catch (_) {
      _showNoInternetDialog();
    }
  }

  void _showNoInternetDialog() {
    if (!mounted) return;

    showDialog(
      context: context,
      barrierDismissible: false,
      builder: (dialogContext) => Dialog(
        child: Padding(
          padding: const EdgeInsets.fromLTRB(
            DsSpace.x6,
            DsSpace.x8,
            DsSpace.x6,
            DsSpace.x6,
          ),
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              Container(
                width: 76,
                height: 76,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: DsColor.danger.withValues(alpha: 0.10),
                ),
                child: const Icon(
                  Icons.wifi_off_rounded,
                  color: DsColor.danger,
                  size: 32,
                ),
              ),
              const SizedBox(height: DsSpace.x5),
              Text(
                'No internet connection',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.headlineSmall,
              ),
              const SizedBox(height: DsSpace.x2),
              Text(
                'Check your connection and try again.',
                textAlign: TextAlign.center,
                style: Theme.of(context).textTheme.bodyMedium,
              ),
              const SizedBox(height: DsSpace.x6),
              Row(
                children: [
                  Expanded(
                    child: OutlinedButton(
                      onPressed: () {
                        if (Platform.isAndroid) {
                          SystemNavigator.pop();
                        } else if (Platform.isIOS) {
                          exit(0);
                        }
                      },
                      child: const Text('Exit'),
                    ),
                  ),
                  const SizedBox(width: DsSpace.x3),
                  Expanded(
                    child: ElevatedButton(
                      onPressed: () async {
                        Navigator.pop(dialogContext);
                        await _checkInternetConnection();
                      },
                      child: const Text('Retry'),
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Public shell API (unchanged) ─────────────────────────────────────────

  void switchToTab(int index) {
    setState(() {
      _selectedIndex = index;
      _isBottomNavVisible = true;
    });
  }

  void switchToHomeTab() {
    setState(() {
      _selectedIndex = 0;
      _isBottomNavVisible = true;
    });
  }

  void setBottomNavVisible(bool visible) {
    if (_isBottomNavVisible == visible) return;
    setState(() => _isBottomNavVisible = visible);
  }

  @override
  void initState() {
    super.initState();
    _checkInternetConnection();

    _screens = [
      const HomeScreen(),
      CategoryScreen(onToggleBottomNav: setBottomNavVisible),
      const CartScreen(inTabShell: true),
      const SettingsScreen(),
    ];
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: DsColor.canvas,
      // extendBody lets the feed scroll beneath the floating pill.
      extendBody: true,
      body: Stack(
        children: [
          // IndexedStack preserves each tab's scroll position and in-flight
          // requests, so switching tabs no longer refetches from scratch.
          IndexedStack(index: _selectedIndex, children: _screens),

          Positioned(
            left: 0,
            right: 0,
            bottom: 0,
            child: AnimatedSlide(
              duration: DsMotion.base,
              curve: DsMotion.ease,
              offset: _isBottomNavVisible ? Offset.zero : const Offset(0, 1.6),
              child: AnimatedOpacity(
                duration: DsMotion.quick,
                opacity: _isBottomNavVisible ? 1 : 0,
                child: IgnorePointer(
                  ignoring: !_isBottomNavVisible,
                  child: DsFloatingNav(
                    currentIndex: _selectedIndex,
                    onSelect: (i) => setState(() => _selectedIndex = i),
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}
