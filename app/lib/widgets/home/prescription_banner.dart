import 'package:flutter/material.dart';
import 'package:font_awesome_flutter/font_awesome_flutter.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';

/// Prescription upload call-to-action.
///
/// In a pharmacy this is the highest-intent action on the home screen, so V2
/// gives it a full-width dark panel instead of a row in the feed — it is the
/// one moment the page goes dark, which is what makes it impossible to miss.
///
/// The launch behaviour is unchanged: native WhatsApp first, web fallback
/// second, message if neither opens.
class PrescriptionBanner extends StatelessWidget {
  final String phoneNumber;
  final String message;

  static const Color _whatsApp = Color(0xFF25D366);

  const PrescriptionBanner({
    super.key,
    required this.phoneNumber,
    required this.message,
  });

  Future<void> _open(BuildContext context) async {
    final messenger = ScaffoldMessenger.of(context);
    final formattedPhone = phoneNumber.replaceAll('+', '').replaceAll(' ', '');
    final encodedText = Uri.encodeComponent(message);

    final nativeUri = Uri.parse(
      'whatsapp://send?phone=$formattedPhone&text=$encodedText',
    );
    final webUri = Uri.parse('https://wa.me/$formattedPhone?text=$encodedText');

    try {
      if (await canLaunchUrl(nativeUri)) {
        final ok = await launchUrl(
          nativeUri,
          mode: LaunchMode.externalNonBrowserApplication,
        );
        if (!ok) {
          await launchUrl(webUri, mode: LaunchMode.externalApplication);
        }
        return;
      }

      if (await canLaunchUrl(webUri)) {
        await launchUrl(webUri, mode: LaunchMode.externalApplication);
        return;
      }

      messenger.showSnackBar(
        const SnackBar(content: Text('Unable to open WhatsApp.')),
      );
    } catch (e) {
      messenger.showSnackBar(
        SnackBar(content: Text('Failed to open WhatsApp: $e')),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return DsPressable(
      onTap: () => _open(context),
      depth: 0.02,
      semanticLabel: 'Upload your prescription on WhatsApp',
      child: DsObsidianPanel(
        padding: const EdgeInsets.all(DsSpace.x6),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    'PRESCRIPTION',
                    style: DsType.eyebrow(color: _whatsApp),
                  ),
                  const SizedBox(height: DsSpace.x2),
                  Text(
                    'Send it, we’ll handle\nthe rest',
                    style: text.headlineSmall?.copyWith(
                      color: DsColor.onDark,
                      height: 1.25,
                    ),
                  ),
                  const SizedBox(height: DsSpace.x3),
                  Row(
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      const FaIcon(
                        FontAwesomeIcons.whatsapp,
                        color: _whatsApp,
                        size: 14,
                      ),
                      const SizedBox(width: DsSpace.x2),
                      Flexible(
                        child: Text(
                          'Chat on WhatsApp',
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: text.labelMedium?.copyWith(
                            color: DsColor.onDark.withValues(alpha: 0.8),
                          ),
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            const SizedBox(width: DsSpace.x3),
            Container(
              width: 52,
              height: 52,
              decoration: BoxDecoration(
                color: _whatsApp,
                borderRadius: DsRadius.pill,
                boxShadow: DsShadow.glow(_whatsApp),
              ),
              child: const Icon(
                Icons.arrow_outward_rounded,
                color: Colors.white,
                size: 22,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
