import 'package:flutter/material.dart';

import '../tokens.dart';
import '../typography.dart';

/// Progress rail for multi-step flows.
///
/// V2 replaces V1's numbered circles joined by hairlines with a **segmented
/// bar**: one filled segment per completed step and a caption below. It reads
/// as progress at a glance, needs no colour vocabulary, and — because the
/// segments flex — it fits any width without the end caps mis-sizing.
class DsSteps extends StatelessWidget {
  final List<String> labels;

  /// 1-based index of the current step.
  final int current;

  const DsSteps({super.key, required this.labels, required this.current});

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final safe = current.clamp(1, labels.length);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: List.generate(labels.length, (i) {
            final done = i < safe;
            return Expanded(
              child: AnimatedContainer(
                duration: DsMotion.base,
                curve: DsMotion.ease,
                height: 5,
                margin: EdgeInsets.only(
                  right: i == labels.length - 1 ? 0 : DsSpace.x1 + 2,
                ),
                decoration: BoxDecoration(
                  color: done ? DsColor.primary : DsColor.sunken,
                  borderRadius: DsRadius.pill,
                ),
              ),
            );
          }),
        ),
        const SizedBox(height: DsSpace.x3),
        Row(
          children: [
            Text(
              'STEP $safe OF ${labels.length}',
              style: DsType.eyebrow(color: DsColor.primary),
            ),
            const SizedBox(width: DsSpace.x2),
            Expanded(
              child: Text(
                labels[safe - 1],
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: text.labelMedium?.copyWith(color: DsColor.inkSoft),
              ),
            ),
          ],
        ),
      ],
    );
  }
}
