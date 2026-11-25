import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/models/reward_wallet.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/repositories/reward_repository.dart';

class RewardsScreen extends StatefulWidget {
  const RewardsScreen({super.key});
  @override
  State<RewardsScreen> createState() => _RewardsScreenState();
}

class _RewardsScreenState extends State<RewardsScreen> {
  final repository = RewardRepository(), points = TextEditingController();
  RewardWallet? wallet;
  List<RewardLedgerEntry> transactions = [];
  Map<String, dynamic>? profile, quote;
  String? error;
  bool loading = true, validating = false;
  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    points.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    final token = context.read<AuthProvider>().token;
    if (token == null) return setState(() => error = 'Please sign in again.');
    try {
      final values = await Future.wait([
        repository.profile(token),
        repository.transactions(token),
      ]);
      if (!mounted) return;
      setState(() {
        profile = values[0] as Map<String, dynamic>;
        wallet = RewardWallet.fromJson(
          Map<String, dynamic>.from(profile!['wallet']),
        );
        transactions = values[1] as List<RewardLedgerEntry>;
        loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          error = '$e'.replaceFirst('Exception: ', '');
          loading = false;
        });
      }
    }
  }

  Future<void> _redeem() async {
    final token = context.read<AuthProvider>().token;
    final cart = context.read<CartProvider>();
    final requested = double.tryParse(points.text) ?? 0;
    if (token == null || requested <= 0) return;
    setState(() {
      validating = true;
      error = null;
    });
    try {
      final value = await repository.redemptionQuote(
        token,
        requested,
        cart.totalAmount,
      );
      cart.setRewardPoints((value['points'] as num).toDouble());
      if (mounted) setState(() => quote = value);
    } catch (e) {
      if (mounted) setState(() => error = '$e'.replaceFirst('Exception: ', ''));
    } finally {
      if (mounted) setState(() => validating = false);
    }
  }

  String number(num? value) =>
      ((value ?? 0).toDouble()).toStringAsFixed((value ?? 0) % 1 == 0 ? 0 : 2);
  @override
  Widget build(BuildContext context) {
    final plan = (profile?['membership'] as Map?)?['plan'] as Map?;
    return Scaffold(
      backgroundColor: DsColor.canvas,
      appBar: AppBar(title: const Text('My Rewards')),
      body: loading
          ? const Center(child: CircularProgressIndicator())
          : error != null && wallet == null
          ? Center(child: Text(error!))
          : RefreshIndicator(
              onRefresh: _load,
              child: ListView(
                padding: const EdgeInsets.all(DsSpace.x4),
                children: [
                  Container(
                    padding: const EdgeInsets.all(DsSpace.x6),
                    decoration: BoxDecoration(
                      gradient: const LinearGradient(
                        colors: [DsColor.primary, Color(0xFF0F172A)],
                      ),
                      borderRadius: BorderRadius.circular(28),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'AVAILABLE BALANCE',
                          style: TextStyle(
                            color: Colors.white70,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                        Text(
                          number(wallet?.available),
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 48,
                            fontWeight: FontWeight.w900,
                          ),
                        ),
                        Text(
                          '${plan?['name'] ?? 'Member'} rewards',
                          style: const TextStyle(color: Colors.white70),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: DsSpace.x4),
                  GridView.count(
                    crossAxisCount: 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    childAspectRatio: 1.7,
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    children: [
                      _stat('Pending', wallet?.pending),
                      _stat('Lifetime earned', wallet?.lifetimeEarned),
                      _stat('Lifetime redeemed', wallet?.lifetimeRedeemed),
                      _stat('Expired', wallet?.expired),
                    ],
                  ),
                  const SizedBox(height: DsSpace.x6),
                  Text(
                    'Redeem rewards',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  const Text(
                    'Validation uses your current membership, wallet, and cart total.',
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: points,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Points',
                      border: OutlineInputBorder(),
                    ),
                  ),
                  const SizedBox(height: 12),
                  FilledButton(
                    onPressed: validating ? null : _redeem,
                    child: Text(validating ? 'Validating…' : 'Use at checkout'),
                  ),
                  if (quote != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(
                        '${quote!['points']} points will apply BDT ${quote!['amount']} at checkout.',
                        style: const TextStyle(
                          color: DsColor.primary,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                    ),
                  if (error != null)
                    Padding(
                      padding: const EdgeInsets.only(top: 12),
                      child: Text(
                        error!,
                        style: const TextStyle(color: DsColor.danger),
                      ),
                    ),
                  const SizedBox(height: DsSpace.x6),
                  Text(
                    'Reward history',
                    style: Theme.of(context).textTheme.titleLarge?.copyWith(
                      fontWeight: FontWeight.w900,
                    ),
                  ),
                  if (transactions.isEmpty)
                    const Padding(
                      padding: EdgeInsets.symmetric(vertical: 24),
                      child: Text('No reward activity yet.'),
                    )
                  else
                    ...transactions.map(
                      (item) => ListTile(
                        contentPadding: EdgeInsets.zero,
                        title: Text(item.type.replaceAll('_', ' ')),
                        subtitle: Text(item.status),
                        trailing: Text(
                          '${item.points > 0 ? '+' : ''}${number(item.points)}',
                          style: TextStyle(
                            fontWeight: FontWeight.bold,
                            color: item.points >= 0
                                ? DsColor.primary
                                : DsColor.danger,
                          ),
                        ),
                      ),
                    ),
                ],
              ),
            ),
    );
  }

  Widget _stat(String label, num? value) => Container(
    padding: const EdgeInsets.all(16),
    decoration: BoxDecoration(
      color: Colors.white,
      borderRadius: BorderRadius.circular(18),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(label, style: const TextStyle(color: Colors.black54)),
        const SizedBox(height: 4),
        Text(
          number(value),
          style: const TextStyle(fontSize: 20, fontWeight: FontWeight.w900),
        ),
      ],
    ),
  );
}
