/// How much of a product is on hand, as a presentation concern.
enum StockLevel {
  /// Stock is not reported by this endpoint.
  unknown,
  out,
  low,
  inStock,
}

/// Read-only view over a product JSON map.
///
/// Pure data reading: it does not fetch, mutate or decide anything, and it
/// deliberately carries no colours or widgets so the same view-model can drive
/// any presentation. The raw map still travels to the cart untouched.
class ProductDisplay {
  final Map<String, dynamic> raw;

  const ProductDisplay(this.raw);

  String get name => (raw['name'] ?? 'Unknown').toString();

  String get slug => (raw['slug'] ?? '').toString();

  /// Brand arrives as a nested object on most endpoints and a bare string on
  /// others.
  String get brand {
    final b = raw['brand'];
    if (b is Map) return (b['title'] ?? 'No Brand').toString();
    final s = b?.toString();
    return (s == null || s.isEmpty) ? 'No Brand' : s;
  }

  String get categoryName => (raw['category']?['name'] ?? '').toString();

  /// Falls back to category artwork, then to empty — which renders the in-app
  /// placeholder rather than pulling a remote placeholder image.
  String get imageUrl {
    final product = (raw['image'] ?? '').toString();
    if (_isReal(product)) return product;
    final category = (raw['category']?['image'] ?? '').toString();
    if (_isReal(category)) return category;
    return '';
  }

  static bool _isReal(String url) =>
      url.isNotEmpty &&
      !url.contains('placehold.co') &&
      !url.contains('no-image.png') &&
      !url.contains('default-medicine-image');

  double get price => _toDouble(raw['price']) ?? 0;

  /// The struck-through "was" price.
  ///
  /// Surfaced only when `promotion_price` is *higher* than `price`, i.e. the
  /// amount being charged is already the reduced one. The cart charges
  /// `price`, so advertising any other figure would promise something checkout
  /// will not honour.
  double? get wasPrice {
    final promo = _toDouble(raw['promotion_price']);
    if (promo == null || promo <= price || price <= 0) return null;
    return promo;
  }

  int? get discountPercent {
    final was = wasPrice;
    if (was == null) return null;
    final pct = ((was - price) / was * 100).round();
    return pct > 0 ? pct : null;
  }

  /// Units on hand. `available_qty` accounts for reserved stock and wins;
  /// `qty` is the raw figure; `stock_qty` appears on explicit cart payloads.
  int? get stockQty {
    for (final key in const ['available_qty', 'qty', 'stock_qty']) {
      final raw_ = raw[key];
      if (raw_ == null) continue;
      final v = _toInt(raw_);
      if (v != null) return v;
    }
    return null;
  }

  StockLevel get stockLevel {
    final s = stockQty;
    if (s == null) return StockLevel.unknown;
    if (s <= 0) return StockLevel.out;
    if (s <= 5) return StockLevel.low;
    return StockLevel.inStock;
  }

  /// Short human label for the stock state, or null when there is nothing
  /// worth saying.
  String? get stockLabel {
    switch (stockLevel) {
      case StockLevel.unknown:
        return null;
      case StockLevel.out:
        return 'Out of stock';
      case StockLevel.low:
        return 'Only $stockQty left';
      case StockLevel.inStock:
        return 'In stock';
    }
  }

  bool get isBuyable => stockLevel != StockLevel.out;

  String get unit {
    final u = raw['unit'];
    if (u is Map) return (u['unit_code'] ?? 'unit').toString();
    final s = u?.toString();
    return (s == null || s.isEmpty) ? 'unit' : s;
  }

  static double? _toDouble(dynamic v) {
    if (v == null) return null;
    if (v is num) return v.toDouble();
    return double.tryParse(v.toString());
  }

  static int? _toInt(dynamic v) {
    if (v == null) return null;
    if (v is num) return v.toInt();
    return int.tryParse(v.toString());
  }
}
