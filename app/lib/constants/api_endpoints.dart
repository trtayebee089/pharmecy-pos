class ApiEndpoints {
  static const String baseUrl = 'https://api.redpharmabd.com/api';

  // public routes
  static const String rewardPointTiers = '$baseUrl/reward-point-tiers';
  static const String shippingZones = '$baseUrl/shipping-zones';
  static const String categories = '$baseUrl/categories';

  static const String featuredProducts = '$baseUrl/products/featured';
  static const String bestSellingProducts = '$baseUrl/products/best-selling';
  static const String suggestionList =
      '$baseUrl/products/suggestions'; // append ?query=$query
  static const String searchProducts = '$baseUrl/search';
  static const String productDetails = '$baseUrl/products';
  static const String checkout = '$baseUrl/checkout';

  static const String banners = '$baseUrl/banners';
  static const String privacyPolicy = '$baseUrl/pages/privacy-and-policy';
  static const String refundPolicy = '$baseUrl/pages/refund-and-returns';

  // sanctum routes
  static const String login = '$baseUrl/customer/login';
  static const String register = '$baseUrl/customer/register';
  static const String userProfile = '$baseUrl/customer/profile';
  static const String userOrders = '$baseUrl/customer/orders';
  static const String orderDetail =
      '$baseUrl/customer/orders'; // append /{order_id}
  static const String updateProfile = '$baseUrl/customer/profile/update';
  static const String changePassword =
      '$baseUrl/customer/profile/update-password';
  static const String forgetPassword = '$baseUrl/customer/forget-password';
  static const String verifyOTP = '$baseUrl/customer/verify-otp';
  static const String membership = '$baseUrl/v1/customer/membership';
  static const String membershipHistory = '$membership/history';
  static const String membershipRenew = '$membership/renew';
  static const String reward = '$baseUrl/reward';
  static const String rewardProfile = '$reward/profile';
  static const String rewardTransactions = '$reward/transactions';
  static const String rewardRedeem = '$reward/redeem';
  static const String reorder = '$baseUrl/orders'; // append /{order_id}/reorder

  // Live delivery (v1 envelope {success, message, data}); poll every data.poll_seconds.
  static const String v1 = '$baseUrl/v1';

  /// Owner view (bearer): store, the customer's pin, rider and arrival time.
  static String orderLive(String number) =>
      '$v1/orders/${Uri.encodeComponent(number)}/live';

  /// Public view by tracking code: never includes the customer's pin.
  static String trackingLive(String code) =>
      '$v1/tracking/${Uri.encodeComponent(code)}/live';
}
