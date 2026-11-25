import 'dart:convert';
import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/helpers/category_image.dart';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/media.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/product_tile.dart';
import 'package:redpharmabd_app/design/components/skeleton.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Category browsing, rebuilt.
///
/// What changed and why:
///
///  * **Exactly two columns on every phone.** Three columns left each tile
///    around 105dp wide, which is why the artwork looked like a stamp marooned
///    in an empty card. Two columns roughly doubles the tile area, so the
///    image finally has somewhere to live.
///  * **Full-bleed artwork with a scrim.** The image now fills the entire card
///    and the label sits on a gradient over it, instead of a small picture
///    floating above text. No empty space is left over by construction.
///  * **Uniform tile height from an aspect ratio**, so rows line up on every
///    device without hand-tuned pixel heights.
///  * Drill-down keeps the same in-place swap and the same slug, so the bottom
///    nav toggle contract is untouched.
/// ─────────────────────────────────────────────────────────────────────────
class CategoryScreen extends StatefulWidget {
  final String? initialSlug;
  final String? initialName;
  final void Function(bool)? onToggleBottomNav;
  final http.Client? client;

  const CategoryScreen({
    super.key,
    this.initialSlug,
    this.initialName,
    this.onToggleBottomNav,
    this.client,
  });

  @override
  State<CategoryScreen> createState() => _CategoryScreenState();
}

class _CategoryScreenState extends State<CategoryScreen> {
  List<dynamic> categories = [];
  bool isLoadingCategories = true;
  String? categoriesError;

  String? selectedCategorySlug;
  String? selectedCategoryName;

  @override
  void initState() {
    super.initState();
    selectedCategorySlug = widget.initialSlug;
    selectedCategoryName = widget.initialName;

    _loadCachedCategories();
    fetchCategories();
  }

  Future<void> _loadCachedCategories() async {
    final prefs = await SharedPreferences.getInstance();
    final cachedData = prefs.getString('cached_categories');

    if (cachedData != null) {
      final decoded = json.decode(cachedData);
      if (!mounted) return;
      setState(() {
        categories = decoded;
        isLoadingCategories = false;
      });
    }
  }

  Future<void> fetchCategories() async {
    const apiUrl = ApiEndpoints.categories;
    try {
      final response =
          await (widget.client?.get(Uri.parse(apiUrl)) ??
                  http.get(Uri.parse(apiUrl)))
              .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        final newCategories = data['data'] ?? [];

        if (!mounted) return;
        setState(() {
          categories = newCategories;
          isLoadingCategories = false;
          categoriesError = null;
        });

        final prefs = await SharedPreferences.getInstance();
        await prefs.setString('cached_categories', json.encode(newCategories));
      } else {
        if (categories.isEmpty && mounted) {
          setState(() {
            categoriesError = 'Failed to load categories';
            isLoadingCategories = false;
          });
        }
      }
    } catch (e) {
      if (categories.isEmpty && mounted) {
        setState(() {
          categoriesError = 'Error: $e';
          isLoadingCategories = false;
        });
      }
    }
  }

  void _open(Map<String, dynamic> category) {
    widget.onToggleBottomNav?.call(false);
    setState(() {
      selectedCategorySlug = (category['slug'] ?? '').toString();
      selectedCategoryName = category['name']?.toString();
    });
  }

  void _clearSelection() {
    widget.onToggleBottomNav?.call(true);
    setState(() {
      selectedCategorySlug = null;
      selectedCategoryName = null;
    });
  }

  @override
  Widget build(BuildContext context) {
    final showingProducts = selectedCategorySlug != null;

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        bottom: false,
        child: DsPage(
          child: showingProducts
              ? _CategoryProducts(
                  key: ValueKey(selectedCategorySlug),
                  slug: selectedCategorySlug!,
                  categoryName: selectedCategoryName,
                  onBack: _clearSelection,
                  client: widget.client,
                )
              : _grid(),
        ),
      ),
    );
  }

  Widget _grid() {
    if (isLoadingCategories && categories.isEmpty) {
      return _skeletonGrid();
    }
    if (categoriesError != null && categories.isEmpty) {
      return DsState.error(
        message: categoriesError!,
        onAction: () {
          setState(() {
            isLoadingCategories = true;
            categoriesError = null;
          });
          fetchCategories();
        },
      );
    }
    if (categories.isEmpty) {
      return const DsState.empty(
        icon: Icons.grid_view_rounded,
        title: 'No categories yet',
        message: 'Categories will appear here once they are published.',
      );
    }

    return RefreshIndicator(
      onRefresh: fetchCategories,
      color: DsColor.primary,
      child: CustomScrollView(
        physics: const AlwaysScrollableScrollPhysics(
          parent: BouncingScrollPhysics(),
        ),
        slivers: [
          SliverToBoxAdapter(child: _header()),
          SliverPadding(
            padding: EdgeInsets.fromLTRB(
              context.gutter,
              0,
              context.gutter,
              context.floatingNavInset,
            ),
            sliver: SliverGrid.builder(
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                // Two on phones; more only where there is genuine room.
                crossAxisCount: context.categoryColumns,
                mainAxisSpacing: DsSpace.x3,
                crossAxisSpacing: DsSpace.x3,
                childAspectRatio: 0.82,
              ),
              itemCount: categories.length,
              itemBuilder: (context, i) => DsEnter.staggered(
                index: i,
                child: _CategoryCard(
                  category: categories[i] as Map<String, dynamic>,
                  onTap: () => _open(categories[i] as Map<String, dynamic>),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _header() {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x6,
        context.gutter,
        DsSpace.x5,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('BROWSE', style: DsType.eyebrow(color: DsColor.primary)),
          const SizedBox(height: DsSpace.x2),
          Text('Categories', style: text.displaySmall),
          const SizedBox(height: DsSpace.x2),
          Text(
            '${categories.length} collections of medicines and health essentials',
            style: text.bodyMedium,
          ),
        ],
      ),
    );
  }

  Widget _skeletonGrid() {
    return DsSkeleton(
      child: CustomScrollView(
        physics: const NeverScrollableScrollPhysics(),
        slivers: [
          SliverToBoxAdapter(
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                context.gutter,
                DsSpace.x6,
                context.gutter,
                DsSpace.x5,
              ),
              child: const Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  DsBone.text(width: 70, height: 9),
                  SizedBox(height: DsSpace.x3),
                  DsBone.text(width: 190, height: 26),
                  SizedBox(height: DsSpace.x3),
                  DsBone.text(width: 240, height: 11),
                ],
              ),
            ),
          ),
          SliverPadding(
            padding: EdgeInsets.symmetric(horizontal: context.gutter),
            sliver: SliverGrid.builder(
              gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                crossAxisCount: context.categoryColumns,
                mainAxisSpacing: DsSpace.x3,
                crossAxisSpacing: DsSpace.x3,
                childAspectRatio: 0.82,
              ),
              itemCount: 6,
              itemBuilder: (_, _) => const DsBone.plate(),
            ),
          ),
        ],
      ),
    );
  }
}

