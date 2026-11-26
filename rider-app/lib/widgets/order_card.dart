import 'package:flutter/material.dart';

import '../design/tokens.dart';
import '../design/typography.dart';
import '../helpers/format.dart';
import '../models/rider_order.dart';
import 'common.dart';

class OrderCard extends StatelessWidget {
  const OrderCard({super.key, required this.order, required this.onTap});

  final RiderOrder order;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final eta = order.eta;
    return SurfaceCard(
      onTap: onTap,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  order.number,
                  style: text.titleSmall,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
              DsSpace.h2,
              StatusChip(order.status),
            ],
          ),
          DsSpace.v3,
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Icon(
                Icons.person_pin_circle_rounded,
                size: DsSize.iconMd,
                color: DsColor.primary,
              ),
              DsSpace.h2,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      order.customerName ?? 'Customer',
                      style: text.titleMedium,
                    ),
                    if (order.fullAddress.isNotEmpty)
                      Text(
                        order.fullAddress,
                        style: text.bodyMedium,
                        maxLines: 2,
                        overflow: TextOverflow.ellipsis,
                      ),
                  ],
                ),
              ),
            ],
          ),
          DsSpace.v3,
          Row(
            children: [
              if (order.codAmount > 0)
                _Pill(
                  icon: Icons.payments_rounded,
                  label: 'Collect ${taka(order.codAmount)}',
                  fg: DsColor.brand,
                  bg: DsColor.brandSoft,
                  strong: true,
                )
              else
                const _Pill(
                  icon: Icons.verified_rounded,
                  label: 'Prepaid',
                  fg: DsColor.primary,
                  bg: DsColor.primarySoft,
                ),
              DsSpace.h2,
              Expanded(
                child: Text(
                  _trailing(order, eta),
                  textAlign: TextAlign.end,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: !order.isClosed && eta?.minutes != null
                      ? DsType.numeral(14, color: DsColor.info)
                      : text.bodySmall,
                ),
              ),
            ],
          ),
        ],
      ),
    );
  }
}

String _trailing(RiderOrder order, OrderEta? eta) {
  if (order.isDelivered) {
    final minutes = order.deliveryMinutes;
    return 'Delivered ${dateTime(order.deliveredAt)}'
        '${minutes != null ? ' · $minutes min' : ''}';
  }
  if (order.isCancelled) return 'Cancelled';
  if (eta?.minutes != null) return 'ETA ${eta!.minutes} min';
  final n = order.items.length;
  return '$n item${n == 1 ? '' : 's'}';
}

class _Pill extends StatelessWidget {
  const _Pill({
    required this.icon,
    required this.label,
    required this.fg,
    required this.bg,
    this.strong = false,
  });

  final IconData icon;
  final String label;
  final Color fg;
  final Color bg;
  final bool strong;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
      decoration: BoxDecoration(color: bg, borderRadius: DsRadius.pill),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: [
          Icon(icon, size: 16, color: fg),
          const SizedBox(width: 6),
          Text(
            label,
            style: strong
                ? DsType.numeral(14, color: fg)
                : Theme.of(context).textTheme.labelMedium?.copyWith(color: fg),
          ),
        ],
      ),
    );
  }
}
