import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/floating_nav.dart';
import 'package:redpharmabd_app/design/components/media.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/components/stepper.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/main.dart';
import 'package:redpharmabd_app/models/cart_item.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/checkout.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Cart.
///
/// Hierarchy, top to bottom: what you have → what each line costs → what the
/// whole thing costs → the one action. Nothing competes for attention with the
/// checkout button.
///
/// Line composition: the thumbnail, name and unit price form the identity
/// block; the stepper and line total sit on a separate row beneath a hairline,
/// so "what is this" and "how many / how much" never crowd each other. That
/// split is also what lets the row wrap cleanly at large text scales.
///
/// Quantity behaviour — matching the website:
///   * Every path funnels through [_setQty], which clamps to available stock
///     and explains the ceiling once.
///   * That covers "+", "−", typed input, and any future caller.
///   * The provider clamps independently as a backstop, so a UI bug cannot put
///     an over-stock quantity into the cart.
///   * No extra API calls — the ceiling comes from stock already returned with
///     the product.
/// ─────────────────────────────────────────────────────────────────────────
class CartScreen extends StatefulWidget {
  final bool inTabShell;
  const CartScreen({super.key, this.inTabShell = false});

  @override
  State<CartScreen> createState() => _CartScreenState();
}

class _CartScreenState extends State<CartScreen> {
  bool _isLoading = true;
  final Map<String, TextEditingController> _controllers = {};

  @override
  void initState() {
    super.initState();
    _loadCart();
  }

  Future<void> _loadCart() async {
    final cart = Provider.of<CartProvider>(context, listen: false);
    await cart.loadCartFromPrefs();
    if (!mounted) return;
    setState(() => _isLoading = false);
  }

  @override
  void dispose() {
    for (final c in _controllers.values) {
      c.dispose();
    }
    super.dispose();
  }

  // ── Quantity ─────────────────────────────────────────────────────────────

  /// The single quantity entry point.
  void _setQty(CartProvider cart, CartItem item, int requested) {
    final stock = item.stockQty;

    if (stock != null && requested > stock) {
      cart.updateQuantity(item.id, stock);
      _syncController(item.id, stock);
      DsToast.stockLimit(context, available: stock, productName: item.name);
      return;
    }

    // Typing 0 or clearing the field holds at 1 — removing a line is an
    // explicit action, never a side effect of editing.
    if (requested < 1) {
      cart.updateQuantity(item.id, 1);
      _syncController(item.id, 1);
      return;
    }

    cart.updateQuantity(item.id, requested);
    _syncController(item.id, requested);
  }

  void _syncController(String id, int qty) {
    final c = _controllers[id];
    if (c != null && c.text != qty.toString()) c.text = qty.toString();
  }

  TextEditingController _controllerFor(CartItem item) {
    final existing = _controllers[item.id];
    if (existing == null) {
      return _controllers[item.id] = TextEditingController(
        text: item.quantity.toString(),
      );
    }
    if (existing.text != item.quantity.toString()) {
      existing.text = item.quantity.toString();
    }
    return existing;
  }

  void _remove(CartProvider cart, CartItem item) {
    cart.removeItem(item.id);
    DsToast.info(context, 'Removed', detail: item.name);
  }

