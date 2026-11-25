import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';
import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';

class MembershipScreen extends StatefulWidget {
  const MembershipScreen({super.key});
  @override
  State<MembershipScreen> createState() => _MembershipScreenState();
}

class _MembershipScreenState extends State<MembershipScreen> {
  bool loading = true, renewing = false;
  String? error;
  Map<String, dynamic>? data;
  List<dynamic> history = [];
  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  Map<String, String> get _headers => {
    'Accept': 'application/json',
    'Content-Type': 'application/json',
    'Authorization': 'Bearer ${context.read<AuthProvider>().token}',
  };
  Future<void> _load() async {
    setState(() {
      loading = true;
      error = null;
    });
    try {
      final responses = await Future.wait([
        http.get(Uri.parse(ApiEndpoints.membership), headers: _headers),
        http.get(Uri.parse(ApiEndpoints.membershipHistory), headers: _headers),
      ]);
      if (responses[0].statusCode != 200) {
        throw Exception(_message(responses[0]));
      }
      final current = jsonDecode(responses[0].body) as Map<String, dynamic>;
      final log = jsonDecode(responses[1].body) as Map<String, dynamic>;
      if (mounted) {
        setState(() {
          data = Map<String, dynamic>.from(current['data'] as Map);
          history = ((log['data'] as Map?)?['items'] as List?) ?? [];
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() => error = e.toString().replaceFirst('Exception: ', ''));
      }
    } finally {
      if (mounted) setState(() => loading = false);
    }
  }

  String _message(http.Response response) {
    try {
      return (jsonDecode(response.body) as Map)['message']?.toString() ??
          'Unable to load membership.';
    } catch (_) {
      return 'Unable to load membership.';
    }
  }

  Future<void> _renew() async {
    setState(() => renewing = true);
    try {
      final response = await http.post(
        Uri.parse(ApiEndpoints.membershipRenew),
        headers: _headers,
        body: '{}',
      );
      if (response.statusCode < 200 || response.statusCode >= 300) {
        throw Exception(_message(response));
      }
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Renewal payment request created.')),
        );
      }
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(e.toString().replaceFirst('Exception: ', ''))),
        );
      }
    } finally {
      if (mounted) setState(() => renewing = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: DsColor.canvas,
      appBar: AppBar(
        title: const Text('Membership Center'),
        actions: [
          IconButton(
            tooltip: 'Refresh membership',
            onPressed: loading ? null : _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Center(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: context.readableWidth),
          child: _body(),
        ),
      ),
    );
  }

  Widget _body() {
    if (loading) return const Center(child: CircularProgressIndicator());
    if (error != null) return _ErrorState(message: error!, retry: _load);
    final membership = Map<String, dynamic>.from(
      data?['membership'] as Map? ?? {},
    );
    final plan = Map<String, dynamic>.from(membership['plan'] as Map? ?? {});
    final stats = Map<String, dynamic>.from(data?['statistics'] as Map? ?? {});
    final progress = Map<String, dynamic>.from(data?['progress'] as Map? ?? {});
    final benefits = Map<String, dynamic>.from(data?['benefits'] as Map? ?? {});
    return RefreshIndicator(
      onRefresh: _load,
      child: ListView(
        padding: context.pagePad,
        children: [
          _MemberCard(plan: plan, membership: membership),
          const SizedBox(height: DsSpace.x4),
          Wrap(
            spacing: DsSpace.x3,
            runSpacing: DsSpace.x3,
            children: [
              _Stat(
                label: 'Current year',
                value: '৳${_money(stats['current_year_purchase'])}',
              ),
              _Stat(
                label: 'Lifetime',
                value: '৳${_money(stats['lifetime_purchase'])}',
              ),
              _Stat(
                label: 'Reward balance',
                value: _money(stats['reward_balance']),
              ),
            ],
          ),
          const SizedBox(height: DsSpace.x5),
          _ProgressCard(
            progress: progress,
            stats: stats,
            renewing: renewing,
            onRenew: _renew,
          ),
          const SizedBox(height: DsSpace.x5),
          Text('Benefits', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: DsSpace.x2),
          ...benefits.values
              .where((value) => value is Map && value['enabled'] == true)
              .map(
                (value) => _BenefitTile(
                  value: Map<String, dynamic>.from(value as Map),
                  all: benefits,
                ),
              ),
          const SizedBox(height: DsSpace.x5),
          Text('History', style: Theme.of(context).textTheme.headlineSmall),
          const SizedBox(height: DsSpace.x2),
          if (history.isEmpty)
            const Card(
              child: Padding(
                padding: EdgeInsets.all(DsSpace.x4),
                child: Text('No membership changes yet.'),
              ),
            )
          else
            ...history.map((raw) {
              final item = Map<String, dynamic>.from(raw as Map);
              final next = item['new_plan'] as Map?;
              return Card(
                child: ListTile(
                  leading: const Icon(Icons.workspace_premium_outlined),
                  title: Text(
                    (item['event'] ?? 'Membership event').toString().replaceAll(
                      '_',
                      ' ',
                    ),
                  ),
                  subtitle: Text(
                    '${next?['name'] ?? ''}\n${item['reason'] ?? ''}',
                  ),
                  isThreeLine: true,
                ),
              );
            }),
          const SizedBox(height: DsSpace.x6),
        ],
      ),
    );
  }

  String _money(dynamic value) =>
      double.tryParse('$value')?.toStringAsFixed(2) ?? '0.00';
}

