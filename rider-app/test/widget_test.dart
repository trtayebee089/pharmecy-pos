// Kept so `flutter create --platforms=ios .` does not generate its default
// counter-app test here (it only writes files that are missing).
import 'package:flutter_test/flutter_test.dart';
import 'package:redpharma_rider/config/api_config.dart';
import 'package:redpharma_rider/helpers/format.dart';

void main() {
  test('rider API lives under /rider/v1', () {
    expect(ApiConfig.riderBase, endsWith('/rider/v1'));
    expect(ApiConfig.baseUrl.endsWith('/'), isFalse);
  });

  test('formats taka', () {
    expect(taka(1250), '৳1,250.00');
    expect(statusLabel('in_transit'), 'On the way');
  });
}
