import 'package:flutter/material.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/main.dart';
import 'package:redpharmabd_app/main_screen.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/screens/forgot_password.dart';
import 'package:redpharmabd_app/screens/registration.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/widgets/auth/auth_scaffold.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  String mobile = '';
  String password = '';
  bool isLoading = false;
  bool _obscure = true;

  Future<void> _login() async {
    if (!_formKey.currentState!.validate()) return;
    _formKey.currentState!.save();

    setState(() => isLoading = true);
    try {
      await Provider.of<AuthProvider>(
        context,
        listen: false,
      ).loginWithMobile(mobile, password);

      if (!mounted) return;
      Navigator.pop(context, true);

      DsToast.success(context, "Logged in successfully");
    } catch (e) {
      if (!mounted) return;
      DsToast.error(context, "Login failed: ${e.toString()}");
    } finally {
      if (mounted) setState(() => isLoading = false);
    }
  }

  void _openForgetScreen() {
    debugPrint('Forget Screen Clicked');
    Navigator.push(
      context,
      MaterialPageRoute(builder: (context) => ForgotPasswordScreen()),
    );
  }

  void _openRegistrationScreen() async {
    final result = await Navigator.push(
      context,
      MaterialPageRoute(builder: (_) => const RegistrationScreen()),
    );

    if (result == true) {
      if (!mounted) return;
      Provider.of<AuthProvider>(context, listen: false).fetchUserData();

      DsToast.success(context, "Account created and logged in successfully");
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;

    return AuthShell(
      showBack: false,
      eyebrow: 'Sign in',
      title: 'Welcome back',
      subtitle: 'Log in to track orders and use your member discount.',
      children: [
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AuthField(
                label: 'Mobile number',
                hint: 'The number on your account',
                icon: Icons.phone_iphone_rounded,
                keyboardType: TextInputType.phone,
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Enter your mobile number'
                    : null,
                onSaved: (v) => mobile = v!.trim(),
              ),
              AuthField(
                label: 'Password',
                icon: Icons.lock_outline_rounded,
                obscure: _obscure,
                textInputAction: TextInputAction.done,
                suffix: PasswordEye(
                  obscured: _obscure,
                  onToggle: () => setState(() => _obscure = !_obscure),
                ),
                validator: (v) =>
                    (v == null || v.isEmpty) ? 'Enter password' : null,
                onSaved: (v) => password = v ?? '',
              ),
              Align(
                alignment: Alignment.centerRight,
                child: TextButton(
                  onPressed: _openForgetScreen,
                  child: const Text('Forgot password?'),
                ),
              ),
              const SizedBox(height: DsSpace.x3),
              AuthButton(
                label: 'Log in',
                isLoading: isLoading,
                onPressed: _login,
              ),
            ],
          ),
        ),

        const SizedBox(height: DsSpace.x6),

        // Divider with a label, so "create account" reads as an alternative
        // rather than a second primary action.
        Row(
          children: [
            const Expanded(child: Divider()),
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: DsSpace.x3),
              child: Text('New to RedPharma?', style: text.bodySmall),
            ),
            const Expanded(child: Divider()),
          ],
        ),
        const SizedBox(height: DsSpace.x4),

        OutlinedButton.icon(
          onPressed: isLoading ? null : _openRegistrationScreen,
          icon: const Icon(Icons.person_add_alt_rounded, size: 18),
          label: const Text('Create an account'),
          style: OutlinedButton.styleFrom(
            foregroundColor: DsColor.primaryDeep,
            backgroundColor: DsColor.primarySoft,
            side: const BorderSide(color: Color(0xFFD6F2E2)),
          ),
        ),
        const SizedBox(height: DsSpace.x3),
        TextButton.icon(
          onPressed: () {
            Navigator.of(context).pushAndRemoveUntil(
              MaterialPageRoute(builder: (_) => MainScreen(key: mainScreenKey)),
              (route) => false,
            );
            mainScreenKey.currentState?.switchToHomeTab();
          },
          icon: const Icon(Icons.home_outlined, size: 18),
          label: const Text('Continue as guest'),
          style: TextButton.styleFrom(
            foregroundColor: DsColor.inkMuted,
            minimumSize: const Size.fromHeight(DsSize.control),
          ),
        ),
      ],
    );
  }
}
