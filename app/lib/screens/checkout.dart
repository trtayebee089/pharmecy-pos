import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:google_maps_flutter/google_maps_flutter.dart' show LatLng;
import 'package:http/http.dart' as http;
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/steps.dart';
import 'package:redpharmabd_app/design/components/surfaces.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/main_screen.dart';
import 'package:redpharmabd_app/models/checkout_response.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/providers/cart_provider.dart';
import 'package:redpharmabd_app/screens/location_picker.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Checkout, rebuilt.
///
/// New composition:
///  * A **segmented progress rail** with a caption, replacing numbered circles.
///  * Each step is a single focused card — one job per screen, so the form
///    never feels like a wall.
///  * The **running total lives in the sticky bar** at every step, so the
///    number you are committing to is never more than a glance away.
///  * A **Back control** that seeds fields from state, so correcting an
///    address costs nothing.
///  * Confirmation is a full success state with the order reference.
///
/// The payload, discount maths, zone lookup, validation and the 422
/// stock-correction path are byte-for-byte the existing logic.
/// ─────────────────────────────────────────────────────────────────────────
class CheckoutScreen extends StatefulWidget {
  const CheckoutScreen({super.key});

  @override
  State<CheckoutScreen> createState() => _CheckoutScreenState();
}

class _CheckoutScreenState extends State<CheckoutScreen> {
  int currentStep = 1;
  final _formKey = GlobalKey<FormState>();
  CheckoutResponse? checkoutResponse;

  String fullName = '';
  String phone = '';
  String address = '';
  String? division;
  String? district;

  /// Optional exact delivery point from the map picker (both or neither).
  double? pinLat;
  double? pinLng;
  String paymentMethod = 'cash_on_delivery';
  bool isLoading = false;

  List<dynamic> zones = [];
  List<String> divisions = [];
  List<Map<String, dynamic>> districts = [];
  double shippingRate = 0.0;

  @override
  void initState() {
    super.initState();
    _fetchShippingZones();
  }

  // ── Data and maths (unchanged) ───────────────────────────────────────────

  Future<void> _fetchShippingZones() async {
    try {
      final response = await http.get(Uri.parse(ApiEndpoints.shippingZones));
      if (response.statusCode == 200) {
        final data = json.decode(response.body);
        if (data['success'] == true) {
          final List zonesData = data['data'];
          final Set<String> uniqueDivisions = zonesData
              .map((z) => z['division'] as String)
              .toSet();
          setState(() {
            zones = zonesData;
            divisions = uniqueDivisions.toList();
          });
        }
      } else {
        debugPrint("❌ Failed to load shipping zones");
      }
    } catch (e) {
      debugPrint("⚠️ Error fetching zones: $e");
    }
  }

  double _getDiscountRate(AuthProvider auth) {
    // The server gives a discount only to signed-in members; guests pay retail.
    double baseDiscount = 0.0;

    if (auth.isLoggedIn &&
        auth.membership != null &&
        auth.membership!['discount'] != null) {
      return double.tryParse(auth.membership!['discount'].toString()) ??
          baseDiscount;
    }

    return baseDiscount;
  }

  double _calculateDiscountAmount(double subtotal, double rate) {
    return (subtotal * rate) / 100;
  }

