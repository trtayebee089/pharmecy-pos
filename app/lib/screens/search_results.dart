import 'dart:async';
import 'dart:convert';
import 'package:redpharmabd_app/constants/api_endpoints.dart';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:shared_preferences/shared_preferences.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/components/motion.dart';
import 'package:redpharmabd_app/design/components/product_row.dart';
import 'package:redpharmabd_app/design/components/states.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';
import 'package:redpharmabd_app/main.dart';

/// ─────────────────────────────────────────────────────────────────────────
/// Search, rebuilt.
///
/// V2 makes search a destination rather than a strip at the top of the feed:
///
///  * The field is a **recessed pill in its own header**, autofocused when you
///    arrive with nothing typed, so the keyboard is already up.
///  * With no query it shows **recent searches as chips** plus live
///    suggestions from the API as you type — the blank screen does work.
///  * Results stream into the shared product row, so a search result and a
///    category result are the same object.
///
/// The lookup itself is untouched: same debounce, same endpoints, same
/// pagination, same history key.
/// ─────────────────────────────────────────────────────────────────────────
class SearchResultsScreen extends StatefulWidget {
  final String initialQuery;

  const SearchResultsScreen({super.key, this.initialQuery = ''});

  @override
  State<SearchResultsScreen> createState() => _SearchResultsScreenState();
}

class _SearchResultsScreenState extends State<SearchResultsScreen> {
  final TextEditingController _searchCtrl = TextEditingController();
  final FocusNode _focus = FocusNode();
  final ScrollController _scrollCtrl = ScrollController();

  List<dynamic> _results = [];
  List<String> _suggestions = [];
  bool _isLoading = false;
  bool _isLoadingMore = false;
  bool _hasMore = true;
  int _page = 1;
  String _lastQuery = '';

  /// Shared history key — the same one the old home field wrote to, so a
  /// user's existing history carries over.
  List<String> _recent = [];
  static const String _recentKey = 'recent_searches';

  Timer? _debouncer;

