import 'dart:async';
import 'package:cached_network_image/cached_network_image.dart';
import 'package:flutter/material.dart';
import '../../helpers/image_urls.dart';

import '../tokens.dart';
import 'skeleton.dart';

/// V2 remote image.
///
/// Disk-cached, decode-capped to the size actually painted, with a shimmering
/// placeholder and a branded fallback. Product photography in this catalogue is
/// frequently a transparent cut-out, so the default backdrop is a soft tinted
/// plate rather than white — cut-outs then read as objects sitting on a
/// surface instead of floating in a void.
class DsImage extends StatefulWidget {
  final String url;
  final BoxFit fit;
  final double? width;
  final double? height;
  final BorderRadius? radius;
  final Color backdrop;

  const DsImage({
    super.key,
    required this.url,
    this.fit = BoxFit.cover,
    this.width,
    this.height,
    this.radius,
    this.backdrop = DsColor.sunken,
  });

  @override
  State<DsImage> createState() => _DsImageState();
}

class _DsImageState extends State<DsImage> {
  Timer? _retryTimer;
  int _attempt = 0;

  @override
  void didUpdateWidget(covariant DsImage oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.url != widget.url) {
      _retryTimer?.cancel();
      _retryTimer = null;
      _attempt = 0;
    }
  }

  @override
  void dispose() {
    _retryTimer?.cancel();
    super.dispose();
  }

  Widget _failed() {
    if (_attempt < 2 && _retryTimer == null) {
      _retryTimer = Timer(const Duration(seconds: 2), () {
        _retryTimer = null;
        if (mounted) setState(() => _attempt++);
      });
    }
    return Semantics(
      button: true,
      label: 'Retry image',
      child: GestureDetector(
        onTap: () {
          _retryTimer?.cancel();
          _retryTimer = null;
          setState(() => _attempt = _attempt < 2 ? _attempt + 1 : 0);
        },
        child: _fallback(),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final dpr = MediaQuery.devicePixelRatioOf(context);
    final candidates = imageUrlsWithFallback(widget.url);

    Widget imageAt(int index) => CachedNetworkImage(
      key: ValueKey('${candidates[index]}-$_attempt'),
      imageUrl: candidates[index],
      fit: widget.fit,
      width: widget.width,
      height: widget.height,
      memCacheWidth: (widget.width != null && widget.width!.isFinite)
          ? (widget.width! * dpr).round()
          : null,
      fadeInDuration: DsMotion.quick,
      placeholder: (_, _) =>
          const DsSkeleton(child: DsBone(radius: BorderRadius.zero)),
      errorWidget: (_, _, _) =>
          index + 1 < candidates.length ? imageAt(index + 1) : _failed(),
    );

    Widget child = candidates.isEmpty ? _fallback() : imageAt(0);

    child = Container(color: widget.backdrop, child: child);

    return widget.radius == null
        ? child
        : ClipRRect(borderRadius: widget.radius!, child: child);
  }

  Widget _fallback() => ColoredBox(
    color: widget.backdrop,
    child: const Center(
      child: Icon(
        Icons.medication_liquid_outlined,
        color: DsColor.inkFaint,
        size: 28,
      ),
    ),
  );
}

/// A media "plate": an image inset on a tinted rounded panel.
///
/// This is the signature product-image treatment in V2 and the main visual
/// break from V1's edge-to-edge card photo. Insetting the artwork gives every
/// product the same optical weight regardless of how tightly its source image
/// is cropped, which is what stops a mixed catalogue looking ragged.
class DsPlate extends StatelessWidget {
  final String url;
  final double inset;
  final BorderRadius radius;
  final Color backdrop;
  final BoxFit fit;
  final List<Widget> overlays;
  final bool dimmed;

  const DsPlate({
    super.key,
    required this.url,
    this.inset = 0,
    this.radius = DsRadius.rMd,
    this.backdrop = DsColor.surface,
    this.overlays = const [],
    this.dimmed = false,
    this.fit = BoxFit.cover,
  });

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(color: backdrop, borderRadius: radius),
      child: Stack(
        fit: StackFit.expand,
        children: [
          // Clipped to the plate's own radius: with no inset a `cover` image
          // would otherwise paint square corners over the rounded backdrop.
          ClipRRect(
            borderRadius: radius,
            child: Padding(
              padding: EdgeInsets.all(inset),
              child: Opacity(
                opacity: dimmed ? 0.4 : 1,
                child: DsImage(
                  url: url,
                  fit: fit,
                  backdrop: Colors.transparent,
                ),
              ),
            ),
          ),
          ...overlays,
        ],
      ),
    );
  }
}