  // ── Composition ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartProvider>();
    final items = cart.items.values.toList();

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        bottom: false,
        child: DsPage(
          child: _isLoading
              ? const DsProductRowSkeleton(count: 3)
              : items.isEmpty
              ? _empty()
              : _list(cart, items),
        ),
      ),
      bottomNavigationBar: (_isLoading || items.isEmpty)
          ? null
          : _checkoutBar(cart),
    );
  }

  Widget _empty() => DsState.empty(
    icon: Icons.shopping_bag_outlined,
    title: 'Your bag is empty',
    message:
        'Browse medicines and health essentials — everything you add shows up here.',
    actionLabel: 'Start shopping',
    onAction: () => mainScreenKey.currentState?.switchToHomeTab(),
    secondaryLabel: 'Browse categories',
    onSecondary: () => mainScreenKey.currentState?.switchToTab(1),
  );

  Widget _list(CartProvider cart, List<CartItem> items) {
    final anyCapped = items.any(
      (i) => i.stockQty != null && i.quantity >= i.stockQty!,
    );

    return CustomScrollView(
      physics: const BouncingScrollPhysics(),
      slivers: [
        SliverToBoxAdapter(child: _header(cart, items.length)),

        // A single banner when anything is at its ceiling, so the per-line
        // tags stay quiet and the shopper is told once, clearly.
        if (anyCapped) SliverToBoxAdapter(child: _stockBanner()),

        SliverPadding(
          padding: EdgeInsets.fromLTRB(
            context.gutter,
            DsSpace.x2,
            context.gutter,
            DsSpace.x5,
          ),
          sliver: SliverList.separated(
            itemCount: items.length,
            separatorBuilder: (_, _) => const SizedBox(height: DsSpace.x3),
            itemBuilder: (context, i) {
              final item = items[i];
              return DsEnter.staggered(
                index: i,
                child: _CartLine(
                  item: item,
                  controller: _controllerFor(item),
                  onRemove: () => _remove(cart, item),
                  onDecrease: () {
                    if (item.quantity > 1) {
                      _setQty(cart, item, item.quantity - 1);
                    } else {
                      _remove(cart, item);
                    }
                  },
                  onIncrease: () => _setQty(cart, item, item.quantity + 1),
                  onBlocked: () => DsToast.stockLimit(
                    context,
                    available: item.stockQty ?? item.quantity,
                    productName: item.name,
                  ),
                  onTyped: (value) {
                    final parsed = int.tryParse(value);
                    if (parsed != null) _setQty(cart, item, parsed);
                  },
                  onEditingDone: () {
                    final parsed = int.tryParse(
                      _controllers[item.id]?.text ?? '',
                    );
                    if (parsed == null || parsed < 1) {
                      _setQty(cart, item, 1);
                    }
                    FocusScope.of(context).unfocus();
                  },
                ),
              );
            },
          ),
        ),

        SliverToBoxAdapter(child: _summary(cart)),
        SliverToBoxAdapter(
          child: SizedBox(height: context.floatingNavInset + DsSpace.x6),
        ),
      ],
    );
  }

  Widget _header(CartProvider cart, int lines) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x6,
        context.gutter,
        DsSpace.x5,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              mainAxisSize: MainAxisSize.min,
              children: [
                Text('YOUR BAG', style: DsType.eyebrow(color: DsColor.primary)),
                const SizedBox(height: DsSpace.x2),
                Text('Cart', style: text.displaySmall),
                const SizedBox(height: DsSpace.x2),
                Text(
                  '$lines item${lines == 1 ? '' : 's'} · '
                  '${cart.totalItems} unit${cart.totalItems == 1 ? '' : 's'}',
                  style: text.bodyMedium,
                ),
              ],
            ),
          ),
          const SizedBox(width: DsSpace.x3),
          DsIconDisc(
            icon: Icons.arrow_back_rounded,
            tooltip: 'Continue shopping',
            background: DsColor.surface,
            shadow: DsShadow.low,
            onTap: () => mainScreenKey.currentState?.switchToHomeTab(),
          ),
        ],
      ),
    );
  }

  /// One consolidated stock notice rather than a tag on every affected line.
  Widget _stockBanner() {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.symmetric(horizontal: context.gutter),
      child: Container(
        padding: const EdgeInsets.all(DsSpace.x4),
        decoration: BoxDecoration(
          color: DsColor.warningSoft,
          borderRadius: DsRadius.rSm,
        ),
        child: Row(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Icon(
              Icons.inventory_2_outlined,
              size: 17,
              color: DsColor.warning,
            ),
            const SizedBox(width: DsSpace.x3),
            Expanded(
              child: Text(
                'Some items are at their maximum available quantity.',
                style: text.bodySmall?.copyWith(color: DsColor.ink),
              ),
            ),
          ],
        ),
      ),
    );
  }

  /// Itemised totals. Only figures the app actually knows are shown —
  /// delivery is computed by the checkout API from the chosen area, so this
  /// states that rather than inventing a placeholder row.
  Widget _summary(CartProvider cart) {
    final text = Theme.of(context).textTheme;

    return Padding(
      padding: EdgeInsets.symmetric(horizontal: context.gutter),
      child: DsSurface(
        padding: const EdgeInsets.all(DsSpace.x5),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('Summary', style: text.titleLarge),
            const SizedBox(height: DsSpace.x4),
            _summaryRow('Items', '${cart.totalItems}', text),
            const SizedBox(height: DsSpace.x3),
            _summaryRow('Subtotal', taka(cart.totalAmount, exact: true), text),
            const Padding(
              padding: EdgeInsets.symmetric(vertical: DsSpace.x4),
              child: Divider(height: 1),
            ),
            Row(
              children: [
                Expanded(child: Text('Total', style: text.titleMedium)),
                Text(
                  taka(cart.totalAmount, exact: true),
                  style: DsType.numeral(22, color: DsColor.primary),
                ),
              ],
            ),
            const SizedBox(height: DsSpace.x4),
            Container(
              padding: const EdgeInsets.all(DsSpace.x3),
              decoration: BoxDecoration(
                color: DsColor.primarySoft,
                borderRadius: DsRadius.rXs,
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.local_shipping_outlined,
                    size: 15,
                    color: DsColor.primary,
                  ),
                  const SizedBox(width: DsSpace.x2),
                  Expanded(
                    child: Text(
                      'Delivery is calculated at checkout, once you pick your area.',
                      style: text.bodySmall?.copyWith(
                        color: DsColor.primaryDeep,
                        fontSize: 11.5,
                      ),
                    ),
                  ),
                ],
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _summaryRow(String label, String value, TextTheme text) => Row(
    children: [
      Expanded(child: Text(label, style: text.bodyMedium)),
      Text(value, style: DsType.numeral(14)),
    ],
  );

  /// Sticky checkout bar, floating clear of the bottom edge.
  Widget _checkoutBar(CartProvider cart) {
    final text = Theme.of(context).textTheme;

    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          context.gutter,
          0,
          context.gutter,
          DsSpace.x3 +
              (widget.inTabShell ? DsFloatingNav.contentHeight(context) : 0),
        ),
        // `heightFactor: 1` is load-bearing. Scaffold measures
        // `bottomNavigationBar` with a maxHeight of the whole screen, and a
        // plain `Center` (Align with null factors) sizes to `constraints
        // .biggest` — so the bar claimed the full height and left the body
        // with none, rendering the item list into a zero-height viewport.
        // Constraining the height to the child keeps the horizontal centring
        // and the tablet width cap without swallowing the screen.
        child: Align(
          alignment: Alignment.center,
          heightFactor: 1,
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 560),
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
                          'SUBTOTAL',
                          style: DsType.eyebrow(
                            color: DsColor.onDark.withValues(alpha: 0.6),
                          ),
                        ),
                        const SizedBox(height: DsSpace.x1),
                        Text(
                          taka(cart.totalAmount, exact: true),
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                          style: DsType.numeral(21, color: DsColor.onDark),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: DsSpace.x3),
                  DsPressable(
                    onTap: () => Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const CheckoutScreen()),
                    ),
                    depth: 0.05,
                    semanticLabel: 'Checkout',
                    child: Container(
                      height: 52,
                      padding: const EdgeInsets.symmetric(
                        horizontal: DsSpace.x5,
                      ),
                      decoration: BoxDecoration(
                        color: DsColor.primaryBright,
                        borderRadius: DsRadius.pill,
                      ),
                      child: Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Text(
                            'Checkout',
                            style: text.labelLarge?.copyWith(
                              color: Colors.white,
                            ),
                          ),
                          const SizedBox(width: DsSpace.x2),
                          const Icon(
                            Icons.arrow_forward_rounded,
                            size: 18,
                            color: Colors.white,
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
      ),
    );
  }
}

