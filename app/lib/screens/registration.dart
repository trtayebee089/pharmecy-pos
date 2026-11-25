import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:http/http.dart' as http;
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/main.dart';
import 'package:redpharmabd_app/main_screen.dart';
import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/widgets/auth/auth_scaffold.dart';

class RegistrationScreen extends StatefulWidget {
  const RegistrationScreen({super.key});

  @override
  State<RegistrationScreen> createState() => _RegistrationScreenState();
}

class _RegistrationScreenState extends State<RegistrationScreen> {
  final _formKey = GlobalKey<FormState>();

  final _nameCtrl = TextEditingController();
  final _mobileCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _addressCtrl = TextEditingController();
  final _passwordCtrl = TextEditingController();
  final _confirmCtrl = TextEditingController();
  String? _selectedGender;
  final List<String> _genders = ['Male', 'Female', 'Other'];

  bool _isLoading = false;
  bool _obscure1 = true;
  bool _obscure2 = true;
  File? _imageFile;

  Future<void> _pickImage() async {
    final picker = ImagePicker();
    final result = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
    );
    if (result != null) {
      setState(() => _imageFile = File(result.path));
    }
  }

  Future<void> _register() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isLoading = true);
    try {
      await Provider.of<AuthProvider>(context, listen: false).register(
        fullName: _nameCtrl.text.trim(),
        mobile: _mobileCtrl.text.trim(),
        email: _emailCtrl.text.trim(),
        address: _addressCtrl.text.trim(),
        password: _passwordCtrl.text,
        gender: _selectedGender!.toLowerCase(),
        passwordConfirmation: _confirmCtrl.text,
        imageFile: _imageFile,
      );

      if (!mounted) return;

      // Success: show snackbar and go back
      DsToast.success(context, 'Account created successfully');

      Navigator.pop(context);
    } catch (e) {
      String message = 'Registration failed';

      if (e is http.Response) {
        final data = jsonDecode(e.body);
        if (data['errors'] != null) {
          message = data['errors'].values.map((v) => v.join('\n')).join('\n');
        } else if (data['message'] != null) {
          message = data['message'];
        }
      } else if (e is Exception) {
        message = e.toString();
      }

      if (!mounted) return;
      DsToast.error(context, message);
    } finally {
      if (mounted) setState(() => _isLoading = false);
    }
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _mobileCtrl.dispose();
    _emailCtrl.dispose();
    _addressCtrl.dispose();
    _passwordCtrl.dispose();
    _confirmCtrl.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AuthShell(
      eyebrow: 'Join',
      title: 'Create your account',
      subtitle: 'It takes a minute, and orders get easier every time.',
      children: [
        Center(child: _avatarPicker()),
        const SizedBox(height: DsSpace.x6),
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AuthField(
                label: 'Full name',
                icon: Icons.person_outline,
                controller: _nameCtrl,
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Enter your full name'
                    : null,
              ),
              AuthField(
                label: 'Mobile number',
                icon: Icons.phone_iphone_rounded,
                controller: _mobileCtrl,
                keyboardType: TextInputType.phone,
                validator: (v) {
                  if (v == null || v.trim().isEmpty) {
                    return 'Enter your mobile number';
                  }
                  final digits = v.replaceAll(RegExp(r'\D'), '');
                  if (digits.length < 8) return 'Enter a valid number';
                  return null;
                },
              ),
              AuthField(
                label: 'Email address',
                icon: Icons.email_outlined,
                controller: _emailCtrl,
                keyboardType: TextInputType.emailAddress,
                validator: (v) {
                  if (v == null || v.trim().isEmpty) return 'Enter your email';
                  final emailRegex = RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$');
                  if (!emailRegex.hasMatch(v)) return 'Enter a valid email';
                  return null;
                },
              ),
              // Now a form field rather than a bare dropdown, so it validates
              // with the rest of the form and shows an inline error.
              Padding(
                padding: const EdgeInsets.only(bottom: DsSpace.x4),
                child: DropdownButtonFormField<String>(
                  initialValue: _selectedGender,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Gender',
                    prefixIcon: Icon(Icons.wc_outlined),
                  ),
                  hint: const Text('Select gender'),
                  items: _genders
                      .map(
                        (gender) => DropdownMenuItem(
                          value: gender,
                          child: Text(gender),
                        ),
                      )
                      .toList(),
                  onChanged: (value) =>
                      setState(() => _selectedGender = value),
                  validator: (v) => v == null ? 'Select your gender' : null,
                ),
              ),
              AuthField(
                label: 'Address',
                icon: Icons.location_on_outlined,
                controller: _addressCtrl,
                keyboardType: TextInputType.multiline,
                maxLines: 3,
                validator: (v) => (v == null || v.trim().isEmpty)
                    ? 'Enter your address'
                    : null,
              ),
              AuthField(
                label: 'Password',
                hint: 'At least 6 characters',
                icon: Icons.lock_outline,
                controller: _passwordCtrl,
                obscure: _obscure1,
                suffix: PasswordEye(
                  obscured: _obscure1,
                  onToggle: () => setState(() => _obscure1 = !_obscure1),
                ),
                validator: (v) {
                  if (v == null || v.isEmpty) return 'Enter password';
                  if (v.length < 6) return 'At least 6 characters';
                  return null;
                },
              ),
              AuthField(
                label: 'Confirm password',
                icon: Icons.lock_outline_rounded,
                controller: _confirmCtrl,
                obscure: _obscure2,
                textInputAction: TextInputAction.done,
                suffix: PasswordEye(
                  obscured: _obscure2,
                  onToggle: () => setState(() => _obscure2 = !_obscure2),
                ),
                validator: (v) {
                  if (v == null || v.isEmpty) return 'Confirm your password';
                  if (v != _passwordCtrl.text) return 'Passwords do not match';
                  return null;
                },
              ),
              const SizedBox(height: DsSpace.x3),
              AuthButton(
                label: 'Create account',
                isLoading: _isLoading,
                onPressed: _register,
              ),
            ],
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
          label: const Text('Return to home'),
          style: TextButton.styleFrom(
            foregroundColor: DsColor.inkMuted,
            minimumSize: const Size.fromHeight(DsSize.control),
          ),
        ),
      ],
    );
  }

  Widget _avatarPicker() {
    return Semantics(
      button: true,
      label: 'Choose a profile photo',
      child: InkWell(
        onTap: _pickImage,
        borderRadius: BorderRadius.circular(60),
        child: Stack(
          alignment: Alignment.bottomRight,
          children: [
            Container(
              width: 108,
              height: 108,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: DsColor.sunken,
                border: Border.all(color: DsColor.hairline, width: 2),
                image: _imageFile != null
                    ? DecorationImage(
                        image: FileImage(_imageFile!),
                        fit: BoxFit.cover,
                      )
                    : null,
              ),
              child: _imageFile == null
                  ? const Icon(
                      Icons.person_outline_rounded,
                      size: 44,
                      color: DsColor.inkFaint,
                    )
                  : null,
            ),
            Container(
              padding: const EdgeInsets.all(DsSpace.x2),
              decoration: BoxDecoration(
                color: DsColor.primary,
                shape: BoxShape.circle,
                border: Border.all(color: DsColor.surface, width: 2),
              ),
              child: const Icon(
                Icons.photo_camera_rounded,
                color: Colors.white,
                size: 16,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
