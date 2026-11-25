import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/widgets/auth/auth_scaffold.dart';

class ChangePasswordScreen extends StatefulWidget {
  const ChangePasswordScreen({super.key});

  @override
  State<ChangePasswordScreen> createState() => _ChangePasswordScreenState();
}

class _ChangePasswordScreenState extends State<ChangePasswordScreen> {
  final _formKey = GlobalKey<FormState>();
  final _newPasswordController = TextEditingController();
  final _confirmPasswordController = TextEditingController();

  bool _isLoading = false;
  bool _obscureNew = true;
  bool _obscureConfirm = true;

  // Live checklist flags
  bool get _hasMinLen => _newPasswordController.text.trim().length >= 8;
  bool get _hasUpper => RegExp(r'[A-Z]').hasMatch(_newPasswordController.text);
  bool get _hasLower => RegExp(r'[a-z]').hasMatch(_newPasswordController.text);
  bool get _hasDigit => RegExp(r'\d').hasMatch(_newPasswordController.text);

  /// How many of the four rules currently pass — drives the strength meter.
  int get _passedRules =>
      [_hasMinLen, _hasUpper, _hasLower, _hasDigit].where((e) => e).length;

  @override
  void initState() {
    super.initState();
    _newPasswordController.addListener(() => setState(() {}));
    _confirmPasswordController.addListener(() => setState(() {}));
  }

  @override
  void dispose() {
    _newPasswordController.dispose();
    _confirmPasswordController.dispose();
    super.dispose();
  }

  Future<void> _changePassword() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      final auth = context.read<AuthProvider>();

      await auth.changePassword(
        newPassword: _newPasswordController.text.trim(),
        confirmPassword: _confirmPasswordController.text.trim(),
      );

      if (!mounted) return;

      DsToast.success(context, 'Password changed successfully!');
      Navigator.pop(context);
    } catch (e) {
      if (!mounted) return;

      DsToast.error(context, e.toString());
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bool match =
        _confirmPasswordController.text == _newPasswordController.text &&
        _confirmPasswordController.text.isNotEmpty;

    return AuthShell(
      eyebrow: 'Security',
      title: 'Change password',
      subtitle: 'Pick something you have not used on another site.',
      children: [
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AuthField(
                label: 'New password',
                icon: Icons.lock_outline,
                controller: _newPasswordController,
                obscure: _obscureNew,
                suffix: PasswordEye(
                  obscured: _obscureNew,
                  onToggle: () => setState(() => _obscureNew = !_obscureNew),
                ),
                validator: (v) {
                  final val = v?.trim() ?? '';
                  if (val.isEmpty) return "Enter new password";
                  if (!_hasMinLen || !_hasUpper || !_hasLower || !_hasDigit) {
                    return "Password doesn’t meet the requirements";
                  }
                  return null;
                },
              ),

              _strengthMeter(),
              const SizedBox(height: DsSpace.x3),
              _checklist(),
              const SizedBox(height: DsSpace.x4),

              AuthField(
                label: 'Confirm password',
                icon: Icons.lock_outline,
                controller: _confirmPasswordController,
                obscure: _obscureConfirm,
                textInputAction: TextInputAction.done,
                suffix: PasswordEye(
                  obscured: _obscureConfirm,
                  onToggle: () =>
                      setState(() => _obscureConfirm = !_obscureConfirm),
                ),
                validator: (v) {
                  if ((v ?? '').isEmpty) return "Re-enter your new password";
                  if (v != _newPasswordController.text) {
                    return "Passwords do not match";
                  }
                  return null;
                },
              ),

              // Inline mismatch hint
              if (!match && _confirmPasswordController.text.isNotEmpty)
                Padding(
                  padding: const EdgeInsets.only(bottom: DsSpace.x3),
                  child: Row(
                    children: [
                      const Icon(
                        Icons.error_outline,
                        size: 16,
                        color: DsColor.danger,
                      ),
                      const SizedBox(width: DsSpace.x2),
                      Expanded(
                        child: Text(
                          "Confirm password does not match",
                          style: Theme.of(context).textTheme.bodySmall
                              ?.copyWith(color: DsColor.danger),
                        ),
                      ),
                    ],
                  ),
                ),

              const SizedBox(height: DsSpace.x2),
              AuthButton(
                label: 'Change password',
                isLoading: _isLoading,
                onPressed: _changePassword,
              ),
            ],
          ),
        ),
      ],
    );
  }

  /// Four-segment strength bar driven by the same rules as the checklist, so
  /// progress is visible at a glance before reading the list.
  Widget _strengthMeter() {
    final passed = _passedRules;
    final color = passed <= 1
        ? DsColor.danger
        : passed <= 3
        ? DsColor.warning
        : DsColor.primary;

    return Row(
      children: List.generate(4, (i) {
        return Expanded(
          child: AnimatedContainer(
            duration: const Duration(milliseconds: 260),
            height: 4,
            margin: EdgeInsets.only(right: i == 3 ? 0 : DsSpace.x1),
            decoration: BoxDecoration(
              color: i < passed ? color : DsColor.hairline,
              borderRadius: DsRadius.pill,
            ),
          ),
        );
      }),
    );
  }

  Widget _checklist() {
    return Container(
      padding: const EdgeInsets.all(DsSpace.x3),
      decoration: BoxDecoration(
        color: DsColor.surface,
        borderRadius: DsRadius.rSm,
        border: Border.all(color: DsColor.hairline),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          _checkItem(_hasMinLen, "At least 8 characters"),
          _checkItem(_hasUpper, "At least one uppercase letter"),
          _checkItem(_hasLower, "At least one lowercase letter"),
          _checkItem(_hasDigit, "At least one number"),
        ],
      ),
    );
  }

  Widget _checkItem(bool ok, String label) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: DsSpace.x1),
      child: Row(
        children: [
          AnimatedContainer(
            duration: const Duration(milliseconds: 200),
            width: 18,
            height: 18,
            decoration: BoxDecoration(
              color: ok ? DsColor.primary : Colors.transparent,
              shape: BoxShape.circle,
              border: Border.all(
                color: ok ? DsColor.primary : DsColor.hairline,
                width: 1.5,
              ),
            ),
            child: ok
                ? const Icon(Icons.check_rounded, size: 12, color: Colors.white)
                : null,
          ),
          const SizedBox(width: DsSpace.x3),
          Expanded(
            child: Text(
              label,
              style: Theme.of(context).textTheme.bodyMedium?.copyWith(
                color: ok ? DsColor.ink : DsColor.inkMuted,
                fontWeight: ok ? FontWeight.w600 : FontWeight.w400,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
