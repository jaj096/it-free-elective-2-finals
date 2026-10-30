import 'package:flutter/material.dart';

import '../api/api_exception.dart';
import '../api/models.dart';
import '../platform/browser.dart';
import '../state/session.dart';
import 'async_view.dart';
import 'dialogs.dart';
import 'status_chip.dart';

/// Full detail of one request, its history, and the actions the signed-in role may take.
class RequestDetail extends StatelessWidget {
  const RequestDetail({super.key, required this.requestId, this.onChanged});

  final int requestId;

  /// Called after an action changes the request, so the list can refresh.
  final VoidCallback? onChanged;

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.read(context);
    return AsyncView<CertRequest>(
      key: ValueKey('detail-$requestId'),
      load: () => session.api.getRequest(requestId),
      pollWhile: (r) => r.isInProgress,
      builder: (context, request, reload) => _DetailBody(
        request: request,
        role: session.user!.role,
        onChanged: () async {
          await reload();
          onChanged?.call();
        },
      ),
    );
  }
}

class _DetailBody extends StatelessWidget {
  const _DetailBody({required this.request, required this.role, required this.onChanged});

  final CertRequest request;
  final UserRole role;
  final Future<void> Function() onChanged;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final cert = request.certificate;
    final isStaff = role != UserRole.requester;

    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Wrap(
          spacing: 12,
          runSpacing: 8,
          crossAxisAlignment: WrapCrossAlignment.center,
          children: [
            Text('Request #${request.id}', style: theme.textTheme.headlineSmall),
            StatusChip(status: request.status, label: request.statusLabel),
          ],
        ),
        const SizedBox(height: 8),
        _StatusMessage(request: request),
        const SizedBox(height: 16),
        _Field('Certificate', request.certificateTypeLabel),
        _Field('Purpose', request.purpose),
        if (isStaff && request.requesterName != null)
          _Field('Requester', '${request.requesterName} · ID ${request.requesterIdNumber ?? 'missing'}'),
        _Field('Submitted', formatDateTime(request.createdAt)),
        if (request.attempts > 0) _Field('Generation attempts', '${request.attempts} of ${request.maxAttempts}'),
        if (request.rejectionReason != null) _Field('Rejection reason', request.rejectionReason!),
        if (cert != null) ...[
          _Field('Certificate number', cert.number),
          if (isStaff && cert.sha256 != null) _Field('SHA-256', cert.sha256!, mono: true),
          _Field('Generated', formatDateTime(cert.generatedAt)),
          if (cert.releasedAt != null) _Field('Released', formatDateTime(cert.releasedAt)),
        ],
        const SizedBox(height: 16),
        _Actions(request: request, role: role, onChanged: onChanged),
        if (request.history.isNotEmpty) ...[
          const Divider(height: 40),
          Text('History', style: theme.textTheme.titleMedium),
          const SizedBox(height: 8),
          for (final h in request.history) _HistoryTile(entry: h, showDetails: isStaff),
        ],
      ],
    );
  }
}

class _StatusMessage extends StatelessWidget {
  const _StatusMessage({required this.request});

  final CertRequest request;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final isProblem = request.status == 'needs_review';
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: isProblem ? scheme.errorContainer : scheme.surfaceContainerHighest,
        borderRadius: BorderRadius.circular(8),
      ),
      child: Row(
        children: [
          if (request.isInProgress)
            const Padding(
              padding: EdgeInsets.only(right: 12),
              child: SizedBox(width: 16, height: 16, child: CircularProgressIndicator(strokeWidth: 2)),
            )
          else
            Padding(
              padding: const EdgeInsets.only(right: 12),
              child: Icon(isProblem ? Icons.report_problem_outlined : Icons.info_outline,
                  color: isProblem ? scheme.onErrorContainer : scheme.onSurfaceVariant),
            ),
          Expanded(
            child: Semantics(
              liveRegion: true,
              child: Text(request.statusMessage,
                  style: TextStyle(color: isProblem ? scheme.onErrorContainer : scheme.onSurfaceVariant)),
            ),
          ),
        ],
      ),
    );
  }
}