  Future<void> _submitOrder(BuildContext context) async {
    final cart = Provider.of<CartProvider>(context, listen: false);
    final auth = Provider.of<AuthProvider>(context, listen: false);

    setState(() => isLoading = true);

    try {
      final subtotal = cart.totalAmount;

      final discountRate = _getDiscountRate(auth);
      final discountAmount = _calculateDiscountAmount(subtotal, discountRate);

      final totalAfterDiscount = subtotal - discountAmount + shippingRate;

      final payload = {
        "user_id": auth.isLoggedIn && auth.userData != null
            ? auth.userData!['id'] as int
            : null,
        "warehouse_id": 1,
        "sale_status": 0,
        "payment_status": 0,
        "paid_amount": 0,
        "sale_type": "website",

        "customer": {
          "name": fullName,
          "phone": phone,
          "address": address,
          // Lets the server pick the exact delivery zone (older builds sent only the rate).
          "division": division,
          "district": district,
          // Exact pin for the rider's map; optional.
          if (pinLat != null && pinLng != null) "latitude": pinLat,
          if (pinLat != null && pinLng != null) "longitude": pinLng,
        },

        "subtotal": subtotal,
        "shipping_cost": shippingRate,
        "reward_points": cart.rewardPoints,
        "total": totalAfterDiscount,

        "order_discount_type": "percentage",
        "order_discount_value": discountRate,
        "discount_value": discountAmount,
        "discount_applied": true,

        "items": cart.items.values.map((item) {
          return {
            "product_id": item.id,
            "qty": item.quantity,
            "net_unit_price": item.price,
            "discount": 0,
            "tax_rate": 0,
            "tax": 0,
            "total": item.price * item.quantity,
          };
        }).toList(),

        "uniqueItems": cart.items.length,
        "total_qty": cart.totalItems,
      };

      final response = await http.post(
        Uri.parse(ApiEndpoints.checkout),
        headers: {
          "Content-Type": "application/json",
          "Accept": "application/json",
          if (auth.token != null) "Authorization": "Bearer ${auth.token}",
        },
        body: jsonEncode(payload),
      );

      if (response.statusCode == 200) {
        final data = jsonDecode(response.body);
        checkoutResponse = CheckoutResponse.fromJson(data);
        cart.clearCart();

        setState(() => currentStep = 4);
      } else if (response.statusCode == 422) {
        // ─── Stock validation failed (server-side) ───────────────────
        final data = jsonDecode(response.body);
        final String message = data['message'] ?? 'Stock limit exceeded.';
        final availableStock = data['available_stock'];
        final productId = data['product_id']?.toString();

        // Auto-correct cart quantity for the offending product
        if (productId != null && availableStock != null) {
          cart.updateQuantity(productId, (availableStock as num).toInt());
        }

        if (!context.mounted) return;
        DsToast.warning(context, message, detail: 'Cart quantity adjusted.');
      } else {
        throw Exception("Checkout failed (${response.statusCode})");
      }
    } catch (e) {
      debugPrint("❌ Checkout Error: $e");
      if (context.mounted) DsToast.error(context, 'Failed to place order');
    } finally {
      if (mounted) setState(() => isLoading = false);
    }
  }

  void _onDivisionSelected(String selectedDivision) {
    final filteredDistricts = zones
        .where((z) => z['division'] == selectedDivision)
        .map(
          (z) => {
            'district': z['district'],
            'rate': double.tryParse(z['rate'].toString()) ?? 0.0,
          },
        )
        .toList();

    setState(() {
      division = selectedDivision;
      districts = filteredDistricts;
      district = null;
      shippingRate = 0.0;
    });
  }

  void _onDistrictSelected(String selectedDistrict) {
    final districtData = districts.firstWhere(
      (d) => d['district'] == selectedDistrict,
      orElse: () => {},
    );
    setState(() {
      district = selectedDistrict;
      shippingRate = double.tryParse('${districtData['rate'] ?? 0}') ?? 0.0;
    });
  }

  // ── Composition ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final cart = context.watch<CartProvider>();
    final auth = context.watch<AuthProvider>();

    final subtotal = cart.totalAmount;
    final rate = _getDiscountRate(auth);
    final discount = _calculateDiscountAmount(subtotal, rate);
    final total = subtotal - discount + shippingRate;

