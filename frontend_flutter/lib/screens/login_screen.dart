import 'package:flutter/material.dart';

import '../api/api_exception.dart';
import '../state/session.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _email = TextEditingController();
  final _password = TextEditingController();
  bool _busy = false;
  bool _obscure = true;
  String? _error;

  @override
  void dispose() {
    _email.dispose();
    _password.dispose();
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() {
      _busy = true;
      _error = null;
    });
    try {
      await SessionScope.read(context).signIn(_email.text, _password.text);
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _error = e.kind == ApiErrorKind.validation ? 'Check your email and password.' : e.userMessage);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    final theme = Theme.of(context);
    final message = _error ?? session.notice;

    return Scaffold(
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: Card(
              child: Padding(
                padding: const EdgeInsets.all(28),
                child: Form(
                  key: _formKey,
                  child: AutofillGroup(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [
                        Icon(Icons.workspace_premium_outlined, size: 48, color: theme.colorScheme.primary),
                        const SizedBox(height: 12),
                        Text('Certificate Queue', textAlign: TextAlign.center, style: theme.textTheme.headlineSmall),
                        const SizedBox(height: 4),
                        Text('Sign in to request or process certificates',
                            textAlign: TextAlign.center, style: theme.textTheme.bodyMedium),
                        const SizedBox(height: 24),
                        if (message != null) ...[
                          Semantics(
                            liveRegion: true,
                            child: Container(
                              padding: const EdgeInsets.all(12),
                              decoration: BoxDecoration(
                                color: theme.colorScheme.errorContainer,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Text(message, style: TextStyle(color: theme.colorScheme.onErrorContainer)),
                            ),
                          ),
                          const SizedBox(height: 16),
                        ],
                        TextFormField(
                          controller: _email,
                          keyboardType: TextInputType.emailAddress,
                          autofillHints: const [AutofillHints.email],
                          textInputAction: TextInputAction.next,
                          decoration: const InputDecoration(labelText: 'Email', border: OutlineInputBorder()),
                          validator: (v) {
                            final value = v?.trim() ?? '';
                            if (value.isEmpty) return 'Enter your email.';
                            if (!RegExp(r'^[^@\s]+@[^@\s]+\.[^@\s]+$').hasMatch(value)) return 'Enter a valid email.';
                            return null;
                          },
                        ),
                        const SizedBox(height: 16),
                        TextFormField(
                          controller: _password,
                          obscureText: _obscure,
                          autofillHints: const [AutofillHints.password],
                          textInputAction: TextInputAction.done,
                          onFieldSubmitted: (_) => _busy ? null : _submit(),
                          decoration: InputDecoration(
                            labelText: 'Password',
                            border: const OutlineInputBorder(),
                            suffixIcon: IconButton(
                              tooltip: _obscure ? 'Show password' : 'Hide password',
                              icon: Icon(_obscure ? Icons.visibility : Icons.visibility_off),
                              onPressed: () => setState(() => _obscure = !_obscure),
                            ),
                          ),
                          validator: (v) => (v == null || v.isEmpty) ? 'Enter your password.' : null,
                        ),
                        const SizedBox(height: 24),
                        FilledButton(
                          onPressed: _busy ? null : _submit,
                          child: _busy
                              ? const SizedBox(width: 20, height: 20, child: CircularProgressIndicator(strokeWidth: 2))
                              : const Text('Sign in'),
                        ),
                        const SizedBox(height: 16),
                        Text(
                          'Class demonstration with fictional data only.',
                          textAlign: TextAlign.center,
                          style: theme.textTheme.bodySmall,
                        ),
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
