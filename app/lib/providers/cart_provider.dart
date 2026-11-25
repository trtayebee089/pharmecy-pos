import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';
import 'package:redpharmabd_app/models/cart_item.dart';

class CartProvider with ChangeNotifier {
  final Map<String, CartItem> _items = {};
  double _rewardPoints = 0;

  Map<String, CartItem> get items => _items;
  double get rewardPoints => _rewardPoints;

  int get totalItems =>
      _items.values.fold(0, (sum, item) => sum + item.quantity);

  int get totalUniqueItems => _items.length;

  double get totalAmount =>
      _items.values.fold(0, (sum, item) => sum + item.price * item.quantity);

  CartProvider() {
    loadCartFromPrefs();
  }

  /// Reads the available stock out of a product payload.
  ///
  /// The API expresses stock under three different keys depending on which
  /// endpoint the map came from: the detail endpoint is read as `stock_qty` by
  /// the caller that builds an explicit payload, while list, search, featured
  /// and category responses carry `available_qty` (stock minus reserved) and
  /// `qty` (raw stock).
  ///
  /// Previously only `stock_qty` was consulted, so anything added straight
  /// from a product card arrived with a null stock and was never capped. This
  /// resolves all three from data the app already has — no extra requests.
  static int? resolveStock(Map<String, dynamic> product) {
    for (final key in const ['stock_qty', 'available_qty', 'qty']) {
      final raw = product[key];
      if (raw == null) continue;
      final value = raw is num ? raw.toInt() : int.tryParse(raw.toString());
      if (value != null) return value;
    }
    return null;
  }

  /// Add a product to cart, respecting its available stock.
  /// Returns `true` if the quantity was capped by stock.
  bool addItem(Map<String, dynamic> product) {
    final id = product['id'].toString();
    final int qtyToAdd = product['quantity'] is int
        ? product['quantity'] as int
        : (int.tryParse(product['quantity']?.toString() ?? '1') ?? 1);

    final int? stockQty = resolveStock(product);

    bool capped = false;

    if (_items.containsKey(id)) {
      final existing = _items[id]!;
      final effectiveStock = stockQty ?? existing.stockQty;
      final newQty = existing.quantity + qtyToAdd;
      if (effectiveStock != null && newQty > effectiveStock) {
        _items[id]!.quantity = effectiveStock;
        capped = true;
      } else {
        _items[id]!.quantity = newQty;
      }
    } else {
      int finalQty = qtyToAdd;
      if (stockQty != null && finalQty > stockQty) {
        finalQty = stockQty;
        capped = true;
      }
      _items[id] = CartItem(
        id: id,
        name: product['name'] ?? 'Unknown',
        price: double.tryParse(product['price'].toString()) ?? 0,
        imageUrl: product['image'] ?? 'https://placehold.co/400',
        brand: product['brand'] != null && product['brand'] is Map
            ? product['brand']['title'] ?? 'No Brand'
            : product['brand']?.toString(),
        unit: product['unit'] != null && product['unit'] is Map
            ? product['unit']['unit_code'] ?? 'unit'
            : product['unit']?.toString(),
        quantity: finalQty,
        stockQty: stockQty,
      );
    }

    _saveCartToPrefs();
    notifyListeners();
    return capped;
  }

  void removeItem(String id) {
    _items.remove(id);
    _saveCartToPrefs();
    notifyListeners();
  }

  void clearCart() {
    _items.clear();
    _rewardPoints = 0;
    _saveCartToPrefs();
    notifyListeners();
  }

  void setRewardPoints(double points) {
    _rewardPoints = points < 0 ? 0 : points;
    _saveCartToPrefs();
    notifyListeners();
  }

  void decreaseQuantity(String id) {
    if (_items.containsKey(id) && _items[id]!.quantity > 1) {
      _items[id]!.quantity -= 1;
    } else {
      _items.remove(id);
    }
    _saveCartToPrefs();
    notifyListeners();
  }

  /// Update quantity, capped at stock if known.
  /// Returns `true` if the value was capped.
  bool updateQuantity(String id, int quantity) {
    if (!_items.containsKey(id)) return false;
    final stockQty = _items[id]!.stockQty;
    bool capped = false;
    int safeQty = quantity < 1 ? 1 : quantity;
    if (stockQty != null && safeQty > stockQty) {
      safeQty = stockQty;
      capped = true;
    }
    if (safeQty > 0) {
      _items[id]!.quantity = safeQty;
    } else {
      _items.remove(id);
    }
    _saveCartToPrefs();
    notifyListeners();
    return capped;
  }

  Future<void> _saveCartToPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    final cartJson = _items.map(
      (key, value) => MapEntry(key, {
        'id': value.id,
        'name': value.name,
        'price': value.price,
        'imageUrl': value.imageUrl,
        'brand': value.brand,
        'unit': value.unit,
        'quantity': value.quantity,
        'stock_qty': value.stockQty,
      }),
    );
    prefs.setString('cart', jsonEncode(cartJson));
    prefs.setDouble('cart_reward_points', _rewardPoints);
  }

  Future<void> loadCartFromPrefs() async {
    final prefs = await SharedPreferences.getInstance();
    final cartString = prefs.getString('cart');
    _rewardPoints = prefs.getDouble('cart_reward_points') ?? 0;
    if (cartString != null) {
      final Map<String, dynamic> decoded = jsonDecode(cartString);
      decoded.forEach((key, value) {
        _items[key] = CartItem(
          id: value['id'],
          name: value['name'],
          price: (value['price'] as num).toDouble(),
          imageUrl: value['imageUrl'],
          brand: value['brand'],
          unit: value['unit'],
          quantity: value['quantity'],
          stockQty: value['stock_qty'] != null
              ? (value['stock_qty'] as num).toInt()
              : null,
        );
      });
      notifyListeners();
    }
  }
}
