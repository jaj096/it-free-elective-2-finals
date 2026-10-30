import 'package:flutter/material.dart';

import '../api/models.dart';
import '../state/session.dart';
import '../widgets/async_view.dart';
import '../widgets/dialogs.dart';

/// Read-only view of the background automation for staff. Refreshes every few seconds.
class AutomationScreen extends StatelessWidget {
  const AutomationScreen({super.key});

  static const _labels = {
    'queue_depth': 'Jobs waiting in queue',
    'needs_review': 'Waiting for review',
    'generated_total': 'Certificates generated',
    'retries_total': 'Retries scheduled',
    'sent_to_review_total': 'Sent to review (total)',
    'duplicates_skipped_total': 'Duplicates skipped',
    'failed_jobs': 'Failed job records',
  };

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;
    return AsyncView<AutomationSnapshot>(
      load: api.automation,
      pollWhile: (_) => true,
      builder: (context, data, reload) {
        final theme = Theme.of(context);
        return ListView(
          padding: const EdgeInsets.all(20),
          children: [
            Row(
              children: [
                Expanded(child: Text('Automation status', style: theme.textTheme.headlineSmall)),
                IconButton(tooltip: 'Refresh', onPressed: reload, icon: const Icon(Icons.refresh)),
              ],
            ),
            const SizedBox(height: 4),
            const Text('Updates every 5 seconds. Times are in your local time zone.'),
            const SizedBox(height: 16),
            Wrap(
              spacing: 12,
              runSpacing: 12,
              children: [
                for (final entry in _labels.entries)
                  _MetricCard(label: entry.value, value: data.metrics[entry.key] ?? 0, warn: entry.key == 'needs_review'),
              ],
            ),
            const SizedBox(height: 24),
            Text('Recent automation events', style: theme.textTheme.titleMedium),
            const SizedBox(height: 8),
            if (data.events.isEmpty) const Text('No automation events yet.'),
            for (final e in data.events)
              Card(
                elevation: 0,
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(8),
                  side: BorderSide(color: theme.colorScheme.outlineVariant),
                ),
                child: ListTile(
                  leading: Icon(_iconFor(e.action), color: _colorFor(e.action, theme.colorScheme)),
                  title: Text('#${e.requestId} · ${e.action.replaceAll('_', ' ')}'
                      '${e.attempt == null ? '' : ' (attempt ${e.attempt})'}'),
                  subtitle: Text([
                    if (e.toStatus != null) '${e.fromStatus ?? '-'} → ${e.toStatus}',
                    if (e.reason != null) e.reason!,
                    '${formatDateTime(e.at)} · Ref ${e.correlationId ?? '-'}',
                  ].join('\n')),
                  isThreeLine: e.reason != null,
                ),
              ),
          ],
        );
      },
    );
  }

  static IconData _iconFor(String action) => switch (action) {
        'generation_succeeded' => Icons.check_circle_outline,
        'generation_retry_scheduled' => Icons.replay,
        'sent_to_review' => Icons.report_problem_outlined,
        'duplicate_skipped' => Icons.content_copy,
        'generation_started' => Icons.play_arrow,
        _ => Icons.smart_toy_outlined,
      };

  static Color _colorFor(String action, ColorScheme scheme) => switch (action) {
        'generation_succeeded' => Colors.green.shade700,
        'generation_retry_scheduled' => Colors.orange.shade800,
        'sent_to_review' => scheme.error,
        _ => scheme.onSurfaceVariant,
      };
}

class _MetricCard extends StatelessWidget {
  const _MetricCard({required this.label, required this.value, this.warn = false});

  final String label;
  final int value;
  final bool warn;

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final highlight = warn && value > 0;
    return SizedBox(
      width: 180,
      child: Card(
        color: highlight ? scheme.errorContainer : null,
        child: Padding(
          padding: const EdgeInsets.all(16),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Text('$value', style: Theme.of(context).textTheme.headlineMedium),
              const SizedBox(height: 4),
              Text(label, style: Theme.of(context).textTheme.bodySmall),
            ],
          ),
        ),
      ),
    );
  }
}
