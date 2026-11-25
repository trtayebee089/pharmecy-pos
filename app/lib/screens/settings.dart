import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/media.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/screens/app_information.dart';
import 'package:redpharmabd_app/screens/support_page.dart';
import 'package:redpharmabd_app/screens/auth/edit_profile.dart';
import 'package:redpharmabd_app/screens/auth/membership.dart';
import 'package:redpharmabd_app/screens/auth/orders.dart';
import 'package:redpharmabd_app/screens/auth/rewards.dart';
import 'package:redpharmabd_app/screens/auth/password.dart';
import 'package:redpharmabd_app/screens/recently_viewed.dart';
import 'package:redpharmabd_app/screens/login_screen.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Account, rebuilt.
///
/// New composition:
///  * A **dark identity panel** at the top carrying avatar, name, tier and
///    lifetime savings — the one place in the app that states who you are, so
///    it gets the obsidian treatment rather than another white card.
///  * **Quick actions as a row of tiles** directly under it (Orders,
///    Membership, Prescription), because those are the three things people
///    open this tab to do.
///  * **Grouped rows** below for the long tail of settings and support.
///  * Signed-out visitors see the same shape with a sign-in prompt, so the
///    screen never looks broken.
/// ─────────────────────────────────────────────────────────────────────────
class SettingsScreen extends StatefulWidget {
  const SettingsScreen({super.key});

  @override
  State<SettingsScreen> createState() => _SettingsScreenState();
}

class _SettingsScreenState extends State<SettingsScreen> with RouteAware {
  bool _isRefreshing = false;
  double? totalSaved;

