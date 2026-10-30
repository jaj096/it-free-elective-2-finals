import 'dart:async';
import 'dart:convert';
import 'dart:math';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../config.dart';
import 'api_exception.dart';
import 'models.dart';

/// The only class that talks to the Laravel API.
///
/// Every response is turned into either data or an [ApiException] with a safe message,
/// so screens never parse HTTP details themselves.
class ApiClient {
  ApiClient({http.Client? httpClient, String? baseUrl})
      : _http = httpClient ?? http.Client(),
        _baseUrl = baseUrl ?? AppConfig.apiBaseUrl;

  final http.Client _http;
  final String _baseUrl;
  static const _timeout = Duration(seconds: 20);

  String? token;

  /// Called when the server says the token is missing or expired (401).
  void Function()? onUnauthenticated;

  // ---------------------------------------------------------------- auth

  Future<({String token, AppUser user})> login(String email, String password) async {
    final json = await _send('POST', '/auth/login', body: {'email': email, 'password': password}, auth: false);
    final data = json['data'] as Map<String, dynamic>;
    return (token: data['token'] as String, user: AppUser.fromJson(data['user'] as Map<String, dynamic>));
  }

  Future<void> logout() async {
    try {
      await _send('POST', '/auth/logout');
    } on ApiException {
      // Already signed out on the server; nothing else to do.
    }
  }

  Future<AppUser> me() async => AppUser.fromJson((await _send('GET', '/auth/me'))['data'] as Map<String, dynamic>);

  // ------------------------------------------------------------ requests

  Future<List<CertRequest>> listRequests({String? status}) async {
    final json = await _send('GET', '/requests', query: {if (status != null) 'status': status});
    return _list(json, CertRequest.fromJson);
  }

  Future<CertRequest> getRequest(int id) async => _item(await _send('GET', '/requests/$id'), CertRequest.fromJson);

  /// [idempotencyKey] stays the same for one form, so a double-click or a retry
  /// after a network error can't create two requests.
  Future<CertRequest> submitRequest({
    required String certificateType,
    required String purpose,
    required String idempotencyKey,
  }) async {
    final json = await _send('POST', '/requests',
        body: {'certificate_type': certificateType, 'purpose': purpose},
        headers: {'Idempotency-Key': idempotencyKey});
    return _item(json, CertRequest.fromJson);
  }

  Future<CertRequest> approve(int id) async => _item(await _send('POST', '/requests/$id/approve'), CertRequest.fromJson);

  Future<CertRequest> reject(int id, String reason) async =>
      _item(await _send('POST', '/requests/$id/reject', body: {'reason': reason}), CertRequest.fromJson);

  Future<List<CertRequest>> reviewQueue() async => _list(await _send('GET', '/review-queue'), CertRequest.fromJson);

  Future<CertRequest> requeue(int id, {String? requesterIdNumber, String? note}) async {
    final body = <String, dynamic>{
      if (requesterIdNumber != null && requesterIdNumber.isNotEmpty) 'requester_id_number': requesterIdNumber,
      if (note != null && note.isNotEmpty) 'note': note,
    };
    return _item(await _send('POST', '/requests/$id/requeue', body: body), CertRequest.fromJson);
  }

  Future<CertRequest> cancel(int id, String reason) async =>
      _item(await _send('POST', '/requests/$id/cancel', body: {'reason': reason}), CertRequest.fromJson);

  // -------------------------------------------------------- certificates

  Future<CertRequest> verify(int certificateId, String certificateNumber) async => _item(
      await _send('POST', '/certificates/$certificateId/verify', body: {'certificate_number': certificateNumber}),
      CertRequest.fromJson);

  Future<CertRequest> release(int certificateId) async =>
      _item(await _send('POST', '/certificates/$certificateId/release'), CertRequest.fromJson);

  Future<CertRequest> acknowledge(int certificateId) async =>
      _item(await _send('POST', '/certificates/$certificateId/acknowledge'), CertRequest.fromJson);

  Future<CertRequest> archive(int certificateId) async =>
      _item(await _send('POST', '/certificates/$certificateId/archive'), CertRequest.fromJson);

  /// Downloads the PDF bytes with the bearer token (a plain link can't send the token).
  Future<Uint8List> downloadCertificate(int certificateId) async {
    final response = await _raw('GET', '/certificates/$certificateId/download', accept: 'application/pdf');
    if (response.statusCode == 200) return response.bodyBytes;
    throw _toException(response);
  }

  // ---------------------------------------------------- automation & misc

  Future<AutomationSnapshot> automation() async =>
      AutomationSnapshot.fromJson((await _send('GET', '/automation/jobs'))['data'] as Map<String, dynamic>);

