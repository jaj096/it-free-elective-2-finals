/// The kinds of failure the UI shows differently (see "UI states" in the design doc).
enum ApiErrorKind {
  validation, // 422
  invalid, // 400: request could not be read
  unauthenticated, // 401
  expired, // 401 TOKEN_EXPIRED: the 8-hour session ended
  insecure, // 403 HTTPS_REQUIRED, or the app was built with an http:// API address
  integrity, // altered or unreadable data (bad response body, hash mismatch, INTEGRITY_FAILED)
  forbidden, // 403
  notFound, // 404
  conflict, // 409
  rateLimited, // 429
  network, // no response / timeout
  server, // 5xx or unreadable response
}

/// A failed API call, already translated into safe, user-facing text.
class ApiException implements Exception {
  ApiException({
    required this.kind,
    required this.message,
    this.statusCode,
    this.code,
    this.fieldErrors = const {},
    this.correlationId,
  });

  final ApiErrorKind kind;
  final String message;
  final int? statusCode;
  final String? code;
  final Map<String, List<String>> fieldErrors;
  final String? correlationId;

  /// First server message for a form field, if any.
  String? fieldError(String field) {
    final errors = fieldErrors[field];
    return (errors == null || errors.isEmpty) ? null : errors.first;
  }

  /// The message shown to users. Server errors never show internals, only the reference.
  String get userMessage {
    switch (kind) {
      case ApiErrorKind.network:
        return "Can't reach the server. Check your connection and try again.";
      case ApiErrorKind.server:
        return correlationId == null
            ? 'Something went wrong. Please try again.'
            : 'Something went wrong. Reference: $correlationId';
      case ApiErrorKind.forbidden:
        return "You don't have access to this.";
      case ApiErrorKind.unauthenticated:
        return code == 'INVALID_CREDENTIALS' ? message : 'Please sign in again.';
      case ApiErrorKind.expired:
        return 'Your session has expired. Please sign in again.';
      case ApiErrorKind.insecure:
        return 'A secure connection is required. Open the app from its https:// address.';
      case ApiErrorKind.integrity:
        return 'The data could not be verified, so it was not used. Please try again; '
            'if it keeps happening, tell the registrar${correlationId == null ? '' : ' (reference $correlationId)'}.';
      case ApiErrorKind.invalid:
        return 'The request was not accepted. Check what you entered and try again.';
      default:
        return message;
    }
  }

  @override
  String toString() => 'ApiException($kind, $statusCode, $code)';
}
