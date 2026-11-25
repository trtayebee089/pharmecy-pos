import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/main.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/screens/auth/order_detail.dart';

/// Colour for an order status, shared with the detail screen so a status reads
/// identically wherever it appears.
Color orderStatusColor(String? status) => switch (status?.toLowerCase()) {
  'pending' => DsColor.warning,
  'processing' => DsColor.info,
  'completed' => DsColor.success,
  'cancelled' => DsColor.danger,
  _ => DsColor.inkSoft,
};

IconData orderStatusIcon(String? status) => switch (status?.toLowerCase()) {
  'pending' => Icons.schedule_rounded,
  'processing' => Icons.local_shipping_outlined,
  'completed' => Icons.check_circle_outline_rounded,
  'cancelled' => Icons.cancel_outlined,
  _ => Icons.receipt_long_outlined,
};

String formatOrderDate(String? dateStr) {
  if (dateStr == null || dateStr.isEmpty) return 'N/A';
  try {
    return DateFormat('dd MMM yyyy').format(DateTime.parse(dateStr));
  } catch (_) {
    return dateStr;
  }
}

/// ─────────────────────────────────────────────────────────────────────────
/// Order history, rebuilt.
///
/// Status filters are horizontal chips carrying live counts rather than a
/// dropdown, so the shape of your order history is visible without opening a
/// menu. Each card leads with a status disc and ends with the total, which is
/// the pair people scan for.
/// ─────────────────────────────────────────────────────────────────────────
class MyOrdersScreen extends StatefulWidget {
  const MyOrdersScreen({super.key});

  @override
  State<MyOrdersScreen> createState() => _MyOrdersScreenState();
}

class _MyOrdersScreenState extends State<MyOrdersScreen> {
  String selectedStatus = 'All';
  static const List<String> _statuses = [
    'All',
    'Pending',
    'Processing',
    'Completed',
    'Cancelled',
  ];

  bool _loading = true;

  @override
  void initState() {
    super.initState();
    Future.microtask(() async {
      if (!mounted) return;
      await context.read<AuthProvider>().fetchUserOrders();
      if (mounted) setState(() => _loading = false);
    });
  }

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final orders = auth.orders ?? [];

