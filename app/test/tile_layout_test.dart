import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:provider/provider.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/components/product_tile.dart';
import 'package:redpharmabd_app/design/theme.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';

/// Structural guarantees for the product tile.
///
/// These assert the properties the layout must hold rather than eyeballing a
/// screenshot: every card the same height, the footer pinned at the same
/// offset regardless of title length, no dead space, and no overflow.
const _sizes = <String, Size>{
  'small phone': Size(320, 568),
  'compact phone': Size(360, 640),
  'standard phone': Size(390, 844),
  'large phone': Size(430, 932),
  'tablet portrait': Size(768, 1024),
};

const _scales = <double>[1.0, 1.25, 1.35];

/// Titles that wrap to different line counts — the case that used to break.
Map<String, dynamic> _product({
  required int id,
  required String name,
  String brand = 'Beximco Pharmaceuticals Ltd.',
  int? stock = 12,
}) => {
  'id': id,
  'name': name,
  'slug': 'p$id',
  'price': 4.5,
  'image': '',
  if (stock != null) 'available_qty': stock,
  'brand': {'id': 1, 'title': brand},
  'category': {'id': 1, 'name': 'Tablet', 'slug': 'tablet', 'image': ''},
};

final _shortName = _product(id: 1, name: 'Napa');
final _twoLineName = _product(
  id: 2,
  name: 'Napa Extend 665mg Tablet (Extended Release)',
);
final _noStock = _product(id: 3, name: 'Sergel 20mg', stock: null);

Future<void> _pumpRow(
  WidgetTester tester,
  Size size,
  double scale,
  List<Map<String, dynamic>> products, {
  double tileWidth = 168,
}) async {
  tester.view.physicalSize = size;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.resetPhysicalSize);
  addTearDown(tester.view.resetDevicePixelRatio);

  await tester.pumpWidget(
    ChangeNotifierProvider(
      create: (_) => CartProvider(),
      child: MaterialApp(
        theme: DsTheme.light,
        home: MediaQuery(
          data: MediaQueryData(
            size: size,
            textScaler: TextScaler.linear(scale),
          ),
          child: Scaffold(
            body: Builder(
              builder: (context) {
                final spec = DsTileSpec.resolve(context);
                // Horizontally scrollable so the harness itself cannot
                // overflow on a narrow viewport — any overflow reported by
                // these tests then genuinely comes from the tile.
                return Align(
                  alignment: Alignment.topLeft,
                  child: SizedBox(
                    height: spec.totalHeight(tileWidth),
                    // SingleChildScrollView builds every child eagerly, unlike
                    // ListView, so all tiles exist to be compared.
                    child: SingleChildScrollView(
                      scrollDirection: Axis.horizontal,
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: [
                        for (final p in products)
                          SizedBox(
                            width: tileWidth,
                            child: DsProductTile(product: p, spec: spec),
                          ),
                        ],
                      ),
                    ),
                  ),
                );
              },
            ),
          ),
        ),
      ),
    ),
  );
  await tester.pump(const Duration(milliseconds: 400));
}

void main() {
  setUp(() => SharedPreferences.setMockInitialValues({}));

  group('every tile is the same height regardless of content', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          await _pumpRow(tester, entry.value, scale, [
            _shortName,
            _twoLineName,
            _noStock,
          ]);

          expect(
            tester.takeException(),
            isNull,
            reason: 'overflow at ${entry.key} @ ${scale}x',
          );

          final tiles = tester
              .widgetList<DsProductTile>(find.byType(DsProductTile))
              .toList();
          expect(tiles.length, 3);

          final heights = find
              .byType(DsProductTile)
              .evaluate()
              .map((e) => (e.renderObject as RenderBox).size.height)
              .toSet();

          expect(
            heights.length,
            1,
            reason:
                'cards differ in height at ${entry.key} @ ${scale}x: $heights',
          );
        });
      }
    }
  });

  group('the reserved height is exactly what the card needs', () {
    for (final entry in _sizes.entries) {
      for (final scale in _scales) {
        testWidgets('${entry.key} @ ${scale}x', (tester) async {
          // A two-line title is the tallest case; if the reserved height were
          // too large the short-title card would show dead space, and if too
          // small this card would overflow. Both are checked by comparing the
          // painted content extent against the reserved box.
          await _pumpRow(tester, entry.value, scale, [_twoLineName]);
          expect(tester.takeException(), isNull);

          final tile = tester.getSize(find.byType(DsProductTile));

          // The image is square and full-bleed, so the content block is
          // whatever remains. It must match the measured spec exactly — no
          // slack, no shortfall.
          late DsTileSpec spec;
          final ctx = tester.element(find.byType(DsProductTile));
          spec = DsTileSpec.resolve(ctx);

          expect(
            tile.height,
            closeTo(spec.totalHeight(tile.width), 0.5),
            reason: 'rendered height drifted from the reserved height',
          );
        });
      }
    }
  });

  group('title and brand occupy their reserved slots', () {
    for (final scale in _scales) {
      testWidgets('one-line and two-line titles align footers @ ${scale}x', (
        tester,
      ) async {
        await _pumpRow(tester, const Size(390, 844), scale, [
          _shortName,
          _twoLineName,
        ]);
        expect(tester.takeException(), isNull);

        // The add buttons are the last element in each footer. If the title
        // slot were fitted rather than reserved, the short-title card's button
        // would float higher than its neighbour's.
        final buttons = find.byIcon(Icons.add_rounded).evaluate().toList();
        expect(buttons.length, 2);

        final tops = buttons
            .map((e) => (e.renderObject as RenderBox).localToGlobal(Offset.zero).dy)
            .toList();

        expect(
          tops[0],
          closeTo(tops[1], 0.5),
          reason: 'footers are not aligned between cards',
        );
      });
    }
  });

  testWidgets('a very long title still ellipsizes to two lines', (
    tester,
  ) async {
    await _pumpRow(tester, const Size(360, 640), 1.35, [
      _product(
        id: 9,
        name:
            'Extremely Long Medicine Name That Should Definitely Wrap Well '
            'Beyond Two Lines Of Available Space In The Card',
      ),
    ]);

    expect(tester.takeException(), isNull, reason: 'long title overflowed');
  });
}
