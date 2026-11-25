import 'dart:convert';
import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:redpharmabd_app/constants/api_endpoints.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/repositories/recently_viewed_repository.dart';
import 'package:redpharmabd_app/screens/product_detail.dart';

class RecentlyViewedScreen extends StatefulWidget {
  const RecentlyViewedScreen({super.key});
  @override
  State<RecentlyViewedScreen> createState() => _RecentlyViewedScreenState();
}

class _RecentlyViewedScreenState extends State<RecentlyViewedScreen> {
  final repository = RecentlyViewedRepository();
  List<Map<String, dynamic>> products = [];
  bool loading = true;
  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    final entries = await repository.list();
    final loaded = <Map<String, dynamic>>[];
    for (final entry in entries) {
      try {
        final response = await http.get(
          Uri.parse('${ApiEndpoints.productDetails}/${entry['slug']}'),
        );
        if (response.statusCode == 200) {
          final body = jsonDecode(response.body);
          if (body['data'] is Map) {
            loaded.add(Map<String, dynamic>.from(body['data']));
          }
        }
      } catch (_) {}
    }
    if (mounted) {
      setState(() {
        products = loaded;
        loading = false;
      });
    }
  }

  Future<void> _clear() async {
    await repository.clear();
    if (mounted) setState(() => products = []);
  }

  @override
  Widget build(BuildContext context) => Scaffold(
    backgroundColor: DsColor.canvas,
    appBar: AppBar(
      title: const Text('Recently Viewed'),
      actions: [
        if (products.isNotEmpty)
          TextButton(onPressed: _clear, child: const Text('Clear')),
      ],
    ),
    body: loading
        ? const Center(child: CircularProgressIndicator())
        : products.isEmpty
        ? const Center(child: Text('Products you view will appear here.'))
        : ListView.separated(
            padding: const EdgeInsets.all(DsSpace.x4),
            itemCount: products.length,
            separatorBuilder: (_, _) => const SizedBox(height: 10),
            itemBuilder: (context, index) {
              final product = products[index];
              return Card(
                child: ListTile(
                  title: Text('${product['name'] ?? 'Medicine'}'),
                  subtitle: Text('BDT ${product['price'] ?? 0}'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => Navigator.push(
                    context,
                    MaterialPageRoute(
                      builder: (_) => ProductDetailScreen(
                        productSlug: '${product['slug'] ?? product['id']}',
                      ),
                    ),
                  ),
                ),
              );
            },
          ),
  );
}