class _Field extends StatelessWidget {
  const _Field(this.label, this.value, {this.mono = false});

  final String label;
  final String value;
  final bool mono;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Text(label, style: theme.textTheme.labelMedium?.copyWith(color: theme.colorScheme.onSurfaceVariant)),
          SelectableText(value, style: mono ? const TextStyle(fontFamily: 'monospace', fontSize: 12) : null),
        ],
      ),
    );
  }
}

class _HistoryTile extends StatelessWidget {
  const _HistoryTile({required this.entry, required this.showDetails});

  final HistoryEntry entry;
  final bool showDetails;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final change = entry.toStatus == null ? '' : ' → ${entry.toStatus}';
    final attempt = entry.attempt == null ? '' : ' (attempt ${entry.attempt})';
    return ListTile(
      dense: true,
      contentPadding: EdgeInsets.zero,
      leading: Icon(entry.actor == 'System' ? Icons.smart_toy_outlined : Icons.person_outline, size: 20),
      title: Text('${entry.action.replaceAll('_', ' ')}$change$attempt'),
      subtitle: Text([
        '${entry.actor} · ${formatDateTime(entry.at)}',
        if (showDetails && entry.reason != null) entry.reason!,
        if (showDetails && entry.correlationId != null) 'Ref: ${entry.correlationId}',
      ].join('\n')),
      subtitleTextStyle: theme.textTheme.bodySmall,
    );
  }
}

/// Role- and status-dependent actions. The server re-checks every one of them.
class _Actions extends StatefulWidget {
  const _Actions({required this.request, required this.role, required this.onChanged});

  final CertRequest request;
  final UserRole role;
  final Future<void> Function() onChanged;

  @override
  State<_Actions> createState() => _ActionsState();
}

class _ActionsState extends State<_Actions> {
  bool _busy = false;
  final _idNumber = TextEditingController();
  final _note = TextEditingController();
  final _certNumber = TextEditingController();
  String? _idNumberError;

  @override
  void initState() {
    super.initState();
    _certNumber.text = widget.request.certificate?.number ?? '';
  }

  @override
  void dispose() {
    _idNumber.dispose();
    _note.dispose();
    _certNumber.dispose();
    super.dispose();
  }