  @override
  void initState() {
    super.initState();
    _searchCtrl.text = widget.initialQuery;
    _lastQuery = widget.initialQuery;
    _loadRecent();

    // The suggestion sheet is gated on focus, so the build has to know when
    // focus changes.
    _focus.addListener(() {
      if (mounted) setState(() {});
    });

    if (widget.initialQuery.trim().isEmpty) {
      // Arriving empty means the shopper came here to type.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        if (mounted) _focus.requestFocus();
      });
    } else {
      _fetchResults(reset: true);
    }

    _scrollCtrl.addListener(() {
      if (_hasMore &&
          !_isLoadingMore &&
          _scrollCtrl.position.pixels >=
              _scrollCtrl.position.maxScrollExtent - 200) {
        _loadMore();
      }
    });
  }

  @override
  void dispose() {
    _debouncer?.cancel();
    _searchCtrl.dispose();
    _focus.dispose();
    _scrollCtrl.dispose();
    super.dispose();
  }

  // ── History ──────────────────────────────────────────────────────────────

  Future<void> _loadRecent() async {
    final prefs = await SharedPreferences.getInstance();
    if (!mounted) return;
    setState(() => _recent = prefs.getStringList(_recentKey) ?? []);
  }

  /// Records a performed search. This used to live in the home search field;
  /// it moved here so history is written wherever a search actually runs.
  Future<void> _saveRecent(String query) async {
    final q = query.trim();
    if (q.isEmpty) return;

    final prefs = await SharedPreferences.getInstance();
    final list = List<String>.from(prefs.getStringList(_recentKey) ?? []);
    list.remove(q);
    list.insert(0, q);
    if (list.length > 10) list.removeRange(10, list.length);
    await prefs.setStringList(_recentKey, list);
    if (!mounted) return;
    setState(() => _recent = list);
  }

  Future<void> _clearRecent() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_recentKey);
    if (!mounted) return;
    setState(() => _recent = []);
  }

  // ── Suggestions ──────────────────────────────────────────────────────────

  Future<void> _fetchSuggestions(String query) async {
    if (query.trim().isEmpty) {
      if (mounted) setState(() => _suggestions = []);
      return;
    }
    try {
      final resp = await http
          .get(Uri.parse('${ApiEndpoints.suggestionList}?query=$query'))
          .timeout(const Duration(seconds: 8));
      if (resp.statusCode == 200) {
        final data = json.decode(resp.body);
        if (data is List && mounted) {
          setState(() {
            _suggestions = List<String>.from(
              data.map((e) => e['name'].toString()),
            ).take(8).toList();
          });
        }
      }
    } catch (e) {
      debugPrint('Error fetching suggestions: $e');
    }
  }

  // ── Results (unchanged) ──────────────────────────────────────────────────

  void _onChanged(String v) {
    setState(() {}); // refresh clear button
    _debouncer?.cancel();
    _debouncer = Timer(const Duration(milliseconds: 400), () {
      final q = v.trim();
      _fetchSuggestions(q);
      if (q != _lastQuery) {
        _lastQuery = q;
        _fetchResults(reset: true);
      }
    });
  }

  Future<void> _fetchResults({bool reset = false}) async {
    final q = _lastQuery.trim();
    if (q.isEmpty) {
      setState(() {
        _results = [];
        _hasMore = false;
      });
      return;
    }

    if (reset) {
      setState(() {
        _isLoading = true;
        _results = [];
        _page = 1;
        _hasMore = true;
      });
    }

    try {
      final encoded = Uri.encodeComponent(q);
      final uri = Uri.parse(
        '${ApiEndpoints.searchProducts}/$encoded?page=$_page',
      );

      final resp = await http.get(uri).timeout(const Duration(seconds: 15));
      if (resp.statusCode == 200) {
        final body = json.decode(resp.body);

        // The API might return either a List or { data: [...] }
        final List<dynamic> items = body is List
            ? body
            : (body is Map && body['data'] is List)
            ? List<dynamic>.from(body['data'])
            : <dynamic>[];

        setState(() {
          _results.addAll(items);
          _hasMore = items.length >= 20;
        });
        if (reset) _saveRecent(q);
      } else {
        _showError('Search failed (${resp.statusCode})');
        setState(() => _hasMore = false);
      }
    } on TimeoutException {
      _showError('Request timed out. Please try again.');
    } catch (e) {
      _showError('Something went wrong', detail: '$e');
      setState(() => _hasMore = false);
    } finally {
      if (mounted) {
        setState(() {
          _isLoading = false;
          _isLoadingMore = false;
        });
      }
    }
  }

  Future<void> _loadMore() async {
    if (!_hasMore || _isLoading || _isLoadingMore) return;
    setState(() => _isLoadingMore = true);
    _page += 1;
    await _fetchResults(reset: false);
  }

  void _runSearch(String q) {
    _searchCtrl.text = q;
    _lastQuery = q;
    _suggestions = [];
    _fetchResults(reset: true);
    _focus.unfocus();
  }

  void _showError(String msg, {String? detail}) {
    if (!mounted) return;
    DsToast.error(context, msg, detail: detail);
  }

  // ── Composition ──────────────────────────────────────────────────────────

  @override
  Widget build(BuildContext context) {
    final showSuggestions =
        _focus.hasFocus && _suggestions.isNotEmpty && _lastQuery.isNotEmpty;

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        child: DsPage(
          child: Column(
            children: [
              _header(),
              Expanded(
                child: showSuggestions
                    ? _suggestionList()
                    : _isLoading
                    ? const DsProductRowSkeleton()
                    : _results.isNotEmpty
                    ? _resultList()
                    : _restingState(),
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _header() {
    return Padding(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x3,
        context.gutter,
        DsSpace.x3,
      ),
      child: Row(
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
            child: Container(
              height: DsSize.control,
              padding: const EdgeInsets.symmetric(horizontal: DsSpace.x4),
              decoration: BoxDecoration(
                color: DsColor.surface,
                borderRadius: DsRadius.pill,
                boxShadow: DsShadow.low,
              ),
              child: Row(
                children: [
                  const Icon(
                    Icons.search_rounded,
                    size: DsSize.iconMd,
                    color: DsColor.inkSoft,
                  ),
                  const SizedBox(width: DsSpace.x2),
                  Expanded(
                    child: TextField(
                      controller: _searchCtrl,
                      focusNode: _focus,
                      textInputAction: TextInputAction.search,
                      style: Theme.of(context).textTheme.bodyLarge,
                      decoration: const InputDecoration(
                        hintText: 'Search medicines, brands',
                        filled: false,
                        isCollapsed: true,
                        border: InputBorder.none,
                        enabledBorder: InputBorder.none,
                        focusedBorder: InputBorder.none,
                        contentPadding: EdgeInsets.zero,
                      ),
                      onChanged: _onChanged,
                      onSubmitted: (v) {
                        final q = v.trim();
                        if (q.isNotEmpty) _runSearch(q);
                      },
                    ),
                  ),
                  if (_searchCtrl.text.isNotEmpty)
                    IconButton(
                      tooltip: 'Clear',
                      visualDensity: VisualDensity.compact,
                      icon: const Icon(Icons.close_rounded, size: 18),
                      onPressed: () {
                        setState(() {
                          _searchCtrl.clear();
                          _lastQuery = '';
                          _results = [];
                          _suggestions = [];
                          _hasMore = false;
                        });
                        _focus.requestFocus();
                      },
                    ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _suggestionList() {
    return ListView.separated(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x2,
        context.gutter,
        DsSpace.x8,
      ),
      itemCount: _suggestions.length,
      separatorBuilder: (_, _) => const Divider(height: 1),
      itemBuilder: (context, i) => ListTile(
        contentPadding: const EdgeInsets.symmetric(horizontal: DsSpace.x2),
        leading: const Icon(
          Icons.search_rounded,
          size: 18,
          color: DsColor.inkSoft,
        ),
        title: Text(
          _suggestions[i],
          maxLines: 1,
          overflow: TextOverflow.ellipsis,
          style: Theme.of(context).textTheme.titleSmall,
        ),
        trailing: const Icon(
          Icons.north_west_rounded,
          size: 15,
          color: DsColor.inkFaint,
        ),
        onTap: () => _runSearch(_suggestions[i]),
      ),
    );
  }

  Widget _resultList() {
    return RefreshIndicator(
      onRefresh: () => _fetchResults(reset: true),
      color: DsColor.primary,
      child: CustomScrollView(
        controller: _scrollCtrl,
        physics: const AlwaysScrollableScrollPhysics(
          parent: BouncingScrollPhysics(),
        ),
        slivers: [
          SliverToBoxAdapter(
            child: Padding(
              padding: EdgeInsets.fromLTRB(
                context.gutter,
                DsSpace.x2,
                context.gutter,
                DsSpace.x4,
              ),
              child: Text(
                '${_results.length} result${_results.length == 1 ? '' : 's'} for “$_lastQuery”',
                style: Theme.of(context).textTheme.bodyMedium,
              ),
            ),
          ),
          SliverPadding(
            padding: EdgeInsets.symmetric(horizontal: context.gutter),
            sliver: SliverList.separated(
              itemCount: _results.length,
              separatorBuilder: (_, _) => const SizedBox(height: DsSpace.x3),
              itemBuilder: (context, i) => DsProductRow(
                product: _results[i] as Map<String, dynamic>,
                heroPrefix: 'search',
              ),
            ),
          ),
          SliverToBoxAdapter(
            child: Padding(
              padding: const EdgeInsets.symmetric(vertical: DsSpace.x8),
              child: Center(
                child: _isLoadingMore
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2.4),
                      )
                    : _hasMore
                    ? const SizedBox.shrink()
                    : Text(
                        'End of results',
                        style: Theme.of(context).textTheme.bodySmall,
                      ),
              ),
            ),
          ),
        ],
      ),
    );
  }

  /// Shown when nothing has been searched, or a search found nothing.
  Widget _restingState() {
    if (_lastQuery.isNotEmpty) {
      return DsState.empty(
        icon: Icons.search_off_rounded,
        title: 'No results for “$_lastQuery”',
        message: 'Check the spelling, or try a shorter, more general keyword.',
        actionLabel: 'Browse categories',
        onAction: () {
          Navigator.pop(context);
          mainScreenKey.currentState?.switchToTab(1);
        },
      );
    }

    if (_recent.isEmpty) {
      return const DsState.empty(
        icon: Icons.search_rounded,
        title: 'Search the pharmacy',
        message:
            'Find medicines, brands and health essentials by name. Your recent searches will appear here.',
      );
    }

    final text = Theme.of(context).textTheme;

    return ListView(
      padding: EdgeInsets.fromLTRB(
        context.gutter,
        DsSpace.x4,
        context.gutter,
        DsSpace.x8,
      ),
      children: [
        Row(
          children: [
            Expanded(
              child: Text(
                'RECENT',
                style: DsType.eyebrow(color: DsColor.primary),
              ),
            ),
            TextButton(onPressed: _clearRecent, child: const Text('Clear')),
          ],
        ),
        const SizedBox(height: DsSpace.x2),
        Wrap(
          spacing: DsSpace.x2,
          runSpacing: DsSpace.x2,
          children: [
            for (var i = 0; i < _recent.length; i++)
              DsEnter.staggered(
                index: i,
                offset: 8,
                child: DsPressable(
                  onTap: () => _runSearch(_recent[i]),
                  depth: 0.05,
                  semanticLabel: 'Search ${_recent[i]}',
                  child: Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: DsSpace.x4,
                      vertical: DsSpace.x3,
                    ),
                    decoration: BoxDecoration(
                      color: DsColor.surface,
                      borderRadius: DsRadius.pill,
                      boxShadow: DsShadow.low,
                    ),
                    child: Row(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        const Icon(
                          Icons.history_rounded,
                          size: 14,
                          color: DsColor.inkFaint,
                        ),
                        const SizedBox(width: DsSpace.x2),
                        Text(_recent[i], style: text.titleSmall),
                      ],
                    ),
                  ),
                ),
              ),
          ],
        ),
      ],
    );
  }
}
