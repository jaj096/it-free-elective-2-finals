import 'package:flutter/material.dart';

/// Asks the user to confirm an action. Returns true if confirmed.
Future<bool> confirmAction(
  BuildContext context, {
  required String title,
  required String message,
  String confirmLabel = 'Confirm',
  bool destructive = false,
}) async {
  final result = await showDialog<bool>(
    context: context,
    builder: (context) => AlertDialog(
      title: Text(title),
      content: Text(message),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancel')),
        FilledButton(
          style: destructive ? FilledButton.styleFrom(backgroundColor: Theme.of(context).colorScheme.error) : null,
          onPressed: () => Navigator.pop(context, true),
          child: Text(confirmLabel),
        ),
      ],
    ),
  );
  return result ?? false;
}

/// Asks for a short reason (reject / cancel). Returns null if the user cancels.
Future<String?> askForReason(BuildContext context, {required String title, required String confirmLabel}) {
  return showDialog<String>(context: context, builder: (_) => _ReasonDialog(title: title, confirmLabel: confirmLabel));
}

class _ReasonDialog extends StatefulWidget {
  const _ReasonDialog({required this.title, required this.confirmLabel});

  final String title;
  final String confirmLabel;

  @override
  State<_ReasonDialog> createState() => _ReasonDialogState();
}

class _ReasonDialogState extends State<_ReasonDialog> {
  final _formKey = GlobalKey<FormState>();
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  void _submit() {
    if (_formKey.currentState!.validate()) Navigator.pop(context, _controller.text.trim());
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.title),
      content: Form(
        key: _formKey,
        child: TextFormField(
          controller: _controller,
          autofocus: true,
          maxLength: 255,
          decoration: const InputDecoration(labelText: 'Reason', hintText: 'Shown to the requester'),
          validator: (v) => (v == null || v.trim().length < 3) ? 'Enter at least 3 characters.' : null,
          onFieldSubmitted: (_) => _submit(),
        ),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(onPressed: _submit, child: Text(widget.confirmLabel)),
      ],
    );
  }
}

void showMessage(BuildContext context, String message, {bool error = false}) {
  final scheme = Theme.of(context).colorScheme;
  ScaffoldMessenger.of(context)
    ..hideCurrentSnackBar()
    ..showSnackBar(SnackBar(
      content: Text(message),
      backgroundColor: error ? scheme.error : null,
      behavior: SnackBarBehavior.floating,
    ));
}

String formatDateTime(DateTime? d) {
  if (d == null) return '-';
  const months = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
  final h = d.hour % 12 == 0 ? 12 : d.hour % 12;
  final m = d.minute.toString().padLeft(2, '0');
  final ampm = d.hour < 12 ? 'AM' : 'PM';
  return '${months[d.month - 1]} ${d.day}, ${d.year} $h:$m $ampm';
}