    if (currentStep == 4) return _confirmation();

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        bottom: false,
        child: DsPage(
          child: Column(
            children: [
              _header(),
              Expanded(
                child: SingleChildScrollView(
                  physics: const BouncingScrollPhysics(),
                  padding: EdgeInsets.fromLTRB(
                    context.gutter,
                    DsSpace.x2,
                    context.gutter,
                    DsSpace.x8,
                  ),
                  child: AnimatedSwitcher(
                    duration: DsMotion.base,
                    transitionBuilder: (child, a) => FadeTransition(
                      opacity: a,
                      child: SlideTransition(
                        position: Tween<Offset>(
                          begin: const Offset(0.05, 0),
                          end: Offset.zero,
                        ).animate(a),
                        child: child,
                      ),
                    ),
                    child: KeyedSubtree(
                      key: ValueKey(currentStep),
                      child: switch (currentStep) {
                        1 => _stepDetails(),
                        2 => _stepPayment(subtotal, rate, discount, total),
                        _ => _stepReview(cart, subtotal, rate, discount, total),
                      },
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
      bottomNavigationBar: _actionBar(total),
    );
  }

  Widget _header() {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x3,
        context.gutter,
        DsSpace.x5,
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              DsIconDisc(
                icon: Icons.arrow_back_rounded,
                tooltip: 'Back',
                background: DsColor.surface,
                shadow: DsShadow.low,
                onTap: () => Navigator.pop(context),
              ),
              const SizedBox(width: DsSpace.x3),
              Expanded(
                child: Text(
                  'Checkout',
                  style: Theme.of(context).textTheme.headlineMedium,
                ),
              ),
            ],
          ),
          const SizedBox(height: DsSpace.x5),
          DsSteps(
            labels: const ['Delivery details', 'Payment', 'Review order'],
            current: currentStep,
          ),
        ],
      ),
    );
  }

  // ── Step 1 ───────────────────────────────────────────────────────────────

