import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:intl/intl.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/repositories/reorder_repository.dart';
import 'package:redpharmabd_app/screens/auth/orders.dart'
    show orderStatusColor, orderStatusIcon;
import 'package:redpharmabd_app/widgets/orders/live_delivery_card.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Order detail, rebuilt.
///
/// The status summary is a dark panel carrying the order number, date and
/// total — one glance answers "what did I buy and what did it cost". Below it,
/// tracking is a genuine connected timeline: a rail of dots joined by a line,
/// most recent highlighted, rather than a stack of tinted boxes.
/// ─────────────────────────────────────────────────────────────────────────
class OrderDetailsScreen extends StatefulWidget {
  final Map<String, dynamic> order;

  const OrderDetailsScreen({super.key, required this.order});

  @override
  State<OrderDetailsScreen> createState() => _OrderDetailsScreenState();
}

class _OrderDetailsScreenState extends State<OrderDetailsScreen> {
  Map<String, dynamic>? orderDetail;
  bool loading = true;
  bool reordering = false;
  String? error;

  @override
  void initState() {
    super.initState();
    _fetchOrderDetail();
  }

  Future<void> _fetchOrderDetail() async {
    setState(() {
      loading = true;
      error = null;
    });

    final orderId = widget.order['id'];
    final url = Uri.parse('${ApiEndpoints.orderDetail}/$orderId');

    try {
      final token = Provider.of<AuthProvider>(context, listen: false).token;

      if (token == null || token.isEmpty) {
        setState(() {
          error = 'Authentication token not found.';
          loading = false;
        });
        return;
      }

      final response = await http.get(
        url,
        headers: {
          'Authorization': 'Bearer $token',
          'Accept': 'application/json',
        },
      );

      if (response.body.startsWith('<!DOCTYPE HTML>')) {
        setState(() {
          error = 'Invalid response from server. Please check your token.';
          loading = false;
        });
        return;
      }

      if (response.statusCode == 200) {
        final jsonData = json.decode(response.body);
        if (jsonData['success'] == true) {
          setState(() {
            orderDetail = jsonData['data'];
            loading = false;
          });
        } else {
          setState(() {
            error = jsonData['message'] ?? 'Failed to fetch order details.';
            loading = false;
          });
        }
      } else {
        setState(() {
          error =
              'Error ${response.statusCode}: Failed to fetch order details.';
          loading = false;
        });
      }
    } catch (e) {
      setState(() {
        error = e.toString();
        loading = false;
      });
    }
  }

  String _date(String? s) {
    if (s == null) return 'N/A';
    try {
      return DateFormat('dd MMM yyyy, hh:mm a').format(DateTime.parse(s));
    } catch (_) {
      return s;
    }
  }

