import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/helpers/product_display.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/product_detail.dart';

import '../adaptive.dart';
import '../text_metrics.dart';
import '../tokens.dart';
import '../typography.dart';
import 'bits.dart';
import 'feedback.dart';
import 'media.dart';
import 'motion.dart';
import 'skeleton.dart';

/// Maps a stock level to its V2 colour.
Color stockColor(StockLevel level) => switch (level) {
  StockLevel.out => DsColor.danger,
  StockLevel.low => DsColor.warning,
  StockLevel.inStock => DsColor.success,
  StockLevel.unknown => DsColor.inkSoft,
};

/// ─────────────────────────────────────────────────────────────────────────
/// Resolved geometry for the product tile.
///
/// Every dimension here is **measured**, never estimated. Each text slot is
/// laid out by a [TextPainter] using the exact style and text scaler the tile
/// will render with, so the height a grid reserves and the height the card
/// occupies come from one source of truth and cannot drift apart.
///
/// The slots are *reserved*, not fitted: the title box is always two lines
/// tall even when the name fits on one. That is what makes every card in a row
/// identical without leaving a gap under the short ones — the footer sits at
/// the same offset in all of them.
/// ─────────────────────────────────────────────────────────────────────────
class DsTileSpec {
  /// Two reserved lines of product name.
  final double titleBlock;

  /// One reserved line of brand.
  final double brandLine;

  /// Price + stock + action row.
  final double footer;

  const DsTileSpec({
    required this.titleBlock,
    required this.brandLine,
    required this.footer,
  });

  // Layout constants the tile and the spec must agree on.
  static const double pad = DsSpace.x3;
  static const double gapTitleBrand = DsSpace.x1;
  static const double gapBrandFooter = DsSpace.x3;
  static const double actionSize = 40;

  static TextStyle _titleStyle(BuildContext context) =>
      Theme.of(context).textTheme.titleSmall!.copyWith(height: 1.3);

  static TextStyle _brandStyle(BuildContext context) =>
      Theme.of(context).textTheme.bodySmall!.copyWith(fontSize: 11);

  static TextStyle _stockStyle(BuildContext context) =>
      Theme.of(context).textTheme.labelSmall!.copyWith(fontSize: 10);

  static TextStyle _priceStyle(BuildContext context) => DsType.numeral(17);

  /// Measures the tile's slots at the current text scale.
  factory DsTileSpec.resolve(BuildContext context) {
    final title = DsText.linesHeight(context, _titleStyle(context), 2);
    final brand = DsText.lineHeight(context, _brandStyle(context));
    final price = DsText.lineHeight(context, _priceStyle(context));
    final stock = DsText.lineHeight(context, _stockStyle(context));

    // The footer is whichever is taller: the two stacked text lines, or the
    // action button. Taking the max means large text scales grow the row
    // instead of squeezing the button.
    final textColumn = price + DsSpace.x1 + stock;
    final footer = math.max(textColumn, actionSize);

    return DsTileSpec(titleBlock: title, brandLine: brand, footer: footer);
  }

  /// Height of everything below the square image.
  double get contentHeight =>
      pad * 2 +
      titleBlock +
      gapTitleBrand +
      brandLine +
      gapBrandFooter +
      footer;

  /// Total tile height for a card of [width]. The media plate is square, so
  /// its height is the card width.
  double totalHeight(double width) => width + contentHeight;
}

/// ─────────────────────────────────────────────────────────────────────────
/// The V2 product tile.
///
///   * **Full-bleed square artwork**, `BoxFit.cover`, clipped by the card's own
///     radius so the top corners round and nothing bleeds square.
///   * **Reserved text slots** — two lines for the name, one for the brand —
///     so a grid row never staggers and no card carries dead space.
///   * **Footer pinned to the bottom**: price and stock on the left, a morphing
///     add button on the right.
/// ─────────────────────────────────────────────────────────────────────────
class DsProductTile extends StatefulWidget {
  final Map<String, dynamic> product;
  final VoidCallback? onTap;

  /// Hero tag prefix, so one product can appear in several rails on one screen
  /// without colliding hero tags.
  final String heroPrefix;

  /// Pre-resolved geometry. Grids and rails measure once and pass it down so
  /// the work is not repeated for every visible tile.
  final DsTileSpec? spec;

  const DsProductTile({
    super.key,
    required this.product,
    this.onTap,
    this.heroPrefix = 'tile',
    this.spec,
  });

  @override
  State<DsProductTile> createState() => _DsProductTileState();
}

class _DsProductTileState extends State<DsProductTile> {
  bool _justAdded = false;

