import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/helpers/product_display.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/product_detail.dart';

import '../adaptive.dart';
import '../tokens.dart';
import 'bits.dart';
import 'feedback.dart';
import 'media.dart';
import 'motion.dart';
import 'product_tile.dart' show stockColor;
import 'skeleton.dart';

/// Horizontal product row for dense listings — category results, search
/// results, related products.
///
/// V2 gives the row the same inset media plate as the grid tile, so the two
/// presentations read as the same product in two densities rather than as two
/// unrelated components. Price and action sit on their own baseline row that
/// wraps rather than truncating when text is scaled up.
class DsProductRow extends StatefulWidget {
  final Map<String, dynamic> product;
  final VoidCallback? onTap;
  final String heroPrefix;

  const DsProductRow({
    super.key,
    required this.product,
    this.onTap,
    this.heroPrefix = 'row',
  });

  @override
  State<DsProductRow> createState() => _DsProductRowState();
}

class _DsProductRowState extends State<DsProductRow> {
  bool _justAdded = false;

  void _add(ProductDisplay p) {
    final capped = context.read<CartProvider>().addItem(widget.product);

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
    DsToast.success(context, 'Added to cart', detail: p.name);
  }

  void _openDetail(ProductDisplay p) {
    if (p.slug.isEmpty) return;
    Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => ProductDetailScreen(productSlug: p.slug)),
    );
  }

  @override
  Widget build(BuildContext context) {
    final p = ProductDisplay(widget.product);
    final text = Theme.of(context).textTheme;
    final discount = p.discountPercent;
    final plate = context.pick<double>(base: 92, compact: 80, expanded: 108);
    final label = p.stockLabel;

    return DsPressable(
      onTap: widget.onTap ?? () => _openDetail(p),
      depth: 0.015,
      semanticLabel: '${p.name}, ${p.brand}, ${taka(p.price)}',
      child: Container(
        padding: const EdgeInsets.all(DsSpace.x3),
        decoration: BoxDecoration(
          color: DsColor.surface,
          borderRadius: DsRadius.rMd,
          boxShadow: DsShadow.low,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            SizedBox(
              width: plate,
              height: plate,
              child: Hero(
                tag: '${widget.heroPrefix}-${widget.product['id']}',
                child: DsPlate(
                  url: p.imageUrl,
                  inset: DsSpace.x2 + 2,
                  radius: DsRadius.rSm,
                  dimmed: !p.isBuyable,
                  overlays: [
                    if (discount != null)
                      Positioned(
                        top: 0,
                        left: 0,
                        child: DsRibbon(label: '-$discount%'),
                      ),
                  ],
                ),
              ),
            ),
            const SizedBox(width: DsSpace.x3),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    p.name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: text.titleMedium,
                  ),
                  const SizedBox(height: 1),
                  Text(
                    p.brand,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: text.bodySmall?.copyWith(fontSize: 11.5),
                  ),
                  if (label != null) ...[
                    const SizedBox(height: DsSpace.x2),
                    DsTag(
                      label: label,
                      color: stockColor(p.stockLevel),
                      icon: p.isBuyable
                          ? Icons.check_circle_outline_rounded
                          : Icons.remove_circle_outline_rounded,
                    ),
                  ],
                  const SizedBox(height: DsSpace.x3),
                  // Wrap, so at large text scales the action drops below the
                  // price instead of squeezing it.
                  Wrap(
                    alignment: WrapAlignment.spaceBetween,
                    crossAxisAlignment: WrapCrossAlignment.center,
                    spacing: DsSpace.x3,
                    runSpacing: DsSpace.x2,
                    children: [
                      DsPrice(amount: p.price, was: p.wasPrice, size: 18),
                      _addPill(p),
                    ],
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _addPill(ProductDisplay p) {
    final enabled = p.isBuyable;

    return DsPressable(
      onTap: enabled ? () => _add(p) : null,
      depth: 0.06,
      semanticLabel: enabled ? 'Add ${p.name} to cart' : 'Out of stock',
      child: AnimatedContainer(
        duration: DsMotion.quick,
        curve: DsMotion.ease,
        height: 38,
        padding: const EdgeInsets.symmetric(horizontal: DsSpace.x4),
        decoration: BoxDecoration(
          color: !enabled
              ? DsColor.sunken
              : _justAdded
              ? DsColor.success
              : DsColor.primarySoft,
          borderRadius: DsRadius.pill,
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            AnimatedSwitcher(
              duration: DsMotion.quick,
              transitionBuilder: (c, a) => ScaleTransition(scale: a, child: c),
              child: Icon(
                _justAdded
                    ? Icons.check_rounded
                    : Icons.add_shopping_cart_rounded,
                key: ValueKey(_justAdded),
                size: 15,
                color: !enabled
                    ? DsColor.inkFaint
                    : _justAdded
                    ? Colors.white
                    : DsColor.primary,
              ),
            ),
            const SizedBox(width: DsSpace.x2),
            Text(
              _justAdded ? 'Added' : 'Add',
              style: Theme.of(context).textTheme.labelMedium?.copyWith(
                fontWeight: FontWeight.w800,
                color: !enabled
                    ? DsColor.inkFaint
                    : _justAdded
                    ? Colors.white
                    : DsColor.primary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// Skeleton list matching [DsProductRow]'s footprint.
class DsProductRowSkeleton extends StatelessWidget {
  final int count;

  const DsProductRowSkeleton({super.key, this.count = 6});

  @override
  Widget build(BuildContext context) {
    final plate = context.pick<double>(base: 92, compact: 80, expanded: 108);

    return DsSkeleton(
      child: ListView.separated(
        padding: EdgeInsets.fromLTRB(
          context.gutter,
          DsSpace.x4,
          context.gutter,
          DsSpace.x4,
        ),
        physics: const NeverScrollableScrollPhysics(),
        itemCount: count,
        separatorBuilder: (_, _) => const SizedBox(height: DsSpace.x3),
        itemBuilder: (_, _) => Container(
          padding: const EdgeInsets.all(DsSpace.x3),
          decoration: BoxDecoration(
            color: DsColor.surface,
            borderRadius: DsRadius.rMd,
            boxShadow: DsShadow.low,
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              DsBone(width: plate, height: plate, radius: DsRadius.rSm),
              const SizedBox(width: DsSpace.x3),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    DsBone.text(width: double.infinity),
                    SizedBox(height: DsSpace.x2),
                    DsBone.text(width: 110, height: 9),
                    SizedBox(height: DsSpace.x5),
                    DsBone.text(width: 74, height: 15),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
