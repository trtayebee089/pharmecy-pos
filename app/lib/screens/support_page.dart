import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../data/support_pages.dart';
import '../design/adaptive.dart';
import '../design/tokens.dart';

enum SupportPage { about, contact, privacy, refund, terms }

class SupportPageScreen extends StatelessWidget {
  final SupportPage page;
  const SupportPageScreen({super.key, required this.page});

  Future<void> _openContact(BuildContext context, String title) async {
    final uri = switch (title) {
      'Phone' => Uri.parse('tel:+8801997202010'),
      'Email' => Uri.parse('mailto:support@redpharmabd.com'),
      'Address' => Uri.parse('geo:0,0?q=303%2C%20Rajshahi%206000'),
      _ => Uri.parse('https://redpharmabd.com'),
    };
    try {
      if (await launchUrl(uri, mode: LaunchMode.externalApplication)) return;
    } catch (_) {
      // Keep the details visible even if no dialler/mail/map app is available.
    }
    if (context.mounted) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text(
            'Unable to open this action. You can copy the details above.',
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final data = supportPageContent[page.name] as Map<String, dynamic>;
    final text = Theme.of(context).textTheme;
    return Scaffold(
      backgroundColor: DsColor.canvas,
      appBar: AppBar(title: Text(data['title'] as String)),
      body: SelectionArea(
        child: Center(
          child: ConstrainedBox(
            constraints: BoxConstraints(maxWidth: context.readableWidth),
            child: ListView(
              key: PageStorageKey('support-${page.name}'),
              padding: EdgeInsets.fromLTRB(
                context.gutter,
                DsSpace.x5,
                context.gutter,
                DsSpace.x8 + MediaQuery.paddingOf(context).bottom,
              ),
              children: [
                if (data['subtitle'] != null) ...[
                  Text(data['subtitle'] as String, style: text.headlineSmall),
                  const SizedBox(height: DsSpace.x4),
                ],
                if (data['intro'] != null) ...[
                  Text(data['intro'] as String, style: text.bodyLarge),
                  const SizedBox(height: DsSpace.x6),
                ],
                for (final section in data['sections'] as List)
                  Padding(
                    padding: const EdgeInsets.only(bottom: DsSpace.x4),
                    child: DecoratedBox(
                      decoration: BoxDecoration(
                        color: DsColor.surface,
                        borderRadius: DsRadius.rMd,
                        border: Border.all(color: DsColor.hairline),
                      ),
                      child: Padding(
                        padding: const EdgeInsets.all(DsSpace.x5),
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            if ((section['title'] ?? '').isNotEmpty) ...[
                              Text(
                                section['title'] as String,
                                style: text.titleLarge,
                              ),
                              const SizedBox(height: DsSpace.x3),
                            ],
                            if (section['body'] != null)
                              Text(
                                section['body'] as String,
                                style: text.bodyLarge,
                              ),
                            for (final paragraph
                                in section['paragraphs'] ?? const [])
                              Padding(
                                padding: const EdgeInsets.only(
                                  bottom: DsSpace.x3,
                                ),
                                child: Text(
                                  paragraph as String,
                                  style: text.bodyLarge,
                                ),
                              ),
                            for (final item in section['items'] ?? const [])
                              Padding(
                                padding: const EdgeInsets.only(top: DsSpace.x2),
                                child: Row(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const Text('•  '),
                                    Expanded(
                                      child: Text(
                                        item as String,
                                        style: text.bodyLarge,
                                      ),
                                    ),
                                  ],
                                ),
                              ),
                            if (page == SupportPage.contact)
                              TextButton.icon(
                                onPressed: () => _openContact(
                                  context,
                                  section['title'] as String,
                                ),
                                icon: Icon(switch (section['title']) {
                                  'Phone' => Icons.call_outlined,
                                  'Email' => Icons.email_outlined,
                                  'Address' => Icons.map_outlined,
                                  _ => Icons.open_in_new_rounded,
                                }),
                                label: Text(switch (section['title']) {
                                  'Phone' => 'Call us',
                                  'Email' => 'Email us',
                                  'Address' => 'Open map',
                                  _ => 'Visit website',
                                }),
                              ),
                          ],
                        ),
                      ),
                    ),
                  ),
                if (data['updated'] != null)
                  Text(
                    'Last updated: ${data['updated']}',
                    style: text.bodySmall,
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
