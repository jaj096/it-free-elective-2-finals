/// Plain data classes for API responses. Parsing is defensive: missing fields
/// fall back to safe defaults instead of crashing the UI.

String _s(dynamic v, [String fallback = '']) => v == null ? fallback : v.toString();
int _i(dynamic v, [int fallback = 0]) => v is int ? v : int.tryParse('${v ?? ''}') ?? fallback;
DateTime? _d(dynamic v) => v == null ? null : DateTime.tryParse(v.toString())?.toLocal();

enum UserRole { requester, approver, custodian }

UserRole _role(String value) => switch (value) {
      'approver' => UserRole.approver,
      'custodian' => UserRole.custodian,
      _ => UserRole.requester,
    };

class AppUser {
  AppUser({required this.id, required this.name, required this.email, required this.role, required this.roleLabel});

  factory AppUser.fromJson(Map<String, dynamic> j) => AppUser(
        id: _i(j['id']),
        name: _s(j['name']),
        email: _s(j['email']),
        role: _role(_s(j['role'])),
        roleLabel: _s(j['role_label']),
      );

  final int id;
  final String name;
  final String email;
  final UserRole role;
  final String roleLabel;

  Map<String, dynamic> toJson() => {
        'id': id,
        'name': name,
        'email': email,
        'role': role.name,
        'role_label': roleLabel,
      };
}

class CertificateInfo {
  CertificateInfo({
    required this.id,
    required this.number,
    this.sha256,
    this.generatedAt,
    this.verifiedAt,
    this.releasedAt,
    this.acknowledgedAt,
    this.archivedAt,
    required this.downloadable,
  });

  factory CertificateInfo.fromJson(Map<String, dynamic> j) => CertificateInfo(
        id: _i(j['id']),
        number: _s(j['certificate_number']),
        sha256: j['sha256'] as String?,
        generatedAt: _d(j['generated_at']),
        verifiedAt: _d(j['verified_at']),
        releasedAt: _d(j['released_at']),
        acknowledgedAt: _d(j['acknowledged_at']),
        archivedAt: _d(j['archived_at']),
        downloadable: j['downloadable'] == true,
      );

  final int id;
  final String number;
  final String? sha256;
  final DateTime? generatedAt;
  final DateTime? verifiedAt;
  final DateTime? releasedAt;
  final DateTime? acknowledgedAt;
  final DateTime? archivedAt;
  final bool downloadable;
}

class HistoryEntry {
  HistoryEntry({
    required this.action,
    this.fromStatus,
    this.toStatus,
    required this.actor,
    this.attempt,
    this.reason,
    this.correlationId,
    this.at,
  });

  factory HistoryEntry.fromJson(Map<String, dynamic> j) => HistoryEntry(
        action: _s(j['action']),
        fromStatus: j['from_status'] as String?,
        toStatus: j['to_status'] as String?,
        actor: _s(j['actor'], 'System'),
        attempt: j['attempt'] == null ? null : _i(j['attempt']),
        reason: j['reason'] as String?,
        correlationId: j['correlation_id'] as String?,
        at: _d(j['at']),
      );

  final String action;
  final String? fromStatus;
  final String? toStatus;
  final String actor;
  final int? attempt;
  final String? reason;
  final String? correlationId;
  final DateTime? at;
}

class CertRequest {
  CertRequest({
    required this.id,
    required this.certificateType,
    required this.certificateTypeLabel,
    required this.purpose,
    required this.status,
    required this.statusLabel,
    required this.statusMessage,
    required this.attempts,
    required this.maxAttempts,
    this.rejectionReason,
    this.reviewReason,
    this.requesterName,
    this.requesterIdNumber,
    this.certificate,
    this.history = const [],
    this.createdAt,
    this.updatedAt,
  });