  Future<void> _refreshUser() async {
    if (!mounted || _isRefreshing) return;
    setState(() => _isRefreshing = true);

    try {
      final auth = context.read<AuthProvider>();
      await auth.fetchUserData();
      await auth.fetchUserOrders();

      final orders = auth.orders ?? [];
      double saved = 0;
      for (var order in orders) {
        if (order['discount'] != null &&
            (order['discount']['amount'] ?? 0) > 0) {
          saved += order['discount']['amount'];
        }
      }
      if (mounted) {
        setState(() => totalSaved = saved > 0 ? saved : null);
      }
    } catch (e) {
      debugPrint('Error refreshing user: $e');
    } finally {
      if (mounted) setState(() => _isRefreshing = false);
    }
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) _refreshUser();
    });
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    WidgetsBinding.instance.addPostFrameCallback((_) => _refreshUser());
  }

  void _launchURL(String url) async {
    final uri = Uri.parse(url);
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      if (!mounted) return;
      DsToast.error(context, 'Could not open link', detail: url);
    }
  }

  void _openLogin() async {
    final result = await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const LoginScreen()),
    );

    if (result == true && mounted) {
      await context.read<AuthProvider>().fetchUserData();
      if (!mounted) return;
      DsToast.success(context, 'Signed in');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Consumer<AuthProvider>(
      builder: (context, auth, _) {
        final isLoggedIn = auth.isLoggedIn;
        final userName = auth.userData?['name']?.isNotEmpty == true
            ? auth.userData!['name'] as String
            : 'Guest';
        final tier = auth.membership?['name']?.isNotEmpty == true
            ? auth.membership!['name'] as String
            : 'General Member';
        final points = auth.userData?['points'];
        final avatar = auth.userData?['avator']?.isNotEmpty == true
            ? auth.userData!['avator'] as String
            : null;
        final orderCount = auth.orders?.length ?? 0;

        return Scaffold(
          backgroundColor: DsColor.canvas,
          body: SafeArea(
            bottom: false,
            child: DsPage(
              child: RefreshIndicator(
                onRefresh: _refreshUser,
                color: DsColor.primary,
                child: ListView(
                  physics: const AlwaysScrollableScrollPhysics(
                    parent: BouncingScrollPhysics(),
                  ),
                  padding: EdgeInsets.fromLTRB(
                    context.gutter,
                    DsSpace.x6,
                    context.gutter,
                    context.floatingNavInset + DsSpace.x6,
                  ),
                  children: [
                    _identity(
                      isLoggedIn: isLoggedIn,
                      name: userName,
                      tier: tier,
                      points: points,
                      avatar: avatar,
                    ),

                    if (isLoggedIn) ...[
                      const SizedBox(height: DsSpace.x4),
                      _quickActions(orderCount),
                    ],

                    const SizedBox(height: DsSpace.x8),
                    _groupLabel('Account'),
                    if (isLoggedIn)
                      _group([
                        _row(
                          Icons.receipt_long_outlined,
                          DsColor.primary,
                          'My orders',
                          orderCount == 0
                              ? 'No orders yet'
                              : '$orderCount order${orderCount == 1 ? '' : 's'}',
                          () => _push(const MyOrdersScreen()),
                        ),
                        _row(
                          Icons.stars_rounded,
                          DsColor.warning,
                          'My rewards',
                          'Wallet, history and redemption',
                          () => _push(const RewardsScreen()),
                        ),
                        _row(
                          Icons.history_rounded,
                          DsColor.info,
                          'Recently viewed',
                          'Stored on this device',
                          () => _push(const RecentlyViewedScreen()),
                        ),
                        _row(
                          Icons.person_outline_rounded,
                          DsColor.info,
                          'Edit profile',
                          'Name, contact and address',
                          () => _push(const EditProfileScreen()),
                        ),
                        _row(
                          Icons.lock_outline_rounded,
                          DsColor.warning,
                          'Change password',
                          null,
                          () => _push(const ChangePasswordScreen()),
                        ),
                        _row(
                          Icons.person_remove_outlined,
                          DsColor.danger,
                          'Delete account',
                          null,
                          () => _launchURL(
                            'https://redpharmabd.com/account-removal-request',
                          ),
                          last: true,
                        ),
                      ])
                    else
                      _group([
                        _row(
                          Icons.login_rounded,
                          DsColor.primary,
                          'Sign in',
                          'Track orders and use your member discount',
                          _openLogin,
                          last: true,
                        ),
                      ]),

                    const SizedBox(height: DsSpace.x6),
                    _groupLabel('Support'),
                    _group([
                      _row(
                        Icons.info_outline_rounded,
                        DsColor.info,
                        'About us',
                        null,
                        () => _push(
                          const SupportPageScreen(page: SupportPage.about),
                        ),
                      ),
                      _row(
                        Icons.headset_mic_outlined,
                        DsColor.primary,
                        'Contact us',
                        null,
                        () => _push(
                          const SupportPageScreen(page: SupportPage.contact),
                        ),
                      ),
                      _row(
                        Icons.privacy_tip_outlined,
                        DsColor.warning,
                        'Privacy policy',
                        null,
                        () => _push(
                          const SupportPageScreen(page: SupportPage.privacy),
                        ),
                      ),
                      _row(
                        Icons.assignment_return_outlined,
                        DsColor.brand,
                        'Refund & returns',
                        null,
                        () => _push(
                          const SupportPageScreen(page: SupportPage.refund),
                        ),
                      ),
                      _row(
                        Icons.phone_iphone_rounded,
                        DsColor.inkSoft,
                        'App information',
                        null,
                        () => _push(const AppInfoScreen()),
                        last: true,
                      ),
                    ]),

                    if (isLoggedIn) ...[
                      const SizedBox(height: DsSpace.x6),
                      OutlinedButton.icon(
                        onPressed: () async {
                          await context.read<AuthProvider>().logout();
                          if (!context.mounted) return;
                          DsToast.info(context, 'Signed out');
                          await _refreshUser();
                        },
                        icon: const Icon(Icons.logout_rounded, size: 18),
                        label: const Text('Sign out'),
                        style: OutlinedButton.styleFrom(
                          foregroundColor: DsColor.danger,
                          backgroundColor: DsColor.dangerSoft,
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        );
      },
    );
  }

  void _push(Widget screen) =>
      Navigator.push(context, MaterialPageRoute(builder: (_) => screen));

  /// Dark identity panel.
  Widget _identity({
    required bool isLoggedIn,
    required String name,
    required String tier,
    required Object? points,
    required String? avatar,
  }) {
    final text = Theme.of(context).textTheme;

    return DsObsidianPanel(
      padding: const EdgeInsets.all(DsSpace.x6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 64,
                height: 64,
                decoration: BoxDecoration(
                  shape: BoxShape.circle,
                  color: Colors.white.withValues(alpha: 0.10),
                  border: Border.all(
                    color: Colors.white.withValues(alpha: 0.18),
                    width: 2,
                  ),
                ),
                clipBehavior: Clip.antiAlias,
                child: avatar != null
                    ? DsImage(
                        url: avatar,
                        fit: BoxFit.cover,
                        backdrop: Colors.transparent,
                      )
                    : const Icon(
                        Icons.person_rounded,
                        color: Colors.white70,
                        size: 30,
                      ),
              ),
              const SizedBox(width: DsSpace.x4),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      isLoggedIn ? 'MEMBER' : 'GUEST',
                      style: DsType.eyebrow(color: DsColor.primaryBright),
                    ),
                    const SizedBox(height: DsSpace.x1),
                    Text(
                      isLoggedIn ? name : 'Welcome',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.headlineSmall?.copyWith(
                        color: DsColor.onDark,
                      ),
                    ),
                    if (isLoggedIn) ...[
                      const SizedBox(height: DsSpace.x1),
                      Text(
                        points != null ? '$tier · $points points' : tier,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: text.bodySmall?.copyWith(
                          color: DsColor.onDark.withValues(alpha: 0.65),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ],
          ),

          if (isLoggedIn && totalSaved != null) ...[
            const SizedBox(height: DsSpace.x5),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(DsSpace.x4),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.08),
                borderRadius: DsRadius.rSm,
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.savings_outlined,
                    size: 18,
                    color: DsColor.primaryBright,
                  ),
                  const SizedBox(width: DsSpace.x3),
                  Expanded(
                    child: Text(
                      'Saved so far',
                      style: text.bodySmall?.copyWith(
                        color: DsColor.onDark.withValues(alpha: 0.7),
                      ),
                    ),
                  ),
                  Text(
                    taka(totalSaved!),
                    style: DsType.numeral(18, color: DsColor.onDark),
                  ),
                ],
              ),
            ),
          ],

          if (!isLoggedIn) ...[
            const SizedBox(height: DsSpace.x5),
            SizedBox(
              width: double.infinity,
              child: ElevatedButton(
                onPressed: _openLogin,
                style: ElevatedButton.styleFrom(
                  backgroundColor: DsColor.primaryBright,
                ),
                child: const Text('Sign in or create account'),
              ),
            ),
          ],
        ],
      ),
    );
  }

  Widget _quickActions(int orderCount) {
    return Row(
      children: [
        Expanded(
          child: _QuickTile(
            icon: Icons.receipt_long_outlined,
            label: 'Orders',
            value: '$orderCount',
            tint: DsColor.primary,
            onTap: () => _push(const MyOrdersScreen()),
          ),
        ),
        const SizedBox(width: DsSpace.x3),
        Expanded(
          child: _QuickTile(
            icon: Icons.workspace_premium_outlined,
            label: 'Membership',
            value: 'Tier',
            tint: DsColor.warning,
            onTap: () => _push(const MembershipScreen()),
          ),
        ),
        const SizedBox(width: DsSpace.x3),
        Expanded(
          child: _QuickTile(
            icon: Icons.edit_outlined,
            label: 'Profile',
            value: 'Edit',
            tint: DsColor.info,
            onTap: () => _push(const EditProfileScreen()),
          ),
        ),
      ],
    );
  }

  Widget _groupLabel(String label) => Padding(
    padding: const EdgeInsets.only(left: DsSpace.x2, bottom: DsSpace.x3),
    child: Text(label.toUpperCase(), style: DsType.eyebrow()),
  );

  Widget _group(List<Widget> children) =>
      DsSurface(child: Column(children: children));

  Widget _row(
    IconData icon,
    Color tint,
    String title,
    String? subtitle,
    VoidCallback onTap, {
    bool last = false,
  }) {
    final text = Theme.of(context).textTheme;

    return Column(
      children: [
        InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: DsSpace.x4,
              vertical: DsSpace.x4,
            ),
            child: Row(
              children: [
                Container(
                  width: 36,
                  height: 36,
                  decoration: BoxDecoration(
                    color: tint.withValues(alpha: 0.10),
                    borderRadius: DsRadius.rXs,
                  ),
                  child: Icon(icon, color: tint, size: 18),
                ),
                const SizedBox(width: DsSpace.x4),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        title,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: text.titleSmall,
                      ),
                      if (subtitle != null)
                        Text(
                          subtitle,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: text.bodySmall?.copyWith(fontSize: 11.5),
                        ),
                    ],
                  ),
                ),
                const Icon(
                  Icons.chevron_right_rounded,
                  color: DsColor.inkFaint,
                  size: 20,
                ),
              ],
            ),
          ),
        ),
        if (!last)
          const Padding(
            padding: EdgeInsets.only(left: 68),
            child: Divider(height: 1),
          ),
      ],
    );
  }
}

class _QuickTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final String value;
  final Color tint;
  final VoidCallback onTap;

  const _QuickTile({
    required this.icon,
    required this.label,
    required this.value,
    required this.tint,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return DsSurface(
      onTap: onTap,
      semanticLabel: '$label, $value',
      padding: const EdgeInsets.symmetric(
        horizontal: DsSpace.x3,
        vertical: DsSpace.x4,
      ),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              color: tint.withValues(alpha: 0.10),
              borderRadius: DsRadius.rXs,
            ),
            child: Icon(icon, color: tint, size: 19),
          ),
          const SizedBox(height: DsSpace.x2),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: text.labelMedium?.copyWith(fontSize: 11.5),
          ),
        ],
      ),
    );
  }
}
