import 'package:flutter/material.dart';

import '../api/models.dart';
import 'dialogs.dart';
import 'status_chip.dart';

/// Summary row used by every request list.
class RequestCard extends StatelessWidget {
  const RequestCard({super.key, required this.request, required this.onTap, this.selected = false, this.showRequester = false});

  final CertRequest request;
  final VoidCallback onTap;
  final bool selected;
  final bool showRequester;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Card(
      elevation: 0,
      color: selected ? theme.colorScheme.secondaryContainer : null,
      shape: RoundedRectangleBorder(
        borderRadius: BorderRadius.circular(12),
        side: BorderSide(color: theme.colorScheme.outlineVariant),
      ),
      child: InkWell(
        borderRadius: BorderRadius.circular(12),
        onTap: onTap,
        child: Padding(
          padding: const EdgeInsets.all(14),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Expanded(
                    child: Text('#${request.id} · ${request.certificateTypeLabel}',
                        style: theme.textTheme.titleSmall, overflow: TextOverflow.ellipsis),
                  ),
                  const SizedBox(width: 8),
                  StatusChip(status: request.status, label: request.statusLabel),
                ],
              ),
              const SizedBox(height: 6),
              if (showRequester && request.requesterName != null)
                Text('${request.requesterName} (${request.requesterIdNumber ?? 'no ID number'})',
                    style: theme.textTheme.bodySmall),
              Text(request.purpose, maxLines: 1, overflow: TextOverflow.ellipsis),
              const SizedBox(height: 6),
              Row(
                children: [
                  if (request.isInProgress) ...[
                    const SizedBox(width: 12, height: 12, child: CircularProgressIndicator(strokeWidth: 2)),
                    const SizedBox(width: 8),
                  ],
                  Expanded(
                    child: Text(request.statusMessage,
                        style: theme.textTheme.bodySmall?.copyWith(color: theme.colorScheme.onSurfaceVariant),
                        overflow: TextOverflow.ellipsis),
                  ),
                  Text(formatDateTime(request.updatedAt), style: theme.textTheme.bodySmall),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }
}