  factory CertRequest.fromJson(Map<String, dynamic> j) {
    final requester = j['requester'];
    final cert = j['certificate'];
    final history = j['history'];
    return CertRequest(
      id: _i(j['id']),
      certificateType: _s(j['certificate_type']),
      certificateTypeLabel: _s(j['certificate_type_label']),
      purpose: _s(j['purpose']),
      status: _s(j['status']),
      statusLabel: _s(j['status_label']),
      statusMessage: _s(j['status_message']),
      attempts: _i(j['attempts']),
      maxAttempts: _i(j['max_attempts'], 3),
      rejectionReason: j['rejection_reason'] as String?,
      reviewReason: j['review_reason'] as String?,
      requesterName: requester is Map ? _s(requester['name']) : null,
      requesterIdNumber: requester is Map ? requester['id_number'] as String? : null,
      certificate: cert is Map<String, dynamic> ? CertificateInfo.fromJson(cert) : null,
      history: history is List
          ? history.whereType<Map<String, dynamic>>().map(HistoryEntry.fromJson).toList()
          : const [],
      createdAt: _d(j['created_at']),
      updatedAt: _d(j['updated_at']),
    );
  }

  final int id;
  final String certificateType;
  final String certificateTypeLabel;
  final String purpose;
  final String status;
  final String statusLabel;
  final String statusMessage;
  final int attempts;
  final int maxAttempts;
  final String? rejectionReason;
  final String? reviewReason;
  final String? requesterName;
  final String? requesterIdNumber;
  final CertificateInfo? certificate;
  final List<HistoryEntry> history;
  final DateTime? createdAt;
  final DateTime? updatedAt;

  /// True while the background job may still change the status.
  bool get isInProgress => const ['approved', 'queued', 'generating', 'retrying'].contains(status);
}

class AppNotification {
  AppNotification({required this.id, required this.message, required this.read, this.requestId, this.at});

  factory AppNotification.fromJson(Map<String, dynamic> j) => AppNotification(
        id: _s(j['id']),
        message: _s(j['message']),
        read: j['read'] == true,
        requestId: j['request_id'] == null ? null : _i(j['request_id']),
        at: _d(j['at']),
      );

  final String id;
  final String message;
  final bool read;
  final int? requestId;
  final DateTime? at;
}

class AutomationEvent {
  AutomationEvent({
    required this.requestId,
    required this.action,
    this.fromStatus,
    this.toStatus,
    this.attempt,
    this.reason,
    this.correlationId,
    this.at,
  });

  factory AutomationEvent.fromJson(Map<String, dynamic> j) => AutomationEvent(
        requestId: _i(j['request_id']),
        action: _s(j['action']),
        fromStatus: j['from_status'] as String?,
        toStatus: j['to_status'] as String?,
        attempt: j['attempt'] == null ? null : _i(j['attempt']),
        reason: j['reason'] as String?,
        correlationId: j['correlation_id'] as String?,
        at: _d(j['at']),
      );

  final int requestId;
  final String action;
  final String? fromStatus;
  final String? toStatus;
  final int? attempt;
  final String? reason;
  final String? correlationId;
  final DateTime? at;
}

class AutomationSnapshot {
  AutomationSnapshot({required this.metrics, required this.events});

  factory AutomationSnapshot.fromJson(Map<String, dynamic> j) {
    final metrics = (j['metrics'] as Map?) ?? {};
    final events = (j['events'] as List?) ?? [];
    return AutomationSnapshot(
      metrics: {
        for (final key in [
          'queue_depth',
          'needs_review',
          'generated_total',
          'retries_total',
          'sent_to_review_total',
          'duplicates_skipped_total',
          'failed_jobs',
        ])
          key: _i(metrics[key]),
      },
      events: events.whereType<Map<String, dynamic>>().map(AutomationEvent.fromJson).toList(),
    );
  }

  final Map<String, int> metrics;
  final List<AutomationEvent> events;
}

/// Certificate types offered in the request form (must match the backend enum).
const certificateTypes = <String, String>{
  'enrollment': 'Certificate of Enrollment',
  'good_moral': 'Certificate of Good Moral Character',
  'grades': 'Certification of Grades',
  'graduation': 'Certificate of Graduation',
};