/// One line in the cart.
class _CartLine extends StatelessWidget {
  final CartItem item;
  final TextEditingController controller;
  final VoidCallback onRemove;
  final VoidCallback onDecrease;
  final VoidCallback onIncrease;
  final VoidCallback onBlocked;
  final ValueChanged<String> onTyped;
  final VoidCallback onEditingDone;

  const _CartLine({
    required this.item,
    required this.controller,
    required this.onRemove,
    required this.onDecrease,
    required this.onIncrease,
    required this.onBlocked,
    required this.onTyped,
    required this.onEditingDone,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    final lineTotal = item.price * item.quantity;
    final stock = item.stockQty;
    final atMax = stock != null && item.quantity >= stock;
    final thumb = context.pick<double>(base: 72, compact: 62, expanded: 88);

    return DsSurface(
      padding: const EdgeInsets.all(DsSpace.x4),
      child: Column(
        children: [
          // Identity block.
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              ClipRRect(
                borderRadius: DsRadius.rSm,
                child: SizedBox(
                  width: thumb,
                  height: thumb,
                  child: DsImage(
                    url: item.imageUrl,
                    fit: BoxFit.cover,
                    backdrop: DsColor.sunken,
                  ),
                ),
              ),
              const SizedBox(width: DsSpace.x4),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Text(
                      item.name,
                      maxLines: 2,
                      overflow: TextOverflow.ellipsis,
                      style: text.titleMedium,
                    ),
                    const SizedBox(height: DsSpace.x1),
                    Text(
                      item.brand ?? 'No Brand',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.bodySmall?.copyWith(fontSize: 11.5),
                    ),
                    const SizedBox(height: DsSpace.x2),
                    Text(
                      '${taka(item.price, exact: true)} / ${item.unit ?? 'unit'}',
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: text.labelMedium?.copyWith(
                        color: DsColor.inkMuted,
                      ),
                    ),
                  ],
                ),
              ),
              // In-flow, so it can never overlap the product name the way a
              // corner-pinned control does.
              SizedBox(
                width: 32,
                height: 32,
                child: IconButton(
                  tooltip: 'Remove ${item.name}',
                  onPressed: onRemove,
                  padding: EdgeInsets.zero,
                  visualDensity: VisualDensity.compact,
                  icon: const Icon(
                    Icons.close_rounded,
                    size: 17,
                    color: DsColor.inkFaint,
                  ),
                ),
              ),
            ],
          ),