  Future<void> _run(Future<Object?> Function() action, String success) async {
    setState(() {
      _busy = true;
      _idNumberError = null;
    });
    try {
      await action();
      if (!mounted) return;
      showMessage(context, success);
      await widget.onChanged();
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() => _idNumberError = e.fieldError('requester_id_number'));
      showMessage(context, e.userMessage, error: true);
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _download() async {
    final cert = widget.request.certificate!;
    final api = SessionScope.read(context).api;
    await _run(() async {
      final bytes = await api.downloadCertificate(cert.id);
      saveFile(bytes, '${cert.number}.pdf', 'application/pdf');
      return null;
    }, 'Downloading ${cert.number}.pdf');
  }

  @override
  Widget build(BuildContext context) {
    final r = widget.request;
    final api = SessionScope.read(context).api;
    final cert = r.certificate;
    final buttons = <Widget>[];
    Widget? form;

    switch (widget.role) {
      case UserRole.requester:
        if (cert != null && cert.downloadable) {
          buttons.add(FilledButton.icon(onPressed: _busy ? null : _download, icon: const Icon(Icons.download), label: const Text('Download PDF')));
        }
        if (cert != null && r.status == 'released') {
          buttons.add(OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    if (await confirmAction(context, title: 'Confirm receipt', message: 'Confirm that you received ${cert.number}?', confirmLabel: 'I received it')) {
                      await _run(() => api.acknowledge(cert.id), 'Receipt confirmed.');
                    }
                  },
            icon: const Icon(Icons.task_alt),
            label: const Text('Confirm receipt'),
          ));
        }

      case UserRole.approver:
        if (r.status == 'submitted') {
          buttons.add(FilledButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    if (await confirmAction(context, title: 'Approve request #${r.id}?', message: 'The certificate will be generated automatically.', confirmLabel: 'Approve')) {
                      await _run(() => api.approve(r.id), 'Approved. Generation is queued.');
                    }
                  },
            icon: const Icon(Icons.check),
            label: const Text('Approve'),
          ));
          buttons.add(OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final reason = await askForReason(context, title: 'Reject request #${r.id}', confirmLabel: 'Reject');
                    if (reason != null) await _run(() => api.reject(r.id, reason), 'Request rejected.');
                  },
            icon: const Icon(Icons.close),
            label: const Text('Reject'),
          ));
        }
        if (r.status == 'needs_review') {
          form = Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('Fix and re-queue', style: Theme.of(context).textTheme.titleSmall),
              const SizedBox(height: 4),
              Text(r.reviewReason ?? 'No reason recorded.', style: Theme.of(context).textTheme.bodySmall),
              const SizedBox(height: 12),
              TextField(
                controller: _idNumber,
                decoration: InputDecoration(
                  labelText: 'Requester ID number (only if missing or wrong)',
                  hintText: '2026-00001',
                  errorText: _idNumberError,
                  border: const OutlineInputBorder(),
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _note,
                maxLength: 200,
                decoration: const InputDecoration(labelText: 'Note for the audit log (optional)', border: OutlineInputBorder()),
              ),
            ],
          );
          buttons.add(FilledButton.icon(
            onPressed: _busy
                ? null
                : () => _run(
                      () => api.requeue(r.id, requesterIdNumber: _idNumber.text.trim(), note: _note.text.trim()),
                      'Re-queued for generation.',
                    ),
            icon: const Icon(Icons.replay),
            label: const Text('Re-queue'),
          ));
          buttons.add(OutlinedButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    final reason = await askForReason(context, title: 'Cancel request #${r.id}', confirmLabel: 'Cancel request');
                    if (reason != null) await _run(() => api.cancel(r.id, reason), 'Request cancelled.');
                  },
            icon: const Icon(Icons.block),
            label: const Text('Cancel request'),
          ));
        }

      case UserRole.custodian:
        if (cert != null && r.status == 'generated') {
          form = TextField(
            controller: _certNumber,
            decoration: const InputDecoration(
              labelText: 'Certificate number printed on the PDF',
              helperText: 'Verification also re-checks the file against its SHA-256 hash.',
              border: OutlineInputBorder(),
            ),
          );
          buttons.add(FilledButton.icon(
            onPressed: _busy ? null : () => _run(() => api.verify(cert.id, _certNumber.text.trim()), 'Certificate verified.'),
            icon: const Icon(Icons.verified_outlined),
            label: const Text('Verify'),
          ));
        }
        if (cert != null && r.status == 'verified') {
          buttons.add(FilledButton.icon(
            onPressed: _busy
                ? null
                : () async {
                    if (await confirmAction(context, title: 'Release ${cert.number}?', message: 'The requester will be notified and can download it.', confirmLabel: 'Release')) {
                      await _run(() => api.release(cert.id), 'Released to the requester.');
                    }
                  },
            icon: const Icon(Icons.send),
            label: const Text('Release'),
          ));
        }
        if (cert != null && r.status == 'acknowledged') {
          buttons.add(FilledButton.icon(
            onPressed: _busy ? null : () => _run(() => api.archive(cert.id), 'Archived.'),
            icon: const Icon(Icons.archive_outlined),
            label: const Text('Archive'),
          ));
        }
        if (cert != null) {
          buttons.add(OutlinedButton.icon(onPressed: _busy ? null : _download, icon: const Icon(Icons.download), label: const Text('Download PDF')));
        }
    }

    if (buttons.isEmpty && form == null) return const SizedBox.shrink();

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        if (form != null) ...[form, const SizedBox(height: 12)],
        Wrap(spacing: 12, runSpacing: 8, children: buttons),
        if (_busy) const Padding(padding: EdgeInsets.only(top: 12), child: LinearProgressIndicator()),
      ],
    );
  }
}
