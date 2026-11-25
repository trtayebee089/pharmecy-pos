import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/adaptive.dart';
import 'support_page.dart';

class AppInfoScreen extends StatefulWidget {
  const AppInfoScreen({super.key});

  @override
  State<AppInfoScreen> createState() => _AppInfoScreenState();
}

class _AppInfoScreenState extends State<AppInfoScreen> {
  String _appVersion = '';

  @override
  void initState() {
    super.initState();
    _loadAppVersion();
  }

  Future<void> _loadAppVersion() async {
    try {
      final info = await PackageInfo.fromPlatform();
      if (!mounted) return;
      setState(() => _appVersion = 'v${info.version} (${info.buildNumber})');
    } catch (_) {
      if (mounted) setState(() => _appVersion = 'Version unavailable');
    }
  }

  static const List<({IconData icon, String title, String subtitle})>
  _features = [
    (
      icon: Icons.shopping_cart_outlined,
      title: 'Online Orders',
      subtitle: 'Order your medicines quickly with just a few taps',
    ),
    (
      icon: Icons.filter_list_rounded,
      title: 'Category Filters',
      subtitle: 'Find what you need easily with smart filters',
    ),
    (
      icon: Icons.search_rounded,
      title: 'Searching',
      subtitle: 'Search for products instantly and efficiently',
    ),
    (
      icon: Icons.navigation_outlined,
      title: 'Easy Navigation',
      subtitle: 'Navigate the app smoothly and find everything fast',
    ),
    (
      icon: Icons.payments_outlined,
      title: 'Cash On Delivery',
      subtitle: 'Pay conveniently when your order arrives',
    ),
  ];

  void _launchUrl(String url) async {
    final uri = Uri.parse(url);
    if (await canLaunchUrl(uri)) await launchUrl(uri);
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Scaffold(
      backgroundColor: DsColor.canvas,
      appBar: AppBar(title: const Text('App information')),
      body: Center(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: context.readableWidth),
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              context.gutter,
              DsSpace.x5,
              context.gutter,
              DsSpace.x8,
            ),
            children: [
              Center(
                child: Column(
                  children: [
                    Container(
                      width: 96,
                      height: 96,
                      decoration: BoxDecoration(
                        color: DsColor.surface,
                        borderRadius: DsRadius.rLg,
                        border: Border.all(color: DsColor.hairline),
                        boxShadow: DsShadow.low,
                      ),
                      clipBehavior: Clip.antiAlias,
                      child: Image.asset(
                        'assets/images/redpharma-icon.png',
                        fit: BoxFit.contain,
                      ),
                    ),
                    const SizedBox(height: DsSpace.x4),
                    Text('RedPharma BD', style: text.headlineSmall),
                    const SizedBox(height: DsSpace.x1),
                    Container(
                      padding: const EdgeInsets.symmetric(
                        horizontal: DsSpace.x3,
                        vertical: DsSpace.x1,
                      ),
                      decoration: BoxDecoration(
                        color: DsColor.sunken,
                        borderRadius: DsRadius.pill,
                      ),
                      child: Text(
                        _appVersion.isEmpty ? 'Loading version…' : _appVersion,
                        style: text.labelMedium,
                      ),
                    ),
                  ],
                ),
              ),

              const SizedBox(height: DsSpace.x5),

              Text(
                'RedPharma by ZONHack LLC is your trusted online pharmacy app for ordering medicines, health products, and wellness essentials with convenience and reliability.',
                textAlign: TextAlign.center,
                style: text.bodyLarge?.copyWith(color: DsColor.inkMuted),
              ),

              const SizedBox(height: DsSpace.x6),

              Text('What you can do', style: text.titleLarge),
              const SizedBox(height: DsSpace.x3),
              _card([
                for (var i = 0; i < _features.length; i++)
                  _row(
                    icon: _features[i].icon,
                    color: DsColor.primary,
                    title: _features[i].title,
                    subtitle: _features[i].subtitle,
                    isLast: i == _features.length - 1,
                  ),
              ]),

              const SizedBox(height: DsSpace.x6),

              Text('Get in touch', style: text.titleLarge),
              const SizedBox(height: DsSpace.x3),
              _card([
                _row(
                  icon: Icons.email_outlined,
                  color: DsColor.brand,
                  title: 'support@redpharmabd.com',
                  onTap: () => _launchUrl('mailto:support@redpharmabd.com'),
                ),
                _row(
                  icon: Icons.language_rounded,
                  color: DsColor.brand,
                  title: 'www.redpharmabd.com',
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) =>
                          const SupportPageScreen(page: SupportPage.about),
                    ),
                  ),
                ),
                _row(
                  icon: Icons.feedback_outlined,
                  color: DsColor.brand,
                  title: 'Send feedback',
                  onTap: () => _launchUrl('mailto:support@redpharmabd.com'),
                  isLast: true,
                ),
              ]),

              const SizedBox(height: DsSpace.x6),

              // Wrap rather than a fixed Row, so the two legal links stack
              // instead of overflowing on a compact phone.
              Wrap(
                alignment: WrapAlignment.center,
                spacing: DsSpace.x2,
                children: [
                  TextButton(
                    onPressed: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) =>
                            const SupportPageScreen(page: SupportPage.privacy),
                      ),
                    ),
                    child: const Text('Privacy policy'),
                  ),
                  TextButton(
                    onPressed: () => Navigator.push(
                      context,
                      MaterialPageRoute(
                        builder: (_) =>
                            const SupportPageScreen(page: SupportPage.terms),
                      ),
                    ),
                    child: const Text('Terms & conditions'),
                  ),
                ],
              ),

              const SizedBox(height: DsSpace.x4),
              Center(
                child: Text(
                  '© 2026 ZONHack LLC. All rights reserved.',
                  style: text.bodySmall?.copyWith(fontSize: 11),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _card(List<Widget> children) {
    return Container(
      decoration: BoxDecoration(
        color: DsColor.surface,
        borderRadius: DsRadius.rMd,
        border: Border.all(color: DsColor.hairline),
        boxShadow: DsShadow.low,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(children: children),
    );
  }

  Widget _row({
    required IconData icon,
    required Color color,
    required String title,
    String? subtitle,
    VoidCallback? onTap,
    bool isLast = false,
  }) {
    final text = Theme.of(context).textTheme;

    return Column(
      children: [
        InkWell(
          onTap: onTap,
          child: Padding(
            padding: const EdgeInsets.symmetric(
              horizontal: DsSpace.x4,
              vertical: DsSpace.x3,
            ),
            child: Row(
              children: [
                Container(
                  width: 38,
                  height: 38,
                  decoration: BoxDecoration(
                    color: color.withValues(alpha: 0.10),
                    borderRadius: DsRadius.rSm,
                  ),
                  child: Icon(icon, color: color, size: 19),
                ),
                const SizedBox(width: DsSpace.x3),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        title,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                        style: text.titleSmall?.copyWith(fontSize: 14.5),
                      ),
                      if (subtitle != null)
                        Text(
                          subtitle,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: text.bodySmall?.copyWith(fontSize: 11.5),
                        ),
                    ],
                  ),
                ),
                if (onTap != null)
                  const Icon(
                    Icons.open_in_new_rounded,
                    color: DsColor.inkFaint,
                    size: 16,
                  ),
              ],
            ),
          ),
        ),
        if (!isLast)
          const Padding(
            padding: EdgeInsets.only(left: 68),
            child: Divider(height: 1),
          ),
      ],
    );
  }
}