  Future<void> _reorder() async {
    final token = context.read<AuthProvider>().token;
    final cart = context.read<CartProvider>();
    if (token == null || reordering) return;
    setState(() => reordering = true);
    try {
      final result = await ReorderRepository().build(
        token,
        (widget.order['id'] as num).toInt(),
      );
      cart.clearCart();
      for (final raw in result['items'] as List? ?? const []) {
        final line = Map<String, dynamic>.from(raw as Map);
        final product = Map<String, dynamic>.from(line['product'] as Map);
        cart.addItem({
          ...product,
          'price': line['unit_price'],
          'quantity': (line['quantity'] as num?)?.toInt() ?? 1,
          'stock_qty': (product['qty'] as num?)?.toInt(),
        });
      }
      if (!mounted) return;
      final warnings = result['warnings'] as List? ?? const [];
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            warnings.isEmpty
                ? 'Cart rebuilt with current prices and membership benefits.'
                : 'Cart rebuilt with ${warnings.length} stock or price update(s).',
          ),
        ),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e'.replaceFirst('Exception: ', ''))),
        );
      }
    } finally {
      if (mounted) setState(() => reordering = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        bottom: false,
        child: DsPage(
          child: Column(
            children: [
              _header(),
              Expanded(
                child: loading
                    ? const DsProductRowSkeleton(count: 3)
                    : error != null
                    ? DsState.error(
                        message: error!,
                        onAction: _fetchOrderDetail,
                      )
                    : RefreshIndicator(
                        onRefresh: _fetchOrderDetail,
                        color: DsColor.primary,
                        child: ListView(
                          physics: const AlwaysScrollableScrollPhysics(
                            parent: BouncingScrollPhysics(),
                          ),
                          padding: EdgeInsets.fromLTRB(
                            context.gutter,
                            0,
                            context.gutter,
                            DsSpace.x10,
                          ),
                          children: [
                            _summary(orderDetail!),
                            const SizedBox(height: DsSpace.x4),
                            _live(orderDetail!),
                            _items(orderDetail!),
                            if (orderDetail!['tracking'] != null) ...[
                              const SizedBox(height: DsSpace.x4),
                              _tracking(orderDetail!['tracking']),
                            ],
                          ],
                        ),
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _header() {
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
            child: Text(
              'Order details',
              style: Theme.of(context).textTheme.headlineSmall,
            ),
          ),
          IconButton(
            tooltip: 'Reorder with current prices',
            onPressed: reordering ? null : _reorder,
            icon: reordering
                ? const SizedBox.square(
                    dimension: 20,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.replay_rounded),
          ),
        ],
      ),
    );
  }

  Widget _summary(Map<String, dynamic> order) {
    final text = Theme.of(context).textTheme;
    final status = (order['tracking']?['current_status'] ?? order['status'])
        ?.toString();
    final tint = orderStatusColor(status);
    final tracking = order['tracking']?['tracking_number'];

    return DsObsidianPanel(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  color: tint.withValues(alpha: 0.18),
                  borderRadius: DsRadius.rSm,
                ),
                child: Icon(orderStatusIcon(status), color: tint, size: 22),
              ),
              const SizedBox(width: DsSpace.x4),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      (status ?? 'UNKNOWN').toUpperCase(),
                      style: DsType.eyebrow(color: tint),
                    ),
                    const SizedBox(height: DsSpace.x1),
                    Text(
                      'Order #${order['id']}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.headlineSmall?.copyWith(
                        color: DsColor.onDark,
                      ),
                    ),
                    Text(
                      _date(order['date']),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.bodySmall?.copyWith(
                        color: DsColor.onDark.withValues(alpha: 0.6),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
          const SizedBox(height: DsSpace.x5),
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.end,
            spacing: DsSpace.x5,
            runSpacing: DsSpace.x3,
            children: [
              if (tracking != null)
                _darkStat('Tracking number', '$tracking', text),
              _darkStat(
                'Total paid',
                '৳ ${order['grand_total']}',
                text,
                big: true,
              ),
            ],
          ),
        ],
      ),
    );
  }

  /// Live map + arrival time while the rider is on the way, and the delivery
  /// time once delivered. The card loads `orders/{number}/live` itself, polls
  /// while in transit and renders nothing otherwise.
  Widget _live(Map<String, dynamic> order) {
    final number = order['number']?.toString();
    final token = Provider.of<AuthProvider>(context, listen: false).token;
    final status = order['status']?.toString().toLowerCase();
    if (number == null ||
        number.isEmpty ||
        token == null ||
        token.isEmpty ||
        status == 'cancelled' ||
        status == 'pending') {
      return const SizedBox.shrink();
    }
    return LiveDeliveryCard(
      key: ValueKey('live-$number'),
      orderNumber: number,
      token: token,
      // Refresh the timeline when the parcel arrives while the screen is open.
      onDelivered: _fetchOrderDetail,
    );
  }

  Widget _darkStat(
    String label,
    String value,
    TextTheme text, {
    bool big = false,
  }) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      mainAxisSize: MainAxisSize.min,
      children: [
        Text(
          label.toUpperCase(),
          style: DsType.eyebrow(color: DsColor.onDark.withValues(alpha: 0.5)),
        ),
        const SizedBox(height: DsSpace.x1),
        Text(
          value,
          style: DsType.numeral(big ? 22 : 15, color: DsColor.onDark),
        ),
      ],
    );
  }

  Widget _items(Map<String, dynamic> order) {
    final text = Theme.of(context).textTheme;
    final items = List<Map<String, dynamic>>.from(order['items'] ?? []);

    return DsSurface(
      padding: const EdgeInsets.all(DsSpace.x5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Items', style: text.titleLarge),
          const SizedBox(height: DsSpace.x3),
          for (final item in items)
            Padding(
              padding: const EdgeInsets.only(bottom: DsSpace.x2),
              child: Container(
                padding: const EdgeInsets.all(DsSpace.x3),
                decoration: BoxDecoration(
                  color: DsColor.canvas,
                  borderRadius: DsRadius.rSm,
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(item['name'] ?? '', style: text.titleSmall),
                          const SizedBox(height: 1),
                          Text(
                            'Qty ${item['quantity'] ?? 0} · ৳ ${item['price']}',
                            style: text.bodySmall?.copyWith(fontSize: 11.5),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(width: DsSpace.x3),
                    Text('৳ ${item['subtotal']}', style: DsType.numeral(14)),
                  ],
                ),
              ),
            ),
          const Padding(
            padding: EdgeInsets.symmetric(vertical: DsSpace.x3),
            child: Divider(height: 1),
          ),
          _totalRow('Subtotal', order['total_price'], text),
          _totalRow('Shipping', order['shipping_cost'], text),
          if (order['discount'] != null &&
              (order['discount']['amount'] ?? 0) > 0)
            _totalRow(
              'Discount',
              order['discount']['amount'],
              text,
              tint: DsColor.success,
            ),
          const Padding(
            padding: EdgeInsets.symmetric(vertical: DsSpace.x3),
            child: Divider(height: 1),
          ),
          Row(
            children: [
              Expanded(child: Text('Grand total', style: text.titleMedium)),
              Text(
                '৳ ${order['grand_total']}',
                style: DsType.numeral(20, color: DsColor.primary),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _totalRow(String label, dynamic value, TextTheme text, {Color? tint}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: DsSpace.x1),
      child: Row(
        children: [
          Expanded(child: Text(label, style: text.bodyMedium)),
          Text(
            '৳ ${value ?? '0.00'}',
            style: DsType.numeral(14, color: tint ?? DsColor.ink),
          ),
        ],
      ),
    );
  }

  Widget _tracking(Map<String, dynamic> tracking) {
    final text = Theme.of(context).textTheme;
    final history = List<Map<String, dynamic>>.from(
      tracking['histories'] ?? [],
    );
    if (history.isEmpty) return const SizedBox.shrink();

    return DsSurface(
      padding: const EdgeInsets.all(DsSpace.x5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Tracking', style: text.titleLarge),
          const SizedBox(height: DsSpace.x5),
          for (var i = 0; i < history.length; i++)
            _TimelineStop(
              status: history[i]['status']?.toString(),
              note: history[i]['notes']?.toString(),
              at: _date(history[i]['changed_at']),
              isLast: i == history.length - 1,
              isCurrent: i == history.length - 1,
            ),
        ],
      ),
    );
  }
}

/// One event on the tracking rail: a dot, an optional connector, and the copy.
class _TimelineStop extends StatelessWidget {
  final String? status;
  final String? note;
  final String at;
  final bool isLast;
  final bool isCurrent;

  const _TimelineStop({
    required this.status,
    required this.note,
    required this.at,
    required this.isLast,
    required this.isCurrent,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final tint = orderStatusColor(status);

    return IntrinsicHeight(
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Column(
            children: [
              Container(
                width: 26,
                height: 26,
                decoration: BoxDecoration(
                  color: isCurrent ? tint : tint.withValues(alpha: 0.12),
                  shape: BoxShape.circle,
                ),
                child: Icon(
                  orderStatusIcon(status),
                  size: 13,
                  color: isCurrent ? Colors.white : tint,
                ),
              ),
              // The connector is omitted on the final stop, so the rail never
              // dangles past the last event.
              if (!isLast)
                Expanded(
                  child: Container(
                    width: 2,
                    margin: const EdgeInsets.symmetric(vertical: 2),
                    color: DsColor.hairline,
                  ),
                ),
            ],
          ),
          const SizedBox(width: DsSpace.x3),
          Expanded(
            child: Padding(
              padding: EdgeInsets.only(bottom: isLast ? 0 : DsSpace.x6),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    (status ?? 'Unknown').toUpperCase(),
                    style: DsType.eyebrow(color: tint),
                  ),
                  if (note != null && note!.isNotEmpty)
                    Padding(
                      padding: const EdgeInsets.only(top: DsSpace.x1),
                      child: Text(note!, style: text.bodyMedium),
                    ),
                  Padding(
                    padding: const EdgeInsets.only(top: DsSpace.x1),
                    child: Text(
                      at,
                      style: text.bodySmall?.copyWith(fontSize: 11),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}