  void _add(ProductDisplay p) {
    final capped = context.read<CartProvider>().addItem(widget.product);

    // Stock is enforced in the provider; the tile only reports the outcome.
    if (capped) {
      DsToast.stockLimit(
        context,
        available: p.stockQty ?? 0,
        productName: p.name,
      );
      return;
    }

    setState(() => _justAdded = true);
    Future.delayed(const Duration(milliseconds: 1400), () {
      if (mounted) setState(() => _justAdded = false);
    });

    DsToast.success(
      context,
      'Added to cart',
      detail: p.name,
      action: SnackBarAction(
        label: 'View',
        textColor: DsColor.primaryBright,
        onPressed: () => _openDetail(p),
      ),
    );
  }

  void _openDetail(ProductDisplay p) {
    if (p.slug.isEmpty) return;
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => ProductDetailScreen(productSlug: p.slug),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final p = ProductDisplay(widget.product);
    final spec = widget.spec ?? DsTileSpec.resolve(context);
    final discount = p.discountPercent;

    return DsPressable(
      onTap: widget.onTap ?? () => _openDetail(p),
      semanticLabel: '${p.name}, ${p.brand}, ${taka(p.price)}',
      depth: 0.025,
      child: Container(
        decoration: BoxDecoration(
          color: DsColor.surface,
          borderRadius: DsRadius.rMd,
          boxShadow: DsShadow.low,
        ),
        // The card clips, which is what rounds the top of the full-bleed image
        // and stops any square corner escaping the surface.
        clipBehavior: Clip.antiAlias,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            AspectRatio(
              aspectRatio: 1,
              child: Stack(
                fit: StackFit.expand,
                children: [
                  Hero(
                    tag: '${widget.heroPrefix}-${widget.product['id']}',
                    child: Opacity(
                      opacity: p.isBuyable ? 1 : 0.4,
                      child: DsImage(
                        url: p.imageUrl,
                        fit: BoxFit.cover,
                        backdrop: DsColor.sunken,
                      ),
                    ),
                  ),
                  if (discount != null)
                    Positioned(
                      top: 0,
                      left: 0,
                      child: DsRibbon(label: '-$discount%'),
                    ),
                  if (!p.isBuyable)
                    const Center(
                      child: DsTag(
                        label: 'Out of stock',
                        color: DsColor.danger,
                        solid: true,
                      ),
                    ),
                ],
              ),
            ),
            _content(context, p, spec),
          ],
        ),
      ),
    );
  }

  Widget _content(BuildContext context, ProductDisplay p, DsTileSpec spec) {
    final label = p.stockLabel;

    return Padding(
      padding: const EdgeInsets.all(DsTileSpec.pad),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisSize: MainAxisSize.min,
        children: [
          // Reserved two-line box: short names sit at the top of it rather
          // than pulling the footer up.
          SizedBox(
            height: spec.titleBlock,
            child: Align(
              alignment: Alignment.topLeft,
              child: Text(
                p.name,
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
                style: DsTileSpec._titleStyle(context),
              ),
            ),
          ),
          const SizedBox(height: DsTileSpec.gapTitleBrand),
          SizedBox(
            height: spec.brandLine,
            child: Text(
              p.brand,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: DsTileSpec._brandStyle(context),
            ),
          ),
          const SizedBox(height: DsTileSpec.gapBrandFooter),
          SizedBox(
            height: spec.footer,
            child: Row(
              crossAxisAlignment: CrossAxisAlignment.center,
              children: [
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    mainAxisAlignment: MainAxisAlignment.center,
                    mainAxisSize: MainAxisSize.min,
                    children: [
                      Text(
                        taka(p.price),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: DsTileSpec._priceStyle(context),
                      ),
                      const SizedBox(height: DsSpace.x1),
                      // The stock line is always rendered, even when stock is
                      // unknown, so the footer height never varies card to card.
                      _stockLine(context, p, label),
                    ],
                  ),
                ),
                const SizedBox(width: DsSpace.x2),
                _addButton(p),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _stockLine(BuildContext context, ProductDisplay p, String? label) {
    final style = DsTileSpec._stockStyle(context);

    if (label == null) {
      // Occupies the line without drawing anything, keeping alignment.
      return Text('', style: style, maxLines: 1);
    }

    final color = stockColor(p.stockLevel);

    return Row(
      mainAxisSize: MainAxisSize.min,
      children: [
        Container(
          width: 5,
          height: 5,
          decoration: BoxDecoration(color: color, shape: BoxShape.circle),
        ),
        const SizedBox(width: DsSpace.x1 + 1),
        Flexible(
          child: Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: style.copyWith(color: color, letterSpacing: 0.2),
          ),
        ),
      ],
    );
  }

  /// Morphing add button: disc → tick on success.
  Widget _addButton(ProductDisplay p) {
    final enabled = p.isBuyable;

    return DsPressable(
      onTap: enabled ? () => _add(p) : null,
      depth: 0.12,
      semanticLabel: enabled ? 'Add ${p.name} to cart' : 'Out of stock',
      child: AnimatedContainer(
        duration: DsMotion.quick,
        curve: DsMotion.ease,
        width: DsTileSpec.actionSize,
        height: DsTileSpec.actionSize,
        decoration: BoxDecoration(
          color: !enabled
              ? DsColor.sunken
              : _justAdded
              ? DsColor.success
              : DsColor.primary,
          borderRadius: DsRadius.pill,
          boxShadow: enabled && !_justAdded
              ? DsShadow.glow(DsColor.primary)
              : null,
        ),
        child: AnimatedSwitcher(
          duration: DsMotion.quick,
          switchInCurve: DsMotion.enter,
          transitionBuilder: (child, a) =>
              ScaleTransition(scale: a, child: child),
          child: Icon(
            _justAdded ? Icons.check_rounded : Icons.add_rounded,
            key: ValueKey(_justAdded),
            size: 20,
            color: enabled ? Colors.white : DsColor.inkFaint,
          ),
        ),
      ),
    );
  }
}

/// Skeleton matching [DsProductTile]'s footprint exactly, so nothing shifts
/// when real content arrives.
class DsProductTileSkeleton extends StatelessWidget {
  final DsTileSpec? spec;

  const DsProductTileSkeleton({super.key, this.spec});

  @override
  Widget build(BuildContext context) {
    final s = spec ?? DsTileSpec.resolve(context);

    return Container(
      decoration: BoxDecoration(
        color: DsColor.surface,
        borderRadius: DsRadius.rMd,
        boxShadow: DsShadow.low,
      ),
      clipBehavior: Clip.antiAlias,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          const AspectRatio(
            aspectRatio: 1,
            child: DsBone(radius: BorderRadius.zero),
          ),
          Padding(
            padding: const EdgeInsets.all(DsTileSpec.pad),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                SizedBox(
                  height: s.titleBlock,
                  child: const Align(
                    alignment: Alignment.topLeft,
                    child: DsBone.text(width: double.infinity),
                  ),
                ),
                const SizedBox(height: DsTileSpec.gapTitleBrand),
                SizedBox(
                  height: s.brandLine,
                  child: const Align(
                    alignment: Alignment.centerLeft,
                    child: DsBone.text(width: 70, height: 9),
                  ),
                ),
                const SizedBox(height: DsTileSpec.gapBrandFooter),
                SizedBox(
                  height: s.footer,
                  child: const Row(
                    children: [
                      Expanded(
                        child: Align(
                          alignment: Alignment.centerLeft,
                          child: DsBone.text(width: 56, height: 15),
                        ),
                      ),
                      DsBone(
                        width: DsTileSpec.actionSize,
                        height: DsTileSpec.actionSize,
                        radius: DsRadius.pill,
                      ),
                    ],
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Horizontal rail of product tiles with a matching skeleton state.
///
/// Measures the tile geometry once and hands it to every child, so a rail of
/// twenty products performs one text measurement rather than twenty.
class DsProductRail extends StatelessWidget {
  final List<dynamic> products;
  final bool isLoading;
  final String heroPrefix;
  final double tileWidth;

  const DsProductRail({
    super.key,
    required this.products,
    required this.tileWidth,
    this.heroPrefix = 'rail',
    this.isLoading = false,
  });

  @override
  Widget build(BuildContext context) {
    final spec = DsTileSpec.resolve(context);
    final loading = isLoading;

    return SizedBox(
      height: spec.totalHeight(tileWidth),
      child: DsSkeleton(
        enabled: loading,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          physics: loading
              ? const NeverScrollableScrollPhysics()
              : const BouncingScrollPhysics(),
          padding: context.pagePad,
          itemCount: loading ? 4 : products.length,
          separatorBuilder: (_, _) => const SizedBox(width: DsSpace.x3),
          itemBuilder: (context, i) => SizedBox(
            width: tileWidth,
            child: loading
                ? DsProductTileSkeleton(spec: spec)
                : DsProductTile(
                    product: products[i] as Map<String, dynamic>,
                    heroPrefix: heroPrefix,
                    spec: spec,
                  ),
          ),
        ),
      ),
    );
  }
}
