import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../app_navigator.dart';
import '../design/tokens.dart';
import '../design/typography.dart';
import '../helpers/format.dart';
import '../models/rider_order.dart';
import '../providers/auth_provider.dart';
import '../providers/orders_provider.dart';
import '../services/location_permission.dart';
import '../services/location_reporter.dart';
import '../services/tracking_flow.dart';
import '../widgets/common.dart';
import '../widgets/order_card.dart';
import 'order_detail_screen.dart';

class HomeScreen extends StatefulWidget {
  const HomeScreen({super.key});

  @override
  State<HomeScreen> createState() => _HomeScreenState();
}

class _HomeScreenState extends State<HomeScreen> with WidgetsBindingObserver {
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    WidgetsBinding.instance.addPostFrameCallback((_) async {
      if (!mounted) return;
      final orders = context.read<OrdersProvider>();
      orders.load('active');
      orders.load('done');
      // Already on duty from a previous session: resume reporting.
      await ensureTracking(context);
    });
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      context.read<LocationReporter>().resume();
      _refresh(includeDone: false);
    }
  }

  Future<void> _refresh({bool includeDone = true}) async {
    final auth = context.read<AuthProvider>();
    final orders = context.read<OrdersProvider>();
    await Future.wait([
      auth.refreshQuietly(),
      orders.load('active'),
      if (includeDone) orders.load('done'),
    ]);
    if (mounted) await ensureTracking(context);
  }

  Future<void> _toggleDuty(bool on) async {
    final auth = context.read<AuthProvider>();
    if (on) {
      final ok = await ensureLocationPermission(context);
      if (!ok) {
        showMessage('Location access is needed to go on duty.', error: true);
        return;
      }
    }
    try {
      final message = await auth.setDuty(on);
      if (message != null) showMessage(message);
    } on ApiException catch (e) {
      showMessage(e.message, error: true);
    }
  }

  Future<void> _logout() async {
    final auth = context.read<AuthProvider>();
    final reporter = context.read<LocationReporter>();
    final queued = reporter.queued;
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Sign out?'),
        content: Text(
          'You will go off duty and stop sharing your location.'
          '${queued > 0 ? '\n\n$queued unsent location point${queued == 1 ? '' : 's'} will be sent first if you are online.' : ''}',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(false),
            child: const Text('Cancel'),
          ),
          TextButton(
            onPressed: () => Navigator.of(ctx).pop(true),
            child: const Text('Sign out'),
          ),
        ],
      ),
    );
    if (confirmed == true) await auth.logout();
  }

  void _open(RiderOrder order) {
    Navigator.of(context).push(
      MaterialPageRoute<void>(
        builder: (_) => OrderDetailScreen(initial: order),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final profile = context.watch<AuthProvider>().profile;
    final text = Theme.of(context).textTheme;
    return DefaultTabController(
      length: 2,
      child: Scaffold(
        appBar: AppBar(
          titleSpacing: DsSpace.x4,
          title: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Hi, ${profile?.name ?? 'Rider'}', style: text.headlineSmall),
              if (profile != null && profile.phone.isNotEmpty)
                Text(profile.phone, style: text.bodySmall),
            ],
          ),
          actions: [
            IconButton(
              tooltip: 'Sign out',
              icon: const Icon(Icons.logout_rounded),
              onPressed: _logout,
            ),
            DsSpace.h2,
          ],
        ),
        body: Column(
          children: [
            Padding(
              padding: const EdgeInsets.fromLTRB(
                DsSpace.x4,
                DsSpace.x2,
                DsSpace.x4,
                DsSpace.x3,
              ),
              child: Column(
                children: [
                  _DutyCard(onToggle: _toggleDuty),
                  DsSpace.v3,
                  Row(
                    children: [
                      Expanded(
                        child: StatTile(
                          icon: Icons.task_alt_rounded,
                          value: '${profile?.todayDelivered ?? 0}',
                          label: 'Delivered today',
                        ),
                      ),
                      DsSpace.h3,
                      Expanded(
                        child: StatTile(
                          icon: Icons.timer_outlined,
                          color: DsColor.info,
                          value: profile?.todayAvgMinutes != null
                              ? '${profile!.todayAvgMinutes} min'
                              : '—',
                          label: 'Avg delivery',
                        ),
                      ),
                      DsSpace.h3,
                      Expanded(
                        child: StatTile(
                          icon: Icons.account_balance_wallet_outlined,
                          color: DsColor.brand,
                          value: taka(profile?.cashHeld ?? 0),
                          label: 'Cash held',
                        ),
                      ),
                    ],
                  ),
                ],
              ),
            ),
            Container(
              margin: const EdgeInsets.symmetric(horizontal: DsSpace.x4),
              padding: const EdgeInsets.all(4),
              decoration: const BoxDecoration(
                color: DsColor.sunken,
                borderRadius: DsRadius.pill,
              ),
              child: TabBar(
                dividerColor: Colors.transparent,
                indicatorSize: TabBarIndicatorSize.tab,
                indicator: BoxDecoration(
                  color: DsColor.surface,
                  borderRadius: DsRadius.pill,
                  boxShadow: DsShadow.low,
                ),
                labelColor: DsColor.ink,
                unselectedLabelColor: DsColor.inkSoft,
                labelStyle: text.labelLarge,
                tabs: [
                  Tab(
                    height: 40,
                    text: 'Active'
                        '${(profile?.activeOrders ?? 0) > 0 ? ' (${profile!.activeOrders})' : ''}',
                  ),
                  const Tab(height: 40, text: 'Done'),
                ],
              ),
            ),
            DsSpace.v2,
            Expanded(
              child: TabBarView(
                children: [
                  _OrdersTab(
                    scope: 'active',
                    onOpen: _open,
                    onRefresh: _refresh,
                  ),
                  _OrdersTab(
                    scope: 'done',
                    onOpen: _open,
                    onRefresh: _refresh,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _DutyCard extends StatelessWidget {
  const _DutyCard({required this.onToggle});

  final ValueChanged<bool> onToggle;

  @override
  Widget build(BuildContext context) {
    final auth = context.watch<AuthProvider>();
    final reporter = context.watch<LocationReporter>();
    final onDuty = auth.onDuty;
    final text = Theme.of(context).textTheme;

    String detail;
    Color detailColor = DsColor.onDark.withValues(alpha: 0.8);
    if (!onDuty) {
      detail = 'Go on duty to receive orders and share your location.';
      detailColor = DsColor.inkMuted;
    } else if (reporter.error != null) {
      detail = reporter.error!;
      detailColor = const Color(0xFFFFD7DE);
    } else if (!reporter.isReporting) {
      detail = 'Location is not being shared. Tap to allow location.';
      detailColor = const Color(0xFFFFD7DE);
    } else {
      final sent = reporter.lastSentAt;
      detail = 'Sharing your location'
          '${sent != null ? ' · last sent ${timeOfDay(sent)}' : ''}'
          '${reporter.queued > 0 ? ' · ${reporter.queued} waiting' : ''}';
    }

    return AnimatedContainer(
      duration: DsMotion.base,
      curve: DsMotion.ease,
      padding: const EdgeInsets.fromLTRB(
        DsSpace.x5,
        DsSpace.x4,
        DsSpace.x3,
        DsSpace.x4,
      ),
      decoration: BoxDecoration(
        color: onDuty ? DsColor.primary : DsColor.surface,
        borderRadius: DsRadius.rLg,
        boxShadow: onDuty ? DsShadow.glow(DsColor.primary) : DsShadow.low,
      ),
      child: Material(
        color: Colors.transparent,
        child: InkWell(
          borderRadius: DsRadius.rLg,
          onTap: onDuty && !reporter.isReporting
              ? () => ensureTracking(context)
              : null,
          child: Row(
            children: [
              Icon(
                onDuty ? Icons.two_wheeler_rounded : Icons.bedtime_outlined,
                size: 30,
                color: onDuty ? DsColor.onDark : DsColor.inkSoft,
              ),
              DsSpace.h4,
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      onDuty ? 'On duty' : 'Off duty',
                      style: DsType.numeral(
                        20,
                        color: onDuty ? DsColor.onDark : DsColor.ink,
                      ),
                    ),
                    DsSpace.v1,
                    Text(
                      detail,
                      style: text.bodySmall?.copyWith(color: detailColor),
                    ),
                  ],
                ),
              ),
              if (auth.dutyBusy)
                const Padding(
                  padding: EdgeInsets.all(DsSpace.x3),
                  child: SizedBox(
                    width: 24,
                    height: 24,
                    child: CircularProgressIndicator(
                      strokeWidth: 2.5,
                      color: DsColor.onDark,
                    ),
                  ),
                )
              else
                Switch(
                  value: onDuty,
                  onChanged: onToggle,
                  thumbColor: WidgetStateProperty.resolveWith(
                    (s) => s.contains(WidgetState.selected)
                        ? DsColor.primary
                        : DsColor.inkFaint,
                  ),
                  trackColor: WidgetStateProperty.resolveWith(
                    (s) => s.contains(WidgetState.selected)
                        ? DsColor.onDark
                        : DsColor.sunken,
                  ),
                ),
            ],
          ),
        ),
      ),
    );
  }
}

class _OrdersTab extends StatelessWidget {
  const _OrdersTab({
    required this.scope,
    required this.onOpen,
    required this.onRefresh,
  });

  final String scope;
  final ValueChanged<RiderOrder> onOpen;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    final orders = context.watch<OrdersProvider>();
    final list = scope == 'done' ? orders.done : orders.active;

    Widget body;
    if (list.items.isEmpty) {
      Widget state;
      if (list.loading || !list.loaded && list.error == null) {
        state = const Padding(
          padding: EdgeInsets.all(DsSpace.x10),
          child: Center(child: CircularProgressIndicator()),
        );
      } else if (list.error != null) {
        state = MessageState(
          icon: Icons.cloud_off_rounded,
          title: 'Could not load orders',
          body: list.error,
          onRetry: () => orders.load(scope),
        );
      } else {
        state = MessageState(
          icon: scope == 'done'
              ? Icons.history_rounded
              : Icons.inventory_2_outlined,
          title: scope == 'done' ? 'Nothing delivered yet' : 'No active orders',
          body: scope == 'done'
              ? 'Orders you delivered in the last 30 days show here.'
              : 'New orders the pharmacy assigns to you will show here. Pull down to refresh.',
        );
      }
      body = ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [state],
      );
    } else {
      body = ListView.separated(
        physics: const AlwaysScrollableScrollPhysics(),
        padding: const EdgeInsets.fromLTRB(
          DsSpace.x4,
          DsSpace.x2,
          DsSpace.x4,
          DsSpace.x8,
        ),
        itemCount: list.items.length + (list.error != null ? 1 : 0),
        separatorBuilder: (_, i) => DsSpace.v3,
        itemBuilder: (context, i) {
          if (i == list.items.length) {
            return Text(
              list.error!,
              textAlign: TextAlign.center,
              style: Theme.of(context)
                  .textTheme
                  .bodySmall
                  ?.copyWith(color: DsColor.danger),
            );
          }
          final order = list.items[i];
          return OrderCard(order: order, onTap: () => onOpen(order));
        },
      );
    }

    return RefreshIndicator(onRefresh: onRefresh, child: body);
  }
}
