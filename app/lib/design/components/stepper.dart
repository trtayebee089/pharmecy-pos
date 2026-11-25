import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../tokens.dart';
import '../typography.dart';

/// Animated quantity control.
///
/// Two deliberate behaviour choices, both aimed at the stock-cap requirement:
///
///  * **"+" is never disabled at the stock ceiling.** A dead button tells the
///    shopper nothing. Instead the press fires [onBlocked], the control gives a
///    short shake, and the screen explains the limit. Disabled controls are the
///    most common way apps leave people stuck without knowing why.
///  * **The number animates directionally** — it slides up when increasing and
///    down when decreasing — so a value that gets clamped back down is visibly
///    different from one that simply did not change.
class DsStepper extends StatefulWidget {
  /// Current quantity.
  final int value;

  /// Optional controller for direct typing. When null the value is read-only.
  final TextEditingController? controller;

  final VoidCallback onDecrease;
  final VoidCallback onIncrease;

  /// Fired when "+" is pressed while [atMax] — the screen explains the cap.
  final VoidCallback? onBlocked;

  final ValueChanged<String>? onChanged;
  final VoidCallback? onEditingComplete;
  final TapRegionCallback? onTapOutside;

  /// True when [value] has reached the available stock.
  final bool atMax;

  final bool compact;
  final String? semanticLabel;

  const DsStepper({
    super.key,
    required this.value,
    required this.onDecrease,
    required this.onIncrease,
    this.controller,
    this.onBlocked,
    this.onChanged,
    this.onEditingComplete,
    this.onTapOutside,
    this.atMax = false,
    this.compact = false,
    this.semanticLabel,
  });

  @override
  State<DsStepper> createState() => _DsStepperState();
}

class _DsStepperState extends State<DsStepper>
    with SingleTickerProviderStateMixin {
  late final AnimationController _shake = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 320),
  );

  bool _increasing = true;

  @override
  void didUpdateWidget(covariant DsStepper old) {
    super.didUpdateWidget(old);
    if (widget.value != old.value) {
      _increasing = widget.value > old.value;
    }
  }

  @override
  void dispose() {
    _shake.dispose();
    super.dispose();
  }

  void _blocked() {
    HapticFeedback.mediumImpact();
    _shake.forward(from: 0);
    widget.onBlocked?.call();
  }

  @override
  Widget build(BuildContext context) {
    final h = widget.compact ? 40.0 : 48.0;
    final fieldW = widget.compact ? 42.0 : 52.0;

    return AnimatedBuilder(
      animation: _shake,
      builder: (context, child) {
        // Damped sine: three quick oscillations that fade to nothing.
        final t = _shake.value;
        final dx = t == 0
            ? 0.0
            : (1 - t) * 8 * (1 - 2 * ((t * 3) % 1 > 0.5 ? 1 : 0));
        return Transform.translate(offset: Offset(dx, 0), child: child);
      },
      child: Semantics(
        label: widget.semanticLabel,
        value: '${widget.value}',
        container: true,
        child: Container(
          height: h,
          decoration: BoxDecoration(
            color: DsColor.sunken,
            borderRadius: DsRadius.pill,
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              _Step(
                icon: Icons.remove_rounded,
                size: h,
                tooltip: 'Decrease quantity',
                onTap: widget.onDecrease,
                enabled: true,
              ),
              SizedBox(
                width: fieldW,
                child: widget.controller != null
                    ? _field()
                    : _animatedValue(),
              ),
              _Step(
                icon: Icons.add_rounded,
                size: h,
                tooltip: widget.atMax
                    ? 'Maximum available reached'
                    : 'Increase quantity',
                onTap: widget.atMax ? _blocked : widget.onIncrease,
                enabled: !widget.atMax,
              ),
            ],
          ),
        ),
      ),
    );
  }

  Widget _animatedValue() {
    return AnimatedSwitcher(
      duration: DsMotion.quick,
      switchInCurve: DsMotion.ease,
      transitionBuilder: (child, a) => SlideTransition(
        position: Tween<Offset>(
          begin: Offset(0, _increasing ? 0.6 : -0.6),
          end: Offset.zero,
        ).animate(a),
        child: FadeTransition(opacity: a, child: child),
      ),
      child: Center(
        key: ValueKey(widget.value),
        child: Text(
          '${widget.value}',
          style: DsType.numeral(widget.compact ? 14 : 15.5),
        ),
      ),
    );
  }

  Widget _field() {
    return TextField(
      controller: widget.controller,
      keyboardType: TextInputType.number,
      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
      textAlign: TextAlign.center,
      style: DsType.numeral(widget.compact ? 14 : 15.5),
      decoration: const InputDecoration(
        border: InputBorder.none,
        enabledBorder: InputBorder.none,
        focusedBorder: InputBorder.none,
        filled: false,
        isCollapsed: true,
        contentPadding: EdgeInsets.zero,
      ),
      onChanged: widget.onChanged,
      onEditingComplete: widget.onEditingComplete,
      onTapOutside: widget.onTapOutside,
    );
  }
}

class _Step extends StatelessWidget {
  final IconData icon;
  final double size;
  final String tooltip;
  final VoidCallback onTap;
  final bool enabled;

  const _Step({
    required this.icon,
    required this.size,
    required this.tooltip,
    required this.onTap,
    required this.enabled,
  });

  @override
  Widget build(BuildContext context) {
    return Tooltip(
      message: tooltip,
      child: Material(
        color: Colors.transparent,
        shape: const CircleBorder(),
        clipBehavior: Clip.antiAlias,
        child: InkWell(
          onTap: onTap,
          child: SizedBox(
            width: size,
            height: size,
            child: Icon(
              icon,
              size: 17,
              // Dimmed but still live, so the affordance reads as "at the
              // limit" rather than "broken".
              color: enabled ? DsColor.ink : DsColor.inkFaint,
            ),
          ),
        ),
      ),
    );
  }
}
