import 'dart:async';
import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/design/adaptive.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/widgets/auth/auth_scaffold.dart';
import 'package:redpharmabd_app/design/components/steps.dart';

class ForgotPasswordScreen extends StatefulWidget {
  const ForgotPasswordScreen({super.key});

  @override
  State<ForgotPasswordScreen> createState() => _ForgotPasswordScreenState();
}

class _ForgotPasswordScreenState extends State<ForgotPasswordScreen> {
  int currentStep = 1;
  final _formKey = GlobalKey<FormState>();
  final TextEditingController _mobileController = TextEditingController();
  final TextEditingController _newPasswordController = TextEditingController();
  final TextEditingController _confirmPasswordController =
      TextEditingController();

  String mobile = '';
  String otp = '';
  String newPassword = '';
  String confirmPassword = '';

  bool isLoading = false;
  bool _obscureNew = true;
  bool _obscureConfirm = true;

  /// Nullable so leaving the screen before an OTP is ever requested cannot
  /// throw on dispose — the timer only exists once step 2 is reached.
  Timer? _timer;
  int _countdown = 60; // 60 seconds countdown
  bool _canResendOtp = false;

  @override
  void initState() {
    super.initState();
    _mobileController.text = mobile;
  }

  @override
  void dispose() {
    _mobileController.dispose();
    _newPasswordController.dispose();
    _confirmPasswordController.dispose();
    _timer?.cancel();
    super.dispose();
  }

  void _startTimer() {
    _timer?.cancel();
    _canResendOtp = false;
    _countdown = 60;

    _timer = Timer.periodic(const Duration(seconds: 1), (timer) {
      if (!mounted) {
        timer.cancel();
        return;
      }
      if (_countdown > 0) {
        setState(() {
          _countdown--;
        });
      } else {
        setState(() {
          _canResendOtp = true;
        });
        timer.cancel();
      }
    });
  }

  Future<void> _resendOtp() async {
    if (!_canResendOtp || mobile.isEmpty) return;

    setState(() => isLoading = true);

    try {
      await Provider.of<AuthProvider>(
        context,
        listen: false,
      ).requestPasswordReset(mobile);

      // Restart the timer
      _startTimer();

      if (!mounted) return;
      DsToast.success(context, "OTP has been resent to your mobile");
    } catch (e) {
      debugPrint("❌ resend OTP error: $e");
      if (!mounted) return;
      DsToast.error(context, e.toString());
    } finally {
      if (mounted) setState(() => isLoading = false);
    }
  }

  Future<void> _sendResetRequest() async {
    if (!_formKey.currentState!.validate()) return;
    _formKey.currentState!.save();

    setState(() => isLoading = true);

    try {
      await Provider.of<AuthProvider>(
        context,
        listen: false,
      ).requestPasswordReset(mobile);

      if (!mounted) return;

      _mobileController.text = mobile;
      setState(() => currentStep = 2);

      _startTimer();

      DsToast.success(context, "OTP has been sent to your email");
    } catch (e) {
      String errorMessage = "Something went wrong";

      try {
        final jsonStart = e.toString().indexOf('{');
        if (jsonStart != -1) {
          final jsonString = e.toString().substring(jsonStart);
          final Map<String, dynamic> data = jsonDecode(jsonString);
          if (data['message'] != null) {
            errorMessage = data['message'];
          }
        } else {
          errorMessage = e.toString();
        }
      } catch (_) {
        // fallback if parsing fails
        errorMessage = e.toString();
      }

      if (!mounted) return;
      DsToast.error(context, errorMessage);
    } finally {
      if (mounted) setState(() => isLoading = false);
    }
  }

  Future<void> _verifyOtpAndResetPassword() async {
    if (!_formKey.currentState!.validate()) return;
    _formKey.currentState!.save();

    if (newPassword != confirmPassword) {
      DsToast.error(context, "Passwords do not match");
      return;
    }

    setState(() => isLoading = true);

    try {
      await Provider.of<AuthProvider>(context, listen: false).verifyOtpAndReset(
        mobile: mobile,
        otp: otp,
        password: _newPasswordController.text,
        passwordConfirmation: _confirmPasswordController.text,
      );
      if (!mounted) return;

      DsToast.success(context, "Password reset successfully");

      setState(() => currentStep = 3);
    } catch (e) {
      if (!mounted) return;
      DsToast.error(context, "Failed: ${e.toString()}");
    } finally {
      if (mounted) setState(() => isLoading = false);
    }
  }

  void _onBottomButtonPressed() {
    if (currentStep == 1) {
      _sendResetRequest();
    } else if (currentStep == 2) {
      _verifyOtpAndResetPassword();
    } else {
      Navigator.pop(context);
    }
  }

  String _getButtonText() {
    if (currentStep == 1) return "Send OTP";
    if (currentStep == 2) return "Reset password";
    return "Back to login";
  }

