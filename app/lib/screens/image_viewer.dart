import 'package:flutter/material.dart';

import 'package:redpharmabd_app/design/components/media.dart';
import 'package:redpharmabd_app/design/tokens.dart';

/// Full-screen, pinch-zoomable image viewer.
///
/// Opens from the product gallery. Deliberately chrome-free: a dim scrim, a
/// single close affordance, and the artwork. Double-tap toggles between fit
/// and 2.5x, which is the gesture people try first.
class ImageViewerScreen extends StatefulWidget {
  final String imageUrl;
  final String heroTag;

  const ImageViewerScreen({
    super.key,
    required this.imageUrl,
    required this.heroTag,
  });

  @override
  State<ImageViewerScreen> createState() => _ImageViewerScreenState();
}

class _ImageViewerScreenState extends State<ImageViewerScreen>
    with SingleTickerProviderStateMixin {
  final TransformationController _controller = TransformationController();
  late final AnimationController _anim = AnimationController(
    vsync: this,
    duration: DsMotion.base,
  );
  Animation<Matrix4>? _zoom;
  TapDownDetails? _lastTap;

  @override
  void initState() {
    super.initState();
    _anim.addListener(() {
      if (_zoom != null) _controller.value = _zoom!.value;
    });
  }

  @override
  void dispose() {
    _anim.dispose();
    _controller.dispose();
    super.dispose();
  }

  void _toggleZoom() {
    final zoomedOut = _controller.value.isIdentity();
    final Matrix4 end;

    if (zoomedOut && _lastTap != null) {
      final p = _lastTap!.localPosition;
      end = Matrix4.identity()
        ..translateByDouble(-p.dx * 1.5, -p.dy * 1.5, 0, 1)
        ..scaleByDouble(2.5, 2.5, 2.5, 1);
    } else {
      end = Matrix4.identity();
    }

    _zoom = Matrix4Tween(begin: _controller.value, end: end).animate(
      CurvedAnimation(parent: _anim, curve: DsMotion.ease),
    );
    _anim.forward(from: 0);
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: DsColor.obsidian,
      extendBodyBehindAppBar: true,
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        elevation: 0,
        scrolledUnderElevation: 0,
        foregroundColor: Colors.white,
        iconTheme: const IconThemeData(color: Colors.white),
        leading: Padding(
          padding: const EdgeInsets.all(DsSpace.x2),
          child: Material(
            color: Colors.white.withValues(alpha: 0.14),
            shape: const CircleBorder(),
            clipBehavior: Clip.antiAlias,
            child: IconButton(
              tooltip: 'Close',
              icon: const Icon(Icons.close_rounded, size: 20),
              onPressed: () => Navigator.pop(context),
            ),
          ),
        ),
      ),
      body: GestureDetector(
        onDoubleTapDown: (d) => _lastTap = d,
        onDoubleTap: _toggleZoom,
        child: Center(
          child: Hero(
            tag: widget.heroTag,
            child: InteractiveViewer(
              transformationController: _controller,
              minScale: 1,
              maxScale: 4,
              child: DsImage(
                url: widget.imageUrl,
                fit: BoxFit.contain,
                backdrop: Colors.transparent,
              ),
            ),
          ),
        ),
      ),
    );
  }
}
