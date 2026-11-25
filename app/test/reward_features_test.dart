import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:redpharmabd_app/models/reward_wallet.dart';
import 'package:redpharmabd_app/repositories/recently_viewed_repository.dart';

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  test('reward wallet maps every authoritative balance', () {
    final wallet = RewardWallet.fromJson({
      'available_points': '120.50',
      'pending_points': 10,
      'expired_points': 2,
      'redeemed_points': 25,
      'lifetime_earned': 157.5,
      'lifetime_redeemed': 25,
    });
    expect(wallet.available, 120.5);
    expect(wallet.pending, 10);
    expect(wallet.lifetimeEarned, 157.5);
    expect(wallet.lifetimeRedeemed, 25);
  });

  test(
    'recently viewed deduplicates, sorts latest and caps at thirty',
    () async {
      final repository = RecentlyViewedRepository();
      for (var id = 1; id <= 31; id++) {
        await repository.record({'id': id, 'slug': 'product-$id'});
      }
      await repository.record({'id': 15, 'slug': 'product-15'});
      final items = await repository.list();
      expect(items, hasLength(30));
      expect(items.first['product_id'], 15);
      expect(items.where((item) => item['product_id'] == 15), hasLength(1));
      expect(
        items.every(
          (item) => item['viewed_time'] != null && item['last_viewed'] != null,
        ),
        isTrue,
      );
      final sync = await repository.syncPayload();
      expect(sync['version'], 1);
      expect(
        (sync['items'] as List).first.keys,
        containsAll(['product_id', 'viewed_time', 'last_viewed']),
      );
    },
  );
}
