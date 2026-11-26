import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:provider/provider.dart';

import '../api/api_client.dart';
import '../design/tokens.dart';
import '../providers/auth_provider.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phone = TextEditingController();
  final _password = TextEditingController();
  bool _obscure = true;
  bool _busy = false;
  String? _error;

  @override
  void dispose() {
    _phone.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (_busy) return;
    if (!(_formKey.currentState?.validate() ?? false)) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await context.read<AuthProvider>().login(_phone.text, _password.text);
    } on ApiException catch (e) {
      if (mounted) setState(() => _error = e.message);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final text = Theme.of(context).textTheme;
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(DsSpace.x6),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Center(
                      child: Container(
                        width: 84,
                        height: 84,
                        padding: const EdgeInsets.all(DsSpace.x3),
                        decoration: BoxDecoration(
                          color: DsColor.surface,
                          borderRadius: DsRadius.rLg,
                          boxShadow: DsShadow.mid,
                        ),
                        child: Image.asset('assets/images/redpharma-icon.png'),
                      ),
                    ),
                    DsSpace.v6,
                    Text(
                      'RedPharma Rider',
                      textAlign: TextAlign.center,
                      style: text.displaySmall,
                    ),
                    DsSpace.v2,
                    Text(
                      'Sign in with the phone number and password the pharmacy gave you.',
                      textAlign: TextAlign.center,
                      style: text.bodyMedium,
                    ),
                    DsSpace.v8,
                    TextFormField(
                      controller: _phone,
                      keyboardType: TextInputType.phone,
                      textInputAction: TextInputAction.next,
                      autofillHints: const [AutofillHints.telephoneNumber],
                      inputFormatters: [
                        FilteringTextInputFormatter.allow(RegExp(r'[0-9+]')),
                        LengthLimitingTextInputFormatter(20),
                      ],
                      decoration: const InputDecoration(
                        labelText: 'Phone number',
                        hintText: '01XXXXXXXXX',
                        prefixIcon: Icon(Icons.phone_iphone_rounded),
                      ),
                      validator: (v) => (v == null || v.trim().length < 6)
                          ? 'Enter your phone number'
                          : null,
                    ),
                    DsSpace.v4,
                    TextFormField(
                      controller: _password,
                      obscureText: _obscure,
                      textInputAction: TextInputAction.done,
                      autofillHints: const [AutofillHints.password],
                      onFieldSubmitted: (_) => _submit(),
                      decoration: InputDecoration(
                        labelText: 'Password',
                        prefixIcon: const Icon(Icons.lock_outline_rounded),
                        suffixIcon: IconButton(
                          tooltip: _obscure ? 'Show password' : 'Hide password',
                          icon: Icon(
                            _obscure
                                ? Icons.visibility_outlined
                                : Icons.visibility_off_outlined,
                          ),
                          onPressed: () => setState(() => _obscure = !_obscure),
                        ),
                      ),
                      validator: (v) => (v == null || v.isEmpty)
                          ? 'Enter your password'
                          : null,
                    ),
                    if (_error != null) ...[
                      DsSpace.v4,
                      Container(
                        padding: const EdgeInsets.all(DsSpace.x3),
                        decoration: const BoxDecoration(
                          color: DsColor.dangerSoft,
                          borderRadius: DsRadius.rSm,
                        ),
                        child: Row(
                          children: [
                            const Icon(
                              Icons.error_outline_rounded,
                              color: DsColor.danger,
                            ),
                            DsSpace.h2,
                            Expanded(
                              child: Text(
                                _error!,
                                style: text.bodyMedium?.copyWith(
                                  color: DsColor.danger,
                                ),
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                    DsSpace.v6,
                    ElevatedButton(
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(
                                strokeWidth: 2.5,
                                color: DsColor.primary,
                              ),
                            )
                          : const Text('Sign in'),
                    ),
                    DsSpace.v6,
                    Text(
                      'Forgot your password? Ask the pharmacy to set a new one.',
                      textAlign: TextAlign.center,
                      style: text.bodySmall,
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