          const Padding(
            padding: EdgeInsets.symmetric(vertical: DsSpace.x4),
            child: Divider(height: 1),
          ),

          // Quantity and line total. Wrap so they stack rather than collide
          // when the text scale grows.
          Wrap(
            alignment: WrapAlignment.spaceBetween,
            crossAxisAlignment: WrapCrossAlignment.center,
            spacing: DsSpace.x4,
            runSpacing: DsSpace.x3,
            children: [
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  DsStepper(
                    value: item.quantity,
                    controller: controller,
                    compact: true,
                    atMax: atMax,
                    semanticLabel: 'Quantity for ${item.name}',
                    onDecrease: onDecrease,
                    onIncrease: onIncrease,
                    onBlocked: onBlocked,
                    onChanged: onTyped,
                    onEditingComplete: onEditingDone,
                    onTapOutside: (_) => onEditingDone(),
                  ),
                  if (atMax) ...[
                    const SizedBox(height: DsSpace.x2),
                    Text(
                      'Max $stock available',
                      style: text.labelSmall?.copyWith(
                        color: DsColor.warning,
                        letterSpacing: 0.2,
                      ),
                    ),
                  ],
                ],
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.end,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text('LINE TOTAL', style: DsType.eyebrow()),
                  const SizedBox(height: DsSpace.x1),
                  Text(
                    taka(lineTotal, exact: true),
                    style: DsType.numeral(19, color: DsColor.primary),
                  ),
                ],
              ),
            ],
          ),
        ],
      ),
    );
  }
}
