import 'package:flutter/material.dart';

import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/bits.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/design/typography.dart';

/// Shell for the authentication screens.
///
/// V2 opens these screens with a large display heading on the bare canvas
/// rather than a boxed card — the form is the page, not a panel floating on
/// one. Fields are recessed wells, the primary action is a full-width pill,
/// and the whole thing is keyboard-safe and width-capped so it composes rather
/// than stretches on a tablet.
class AuthShell extends StatelessWidget {
  final String eyebrow;
  final String title;
  final String subtitle;
  final List<Widget> children;

  /// Back control. Off for the root sign-in screen, which offers "continue as
  /// guest" instead.
  final bool showBack;

  const AuthShell({
    super.key,
    required this.eyebrow,
    required this.title,
    required this.subtitle,
    required this.children,
    this.showBack = true,
  });

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return Scaffold(
      backgroundColor: DsColor.canvas,
      body: SafeArea(
        child: LayoutBuilder(
          builder: (context, c) => SingleChildScrollView(
            // Keeps the focused field above the keyboard instead of letting it
            // slide underneath.
            padding: EdgeInsets.only(
              bottom: MediaQuery.viewInsetsOf(context).bottom,
            ),
            child: ConstrainedBox(
              constraints: BoxConstraints(minHeight: c.maxHeight),
              child: Center(
                child: ConstrainedBox(
                  constraints: const BoxConstraints(maxWidth: 480),
                  child: Padding(
                    padding: EdgeInsets.fromLTRB(
                      context.gutter,
                      DsSpace.x4,
                      context.gutter,
                      DsSpace.x10,
                    ),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        if (showBack)
                          Align(
                            alignment: Alignment.centerLeft,
                            child: DsIconDisc(
                              icon: Icons.arrow_back_rounded,
                              tooltip: 'Back',
                              background: DsColor.surface,
                              shadow: DsShadow.low,
                              onTap: () => Navigator.pop(context),
                            ),
                          ),
                        const SizedBox(height: DsSpace.x8),
                        Text(
                          eyebrow.toUpperCase(),
                          style: DsType.eyebrow(color: DsColor.primary),
                        ),
                        const SizedBox(height: DsSpace.x3),
                        Text(title, style: text.displayMedium),
                        const SizedBox(height: DsSpace.x3),
                        Text(subtitle, style: text.bodyLarge),
                        const SizedBox(height: DsSpace.x8),
                        ...children,
                      ],
                    ),
                  ),
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}

/// Recessed input for the auth screens.
class AuthField extends StatelessWidget {
  final String label;
  final String? hint;
  final IconData icon;
  final bool obscure;
  final Widget? suffix;
  final TextInputType? keyboardType;
  final String? initialValue;
  final TextEditingController? controller;
  final FormFieldSetter<String>? onSaved;
  final FormFieldValidator<String>? validator;
  final ValueChanged<String>? onChanged;
  final TextInputAction textInputAction;
  final int maxLines;

  const AuthField({
    super.key,
    required this.label,
    required this.icon,
    this.hint,
    this.obscure = false,
    this.suffix,
    this.keyboardType,
    this.initialValue,
    this.controller,
    this.onSaved,
    this.validator,
    this.onChanged,
    this.textInputAction = TextInputAction.next,
    this.maxLines = 1,
  });

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: DsSpace.x4),
      child: TextFormField(
        controller: controller,
        initialValue: controller == null ? initialValue : null,
        obscureText: obscure,
        keyboardType: keyboardType,
        textInputAction: textInputAction,
        maxLines: obscure ? 1 : maxLines,
        style: Theme.of(context).textTheme.bodyLarge,
        decoration: InputDecoration(
          labelText: label,
          hintText: hint,
          prefixIcon: Icon(icon, size: DsSize.iconMd),
          suffixIcon: suffix,
        ),
        onSaved: onSaved,
        validator: validator,
        onChanged: onChanged,
      ),
    );
  }
}

/// Show/hide toggle for password fields.
class PasswordEye extends StatelessWidget {
  final bool obscured;
  final VoidCallback onToggle;

  const PasswordEye({
    super.key,
    required this.obscured,
    required this.onToggle,
  });

  @override
  Widget build(BuildContext context) {
    return IconButton(
      tooltip: obscured ? 'Show password' : 'Hide password',
      onPressed: onToggle,
      icon: Icon(
        obscured ? Icons.visibility_off_outlined : Icons.visibility_outlined,
        size: DsSize.iconMd,
      ),
    );
  }
}

/// Full-width primary action with an inline busy state.
class AuthButton extends StatelessWidget {
  final String label;
  final bool isLoading;
  final VoidCallback? onPressed;
  final IconData? icon;

  const AuthButton({
    super.key,
    required this.label,
    required this.isLoading,
    required this.onPressed,
    this.icon,
  });

  @override
  Widget build(BuildContext context) {
    return ElevatedButton(
      onPressed: isLoading ? null : onPressed,
      child: isLoading
          ? const SizedBox(
              width: 22,
              height: 22,
              child: CircularProgressIndicator(
                strokeWidth: 2.4,
                color: Colors.white,
              ),
            )
          : Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                if (icon != null) ...[
                  Icon(icon, size: 18),
                  const SizedBox(width: DsSpace.x2),
                ],
                Text(label),
              ],
            ),
    );
  }
}
