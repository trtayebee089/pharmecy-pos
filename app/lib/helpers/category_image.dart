import '../constants/api_endpoints.dart';

/// Prefer the verified category asset host. DsImage still retries the POS host
/// if the API host cannot return the image.
String categoryImageUrl(Object? value) {
  final raw = value?.toString().trim() ?? '';
  if (raw.isEmpty) return '';
  final image = Uri.tryParse(raw);
  if (image == null) return '';
  final api = Uri.parse(ApiEndpoints.baseUrl);
  if (image.hasAuthority) {
    if (image.host == 'pos.redpharmabd.com' &&
        (image.path.startsWith('/images/category/') ||
            image.path == '/images/no-image.png')) {
      return image.replace(scheme: api.scheme, host: api.host).toString();
    }
    return image.hasScheme ? raw : image.replace(scheme: api.scheme).toString();
  }
  final path = image.path.startsWith('/')
      ? image.path
      : image.path.startsWith('images/')
      ? '/${image.path}'
      : '/images/category/${image.path}';
  return api
      .replace(
        path: path,
        query: image.hasQuery ? image.query : null,
        fragment: image.hasFragment ? image.fragment : null,
      )
      .toString();
}
