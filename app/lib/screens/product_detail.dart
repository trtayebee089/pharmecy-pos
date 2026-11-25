import 'dart:convert';
import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'dart:math' as math;

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/media.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/components/stepper.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/repositories/recently_viewed_repository.dart';
import 'package:redpharmabd_app/screens/checkout.dart';
import 'package:redpharmabd_app/screens/image_viewer.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Product detail, rebuilt.
///
/// New composition:
///  * A **collapsing gallery** on the canvas colour with floating circular
///    controls, rather than a boxed image under an app bar. Tap to open the
///    zoom viewer; the hero flies between the two.
///  * **Title block first, price second** — name, brand and category before
///    the number, which is the order a shopper actually reads a medicine.
///  * **Package selector as pills** that state what you get, not just a price.
///  * **Expandable description** so a long leaflet does not bury the specs.
///  * **Sticky action bar** carrying the live line total.
///
/// Every fetch, the package/quantity maths, the stock ceiling and the exact
/// cart payload are the existing ones.
/// ─────────────────────────────────────────────────────────────────────────
class ProductDetailScreen extends StatefulWidget {
  final String productSlug;

  const ProductDetailScreen({super.key, required this.productSlug});

  @override
  State<ProductDetailScreen> createState() => _ProductDetailScreenState();
}

class _ProductDetailScreenState extends State<ProductDetailScreen> {
  int quantity = 1;
  int soldQty = 0;
  int? stockQty; // Available stock from API
  Map<String, dynamic>? product;
  List<dynamic> relatedProducts = [];
  bool isLoading = true;
  bool isLoadingRelated = true;
  String? error;
  late TextEditingController _quantityController;
  String selectedPackage = '';
  bool _descriptionExpanded = false;

  @override
  void initState() {
    super.initState();
    _quantityController = TextEditingController(text: quantity.toString());
    fetchProduct();
  }

  @override
  void dispose() {
    _quantityController.dispose();
    super.dispose();
  }

  // ── Data (unchanged) ─────────────────────────────────────────────────────