  Widget _stepDetails() {
    return Form(
      key: _formKey,
      child: DsSurface(
        padding: const EdgeInsets.all(DsSpace.x5),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(
              'Where should we deliver?',
              style: Theme.of(context).textTheme.titleLarge,
            ),
            const SizedBox(height: DsSpace.x5),
            _field(
              label: 'Full name',
              hint: 'e.g. Rahim Uddin',
              icon: Icons.person_outline_rounded,
              initial: fullName,
              onSaved: (v) => fullName = v!,
              validator: (v) => v!.isEmpty ? 'Enter your name' : null,
            ),
            _field(
              label: 'Phone number',
              hint: 'We call before delivery',
              icon: Icons.phone_iphone_rounded,
              initial: phone,
              keyboard: TextInputType.phone,
              onSaved: (v) => phone = v!,
              validator: (v) => v!.isEmpty ? 'Enter phone number' : null,
            ),
            _field(
              label: 'Address',
              hint: 'House, road, area',
              icon: Icons.location_on_outlined,
              initial: address,
              lines: 2,
              onSaved: (v) => address = v!,
              validator: (v) => v!.isEmpty ? 'Enter address' : null,
            ),
            _pinControl(),
            const SizedBox(height: DsSpace.x4),
            _dropdown(
              label: 'Division',
              icon: Icons.public_outlined,
              value: division,
              items: divisions,
              onChanged: (v) => _onDivisionSelected(v!),
              validator: (v) => v == null ? 'Select division' : null,
            ),
            const SizedBox(height: DsSpace.x4),
            _dropdown(
              label: 'District',
              icon: Icons.map_outlined,
              value: district,
              items: districts.map((d) => d['district'] as String).toList(),
              onChanged: (v) => _onDistrictSelected(v!),
              validator: (v) => v == null ? 'Select district' : null,
            ),
            if (shippingRate > 0) ...[
              const SizedBox(height: DsSpace.x4),
              _note(
                Icons.local_shipping_outlined,
                DsColor.info,
                'Delivery to $district costs ${taka(shippingRate)}.',
              ),
            ],
          ],
        ),
      ),
    );
  }

  Future<void> _openPinPicker() async {
    final lat = pinLat;
    final lng = pinLng;
    final picked = await Navigator.push<LatLng>(
      context,
      MaterialPageRoute(
        fullscreenDialog: true,
        builder: (_) => LocationPickerScreen(
          initial: lat != null && lng != null ? LatLng(lat, lng) : null,
        ),
      ),
    );
    if (picked == null || !mounted) return;
    setState(() {
      pinLat = double.parse(picked.latitude.toStringAsFixed(7));
      pinLng = double.parse(picked.longitude.toStringAsFixed(7));
    });
  }

  /// Optional "pin your exact location" control. Not part of validation:
  /// checkout works the same without a pin.
  Widget _pinControl() {
    final text = Theme.of(context).textTheme;
    final pinned = pinLat != null && pinLng != null;

    return DsPressable(
      onTap: _openPinPicker,
      depth: 0.02,
      semanticLabel: pinned
          ? 'Location pinned. Change'
          : 'Pin your exact location on a map',
      child: AnimatedContainer(
        duration: DsMotion.quick,
        padding: const EdgeInsets.all(DsSpace.x3),
        decoration: BoxDecoration(
          color: pinned ? DsColor.primarySoft : DsColor.canvas,
          borderRadius: DsRadius.rSm,
        ),
        child: Row(
          children: [
            Container(
              width: 40,
              height: 40,
              decoration: BoxDecoration(
                color: DsColor.surface,
                borderRadius: DsRadius.rXs,
              ),
              child: Icon(
                pinned ? Icons.where_to_vote_rounded : Icons.pin_drop_outlined,
                color: pinned ? DsColor.primary : DsColor.brand,
                size: 21,
              ),
            ),
            const SizedBox(width: DsSpace.x3),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    pinned ? 'Location pinned ✓' : 'Pin your exact location',
                    style: text.titleSmall,
                  ),
                  Text(
                    pinned
                        ? '${pinLat!.toStringAsFixed(5)}, ${pinLng!.toStringAsFixed(5)}'
                        : 'Optional · helps the rider find your door',
                    style: text.bodySmall?.copyWith(fontSize: 11.5),
                  ),
                ],
              ),
            ),
            if (pinned) ...[
              Text(
                'change',
                style: text.labelLarge?.copyWith(color: DsColor.primary),
              ),
              IconButton(
                tooltip: 'Remove pin',
                visualDensity: VisualDensity.compact,
                onPressed: () => setState(() {
                  pinLat = null;
                  pinLng = null;
                }),
                icon: const Icon(
                  Icons.close_rounded,
                  size: 18,
                  color: DsColor.inkSoft,
                ),
              ),
            ] else
              const Icon(
                Icons.chevron_right_rounded,
                color: DsColor.inkFaint,
              ),
          ],
        ),
      ),
    );
  }

  Widget _field({
    required String label,
    required String hint,
    required IconData icon,
    required String initial,
    required FormFieldSetter<String> onSaved,
    required FormFieldValidator<String> validator,
    TextInputType? keyboard,
    int lines = 1,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: DsSpace.x4),
      child: TextFormField(
        // Seeded from state, so stepping back to correct something does not
        // wipe what was already typed.
        initialValue: initial,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          prefixIcon: Icon(icon, size: DsSize.iconMd),
          alignLabelWithHint: lines > 1,
        ),
        style: Theme.of(context).textTheme.bodyLarge,
        keyboardType: keyboard,
        maxLines: lines,
        onSaved: onSaved,
        validator: validator,
      ),
    );
  }

  Widget _dropdown({
    required String label,
    required IconData icon,
    required String? value,
    required List<String> items,
    required ValueChanged<String?> onChanged,
    required FormFieldValidator<String> validator,
  }) {
    return DropdownButtonFormField<String>(
      initialValue: value,
      isExpanded: true,
      decoration: InputDecoration(
        labelText: label,
        prefixIcon: Icon(icon, size: DsSize.iconMd),
      ),
      items: items
          .map(
            (d) => DropdownMenuItem(
              value: d,
              child: Text(d, overflow: TextOverflow.ellipsis),
            ),
          )
          .toList(),
      onChanged: onChanged,
      validator: validator,
    );
  }

  // ── Step 2 ───────────────────────────────────────────────────────────────

  Widget _stepPayment(
    double subtotal,
    double rate,
    double discount,
    double total,
  ) {
    final text = Theme.of(context).textTheme;

    return Column(
      children: [
        DsSurface(
          padding: const EdgeInsets.all(DsSpace.x5),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('How would you like to pay?', style: text.titleLarge),
              const SizedBox(height: DsSpace.x4),
              _PayOption(
                icon: Icons.delivery_dining_rounded,
                title: 'Cash on delivery',
                subtitle: 'Pay the rider when your order arrives',
                selected: paymentMethod == 'cash_on_delivery',
                onTap: () => setState(() => paymentMethod = 'cash_on_delivery'),
              ),
              const SizedBox(height: DsSpace.x3),
              _note(
                Icons.info_outline_rounded,
                DsColor.inkSoft,
                'Cash on delivery is the only method available right now.',
              ),
            ],
          ),
        ),
        const SizedBox(height: DsSpace.x4),
        _summaryCard(subtotal, rate, discount, total),
      ],
    );
  }

  // ── Step 3 ───────────────────────────────────────────────────────────────

  Widget _stepReview(
    CartProvider cart,
    double subtotal,
    double rate,
    double discount,
    double total,
  ) {
    final text = Theme.of(context).textTheme;

    return Column(
      children: [
        DsSurface(
          padding: const EdgeInsets.all(DsSpace.x5),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text('Delivering to', style: text.titleLarge),
                  ),
                  TextButton(
                    onPressed: () => setState(() => currentStep = 1),
                    child: const Text('Edit'),
                  ),
                ],
              ),
              const SizedBox(height: DsSpace.x2),
              _kv('Name', fullName),
              _kv('Phone', phone),
              _kv('Address', address),
              _kv('Area', [district, division].whereType<String>().join(', ')),
              if (pinLat != null && pinLng != null)
                _kv('Map pin', 'Location pinned ✓'),
            ],
          ),
        ),
        const SizedBox(height: DsSpace.x4),
        DsSurface(
          padding: const EdgeInsets.all(DsSpace.x5),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text(
                '${cart.totalItems} item${cart.totalItems == 1 ? '' : 's'}',
                style: text.titleLarge,
              ),
              const SizedBox(height: DsSpace.x3),
              for (final item in cart.items.values)
                Padding(
                  padding: const EdgeInsets.symmetric(vertical: DsSpace.x2),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Container(
                        margin: const EdgeInsets.only(top: 2),
                        padding: const EdgeInsets.symmetric(
                          horizontal: 6,
                          vertical: 2,
                        ),
                        decoration: BoxDecoration(
                          color: DsColor.sunken,
                          borderRadius: DsRadius.rXs,
                        ),
                        child: Text(
                          '${item.quantity}×',
                          style: DsType.numeral(10),
                        ),
                      ),
                      const SizedBox(width: DsSpace.x3),
                      Expanded(child: Text(item.name, style: text.bodyLarge)),
                      const SizedBox(width: DsSpace.x2),
                      Text(
                        taka(item.price * item.quantity, exact: true),
                        style: DsType.numeral(13),
                      ),
                    ],
                  ),
                ),
            ],
          ),
        ),
        const SizedBox(height: DsSpace.x4),
        _summaryCard(subtotal, rate, discount, total),
      ],
    );
  }

  Widget _summaryCard(
    double subtotal,
    double rate,
    double discount,
    double total,
  ) {
    final text = Theme.of(context).textTheme;

    return DsSurface(
      padding: const EdgeInsets.all(DsSpace.x5),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text('Order summary', style: text.titleLarge),
          const SizedBox(height: DsSpace.x4),
          _money('Subtotal', taka(subtotal, exact: true)),
          _money('Shipping', taka(shippingRate, exact: true)),
          _money(
            'Discount (${rate.toStringAsFixed(0)}%)',
            '-${taka(discount, exact: true)}',
            tint: DsColor.success,
          ),
          const Padding(
            padding: EdgeInsets.symmetric(vertical: DsSpace.x3),
            child: Divider(height: 1),
          ),
          Row(
            children: [
              Expanded(child: Text('Total', style: text.titleMedium)),
              Text(
                taka(total, exact: true),
                style: DsType.numeral(22, color: DsColor.primary),
              ),
            ],
          ),
        ],
      ),
    );
  }

  Widget _money(String label, String value, {Color? tint}) {
    final text = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: DsSpace.x1 + 1),
      child: Row(
        children: [
          Expanded(child: Text(label, style: text.bodyMedium)),
          Text(value, style: DsType.numeral(14, color: tint ?? DsColor.ink)),
        ],
      ),
    );
  }

  Widget _kv(String label, String? value) {
    final text = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: DsSpace.x2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(width: 84, child: Text(label, style: text.bodyMedium)),
          const SizedBox(width: DsSpace.x3),
          Expanded(
            child: Text(
              (value == null || value.isEmpty) ? '—' : value,
              style: text.titleSmall,
            ),
          ),
        ],
      ),
    );
  }

  Widget _note(IconData icon, Color tint, String message) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(DsSpace.x3),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: 0.08),
        borderRadius: DsRadius.rSm,
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Icon(icon, size: 15, color: tint),
          const SizedBox(width: DsSpace.x2),
          Expanded(
            child: Text(message, style: Theme.of(context).textTheme.bodySmall),
          ),
        ],
      ),
    );
  }

  // ── Step 4: confirmation ─────────────────────────────────────────────────

  Widget _confirmation() {
    final text = Theme.of(context).textTheme;
    final sale = checkoutResponse?.sale;
    final tracking = checkoutResponse?.tracking;

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        child: DsPage(
          child: ListView(
            padding: EdgeInsets.fromLTRB(
              context.gutter,
              DsSpace.x10,
              context.gutter,
              DsSpace.x8,
            ),
            children: [
              DsEnter(
                child: Center(
                  child: Container(
                    width: 116,
                    height: 116,
                    decoration: const BoxDecoration(
                      shape: BoxShape.circle,
                      color: DsColor.successSoft,
                    ),
                    child: const Icon(
                      Icons.check_rounded,
                      size: 56,
                      color: DsColor.success,
                    ),
                  ),
                ),
              ),
              const SizedBox(height: DsSpace.x6),
              DsEnter(
                delay: const Duration(milliseconds: 80),
                child: Text(
                  'Order placed',
                  textAlign: TextAlign.center,
                  style: text.displaySmall,
                ),
              ),
              const SizedBox(height: DsSpace.x2),
              DsEnter(
                delay: const Duration(milliseconds: 130),
                child: Text(
                  'Thank you. We will call shortly to confirm delivery.',
                  textAlign: TextAlign.center,
                  style: text.bodyMedium,
                ),
              ),
              const SizedBox(height: DsSpace.x8),
              DsSurface(
                padding: const EdgeInsets.all(DsSpace.x5),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text('Order details', style: text.titleLarge),
                    const SizedBox(height: DsSpace.x3),
                    _kv('Reference', sale?['reference_no']?.toString()),
                    _kv('Tracking', tracking?['tracking_no']?.toString()),
                    _kv('Total', '৳${sale?['grand_total']}'),
                  ],
                ),
              ),

              /// New customers get generated credentials.
              if (checkoutResponse?.isNewCustomer == true) ...[
                const SizedBox(height: DsSpace.x4),
                DsSurface(
                  padding: const EdgeInsets.all(DsSpace.x5),
                  color: DsColor.warningSoft,
                  shadow: const [],
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text('Your login details', style: text.titleLarge),
                      const SizedBox(height: DsSpace.x3),
                      _kv(
                        'Email',
                        sale?['customer']?['email'] != null &&
                                sale!['customer']!['email']
                                    .toString()
                                    .isNotEmpty
                            ? sale['customer']!['email'].toString()
                            : 'Contact support to add your email',
                      ),
                      _kv(
                        'Password',
                        checkoutResponse?.temporaryPassword?.toString(),
                      ),
                      const SizedBox(height: DsSpace.x2),
                      _note(
                        Icons.lock_outline_rounded,
                        DsColor.warning,
                        'Please sign in and change your password.',
                      ),
                    ],
                  ),
                ),
              ],

              const SizedBox(height: DsSpace.x8),
              ElevatedButton.icon(
                onPressed: () => Navigator.pushAndRemoveUntil(
                  context,
                  MaterialPageRoute(builder: (_) => const MainScreen()),
                  (route) => false,
                ),
                icon: const Icon(Icons.home_outlined, size: 18),
                label: const Text('Back to home'),
              ),
            ],
          ),
        ),
      ),
    );
  }

  // ── Sticky action bar ────────────────────────────────────────────────────

  Widget _actionBar(double total) {
    final text = Theme.of(context).textTheme;

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
              if (currentStep > 1) ...[
                DsIconDisc(
                  icon: Icons.arrow_back_rounded,
                  tooltip: 'Previous step',
                  size: 48,
                  background: DsColor.onDark.withValues(alpha: 0.12),
                  foreground: Colors.white,
                  onTap: isLoading
                      ? null
                      : () => setState(() => currentStep -= 1),
                ),
                const SizedBox(width: DsSpace.x3),
              ] else
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
                      taka(total, exact: true),
                      maxLines: 1,
                      overflow: TextOverflow.ellipsis,
                      style: DsType.numeral(19, color: DsColor.onDark),
                    ),
                  ],
                ),
              ),
              const SizedBox(width: DsSpace.x3),
              DsPressable(
                onTap: isLoading ? null : _advance,
                depth: 0.05,
                semanticLabel: _actionLabel,
                child: Container(
                  height: 52,
                  constraints: const BoxConstraints(minWidth: 132),
                  padding: const EdgeInsets.symmetric(horizontal: DsSpace.x5),
                  alignment: Alignment.center,
                  decoration: BoxDecoration(
                    color: isLoading
                        ? DsColor.onDark.withValues(alpha: 0.2)
                        : DsColor.primaryBright,
                    borderRadius: DsRadius.pill,
                  ),
                  child: isLoading
                      ? const SizedBox(
                          width: 20,
                          height: 20,
                          child: CircularProgressIndicator(
                            strokeWidth: 2.4,
                            color: Colors.white,
                          ),
                        )
                      : Text(
                          _actionLabel,
                          style: text.labelLarge?.copyWith(color: Colors.white),
                        ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  String get _actionLabel => switch (currentStep) {
    1 => 'Continue',
    2 => 'Review',
    _ => 'Place order',
  };

  void _advance() {
    if (currentStep == 1) {
      if (_formKey.currentState!.validate()) {
        _formKey.currentState!.save();
        setState(() => currentStep = 2);
      }
    } else if (currentStep == 2) {
      setState(() => currentStep = 3);
    } else if (currentStep == 3) {
      _submitOrder(context);
    }
  }
}

class _PayOption extends StatelessWidget {
  final IconData icon;
  final String title;
  final String subtitle;
  final bool selected;
  final VoidCallback onTap;

  const _PayOption({
    required this.icon,
    required this.title,
    required this.subtitle,
    required this.selected,
    required this.onTap,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return DsPressable(
      onTap: onTap,
      depth: 0.02,
      semanticLabel: '$title. $subtitle',
      child: AnimatedContainer(
        duration: DsMotion.quick,
        padding: const EdgeInsets.all(DsSpace.x4),
        decoration: BoxDecoration(
          color: selected ? DsColor.primarySoft : DsColor.canvas,
          borderRadius: DsRadius.rSm,
        ),
        child: Row(
          children: [
            Container(
              width: 44,
              height: 44,
              decoration: BoxDecoration(
                color: DsColor.surface,
                borderRadius: DsRadius.rXs,
              ),
              child: Icon(icon, color: DsColor.primary, size: 21),
            ),
            const SizedBox(width: DsSpace.x3),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(title, style: text.titleSmall),
                  Text(
                    subtitle,
                    style: text.bodySmall?.copyWith(fontSize: 11.5),
                  ),
                ],
              ),
            ),
            Icon(
              selected
                  ? Icons.radio_button_checked_rounded
                  : Icons.radio_button_unchecked_rounded,
              color: selected ? DsColor.primary : DsColor.inkFaint,
              size: 20,
            ),
          ],
        ),
      ),
    );
  }
}