  Future<({List<AppNotification> items, int unread})> notifications() async {
    final json = await _send('GET', '/notifications');
    final meta = (json['meta'] as Map?) ?? {};
    return (items: _list(json, AppNotification.fromJson), unread: (meta['unread'] as int?) ?? 0);
  }

  Future<void> markNotificationsRead() async {
    await _send('POST', '/notifications/read');
  }

  /// Random key for Idempotency-Key headers (no extra package needed).
  static String newIdempotencyKey() {
    final r = Random.secure();
    String hex(int n) => List.generate(n, (_) => r.nextInt(16).toRadixString(16)).join();
    return '${hex(8)}-${hex(4)}-${hex(4)}-${hex(4)}-${hex(12)}';
  }

  // ------------------------------------------------------------ internals

  List<T> _list<T>(Map<String, dynamic> json, T Function(Map<String, dynamic>) parse) =>
      ((json['data'] as List?) ?? []).whereType<Map<String, dynamic>>().map(parse).toList();

  T _item<T>(Map<String, dynamic> json, T Function(Map<String, dynamic>) parse) =>
      parse(json['data'] as Map<String, dynamic>);

  Future<Map<String, dynamic>> _send(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
    Map<String, String>? headers,
    bool auth = true,
  }) async {
    final response = await _raw(method, path, body: body, query: query, headers: headers, auth: auth);
    if (response.statusCode >= 200 && response.statusCode < 300) {
      if (response.body.isEmpty) return <String, dynamic>{};
      try {
        final decoded = jsonDecode(response.body);
        return decoded is Map<String, dynamic> ? decoded : <String, dynamic>{'data': decoded};
      } on FormatException {
        throw ApiException(kind: ApiErrorKind.server, message: 'Unreadable server response.');
      }
    }
    throw _toException(response, notifyOn401: auth);
  }

  Future<http.Response> _raw(
    String method,
    String path, {
    Map<String, dynamic>? body,
    Map<String, String>? query,
    Map<String, String>? headers,
    bool auth = true,
    String accept = 'application/json',
  }) async {
    final uri = Uri.parse('$_baseUrl$path').replace(queryParameters: (query == null || query.isEmpty) ? null : query);
    final request = http.Request(method, uri)
      ..headers.addAll({
        'Accept': accept,
        if (body != null) 'Content-Type': 'application/json',
        if (auth && token != null) 'Authorization': 'Bearer $token',
        ...?headers,
      });
    if (body != null) request.body = jsonEncode(body);

    try {
      final streamed = await _http.send(request).timeout(_timeout);
      return await http.Response.fromStream(streamed).timeout(_timeout);
    } on TimeoutException {
      throw ApiException(kind: ApiErrorKind.network, message: 'The server took too long to respond.');
    } on http.ClientException {
      throw ApiException(kind: ApiErrorKind.network, message: "Can't reach the server.");
    }
  }

  ApiException _toException(http.Response response, {bool notifyOn401 = true}) {
    final status = response.statusCode;
    Map<String, dynamic> error = {};
    try {
      final decoded = jsonDecode(response.body);
      if (decoded is Map && decoded['error'] is Map) error = Map<String, dynamic>.from(decoded['error'] as Map);
    } catch (_) {
      // Non-JSON error body (e.g. a proxy page); fall back to generic text below.
    }

    final fields = <String, List<String>>{};
    final rawFields = error['fields'];
    if (rawFields is Map) {
      rawFields.forEach((key, value) {
        fields['$key'] = value is List ? value.map((e) => '$e').toList() : ['$value'];
      });
    }

    final kind = switch (status) {
      401 => ApiErrorKind.unauthenticated,
      403 => ApiErrorKind.forbidden,
      404 => ApiErrorKind.notFound,
      409 => ApiErrorKind.conflict,
      422 => ApiErrorKind.validation,
      429 => ApiErrorKind.rateLimited,
      _ => status >= 500 ? ApiErrorKind.server : ApiErrorKind.conflict,
    };

    final code = error['code'] as String?;
    // A wrong password is also a 401, but it must not end the (non-existent) session.
    if (kind == ApiErrorKind.unauthenticated && notifyOn401 && code != 'INVALID_CREDENTIALS') {
      onUnauthenticated?.call();
    }

    return ApiException(
      kind: kind,
      statusCode: status,
      code: code,
      message: (error['message'] as String?) ?? 'Request failed (HTTP $status).',
      fieldErrors: fields,
      correlationId: (error['correlation_id'] as String?) ?? response.headers['x-correlation-id'],
    );
  }
}
