import 'dart:async';

import 'package:flutter/material.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/text_metrics.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// "How to order", as a full-width onboarding carousel.
///
/// Rebuilt to fix three structural faults in the previous version:
///
///  * **One card, full width.** The old rail used `viewportFraction: 0.86`, so
///    neighbours bled in at both edges and every card was clipped. It now
///    occupies the full content width and pages one at a time.
///  * **Height measured, not guessed.** Each card's height is computed by
///    laying out its real title and body at the real width with a
///    [TextPainter], and the carousel takes the tallest. Nothing is truncated,
///    and every card is the same height, so paging does not jump.
///  * **No `maxLines` on the body.** Because the container is sized to the
///    text, the text never needs clipping.
/// ─────────────────────────────────────────────────────────────────────────
class HowToOrderCarousel extends StatefulWidget {
  const HowToOrderCarousel({super.key});

  static const List<HowToOrderStep> steps = [
    HowToOrderStep(
      icon: Icons.search_rounded,
      title: 'Find what you need',
      body:
          'Search by medicine name or brand, or browse the categories to see '
          'everything we stock.',
      tint: DsColor.primary,
    ),
    HowToOrderStep(
      icon: Icons.add_shopping_cart_rounded,
      title: 'Add to your cart',
      body:
          'Pick your pack size and quantity. We check availability as you go, '
          'so nothing is oversold.',
      tint: DsColor.flame,
    ),
    HowToOrderStep(
      icon: Icons.description_outlined,
      title: 'Send your prescription',
      body:
          'Prescription medicines need a doctor’s note. Send a photo on '
          'WhatsApp and we will match it to your order.',
      tint: DsColor.info,
    ),
    HowToOrderStep(
      icon: Icons.local_shipping_outlined,
      title: 'Confirm and relax',
      body:
          'Choose your address, pay cash on delivery, and track the order from '
          'your account until it arrives.',
      tint: DsColor.success,
    ),
  ];

  @override
  State<HowToOrderCarousel> createState() => _HowToOrderCarouselState();
}

class _HowToOrderCarouselState extends State<HowToOrderCarousel> {
  final PageController _controller = PageController();
  Timer? _timer;
  int _index = 0;
  bool _dragging = false;

  @override
  void initState() {
    super.initState();
    _timer = Timer.periodic(const Duration(seconds: 6), (_) {
      if (!mounted || _dragging || !_controller.hasClients) return;
      _controller.animateToPage(
        (_index + 1) % HowToOrderCarousel.steps.length,
        duration: DsMotion.slow,
        curve: DsMotion.emphasis,
      );
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    _controller.dispose();
    super.dispose();
  }

  /// Height of the tallest card at [cardWidth], measured from the real strings.
  double _measure(BuildContext context, double cardWidth) {
    final text = Theme.of(context).textTheme;
    final titleStyle = text.headlineSmall!;
    final bodyStyle = text.bodyMedium!;

    final pad = _StepCard.padFor(context);
    final iconSize = _StepCard.iconFor(context);

    // Width available to text inside the card's padding.
    final inner = cardWidth - pad * 2;

    var tallest = 0.0;
    for (final step in HowToOrderCarousel.steps) {
      final title = DsText.wrappedHeight(
        context,
        text: step.title,
        style: titleStyle,
        maxWidth: inner,
      );
      final body = DsText.wrappedHeight(
        context,
        text: step.body,
        style: bodyStyle,
        maxWidth: inner,
      );
      final eyebrow = DsText.lineHeight(context, DsType.eyebrow());

      final height =
          pad * 2 +
          iconSize +
          DsSpace.x5 + // icon → eyebrow
          eyebrow +
          DsSpace.x1 + // eyebrow → title
          title +
          DsSpace.x3 + // title → body
          body;

      if (height > tallest) tallest = height;
    }
    return tallest;
  }

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: context.pagePad,
      child: LayoutBuilder(
        builder: (context, c) {
          final cardWidth = c.maxWidth;
          final height = _measure(context, cardWidth);

          return Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              SizedBox(
                height: height,
                child: NotificationListener<ScrollNotification>(
                  onNotification: (n) {
                    if (n is ScrollStartNotification && n.dragDetails != null) {
                      _dragging = true;
                    } else if (n is ScrollEndNotification) {
                      _dragging = false;
                    }
                    return false;
                  },
                  child: PageView.builder(
                    controller: _controller,
                    itemCount: HowToOrderCarousel.steps.length,
                    // pageSnapping with a full-width viewport gives a clean
                    // one-card-per-swipe rhythm.
                    physics: const BouncingScrollPhysics(),
                    onPageChanged: (i) => setState(() => _index = i),
                    itemBuilder: (context, i) => _StepCard(
                      step: HowToOrderCarousel.steps[i],
                      index: i + 1,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: DsSpace.x4),
              DsDots(
                count: HowToOrderCarousel.steps.length,
                active: _index,
              ),
            ],
          );
        },
      ),
    );
  }
}

class HowToOrderStep {
  final IconData icon;
  final String title;
  final String body;
  final Color tint;

  const HowToOrderStep({
    required this.icon,
    required this.title,
    required this.body,
    required this.tint,
  });
}

class _StepCard extends StatelessWidget {
  final HowToOrderStep step;
  final int index;

  /// Shared with the measurement above — the two must not drift.
  ///
  /// Compact phones get tighter chrome so the card does not balloon when the
  /// body text wraps to many lines at a large text scale.
  static double padFor(BuildContext context) =>
      context.pick<double>(base: DsSpace.x6, compact: DsSpace.x4, regular: DsSpace.x5);

  static double iconFor(BuildContext context) =>
      context.pick<double>(base: 48, compact: 40, regular: 44);

  const _StepCard({required this.step, required this.index});

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    final pad = padFor(context);
    final iconSize = iconFor(context);

    return Container(
      decoration: BoxDecoration(
        color: DsColor.surface,
        borderRadius: DsRadius.rLg,
        boxShadow: DsShadow.mid,
      ),
      clipBehavior: Clip.antiAlias,
      child: Stack(
        children: [
          // Oversized step numeral bled off the corner — decorative, and it
          // makes position in the sequence readable at a glance.
          Positioned(
            right: -14,
            top: -24,
            child: Text(
              '$index',
              style: DsType.numeral(
                108,
                color: step.tint.withValues(alpha: 0.07),
              ),
            ),
          ),
          Padding(
            padding: EdgeInsets.all(pad),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Container(
                  width: iconSize,
                  height: iconSize,
                  decoration: BoxDecoration(
                    color: step.tint.withValues(alpha: 0.12),
                    borderRadius: DsRadius.rSm,
                  ),
                  child: Icon(step.icon, color: step.tint, size: 23),
                ),
                const SizedBox(height: DsSpace.x5),
                Text(
                  'STEP $index OF ${HowToOrderCarousel.steps.length}',
                  style: DsType.eyebrow(color: step.tint),
                ),
                const SizedBox(height: DsSpace.x1),
                // No maxLines anywhere below: the card is sized to the text,
                // so there is nothing to clip.
                Text(step.title, style: text.headlineSmall),
                const SizedBox(height: DsSpace.x3),
                Text(step.body, style: text.bodyMedium),
              ],
            ),
          ),
        ],
      ),
    );
  }
}
