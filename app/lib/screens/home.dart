import 'dart:convert';
import 'dart:async';
import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/helpers/category_image.dart';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';
import 'package:url_launcher/url_launcher.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/carousel.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/media.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/product_tile.dart';
import 'package:redpharmabd_app/design/components/skeleton.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/main.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/categories_screen.dart';
import 'package:redpharmabd_app/screens/search_results.dart';
import 'package:redpharmabd_app/widgets/home/how_to_order.dart';
import 'package:redpharmabd_app/widgets/home/prescription_banner.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Home, rebuilt from a blank screen.
///
/// New composition, top to bottom:
///
///   1. **Greeting header** — time-aware, with the shopper's name and a cart
///      shortcut. Establishes a person, not a storefront.
///   2. **Search as a resting pill** that opens the real search screen. A live
///      text field at the top of a feed invites accidental focus and shoves the
///      whole page up with the keyboard; a button never does.
///   3. **Hero carousel**, sized from the artwork's own ratio.
///   4. **Category strip** — circular plates, fast lateral movement.
///   5. **Best sellers / Featured rails** built from the shared product tile.
///   6. **Prescription banner** — the highest-intent action in a pharmacy, so
///      it gets a full-width dark panel rather than a row in a list.
///   7. **How to order** — now a one-card-per-slide carousel.
///
/// Every fetch method, timer-free refresh path and navigation target is the
/// existing one; only presentation is new.
/// ─────────────────────────────────────────────────────────────────────────
class HomeScreen extends StatefulWidget {
  final http.Client? client;
  final Duration requestTimeout;

  const HomeScreen({
    super.key,
    this.client,
    this.requestTimeout = const Duration(seconds: 15),
  });

  @override
  HomeScreenState createState() => HomeScreenState();
}

class HomeScreenState extends State<HomeScreen> {
  late final http.Client _client;
  final Set<String> _loading = {'categories', 'featured', 'best'};
  final Set<String> _inFlight = {};
  final Map<String, String> _errors = {};
  List<dynamic> categories = [];
  List<dynamic> featuredProducts = [];
  List<dynamic> bestSellingProducts = [];

  final List<String> bannerImages = const [
    'assets/images/banners/slide-1.jpg',
    'assets/images/banners/slide-2.jpg',
    'assets/images/banners/slide-3.jpg',
  ];

  @override
  void initState() {
    super.initState();
    _client = widget.client ?? http.Client();
    fetchCategories();
    fetchFeaturedProducts();
    fetchBestSellingProducts();
  }

  @override
  void dispose() {
    if (widget.client == null) _client.close();
    super.dispose();
  }

  Future<void> _loadCatalog(
    String section,
    String endpoint,
    void Function(List<dynamic>) save,
  ) async {
    if (_inFlight.contains(section)) return;
    _inFlight.add(section);
    setState(() {
      _loading.add(section);
      _errors.remove(section);
    });
    try {
      final response = await _client
          .get(Uri.parse(endpoint), headers: {'Accept': 'application/json'})
          .timeout(widget.requestTimeout);
      if (response.statusCode != 200) {
        throw http.ClientException('HTTP ${response.statusCode}');
      }
      final decoded = json.decode(response.body);
      final rows = decoded is List
          ? decoded
          : decoded is Map
          ? decoded['data']
          : null;
      if (rows is! List || rows.any((row) => row is! Map<String, dynamic>)) {
        throw const FormatException('Invalid catalog response');
      }
      if (!mounted) return;
      setState(() => save(rows));
    } catch (e) {
      debugPrint('Error fetching $section: $e');
      if (!mounted) return;
      setState(() {
        _errors[section] = e is TimeoutException
            ? 'The connection timed out. Please try again.'
            : 'Unable to load data. Please check your connection and try again.';
      });
    } finally {
      _inFlight.remove(section);
      if (mounted) setState(() => _loading.remove(section));
    }
  }

  Future<void> fetchFeaturedProducts() => _loadCatalog(
    'featured',
    ApiEndpoints.featuredProducts,
    (rows) => featuredProducts = rows,
  );

  Future<void> fetchBestSellingProducts() => _loadCatalog(
    'best',
    ApiEndpoints.bestSellingProducts,
    (rows) => bestSellingProducts = rows,
  );