  Future<void> fetchProduct() async {
    final apiUrl = '${ApiEndpoints.productDetails}/${widget.productSlug}';

    try {
      final response = await http
          .get(Uri.parse(apiUrl))
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true && data['data'] != null) {
          setState(() {
            product = data['data'];
            soldQty = data['saleCount'] ?? 0;
            // Read available stock from API (field: qty)
            stockQty = product!['qty'] != null
                ? (product!['qty'] as num).toInt()
                : null;
            isLoading = false;

            int pPiece =
                (double.tryParse(product!['piece_qty']?.toString() ?? '0') ?? 0)
                    .toInt();
            int pStripe =
                (double.tryParse(product!['stripe_qty']?.toString() ?? '0') ??
                        0)
                    .toInt();
            int pBox =
                (double.tryParse(product!['box_qty']?.toString() ?? '0') ?? 0)
                    .toInt();

            if (pPiece > 0) {
              selectedPackage = 'Piece';
              quantity = 1;
            } else if (pStripe > 0) {
              selectedPackage = 'Stripe';
              quantity = pPiece > 0 ? pPiece : 1;
            } else if (pBox > 0) {
              selectedPackage = 'Box';
              quantity =
                  (pStripe > 0 ? pStripe : 1) * (pPiece > 0 ? pPiece : 1);
            }
            _quantityController.text = quantity.toString();
          });
          await RecentlyViewedRepository().record(product!);
          fetchRelatedProducts();
        } else {
          setState(() {
            error = 'Product not found';
            isLoading = false;
          });
        }
      } else {
        setState(() {
          error = 'Failed to load product';
          isLoading = false;
        });
      }
    } catch (e) {
      setState(() {
        error = 'Error: $e';
        isLoading = false;
      });
    }
  }

  Future<void> fetchRelatedProducts() async {
    if (product == null) return;
    final categorySlug = product!['category']?['slug'] ?? '';
    if (categorySlug.isEmpty) {
      setState(() => isLoadingRelated = false);
      return;
    }
    final apiUrl = '${ApiEndpoints.categories}/$categorySlug';
    try {
      final response = await http
          .get(Uri.parse(apiUrl))
          .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        setState(() {
          relatedProducts = data['products']?['data'] ?? [];
          relatedProducts.removeWhere((p) => p['id'] == product!['id']);
          isLoadingRelated = false;
        });
      } else {
        setState(() => isLoadingRelated = false);
      }
    } catch (e) {
      setState(() => isLoadingRelated = false);
    }
  }

  void _retry() {
    setState(() {
      isLoading = true;
      error = null;
    });
    fetchProduct();
  }

  /// Cart payload — same keys, same values as before.
  Map<String, dynamic> _cartPayload({
    required String name,
    required double price,
    required String productImage,
    required String unit,
  }) => {
    'id': product!['id'],
    'name': name,
    'price': price,
    'image': productImage,
    'unit': unit,
    'quantity': quantity,
    'stock_qty': stockQty,
  };

  /// Single quantity entry point, so "+", "−" and typing share one ceiling.
  void _setQuantity(int requested, {bool announce = true}) {
    if (stockQty != null && requested > stockQty!) {
      setState(() {
        quantity = stockQty!;
        _quantityController.text = quantity.toString();
      });
      if (announce) {
        DsToast.stockLimit(context, available: stockQty!);
      }
      return;
    }
    setState(() {
      quantity = requested < 1 ? 1 : requested;
      _quantityController.text = quantity.toString();
    });
  }

  // ── Composition ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final cart = Provider.of<CartProvider>(context, listen: false);

    if (isLoading) return _loadingScaffold();

    if (product == null || error != null) {
      return Scaffold(
        backgroundColor: DsColor.canvas,
        appBar: AppBar(leading: const _BackDisc()),
        body: DsState.error(
          message: error ?? 'Product not found',
          onAction: _retry,
        ),
      );
    }

    final String productImage = product!['image'] ?? '';
    final String categoryImage = product!['category']?['image'] ?? '';
    final String categoryName = product!['category']?['name'] ?? '';
    final String brandName = product!['brand'] is Map
        ? (product!['brand']['title'] ?? '').toString()
        : (product!['brand']?.toString() ?? '');
    final String name = product!['name'] ?? '';
    final String description = product!['description'] ?? '';
    final double price = product!['price']?.toDouble() ?? 0.0;
    final String unit = product!['unit'] ?? 'pcs';

    final String heroImage = productImage.isNotEmpty
        ? productImage
        : categoryImage;

    final int boxQtyVal =
        (double.tryParse(product!['box_qty']?.toString() ?? '0') ?? 0).toInt();
    final int stripeQtyVal =
        (double.tryParse(product!['stripe_qty']?.toString() ?? '0') ?? 0)
            .toInt();
    final int pieceQtyVal =
        (double.tryParse(product!['piece_qty']?.toString() ?? '0') ?? 0)
            .toInt();

    final int piecesInBox =
        (stripeQtyVal > 0 ? stripeQtyVal : 1) *
        (pieceQtyVal > 0 ? pieceQtyVal : 1);
    final int piecesInStripe = (pieceQtyVal > 0 ? pieceQtyVal : 1);

    final double totalPrice = price * quantity;

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: DsPage(
        child: CustomScrollView(
          physics: const BouncingScrollPhysics(),
          slivers: [
            _gallery(heroImage, name),
            SliverToBoxAdapter(
              child: Padding(
                padding: EdgeInsets.fromLTRB(
                  context.gutter,
                  DsSpace.x5,
                  context.gutter,
                  0,
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    _titleBlock(categoryName, brandName, name),
                    const SizedBox(height: DsSpace.x4),
                    _priceBlock(price, unit),
                    const SizedBox(height: DsSpace.x6),
                    if (boxQtyVal > 0 ||
                        stripeQtyVal > 0 ||
                        pieceQtyVal > 0) ...[
                      _packageSelector(
                        price: price,
                        boxQtyVal: boxQtyVal,
                        stripeQtyVal: stripeQtyVal,
                        pieceQtyVal: pieceQtyVal,
                        piecesInBox: piecesInBox,
                        piecesInStripe: piecesInStripe,
                      ),
                      const SizedBox(height: DsSpace.x5),
                    ],
                    _quantityBlock(),
                  ],
                ),
              ),
            ),

            if (description.isNotEmpty)
              SliverToBoxAdapter(child: _description(description)),

            SliverToBoxAdapter(
              child: _specs(
                brand: brandName,
                category: categoryName,
                unit: unit,
                stripeQty: stripeQtyVal,
                pieceQty: pieceQtyVal,
              ),
            ),

            ..._related(),

            SliverToBoxAdapter(child: const SizedBox(height: DsSpace.x10)),
          ],
        ),
      ),
      bottomNavigationBar: _actionBar(
        cart: cart,
        name: name,
        price: price,
        productImage: productImage,
        unit: unit,
        totalPrice: totalPrice,
      ),
    );
  }

  Widget _loadingScaffold() {
    return Scaffold(
      backgroundColor: DsColor.canvas,
      appBar: AppBar(leading: const _BackDisc()),
      body: const DsProductRowSkeleton(count: 4),
    );
  }

  /// Collapsing gallery.
  ///
  /// Height is a fraction of the viewport rather than a fixed box, so it is
  /// generous on a large phone and does not swallow a short one.
  Widget _gallery(String imageUrl, String name) {
    final expanded = math.min(
      context.vpWidth * (context.isTablet ? 0.5 : 0.86),
      context.vpHeight * 0.42,
    );
    final heroTag = 'detail-${product!['id']}';

    return SliverAppBar(
      expandedHeight: expanded,
      pinned: true,
      stretch: true,
      backgroundColor: DsColor.canvas,
      surfaceTintColor: Colors.transparent,
      elevation: 0,
      scrolledUnderElevation: 0,
      leading: const _BackDisc(),
      // The title only appears once the artwork has scrolled away, so it never
      // competes with the product image.
      title: Text(name, maxLines: 1, overflow: TextOverflow.ellipsis),
      flexibleSpace: FlexibleSpaceBar(
        collapseMode: CollapseMode.parallax,
        stretchModes: const [StretchMode.zoomBackground],
        background: DsPressable(
          onTap: () => Navigator.push(
            context,
            MaterialPageRoute(
              builder: (_) =>
                  ImageViewerScreen(imageUrl: imageUrl, heroTag: heroTag),
            ),
          ),
          depth: 0.01,
          semanticLabel: 'View $name full screen',
          child: Padding(
            padding: EdgeInsets.fromLTRB(
              context.gutter,
              kToolbarHeight * 0.6,
              context.gutter,
              0,
            ),
            child: Stack(
              children: [
                Hero(
                  tag: heroTag,
                  child: DsPlate(
                    url: imageUrl,
                    fit: BoxFit.contain,
                    inset: DsSpace.x6,
                    radius: DsRadius.rLg,
                    backdrop: DsColor.surface,
                  ),
                ),
                Positioned(
                  right: DsSpace.x4,
                  bottom: DsSpace.x4,
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: DsSpace.x3,
                      vertical: DsSpace.x2,
                    ),
                    decoration: BoxDecoration(
                      color: DsColor.obsidian.withValues(alpha: 0.7),
                      borderRadius: DsRadius.pill,
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(
                          Icons.zoom_in_rounded,
                          size: 14,
                          color: Colors.white,
                        ),
                        const SizedBox(width: DsSpace.x1),
                        Text(
                          'Tap to zoom',
                          style: DsType.eyebrow(color: Colors.white),
                        ),
                      ],
                    ),
                  ),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _titleBlock(String category, String brand, String name) {
    final text = Theme.of(context).textTheme;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (category.isNotEmpty || brand.isNotEmpty)
          Wrap(
            spacing: DsSpace.x2,
            runSpacing: DsSpace.x2,
            children: [
              if (category.isNotEmpty)
                DsTag(label: category, color: DsColor.primary),
              if (brand.isNotEmpty)
                DsTag(
                  label: brand,
                  color: DsColor.info,
                  icon: Icons.verified_outlined,
                ),
            ],
          ),
        const SizedBox(height: DsSpace.x3),
        Text(name, style: text.headlineLarge),
      ],
    );
  }

  Widget _priceBlock(double price, String unit) {
    final text = Theme.of(context).textTheme;
    final inStock = stockQty == null || stockQty! > 0;
    final low = stockQty != null && stockQty! > 0 && stockQty! <= 5;

    return Wrap(
      crossAxisAlignment: WrapCrossAlignment.center,
      spacing: DsSpace.x3,
      runSpacing: DsSpace.x2,
      children: [
        Row(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.baseline,
          textBaseline: TextBaseline.alphabetic,
          children: [
            Text(
              taka(price),
              style: DsType.numeral(30, color: DsColor.primary),
            ),
            const SizedBox(width: DsSpace.x1),
            Text('/ $unit', style: text.bodyMedium),
          ],
        ),
        DsTag(
          label: !inStock
              ? 'Out of stock'
              : low
              ? 'Only $stockQty left'
              : 'In stock',
          color: !inStock
              ? DsColor.danger
              : low
              ? DsColor.warning
              : DsColor.success,
          icon: inStock
              ? Icons.check_circle_outline_rounded
              : Icons.remove_circle_outline_rounded,
        ),
        if (soldQty > 0)
          DsTag(
            label: '$soldQty sold',
            color: DsColor.inkSoft,
            icon: Icons.local_fire_department_outlined,
          ),
      ],
    );
  }

  Widget _packageSelector({
    required double price,
    required int boxQtyVal,
    required int stripeQtyVal,
    required int pieceQtyVal,
    required int piecesInBox,
    required int piecesInStripe,
  }) {
    final options = <({String title, double price, String sub, int mult})>[
      if (boxQtyVal > 0)
        (
          title: 'Box',
          price: piecesInBox * price,
          sub: '$piecesInBox pcs',
          mult: piecesInBox,
        ),
      if (stripeQtyVal > 0)
        (
          title: 'Stripe',
          price: piecesInStripe * price,
          sub: '$piecesInStripe pcs',
          mult: piecesInStripe,
        ),
      if (pieceQtyVal > 0) (title: 'Piece', price: price, sub: '1 pc', mult: 1),
    ];

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text('Pack size', style: Theme.of(context).textTheme.titleLarge),
        const SizedBox(height: DsSpace.x3),
        // Wrap rather than a fixed row: three options never overflow a narrow
        // phone, they simply run onto a second line.
        Wrap(
          spacing: DsSpace.x2,
          runSpacing: DsSpace.x2,
          children: [
            for (final o in options)
              _PackPill(
                title: o.title,
                price: o.price,
                subtitle: o.sub,
                selected: selectedPackage == o.title,
                onTap: () {
                  setState(() => selectedPackage = o.title);
                  _setQuantity(o.mult);
                },
              ),
          ],
        ),
      ],
    );
  }

  Widget _quantityBlock() {
    final atMax = stockQty != null && quantity >= stockQty!;
    final text = Theme.of(context).textTheme;

    return DsSurface(
      padding: const EdgeInsets.all(DsSpace.x4),
      color: DsColor.surface,
      child: Row(
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('Quantity', style: text.titleMedium),
                if (stockQty != null)
                  Text('$stockQty available', style: text.bodySmall),
              ],
            ),
          ),
          const SizedBox(width: DsSpace.x3),
          DsStepper(
            value: quantity,
            controller: _quantityController,
            atMax: atMax,
            semanticLabel: 'Quantity',
            onDecrease: () => _setQuantity(quantity - 1),
            onIncrease: () => _setQuantity(quantity + 1),
            onBlocked: () =>
                DsToast.stockLimit(context, available: stockQty ?? quantity),
            onChanged: (v) {
              final parsed = int.tryParse(v);
              if (parsed != null && parsed > 0) _setQuantity(parsed);
            },
            onEditingComplete: () {
              final parsed = int.tryParse(_quantityController.text);
              _setQuantity(
                parsed == null || parsed < 1 ? 1 : parsed,
                announce: false,
              );
              FocusScope.of(context).unfocus();
            },
            onTapOutside: (_) {
              final parsed = int.tryParse(_quantityController.text);
              _setQuantity(
                parsed == null || parsed < 1 ? 1 : parsed,
                announce: false,
              );
              FocusManager.instance.primaryFocus?.unfocus();
            },
          ),
        ],
      ),
    );
  }

  /// Description that expands in place.
  ///
  /// Medicine leaflets are long. Collapsing to six lines keeps the specs and
  /// related products reachable without a scroll marathon, while the full text
  /// is one tap away.
  Widget _description(String description) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x5,
        context.gutter,
        0,
      ),
      child: DsSurface(
        padding: const EdgeInsets.all(DsSpace.x5),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('About this product', style: text.titleLarge),
            const SizedBox(height: DsSpace.x3),
            AnimatedSize(
              duration: DsMotion.base,
              curve: DsMotion.ease,
              alignment: Alignment.topCenter,
              child: Text(
                description,
                maxLines: _descriptionExpanded ? null : 6,
                overflow: _descriptionExpanded
                    ? TextOverflow.visible
                    : TextOverflow.ellipsis,
                style: text.bodyLarge,
              ),
            ),
            const SizedBox(height: DsSpace.x2),
            TextButton(
              onPressed: () =>
                  setState(() => _descriptionExpanded = !_descriptionExpanded),
              child: Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(_descriptionExpanded ? 'Show less' : 'Read more'),
                  Icon(
                    _descriptionExpanded
                        ? Icons.keyboard_arrow_up_rounded
                        : Icons.keyboard_arrow_down_rounded,
                    size: 16,
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _specs({
    required String brand,
    required String category,
    required String unit,
    required int stripeQty,
    required int pieceQty,
  }) {
    final text = Theme.of(context).textTheme;
    final rows = <({String k, String v})>[
      if (brand.isNotEmpty) (k: 'Brand', v: brand),
      if (category.isNotEmpty) (k: 'Category', v: category),
      (k: 'Unit', v: unit),
      if (pieceQty > 0) (k: 'Pieces per stripe', v: '$pieceQty'),
      if (stripeQty > 0) (k: 'Stripes per box', v: '$stripeQty'),
      if (stockQty != null) (k: 'Availability', v: '$stockQty in stock'),
    ];

    if (rows.isEmpty) return const SizedBox.shrink();

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x4,
        context.gutter,
        0,
      ),
      child: DsSurface(
        padding: const EdgeInsets.all(DsSpace.x5),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Specifications', style: text.titleLarge),
            const SizedBox(height: DsSpace.x2),
            for (var i = 0; i < rows.length; i++) ...[
              if (i > 0) const Divider(height: DsSpace.x6),
              Row(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  SizedBox(
                    width: 132,
                    child: Text(rows[i].k, style: text.bodyMedium),
                  ),
                  const SizedBox(width: DsSpace.x3),
                  Expanded(child: Text(rows[i].v, style: text.titleSmall)),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  List<Widget> _related() {
    if (isLoadingRelated) {
      return const [
        SliverToBoxAdapter(child: DsSection(title: 'You might also need')),
        SliverToBoxAdapter(child: DsProductRowSkeleton(count: 3)),
      ];
    }
    if (relatedProducts.isEmpty) return const [];

    return [
      const SliverToBoxAdapter(
        child: DsSection(
          eyebrow: 'Same category',
          title: 'You might also need',
        ),
      ),
      SliverPadding(
        padding: EdgeInsets.symmetric(horizontal: context.gutter),
        sliver: SliverList.separated(
          itemCount: relatedProducts.length,
          separatorBuilder: (_, _) => const SizedBox(height: DsSpace.x3),
          itemBuilder: (context, i) => DsProductRow(
            product: relatedProducts[i] as Map<String, dynamic>,
            heroPrefix: 'related',
            onTap: () => Navigator.pushReplacement(
              context,
              MaterialPageRoute(
                builder: (_) => ProductDetailScreen(
                  productSlug: relatedProducts[i]['slug'],
                ),
              ),
            ),
          ),
        ),
      ),
    ];
  }

  Widget _actionBar({
    required CartProvider cart,
    required String name,
    required double price,
    required String productImage,
    required String unit,
    required double totalPrice,
  }) {
    final outOfStock = stockQty != null && stockQty! <= 0;

    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          context.gutter,
          0,
          context.gutter,
          DsSpace.x3,
        ),
        child: Container(
          padding: const EdgeInsets.all(DsSpace.x3),
          decoration: BoxDecoration(
            color: DsColor.obsidian,
            borderRadius: DsRadius.rLg,
            boxShadow: DsShadow.high,
          ),
          child: Row(
            children: [
              const SizedBox(width: DsSpace.x2),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      'TOTAL',
                      style: DsType.eyebrow(
                        color: DsColor.onDark.withValues(alpha: 0.6),
                      ),
                    ),
                    const SizedBox(height: DsSpace.x1),
                    Text(
                      taka(totalPrice, exact: true),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: DsType.numeral(20, color: DsColor.onDark),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: DsSpace.x3),
              DsIconDisc(
                icon: Icons.add_shopping_cart_rounded,
                tooltip: 'Add to cart',
                size: 52,
                background: DsColor.onDark.withValues(alpha: 0.12),
                foreground: outOfStock ? DsColor.inkFaint : Colors.white,
                onTap: outOfStock
                    ? null
                    : () {
                        final capped = cart.addItem(
                          _cartPayload(
                            name: name,
                            price: price,
                            productImage: productImage,
                            unit: unit,
                          ),
                        );
                        if (capped) {
                          DsToast.stockLimit(
                            context,
                            available: stockQty ?? 0,
                            productName: name,
                          );
                        } else {
                          DsToast.success(
                            context,
                            'Added to cart',
                            detail: name,
                          );
                        }
                      },
              ),
              const SizedBox(width: DsSpace.x2),
              DsPressable(
                onTap: outOfStock
                    ? null
                    : () {
                        cart.addItem(
                          _cartPayload(
                            name: name,
                            price: price,
                            productImage: productImage,
                            unit: unit,
                          ),
                        );
                        Navigator.push(
                          context,
                          MaterialPageRoute(
                            builder: (_) => const CheckoutScreen(),
                          ),
                        );
                      },
                depth: 0.05,
                semanticLabel: outOfStock ? 'Unavailable' : 'Buy now',
                child: Container(
                  height: 52,
                  padding: const EdgeInsets.symmetric(horizontal: DsSpace.x5),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: outOfStock
                        ? DsColor.onDark.withValues(alpha: 0.12)
                        : DsColor.primaryBright,
                    borderRadius: DsRadius.pill,
                  ),
                  child: Text(
                    outOfStock ? 'Unavailable' : 'Buy now',
                    style: Theme.of(context).textTheme.labelLarge?.copyWith(
                      color: outOfStock ? DsColor.inkFaint : Colors.white,
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Back control as a floating disc, legible over artwork or canvas.
class _BackDisc extends StatelessWidget {
  const _BackDisc();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.all(DsSpace.x2),
      child: DsIconDisc(
        icon: Icons.arrow_back_rounded,
        tooltip: 'Back',
        background: DsColor.surface,
        shadow: DsShadow.low,
        onTap: () => Navigator.pop(context),
      ),
    );
  }
}

/// Pack-size choice: name, what you get, and what it costs.
class _PackPill extends StatelessWidget {
  final String title;
  final double price;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  const _PackPill({
    required this.title,
    required this.price,
    required this.subtitle,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return DsPressable(
      onTap: onTap,
      depth: 0.04,
      semanticLabel: '$title, $subtitle, ${taka(price)}',
      child: AnimatedContainer(
        duration: DsMotion.quick,
        curve: DsMotion.ease,
        padding: const EdgeInsets.symmetric(
          horizontal: DsSpace.x4,
          vertical: DsSpace.x3,
        ),
        decoration: BoxDecoration(
          color: selected ? DsColor.primary : DsColor.surface,
          borderRadius: DsRadius.rSm,
          boxShadow: selected ? DsShadow.glow(DsColor.primary) : DsShadow.low,
        ),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              title,
              style: text.titleSmall?.copyWith(
                color: selected ? Colors.white : DsColor.ink,
              ),
            ),
            const SizedBox(height: 1),
            Text(
              subtitle,
              style: text.labelSmall?.copyWith(
                color: selected
                    ? Colors.white.withValues(alpha: 0.75)
                    : DsColor.inkSoft,
                letterSpacing: 0.2,
              ),
            ),
            const SizedBox(height: DsSpace.x2),
            Text(
              taka(price),
              style: DsType.numeral(
                15,
                color: selected ? Colors.white : DsColor.primary,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
