import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../api/models.dart';
import '../state/session.dart';
import '../widgets/dialogs.dart';

/// Requester form. Client-side checks match the server rules, but the server decides.
class NewRequestScreen extends StatefulWidget {
  const NewRequestScreen({super.key, required this.onSubmitted});

  final VoidCallback onSubmitted;

  @override
  State<NewRequestScreen> createState() => _NewRequestScreenState();
}

class _NewRequestScreenState extends State<NewRequestScreen> {
  final _formKey = GlobalKey<FormState>();
  final _purpose = TextEditingController();
  String? _type;
  bool _busy = false;
  ApiException? _serverError;

  /// One key per form: resubmitting after a network error can't create a duplicate.
  String _idempotencyKey = ApiClient.newIdempotencyKey();

  @override
  void dispose() {
    _purpose.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    setState(() => _serverError = null);
    if (!_formKey.currentState!.validate()) return;

    setState(() => _busy = true);
    try {
      final created = await SessionScope.read(context).api.submitRequest(
            certificateType: _type!,
            purpose: _purpose.text.trim(),
            idempotencyKey: _idempotencyKey,
          );
      if (!mounted) return;
      showMessage(context, 'Request #${created.id} submitted. It is waiting for approval.');
      _formKey.currentState!.reset();
      _purpose.clear();
      setState(() {
        _type = null;
        _idempotencyKey = ApiClient.newIdempotencyKey();
      });
      widget.onSubmitted();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _serverError = e);
      // Field errors show under the fields; everything else as a message.
      if (e.kind != ApiErrorKind.validation) showMessage(context, e.userMessage, error: true);
      _formKey.currentState!.validate();
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return SingleChildScrollView(
      padding: const EdgeInsets.all(24),
      child: Center(
        child: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 560),
          child: Form(
            key: _formKey,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                Text('Request a certificate', style: theme.textTheme.headlineSmall),
                const SizedBox(height: 8),
                const Text('Staff will review your request. Once approved, the certificate is generated automatically.'),
                const SizedBox(height: 24),
                DropdownButtonFormField<String>(
                  // New key per form instance, so the field clears after a successful submit.
                  key: ValueKey(_idempotencyKey),
                  initialValue: _type,
                  decoration: const InputDecoration(labelText: 'Certificate type', border: OutlineInputBorder()),
                  items: [
                    for (final entry in certificateTypes.entries)
                      DropdownMenuItem(value: entry.key, child: Text(entry.value)),
                  ],
                  onChanged: _busy ? null : (v) => setState(() => _type = v),
                  validator: (v) => v == null ? 'Choose a certificate type.' : _serverError?.fieldError('certificate_type'),
                ),
                const SizedBox(height: 16),
                TextFormField(
                  controller: _purpose,
                  enabled: !_busy,
                  maxLength: 255,
                  minLines: 2,
                  maxLines: 4,
                  decoration: const InputDecoration(
                    labelText: 'Purpose',
                    hintText: 'e.g. Scholarship application',
                    border: OutlineInputBorder(),
                  ),
                  validator: (v) {
                    final value = v?.trim() ?? '';
                    if (value.isEmpty) return 'Enter the purpose.';
                    if (value.length < 5) return 'Purpose must be at least 5 characters.';
                    return _serverError?.fieldError('purpose');
                  },
                ),
                const SizedBox(height: 16),
                FilledButton.icon(
                  onPressed: _busy ? null : _submit,
                  icon: _busy
                      ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.send),
                  label: const Text('Submit request'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
