/// Where the app talks to.
///
/// The customer app (`app/`) uses `https://api.redpharmabd.com/api`; the rider
/// app uses the same host by default and can be pointed elsewhere at build
/// time:
///
/// ```
/// flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api
/// ```
///
/// `API_BASE_URL` is the Laravel `/api` root (no trailing slash); the rider
/// endpoints live under `/rider/v1`.
class ApiConfig {
  const ApiConfig._();

  static const String _rawBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://api.redpharmabd.com/api',
  );

  /// The `/api` root without a trailing slash.
  static String get baseUrl => _rawBaseUrl.endsWith('/')
      ? _rawBaseUrl.substring(0, _rawBaseUrl.length - 1)
      : _rawBaseUrl;

  /// Root of the rider API: `{baseUrl}/rider/v1`.
  static String get riderBase => '$baseUrl/rider/v1';

  /// Sent with the login so staff can see which device signed in.
  static const String deviceName = 'redpharma-rider-app';
}