/// A category tile: full-bleed artwork under a scrim, label anchored bottom.
class _CategoryCard extends StatelessWidget {
  final Map<String, dynamic> category;
  final VoidCallback onTap;

  const _CategoryCard({required this.category, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final name = (category['name'] ?? '').toString();
    final image = categoryImageUrl(category['image']);
    final total = category['total_products'];

    return DsPressable(
      onTap: onTap,
      depth: 0.025,
      semanticLabel: total != null ? '$name, $total items' : name,
      child: Container(
        decoration: BoxDecoration(
          borderRadius: DsRadius.rMd,
          boxShadow: DsShadow.mid,
          color: DsColor.surface,
        ),
        clipBehavior: Clip.antiAlias,
        child: Stack(
          fit: StackFit.expand,
          children: [
            // cover, so the artwork occupies the whole card rather than
            // sitting in a corner of an oversized white box.
            DsImage(url: image, fit: BoxFit.cover, backdrop: DsColor.sunken),

            // Scrim: transparent at the top, near-opaque under the label, so
            // the text is legible over any artwork without hiding the image.
            const DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topCenter,
                  end: Alignment.bottomCenter,
                  colors: [
                    Color(0x00081F19),
                    Color(0x33081F19),
                    Color(0xE6061B15),
                  ],
                  stops: [0.35, 0.6, 1],
                ),
              ),
            ),

            if (total != null)
              Positioned(
                top: DsSpace.x3,
                right: DsSpace.x3,
                child: DsTag(
                  label: '$total',
                  color: Colors.white,
                  solid: false,
                ),
              ),

            Positioned(
              left: DsSpace.x4,
              right: DsSpace.x4,
              bottom: DsSpace.x4,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    name,
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                    style: text.titleMedium?.copyWith(
                      color: Colors.white,
                      height: 1.2,
                    ),
                  ),
                  const SizedBox(height: DsSpace.x1),
                  Row(
                    children: [
                      Text(
                        total != null ? '$total items' : 'Browse',
                        style: text.labelSmall?.copyWith(
                          color: Colors.white70,
                          letterSpacing: 0.3,
                        ),
                      ),
                      const SizedBox(width: DsSpace.x1),
                      const Icon(
                        Icons.arrow_forward_rounded,
                        size: 12,
                        color: Colors.white70,
                      ),
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
}

/// Products inside one category.
class _CategoryProducts extends StatefulWidget {
  final http.Client? client;
  final String slug;
  final String? categoryName;
  final VoidCallback onBack;

  const _CategoryProducts({
    super.key,
    required this.slug,
    required this.categoryName,
    required this.onBack,
    this.client,
  });

  @override
  State<_CategoryProducts> createState() => _CategoryProductsState();
}

class _CategoryProductsState extends State<_CategoryProducts> {
  List<dynamic> products = [];
  bool isLoading = true;
  bool isLoadingMore = false;
  bool hasMore = true;
  int currentPage = 1;

  /// Presentation-only preference.
  bool _grid = true;

  @override
  void initState() {
    super.initState();
    fetchProducts();
  }

  Future<void> fetchProducts({bool loadMore = false}) async {
    if (loadMore) {
      setState(() => isLoadingMore = true);
    } else {
      setState(() => isLoading = true);
    }

    final apiUrl =
        '${ApiEndpoints.categories}/${widget.slug}?page=$currentPage';

    try {
      final response =
          await (widget.client?.get(Uri.parse(apiUrl)) ??
                  http.get(Uri.parse(apiUrl)))
              .timeout(const Duration(seconds: 15));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        final newProducts = data['products']?['data'] ?? [];

        if (!mounted) return;
        setState(() {
          if (loadMore) {
            products.addAll(newProducts);
          } else {
            products = newProducts;
          }
          hasMore = newProducts.length >= 20;
          isLoading = false;
          isLoadingMore = false;
        });
      } else {
        if (!mounted) return;
        setState(() {
          isLoading = false;
          isLoadingMore = false;
        });
      }
    } catch (e) {
      if (!mounted) return;
      setState(() {
        isLoading = false;
        isLoadingMore = false;
      });
    }
  }

  void loadMore() {
    if (!hasMore || isLoadingMore) return;
    currentPage++;
    fetchProducts(loadMore: true);
  }

  Future<void> _refresh() async {
    currentPage = 1;
    await fetchProducts();
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      children: [
        _bar(),
        Expanded(
          child: isLoading
              ? const DsProductRowSkeleton()
              : products.isEmpty
              ? DsState.empty(
                  icon: Icons.inventory_2_outlined,
                  title: 'Nothing here yet',
                  message:
                      'There are no products in ${widget.categoryName ?? 'this category'} right now.',
                  actionLabel: 'Reload',
                  onAction: _refresh,
                )
              : RefreshIndicator(
                  onRefresh: _refresh,
                  color: DsColor.primary,
                  child: _productsView(),
                ),
        ),
      ],
    );
  }

  Widget _bar() {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x3,
        context.gutter,
        DsSpace.x3,
      ),
      child: Row(
        children: [
          DsIconDisc(
            icon: Icons.arrow_back_rounded,
            tooltip: 'All categories',
            onTap: widget.onBack,
            background: DsColor.surface,
            shadow: DsShadow.low,
          ),
          const SizedBox(width: DsSpace.x3),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text(
                  widget.categoryName ?? 'Category',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: text.headlineSmall,
                ),
                Text(
                  isLoading
                      ? 'Loading…'
                      : '${products.length} product${products.length == 1 ? '' : 's'}',
                  style: text.bodySmall,
                ),
              ],
            ),
          ),
          DsIconDisc(
            icon: _grid ? Icons.view_agenda_outlined : Icons.grid_view_rounded,
            tooltip: _grid ? 'List view' : 'Grid view',
            onTap: () => setState(() => _grid = !_grid),
            background: DsColor.surface,
          ),
        ],
      ),
    );
  }

  Widget _productsView() {
    final columns = context.productColumns;
    final cell = context.gridCellWidth(columns);
    final spec = DsTileSpec.resolve(context);

    return CustomScrollView(
      key: PageStorageKey('category-${widget.slug}-${_grid ? 'grid' : 'list'}'),
      physics: const AlwaysScrollableScrollPhysics(
        parent: BouncingScrollPhysics(),
      ),
      slivers: [
        SliverPadding(
          padding: EdgeInsets.fromLTRB(
            context.gutter,
            DsSpace.x2,
            context.gutter,
            0,
          ),
          sliver: _grid
              ? SliverGrid.builder(
                  gridDelegate: SliverGridDelegateWithFixedCrossAxisCount(
                    crossAxisCount: columns,
                    mainAxisSpacing: DsSpace.x3,
                    crossAxisSpacing: DsSpace.x3,
                    mainAxisExtent: spec.totalHeight(cell),
                  ),
                  itemCount: products.length,
                  itemBuilder: (_, i) => DsProductTile(
                    product: products[i] as Map<String, dynamic>,
                    heroPrefix: 'cat',
                    spec: spec,
                  ),
                )
              : SliverList.separated(
                  itemCount: products.length,
                  separatorBuilder: (_, _) =>
                      const SizedBox(height: DsSpace.x3),
                  itemBuilder: (_, i) => DsProductRow(
                    product: products[i] as Map<String, dynamic>,
                    heroPrefix: 'cat',
                  ),
                ),
        ),
        if (hasMore)
          SliverToBoxAdapter(
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                context.gutter,
                DsSpace.x4,
                context.gutter,
                0,
              ),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 320),
                  child: SizedBox(
                    width: double.infinity,
                    height: 52,
                    child: _loadMoreButton(),
                  ),
                ),
              ),
            ),
          ),
        SliverToBoxAdapter(
          child: SizedBox(
            height: DsSpace.x6 + MediaQuery.paddingOf(context).bottom,
          ),
        ),
      ],
    );
  }

  Widget _loadMoreButton() {
    return OutlinedButton(
      key: const ValueKey('category-load-more'),
      onPressed: isLoadingMore ? null : loadMore,
      child: isLoadingMore
          ? const SizedBox(
              width: 18,
              height: 18,
              child: CircularProgressIndicator(strokeWidth: 2.4),
            )
          : const Text('Load more'),
    );
  }
}