  String _formatCountdown() {
    final minutes = (_countdown ~/ 60).toString().padLeft(2, '0');
    final seconds = (_countdown % 60).toString().padLeft(2, '0');
    return '$minutes:$seconds';
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: DsColor.canvas,
      appBar: AppBar(title: const Text("Forgot password")),
      body: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 460),
          child: SingleChildScrollView(
            padding: EdgeInsets.fromLTRB(
              context.gutter + DsSpace.x1,
              DsSpace.x4,
              context.gutter + DsSpace.x1,
              DsSpace.x6,
            ),
            child: Form(
              key: _formKey,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  DsSteps(
                    labels: const ["Your mobile", "Verify code", "Done"],
                    current: currentStep,
                  ),
                  const SizedBox(height: DsSpace.x6),
                  AnimatedSwitcher(
                    duration: const Duration(milliseconds: 260),
                    child: KeyedSubtree(
                      key: ValueKey(currentStep),
                      child: _buildStepContent(),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
      bottomNavigationBar: _ActionBar(
        children: [
          // Resend OTP button (only shown in step 2)
          if (currentStep == 2)
            Padding(
              padding: const EdgeInsets.only(bottom: DsSpace.x3),
              child: OutlinedButton.icon(
                onPressed: _canResendOtp && !isLoading ? _resendOtp : null,
                icon: const Icon(Icons.refresh_rounded, size: 18),
                label: Text(
                  _canResendOtp
                      ? 'Resend OTP'
                      : 'Resend OTP in ${_formatCountdown()}',
                ),
                style: OutlinedButton.styleFrom(
                  minimumSize: const Size.fromHeight(DsSize.control),
                  foregroundColor: DsColor.primaryDeep,
                  backgroundColor: _canResendOtp
                      ? DsColor.primarySoft
                      : DsColor.sunken,
                  disabledForegroundColor: DsColor.inkFaint,
                  side: BorderSide(
                    color: _canResendOtp
                        ? const Color(0xFFD6F2E2)
                        : DsColor.hairline,
                  ),
                ),
              ),
            ),
          AuthButton(
            label: _getButtonText(),
            isLoading: isLoading,
            onPressed: _onBottomButtonPressed,
          ),
        ],
      ),
    );
  }

  Widget _buildStepContent() {
    final text = Theme.of(context).textTheme;

    if (currentStep == 1) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Reset your password', style: text.headlineSmall),
          const SizedBox(height: DsSpace.x2),
          Text(
            'Enter the mobile number on your account and we will send you a one-time code.',
            style: text.bodyMedium,
          ),
          const SizedBox(height: DsSpace.x6),
          AuthField(
            label: "Mobile number",
            icon: Icons.phone_iphone_rounded,
            keyboardType: TextInputType.phone,
            textInputAction: TextInputAction.done,
            validator: (v) =>
                (v == null || v.isEmpty) ? "Enter mobile number" : null,
            onSaved: (v) => mobile = v!.trim(),
          ),
        ],
      );
    }

    if (currentStep == 2) {
      return Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text('Enter your code', style: text.headlineSmall),
          const SizedBox(height: DsSpace.x2),
          Text(
            'We sent a one-time code to the number below. Choose a new password to finish.',
            style: text.bodyMedium,
          ),
          const SizedBox(height: DsSpace.x6),
          Padding(
            padding: const EdgeInsets.only(bottom: DsSpace.x4),
            child: TextFormField(
              controller: _mobileController,
              readOnly: true,
              style: Theme.of(context).textTheme.bodyLarge,
              decoration: const InputDecoration(
                labelText: 'Mobile number',
                prefixIcon: Icon(Icons.phone_iphone_rounded),
                filled: true,
                fillColor: DsColor.sunken,
              ),
            ),
          ),
          AuthField(
            label: "One-time code",
            icon: Icons.pin_outlined,
            keyboardType: TextInputType.number,
            validator: (v) => (v == null || v.isEmpty) ? "Enter OTP" : null,
            onSaved: (v) => otp = v!.trim(),
          ),
          AuthField(
            label: "New password",
            hint: 'At least 8 characters',
            icon: Icons.lock_outline,
            controller: _newPasswordController,
            obscure: _obscureNew,
            suffix: PasswordEye(
              obscured: _obscureNew,
              onToggle: () => setState(() => _obscureNew = !_obscureNew),
            ),
            validator: (v) => (v == null || v.length < 8)
                ? "Password must be at least 8 chars"
                : null,
            onSaved: (v) => newPassword = v!.trim(),
          ),
          AuthField(
            label: "Confirm password",
            icon: Icons.lock_outline_rounded,
            controller: _confirmPasswordController,
            obscure: _obscureConfirm,
            textInputAction: TextInputAction.done,
            suffix: PasswordEye(
              obscured: _obscureConfirm,
              onToggle: () =>
                  setState(() => _obscureConfirm = !_obscureConfirm),
            ),
            validator: (v) => (v != _newPasswordController.text)
                ? "Passwords do not match"
                : null,
            onSaved: (v) => confirmPassword = v!.trim(),
          ),
        ],
      );
    }

    return Column(
      children: [
        const SizedBox(height: DsSpace.x6),
        Container(
          width: 120,
          height: 120,
          decoration: const BoxDecoration(
            shape: BoxShape.circle,
            color: DsColor.primarySoft,
          ),
          child: const Icon(
            Icons.check_circle_rounded,
            color: DsColor.primary,
            size: 64,
          ),
        ),
        const SizedBox(height: DsSpace.x6),
        Text(
          "Password reset",
          textAlign: TextAlign.center,
          style: text.headlineMedium,
        ),
        const SizedBox(height: DsSpace.x2),
        Text(
          "Your password has been updated. You can now log in with your new credentials.",
          textAlign: TextAlign.center,
          style: text.bodyMedium,
        ),
      ],
    );
  }
}

/// Bottom action bar: a floating sheet clear of the screen edge, matching the
/// sticky bars used by cart, checkout and product detail.
class _ActionBar extends StatelessWidget {
  final List<Widget> children;

  const _ActionBar({required this.children});

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      top: false,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          context.gutter,
          0,
          context.gutter,
          DsSpace.x3,
        ),
        child: Container(
          padding: const EdgeInsets.all(DsSpace.x4),
          decoration: BoxDecoration(
            color: DsColor.surface,
            borderRadius: DsRadius.rLg,
            boxShadow: DsShadow.high,
          ),
          child: Column(mainAxisSize: MainAxisSize.min, children: children),
        ),
      ),
    );
  }
}