    final filtered = selectedStatus == 'All'
        ? orders
        : orders.where((o) => o['status'] == selectedStatus).toList();

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        bottom: false,
        child: DsPage(
          child: Column(
            children: [
              _header(orders.length),
              if (orders.isNotEmpty) _filters(orders),
              Expanded(
                child: _loading && orders.isEmpty
                    ? const DsProductRowSkeleton(count: 4)
                    : orders.isEmpty
                    ? _emptyAll()
                    : filtered.isEmpty
                    ? _emptyFilter()
                    : RefreshIndicator(
                        onRefresh: () async => auth.fetchUserOrders(),
                        color: DsColor.primary,
                        child: ListView.separated(
                          padding: EdgeInsets.fromLTRB(
                            context.gutter,
                            DsSpace.x4,
                            context.gutter,
                            DsSpace.x10,
                          ),
                          physics: const AlwaysScrollableScrollPhysics(
                            parent: BouncingScrollPhysics(),
                          ),
                          itemCount: filtered.length,
                          separatorBuilder: (_, _) =>
                              const SizedBox(height: DsSpace.x3),
                          itemBuilder: (context, i) => DsEnter.staggered(
                            index: i,
                            child: _OrderCard(
                              order: filtered[i] as Map<String, dynamic>,
                            ),
                          ),
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _header(int total) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x3,
        context.gutter,
        DsSpace.x4,
      ),
      child: Row(
        children: [
          DsIconDisc(
            icon: Icons.arrow_back_rounded,
            tooltip: 'Back',
            background: DsColor.surface,
            shadow: DsShadow.low,
            onTap: () => Navigator.pop(context),
          ),
          const SizedBox(width: DsSpace.x3),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('HISTORY', style: DsType.eyebrow(color: DsColor.primary)),
                Text('My orders', style: text.headlineMedium),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _filters(List<dynamic> orders) {
    return SizedBox(
      height: 46,
      child: ListView.separated(
        scrollDirection: Axis.horizontal,
        padding: EdgeInsets.symmetric(horizontal: context.gutter),
        itemCount: _statuses.length,
        separatorBuilder: (_, _) => const SizedBox(width: DsSpace.x2),
        itemBuilder: (context, i) {
          final status = _statuses[i];
          final selected = status == selectedStatus;
          final count = status == 'All'
              ? orders.length
              : orders.where((o) => o['status'] == status).length;

          return Center(
            child: DsPressable(
              onTap: () => setState(() => selectedStatus = status),
              depth: 0.05,
              semanticLabel: '$status, $count orders',
              child: AnimatedContainer(
                duration: DsMotion.quick,
                padding: const EdgeInsets.symmetric(
                  horizontal: DsSpace.x4,
                  vertical: DsSpace.x2 + 2,
                ),
                decoration: BoxDecoration(
                  color: selected ? DsColor.ink : DsColor.surface,
                  borderRadius: DsRadius.pill,
                  boxShadow: selected ? null : DsShadow.low,
                ),
                child: Text(
                  '$status · $count',
                  style: Theme.of(context).textTheme.labelMedium?.copyWith(
                    color: selected ? Colors.white : DsColor.inkMuted,
                    fontWeight: FontWeight.w800,
                  ),
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  Widget _emptyAll() => DsState.empty(
    icon: Icons.receipt_long_outlined,
    title: 'No orders yet',
    message: 'When you place an order it appears here so you can track it.',
    actionLabel: 'Start shopping',
    onAction: () {
      mainScreenKey.currentState?.switchToHomeTab();
      Navigator.pop(context);
    },
  );

  Widget _emptyFilter() => DsState.empty(
    icon: Icons.filter_alt_off_outlined,
    title: 'No $selectedStatus orders',
    message: 'Try a different status to see the rest of your history.',
    actionLabel: 'Show all',
    onAction: () => setState(() => selectedStatus = 'All'),
  );
}

class _OrderCard extends StatelessWidget {
  final Map<String, dynamic> order;

  const _OrderCard({required this.order});

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final status = order['status']?.toString();
    final tint = orderStatusColor(status);

    return DsSurface(
      onTap: () => Navigator.push(
        context,
        MaterialPageRoute(builder: (_) => OrderDetailsScreen(order: order)),
      ),
      semanticLabel: 'Order ${order['id']}, $status',
      padding: const EdgeInsets.all(DsSpace.x4),
      child: Column(
        children: [
          Row(
            children: [
              Container(
                width: 44,
                height: 44,
                decoration: BoxDecoration(
                  color: tint.withValues(alpha: 0.10),
                  borderRadius: DsRadius.rSm,
                ),
                child: Icon(orderStatusIcon(status), color: tint, size: 21),
              ),
              const SizedBox(width: DsSpace.x3),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      'Order #${order['id'] ?? ''}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.titleMedium,
                    ),
                    Text(
                      formatOrderDate(order['date']),
                      style: text.bodySmall?.copyWith(fontSize: 11.5),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: DsSpace.x2),
              DsTag(label: (status ?? 'Unknown').toUpperCase(), color: tint),
            ],
          ),
          const Padding(
            padding: EdgeInsets.symmetric(vertical: DsSpace.x3),
            child: Divider(height: 1),
          ),
          Row(
            children: [
              Expanded(
                child: Text('Order total', style: text.bodySmall),
              ),
              Text(
                '৳${order['total'] ?? '0.00'}',
                style: DsType.numeral(18, color: DsColor.primary),
              ),
              const SizedBox(width: DsSpace.x2),
              const Icon(
                Icons.chevron_right_rounded,
                size: 18,
                color: DsColor.inkFaint,
              ),
            ],
          ),
        ],
      ),
    );
  }
}
