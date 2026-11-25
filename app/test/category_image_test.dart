import 'package:flutter_test/flutter_test.dart';
import 'package:redpharmabd_app/helpers/category_image.dart';

void main() {
  test(
    'cached POS category artwork prefers the verified API asset host',
    () {
      expect(
        categoryImageUrl(
          'https://pos.redpharmabd.com/images/category/baby-care.webp?v=2',
        ),
        'https://api.redpharmabd.com/images/category/baby-care.webp?v=2',
      );
      expect(
        categoryImageUrl('https://pos.redpharmabd.com/images/no-image.png'),
        'https://api.redpharmabd.com/images/no-image.png',
      );
    },
  );

  test('relative artwork paths and filenames resolve against the API', () {
    for (final path in [
      '/images/category/Hygiene.webp',
      'images/category/Hygiene.webp',
      'Hygiene.webp',
    ]) {
      expect(
        categoryImageUrl(path),
        'https://api.redpharmabd.com/images/category/Hygiene.webp',
      );
    }
  });

  test('existing CDN and non-category URLs are preserved', () {
    for (final url in [
      'https://cdn.example.com/category.webp',
      'https://api.redpharmabd.com/images/category/baby-care.webp',
      'https://pos.redpharmabd.com/images/product/item.png',
    ]) {
      expect(categoryImageUrl(url), url);
    }
  });

  test('missing artwork remains empty for the normal placeholder', () {
    expect(categoryImageUrl(null), '');
    expect(categoryImageUrl('  '), '');
  });
}
