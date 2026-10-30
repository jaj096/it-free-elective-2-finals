import 'package:flutter/material.dart';

/// One label, icon and colour per status. The text label is always shown,
/// so the status never depends on colour alone (accessibility).
class StatusChip extends StatelessWidget {
  const StatusChip({super.key, required this.status, required this.label});

  final String status;
  final String label;

  static (Color, IconData) styleFor(String status, ColorScheme scheme) => switch (status) {
        'submitted' => (scheme.secondary, Icons.inbox_outlined),
        'approved' || 'queued' => (Colors.blueGrey, Icons.schedule),
        'generating' => (Colors.indigo, Icons.autorenew),
        'retrying' => (Colors.orange.shade800, Icons.replay),
        'generated' => (Colors.teal, Icons.description_outlined),
        'verified' => (Colors.teal.shade800, Icons.verified_outlined),
        'released' => (Colors.green.shade700, Icons.download_done),
        'acknowledged' => (Colors.green.shade900, Icons.task_alt),
        'archived' => (Colors.grey.shade700, Icons.archive_outlined),
        'needs_review' => (scheme.error, Icons.report_problem_outlined),
        'rejected' || 'cancelled' => (Colors.grey.shade800, Icons.block),
        _ => (scheme.outline, Icons.help_outline),
      };

  @override
  Widget build(BuildContext context) {
    final (color, icon) = styleFor(status, Theme.of(context).colorScheme);
    return Semantics(
      label: 'Status: $label',
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4),
        decoration: BoxDecoration(
          color: color.withAlpha(28),
          border: Border.all(color: color),
          borderRadius: BorderRadius.circular(16),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 16, color: color),
            const SizedBox(width: 6),
            Text(label, style: TextStyle(color: color, fontWeight: FontWeight.w600, fontSize: 13)),
          ],
        ),
      ),
    );
  }
}