  Future<void> fetchCategories() => _loadCatalog(
    'categories',
    ApiEndpoints.categories,
    (rows) => categories = rows,
  );

  Widget _catalogSection({
    required String section,
    required List<dynamic> items,
    required Widget child,
    required VoidCallback retry,
  }) {
    final error = _errors[section];
    if (error != null) {
      return Column(
        children: [
          Padding(
            padding: context.pagePad,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(error, key: ValueKey('$section-error')),
                TextButton(
                  key: ValueKey('$section-retry'),
                  onPressed: retry,
                  child: const Text('Try again'),
                ),
              ],
            ),
          ),
          if (items.isNotEmpty) child,
        ],
      );
    }
    if (!_loading.contains(section) && items.isEmpty) {
      return Padding(
        padding: context.pagePad,
        child: Text(
          'No items available right now.',
          key: ValueKey('$section-empty'),
        ),
      );
    }
    return child;
  }

  Future<void> _refresh() async {
    await Future.wait([
      fetchCategories(),
      fetchFeaturedProducts(),
      fetchBestSellingProducts(),
    ]);
  }

  Future<void> _launch(Uri uri) async {
    if (!await launchUrl(uri, mode: LaunchMode.externalApplication)) {
      if (!mounted) return;
      DsToast.error(context, 'Could not open link', detail: uri.toString());
    }
  }

  void _openCategory(Map<String, dynamic> category) {
    Navigator.push(
      context,
      MaterialPageRoute(
        builder: (_) => CategoryScreen(
          initialSlug: category['slug'],
          initialName: category['name'],
        ),
      ),
    );
  }

  // ── Composition ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final tileWidth = context.pick<double>(
      base: 168,
      compact: 150,
      large: 180,
      expanded: 200,
      wide: 220,
    );

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: RefreshIndicator(
        onRefresh: _refresh,
        color: DsColor.primary,
        edgeOffset: MediaQuery.paddingOf(context).top + 60,
        child: DsPage(
          child: CustomScrollView(
            physics: const AlwaysScrollableScrollPhysics(
              parent: BouncingScrollPhysics(),
            ),
            slivers: [
              SliverToBoxAdapter(child: _greeting()),
              SliverToBoxAdapter(child: _searchPill()),
              const SliverToBoxAdapter(child: SizedBox(height: DsSpace.x6)),

              SliverToBoxAdapter(child: _hero()),

              SliverToBoxAdapter(
                child: DsSection(
                  eyebrow: 'Browse',
                  title: 'Shop by category',
                  onAction: () => mainScreenKey.currentState?.switchToTab(1),
                ),
              ),
              SliverToBoxAdapter(
                child: _catalogSection(
                  section: 'categories',
                  items: categories,
                  child: _categoryStrip(),
                  retry: fetchCategories,
                ),
              ),

              SliverToBoxAdapter(
                child: DsSection(
                  eyebrow: 'Popular',
                  title: 'Best sellers',
                  subtitle: 'What people are buying right now',
                  accent: DsColor.flame,
                ),
              ),
              SliverToBoxAdapter(
                child: _catalogSection(
                  section: 'best',
                  items: bestSellingProducts,
                  retry: fetchBestSellingProducts,
                  child: DsProductRail(
                    products: bestSellingProducts,
                    isLoading:
                        _loading.contains('best') &&
                        bestSellingProducts.isEmpty,
                    tileWidth: tileWidth,
                    heroPrefix: 'best',
                  ),
                ),
              ),

              SliverToBoxAdapter(
                child: Padding(
                  padding: EdgeInsets.fromLTRB(
                    context.gutter,
                    DsSpace.x8,
                    context.gutter,
                    0,
                  ),
                  child: const PrescriptionBanner(
                    phoneNumber: '8801997202010',
                    message: 'Hello, I want to upload my prescription.',
                  ),
                ),
              ),

              SliverToBoxAdapter(
                child: DsSection(
                  eyebrow: 'Curated',
                  title: 'Featured products',
                  subtitle: 'Hand-picked by our pharmacists',
                ),
              ),
              SliverToBoxAdapter(
                child: _catalogSection(
                  section: 'featured',
                  items: featuredProducts,
                  retry: fetchFeaturedProducts,
                  child: DsProductRail(
                    products: featuredProducts,
                    isLoading:
                        _loading.contains('featured') &&
                        featuredProducts.isEmpty,
                    tileWidth: tileWidth,
                    heroPrefix: 'feat',
                  ),
                ),
              ),

              SliverToBoxAdapter(child: _support()),

              const SliverToBoxAdapter(
                child: DsSection(
                  eyebrow: 'Getting started',
                  title: 'How to order',
                  subtitle: 'Four steps from search to doorstep',
                ),
              ),
              const SliverToBoxAdapter(child: HowToOrderCarousel()),

              SliverToBoxAdapter(
                child: SizedBox(height: context.floatingNavInset + DsSpace.x6),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _greeting() {
    final text = Theme.of(context).textTheme;
    final hour = DateTime.now().hour;
    final greeting = hour < 12
        ? 'Good morning'
        : hour < 17
        ? 'Good afternoon'
        : 'Good evening';

    final auth = context.watch<AuthProvider>();
    final name = auth.userData?['name']?.toString();
    final hasName = auth.isLoggedIn && name != null && name.isNotEmpty;

    return SafeArea(
      bottom: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          context.gutter,
          DsSpace.x4,
          context.gutter,
          DsSpace.x5,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.center,
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    greeting.toUpperCase(),
                    style: DsType.eyebrow(color: DsColor.primary),
                  ),
                  const SizedBox(height: DsSpace.x1 + 2),
                  Text(
                    hasName ? name.split(' ').first : 'Welcome',
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: text.displaySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: DsSpace.x3),
            _cartButton(),
          ],
        ),
      ),
    );
  }

  Widget _cartButton() => const _CartBadgeButton();

  /// Search presented as a resting control that pushes the search screen.
  Widget _searchPill() {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: context.pagePad,
      child: DsSurface(
        radius: DsRadius.pill,
        shadow: DsShadow.mid,
        onTap: () => Navigator.push(
          context,
          MaterialPageRoute(
            builder: (_) => const SearchResultsScreen(initialQuery: ''),
          ),
        ),
        semanticLabel: 'Search medicines and health products',
        padding: const EdgeInsets.fromLTRB(
          DsSpace.x5,
          DsSpace.x4,
          DsSpace.x2,
          DsSpace.x4,
        ),
        child: Row(
          children: [
            const Icon(
              Icons.search_rounded,
              size: DsSize.iconLg,
              color: DsColor.inkSoft,
            ),
            const SizedBox(width: DsSpace.x3),
            Expanded(
              child: Text(
                'Search medicines, brands, health',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: text.bodyMedium?.copyWith(color: DsColor.inkSoft),
              ),
            ),
            Container(
              width: 36,
              height: 36,
              decoration: const BoxDecoration(
                color: DsColor.primarySoft,
                shape: BoxShape.circle,
              ),
              child: const Icon(
                Icons.tune_rounded,
                size: 17,
                color: DsColor.primary,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _hero() {
    return DsCarousel(
      itemCount: bannerImages.length,
      // The shipped artwork is 1400x480. Driving the slide off its true ratio
      // is what stops the crop that a fixed height forced.
      aspectRatio: 1400 / 480,
      maxHeight: context.heroMaxHeight,
      viewportFraction: 1,
      itemBuilder: (context, i, active) => Padding(
        padding: const EdgeInsets.symmetric(horizontal: 2),
        child: ClipRRect(
          borderRadius: DsRadius.rLg,
          child: DecoratedBox(
            decoration: BoxDecoration(
              borderRadius: DsRadius.rLg,
              boxShadow: DsShadow.mid,
            ),
            child: Image.asset(
              bannerImages[i],
              fit: BoxFit.cover,
              filterQuality: FilterQuality.medium,
              errorBuilder: (_, _, _) => const ColoredBox(
                color: DsColor.sunken,
                child: Center(
                  child: Icon(Icons.image_outlined, color: DsColor.inkFaint),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _categoryStrip() {
    final size = context.pick<double>(base: 68, compact: 60, expanded: 84);
    final labelH = MediaQuery.textScalerOf(context).scale(11) * 2.6;
    final loading = _loading.contains('categories') && categories.isEmpty;

    return SizedBox(
      height: size + DsSpace.x3 + labelH,
      child: DsSkeleton(
        enabled: loading,
        child: ListView.separated(
          scrollDirection: Axis.horizontal,
          padding: context.pagePad,
          physics: loading
              ? const NeverScrollableScrollPhysics()
              : const BouncingScrollPhysics(),
          itemCount: loading ? 6 : categories.length,
          separatorBuilder: (_, _) => const SizedBox(width: DsSpace.x4),
          itemBuilder: (context, i) {
            if (loading) {
              return SizedBox(
                width: size,
                child: Column(
                  children: [
                    DsBone(width: size, height: size, radius: DsRadius.pill),
                    const SizedBox(height: DsSpace.x3),
                    const DsBone.text(width: 44, height: 9),
                  ],
                ),
              );
            }

            final c = categories[i] as Map<String, dynamic>;
            return _CategoryBubble(
              category: c,
              size: size,
              onTap: () => _openCategory(c),
            );
          },
        ),
      ),
    );
  }

  /// Two support actions, presented as a single split panel rather than two
  /// competing buttons.
  Widget _support() {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x8,
        context.gutter,
        0,
      ),
      child: DsSurface(
        padding: const EdgeInsets.all(DsSpace.x5),
        child: Row(
          children: [
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('Need help choosing?', style: text.titleMedium),
                  const SizedBox(height: DsSpace.x1),
                  Text(
                    'Our pharmacists answer in minutes.',
                    style: text.bodySmall,
                  ),
                ],
              ),
            ),
            const SizedBox(width: DsSpace.x3),
            DsIconDisc(
              icon: Icons.call_rounded,
              tooltip: 'Call to order',
              background: DsColor.primarySoft,
              foreground: DsColor.primary,
              onTap: () => _launch(Uri.parse('tel:+8801997202010')),
            ),
            const SizedBox(width: DsSpace.x2),
            DsIconDisc(
              icon: Icons.chat_bubble_rounded,
              tooltip: 'Live chat',
              background: DsColor.infoSoft,
              foreground: DsColor.info,
              onTap: () =>
                  _launch(Uri.parse('https://www.facebook.com/redpharmabd/')),
            ),
          ],
        ),
      ),
    );
  }
}

/// Cart shortcut with a live badge.
///
/// `context.select` narrows the subscription to a single integer, so adding an
/// item repaints the badge and nothing else on the home feed.
class _CartBadgeButton extends StatelessWidget {
  const _CartBadgeButton();

  @override
  Widget build(BuildContext context) {
    final count = context.select<CartProvider, int>((c) => c.totalUniqueItems);

    return Stack(
      clipBehavior: Clip.none,
      children: [
        DsIconDisc(
          icon: Icons.shopping_bag_outlined,
          tooltip: count > 0 ? 'Cart, $count items' : 'Cart',
          size: 48,
          background: DsColor.surface,
          shadow: DsShadow.low,
          onTap: () => mainScreenKey.currentState?.switchToTab(2),
        ),
        Positioned(right: -2, top: -2, child: DsCount(count: count)),
      ],
    );
  }
}

/// A circular category plate in the home strip.
class _CategoryBubble extends StatelessWidget {
  final Map<String, dynamic> category;
  final double size;
  final VoidCallback onTap;

  const _CategoryBubble({
    required this.category,
    required this.size,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final name = (category['name'] ?? '').toString();
    final image = categoryImageUrl(category['image']);

    return DsPressable(
      onTap: onTap,
      depth: 0.06,
      semanticLabel: name,
      child: SizedBox(
        width: size,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Container(
              width: size,
              height: size,
              decoration: BoxDecoration(
                color: DsColor.surface,
                shape: BoxShape.circle,
                boxShadow: DsShadow.low,
              ),
              clipBehavior: Clip.antiAlias,
              child: DsImage(
                url: image,
                fit: BoxFit.cover,
                width: size,
                height: size,
              ),
            ),
            const SizedBox(height: DsSpace.x3),
            Expanded(
              child: Text(
                name,
                maxLines: 2,
                textAlign: TextAlign.center,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(
                  context,
                ).textTheme.labelMedium?.copyWith(fontSize: 11, height: 1.2),
              ),
            ),
          ],
        ),
      ),
    );
  }
}