class _MemberCard extends StatelessWidget {
  const _MemberCard({required this.plan, required this.membership});
  final Map<String, dynamic> plan, membership;
  Color _color() {
    final raw = (plan['color'] ?? '#0F766E').toString().replaceAll('#', '');
    return Color(int.tryParse('FF$raw', radix: 16) ?? 0xFF0F766E);
  }

  @override
  Widget build(BuildContext context) => Semantics(
    label: '${plan['name'] ?? 'Silver'} membership card',
    child: Container(
      padding: const EdgeInsets.all(DsSpace.x5),
      decoration: BoxDecoration(
        gradient: LinearGradient(colors: [_color(), DsColor.ink]),
        borderRadius: DsRadius.rLg,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          const Text(
            'REDPHARMA MEMBER',
            style: TextStyle(
              color: Colors.white70,
              letterSpacing: 2,
              fontWeight: FontWeight.bold,
            ),
          ),
          const SizedBox(height: DsSpace.x2),
          Text(
            '${plan['name'] ?? 'Silver'}',
            style: Theme.of(context).textTheme.displaySmall?.copyWith(
              color: Colors.white,
              fontWeight: FontWeight.w900,
            ),
          ),
          const SizedBox(height: DsSpace.x3),
          Text(
            'Status: ${membership['status'] ?? 'active'}',
            style: const TextStyle(color: Colors.white70),
          ),
        ],
      ),
    ),
  );
}

class _Stat extends StatelessWidget {
  const _Stat({required this.label, required this.value});
  final String label, value;
  @override
  Widget build(BuildContext context) => SizedBox(
    width: 150,
    child: Card(
      child: Padding(
        padding: const EdgeInsets.all(DsSpace.x3),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(label, style: Theme.of(context).textTheme.bodySmall),
            const SizedBox(height: 4),
            Text(
              value,
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
            ),
          ],
        ),
      ),
    ),
  );
}

class _ProgressCard extends StatelessWidget {
  const _ProgressCard({
    required this.progress,
    required this.stats,
    required this.renewing,
    required this.onRenew,
  });
  final Map<String, dynamic> progress, stats;
  final bool renewing;
  final VoidCallback onRenew;
  @override
  Widget build(BuildContext context) {
    final percent = (progress['percentage'] as num?)?.toDouble() ?? 0;
    final next = progress['next'] as Map?;
    final eligible =
        progress['fee_required'] == true &&
        (double.tryParse('${stats['current_year_purchase']}') ?? 0) >=
            (double.tryParse('${next?['minimum_annual_purchase']}') ??
                double.infinity);
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(DsSpace.x4),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Upgrade progress',
              style: Theme.of(
                context,
              ).textTheme.titleLarge?.copyWith(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: DsSpace.x2),
            Semantics(
              label: 'Upgrade progress ${percent.toStringAsFixed(0)} percent',
              child: LinearProgressIndicator(
                value: (percent / 100).clamp(0, 1),
                minHeight: 10,
              ),
            ),
            const SizedBox(height: DsSpace.x2),
            Text(
              next == null
                  ? 'Highest eligible tier reached'
                  : '৳${progress['remaining_purchase']} remaining for ${next['name']}',
            ),
            if (progress['fee_required'] == true)
              const Padding(
                padding: EdgeInsets.only(top: 8),
                child: Text(
                  'Red also requires the annual fee.',
                  style: TextStyle(color: Colors.orange),
                ),
              ),
            if (eligible)
              Padding(
                padding: const EdgeInsets.only(top: 12),
                child: FilledButton(
                  onPressed: renewing ? null : onRenew,
                  child: Text(
                    renewing ? 'Creating request…' : 'Request renewal',
                  ),
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _BenefitTile extends StatelessWidget {
  const _BenefitTile({required this.value, required this.all});
  final Map<String, dynamic> value, all;
  @override
  Widget build(BuildContext context) {
    var text = 'Included';
    if (value['code'] == 'reward_points') {
      text =
          '${value['value']} points per ${all['reward_spend']?['value'] ?? 100} BDT';
    } else if (value['unit'] == 'percent') {
      text = '${value['value']}%';
    } else if (value['unit'] == 'BDT') {
      text = (value['value'] as num?) == 0 ? 'Free' : '৳${value['value']}';
    } else if (value['value'] is String) {
      text = value['value'];
    }
    return Card(
      child: ListTile(
        leading: const Icon(Icons.check_circle, color: DsColor.primary),
        title: Text('${value['name']}'),
        subtitle: Text(text),
      ),
    );
  }
}

class _ErrorState extends StatelessWidget {
  const _ErrorState({required this.message, required this.retry});
  final String message;
  final VoidCallback retry;
  @override
  Widget build(BuildContext context) => Center(
    child: Padding(
      padding: const EdgeInsets.all(DsSpace.x5),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(message, textAlign: TextAlign.center),
          const SizedBox(height: DsSpace.x3),
          FilledButton(onPressed: retry, child: const Text('Try again')),
        ],
      ),
    ),
  );
}
