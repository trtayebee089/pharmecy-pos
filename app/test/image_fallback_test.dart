import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:redpharmabd_app/helpers/image_urls.dart';
import 'package:redpharmabd_app/design/components/media.dart';

void main() {
  test(
    'both image hosts fall back in the opposite direction, preserving paths',
    () {
      for (final hosts in [
        ['pos.redpharmabd.com', 'api.redpharmabd.com'],
        ['api.redpharmabd.com', 'pos.redpharmabd.com'],
      ]) {
        expect(
          imageUrlsWithFallback(
            'https://${hosts[0]}/images/category/Hygiene.webp?v=2',
          ),
          [
            'https://${hosts[0]}/images/category/Hygiene.webp?v=2',
            'https://${hosts[1]}/images/category/Hygiene.webp?v=2',
          ],
        );
      }
      expect(imageUrlsWithFallback('https://cdn.example.com/item.png'), [
        'https://cdn.example.com/item.png',
      ]);
      expect(imageUrlsWithFallback(''), isEmpty);
    },
  );

  testWidgets(
    'image errors try the alternate host and retry after a transient failure',
    (tester) async {
      late BuildContext context;
      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (c) {
              context = c;
              return const SizedBox(
                width: 80,
                height: 80,
                child: DsImage(
                  url:
                      'https://pos.redpharmabd.com/images/category/baby-care.webp',
                ),
              );
            },
          ),
        ),
      );
      final primary = tester.widget<CachedNetworkImage>(
        find.byType(CachedNetworkImage).first,
      );
      expect(primary.imageUrl, contains('pos.redpharmabd.com'));
      final alternate =
          primary.errorWidget!(context, primary.imageUrl, Exception('404'))
              as CachedNetworkImage;
      expect(
        alternate.imageUrl,
        'https://api.redpharmabd.com/images/category/baby-care.webp',
      );
      expect(
        alternate.errorWidget!(context, alternate.imageUrl, Exception('404')),
        isA<Semantics>(),
      );
      await tester.pump(const Duration(seconds: 2));
      final retried = tester.widget<CachedNetworkImage>(
        find.byType(CachedNetworkImage).first,
      );
      expect(retried.key, isNot(primary.key));
      await tester.pumpWidget(const SizedBox());
    },
  );
}
