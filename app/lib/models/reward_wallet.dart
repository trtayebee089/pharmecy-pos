class RewardWallet {
  final double available,
      pending,
      expired,
      redeemed,
      lifetimeEarned,
      lifetimeRedeemed;
  const RewardWallet({
    required this.available,
    required this.pending,
    required this.expired,
    required this.redeemed,
    required this.lifetimeEarned,
    required this.lifetimeRedeemed,
  });
  factory RewardWallet.fromJson(Map<String, dynamic> json) => RewardWallet(
    available: _number(json['available_points']),
    pending: _number(json['pending_points']),
    expired: _number(json['expired_points']),
    redeemed: _number(json['redeemed_points']),
    lifetimeEarned: _number(json['lifetime_earned']),
    lifetimeRedeemed: _number(json['lifetime_redeemed']),
  );
  static double _number(dynamic value) =>
      value is num ? value.toDouble() : double.tryParse('$value') ?? 0;
}

class RewardLedgerEntry {
  final int id;
  final String type, status;
  final double points, balance;
  final DateTime? occurredAt;
  const RewardLedgerEntry({
    required this.id,
    required this.type,
    required this.status,
    required this.points,
    required this.balance,
    this.occurredAt,
  });
  factory RewardLedgerEntry.fromJson(Map<String, dynamic> json) =>
      RewardLedgerEntry(
        id: (json['id'] as num?)?.toInt() ?? 0,
        type: '${json['type'] ?? ''}',
        status: '${json['status'] ?? ''}',
        points: RewardWallet._number(json['points']),
        balance: RewardWallet._number(json['balance_after']),
        occurredAt: DateTime.tryParse('${json['occurred_at'] ?? ''}'),
      );
}
