/// Try the supplied RedPharma image URL first, then the identical path on the
/// other asset host. Third-party image URLs keep their original destination.
List<String> imageUrlsWithFallback(String value) {
  final raw = value.trim();
  if (raw.isEmpty) return const [];
  final uri = Uri.tryParse(raw);
  if (uri == null || !['http', 'https'].contains(uri.scheme)) return [raw];
  final alternate = switch (uri.host) {
    'pos.redpharmabd.com' => 'api.redpharmabd.com',
    'api.redpharmabd.com' => 'pos.redpharmabd.com',
    _ => null,
  };
  return [raw, if (alternate != null) uri.replace(host: alternate).toString()];
}
