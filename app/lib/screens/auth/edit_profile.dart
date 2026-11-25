import 'dart:io';

import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';
import 'package:provider/provider.dart';

import 'package:redpharmabd_app/providers/auth_provider.dart';
import 'package:redpharmabd_app/design/components/feedback.dart';
import 'package:redpharmabd_app/design/tokens.dart';
import 'package:redpharmabd_app/widgets/auth/auth_scaffold.dart';
import 'package:redpharmabd_app/design/components/media.dart';

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final picker = ImagePicker();

  File? _imageFile;
  late TextEditingController _nameController;
  late TextEditingController _emailController;
  late TextEditingController _phoneController;
  late TextEditingController _addressController;
  String? _selectedGender;

  bool _isSaving = false;

  @override
  void initState() {
    super.initState();
    final user = context.read<AuthProvider>().userData ?? {};

    _nameController = TextEditingController(text: user['name'] ?? '');
    _emailController = TextEditingController(text: user['email'] ?? '');
    _phoneController = TextEditingController(text: user['phone_number'] ?? '');
    _addressController = TextEditingController(text: user['address'] ?? '');
    final gender = (user['gender'] ?? '').toString().toLowerCase();
    if (gender == 'male') {
      _selectedGender = 'Male';
    } else if (gender == 'female') {
      _selectedGender = 'Female';
    } else if (gender == 'other') {
      _selectedGender = 'Other';
    } else {
      _selectedGender = null; // default empty
    }
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _phoneController.dispose();
    _addressController.dispose();
    super.dispose();
  }

  Future<void> _pickImage() async {
    final picked = await picker.pickImage(
      source: ImageSource.gallery,
      imageQuality: 80,
    );
    if (picked != null) {
      setState(() => _imageFile = File(picked.path));
    }
  }

  Future<void> _saveProfile() async {
    if (!_formKey.currentState!.validate()) return;

    setState(() => _isSaving = true);
    try {
      await context.read<AuthProvider>().updateProfile(
        name: _nameController.text,
        email: _emailController.text,
        phoneNumber: _phoneController.text,
        gender: _selectedGender ?? '',
        address: _addressController.text,
        imageFile: _imageFile,
      );

      if (!mounted) return;

      DsToast.success(context, "Profile updated successfully");
      Navigator.pop(context);
    } catch (e) {
      if (!mounted) return;
      DsToast.error(context, 'Error updating profile: $e');
    } finally {
      if (mounted) setState(() => _isSaving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = context.watch<AuthProvider>().userData;
    final remoteAvatar = user?['avator']?.isNotEmpty == true
        ? user!['avator'] as String
        : '';

    return AuthShell(
      eyebrow: 'Account',
      title: 'Edit profile',
      subtitle: 'Keep your delivery details up to date.',
      children: [
        Center(child: _avatarPicker(remoteAvatar)),
        const SizedBox(height: DsSpace.x6),
        Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AuthField(
                label: 'Full name',
                icon: Icons.person_outline,
                controller: _nameController,
                validator: _required('Full name'),
              ),
              AuthField(
                label: 'Email',
                icon: Icons.email_outlined,
                controller: _emailController,
                keyboardType: TextInputType.emailAddress,
                validator: _required('Email'),
              ),
              AuthField(
                label: 'Phone number',
                icon: Icons.phone_iphone_rounded,
                controller: _phoneController,
                keyboardType: TextInputType.phone,
                validator: _required('Phone number'),
              ),
              Padding(
                padding: const EdgeInsets.only(bottom: DsSpace.x4),
                child: DropdownButtonFormField<String>(
                  initialValue: _selectedGender,
                  isExpanded: true,
                  decoration: const InputDecoration(
                    labelText: 'Gender',
                    prefixIcon: Icon(Icons.wc_outlined),
                  ),
                  items: const [
                    DropdownMenuItem(value: 'Male', child: Text('Male')),
                    DropdownMenuItem(value: 'Female', child: Text('Female')),
                    DropdownMenuItem(value: 'Other', child: Text('Other')),
                  ],
                  onChanged: (value) => setState(() => _selectedGender = value),
                  validator: (val) =>
                      val == null ? 'Please select gender' : null,
                ),
              ),
              AuthField(
                label: 'Address',
                icon: Icons.location_on_outlined,
                controller: _addressController,
                keyboardType: TextInputType.streetAddress,
                maxLines: 2,
                textInputAction: TextInputAction.done,
                validator: _required('Address'),
              ),
              const SizedBox(height: DsSpace.x3),
              AuthButton(
                label: 'Save changes',
                isLoading: _isSaving,
                onPressed: _saveProfile,
              ),
            ],
          ),
        ),
      ],
    );
  }

  FormFieldValidator<String> _required(String label) =>
      (val) => (val == null || val.trim().isEmpty)
      ? "Please enter ${label.toLowerCase()}"
      : null;

  Widget _avatarPicker(String remoteAvatar) {
    return Semantics(
      button: true,
      label: 'Change profile photo',
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
              ),
              clipBehavior: Clip.antiAlias,
              child: _imageFile != null
                  ? Image.file(_imageFile!, fit: BoxFit.cover)
                  : remoteAvatar.isNotEmpty
                  ? DsImage(url: remoteAvatar, fit: BoxFit.cover)
                  : Image.asset(
                      'assets/images/guest-user.png',
                      fit: BoxFit.cover,
                    ),
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
