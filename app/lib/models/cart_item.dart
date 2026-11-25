class CartItem {
  final String id;
  final String name;
  final double price;
  final String imageUrl;
  final String? brand;
  final String? unit;
  int quantity;

  /// Available stock for this product. Null means "unknown / unlimited".
  final int? stockQty;

  CartItem({
    required this.id,
    required this.name,
    required this.price,
    required this.imageUrl,
    required this.brand,
    required this.unit,
    this.quantity = 1,
    this.stockQty,
  });

  @override
  String toString() {
    return 'CartItem(id: $id, name: $name, price: $price, quantity: $quantity, brand: $brand, unit: $unit, stockQty: $stockQty)';
  }
}
