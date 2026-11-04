import 'dart:convert';
import 'dart:typed_data';

import 'package:certificate_queue_web/api/api_client.dart';
import 'package:certificate_queue_web/api/api_exception.dart';
import 'package:crypto/crypto.dart' as crypto;
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Week 14: the client shows safe, specific messages for invalid, altered, expired and
/// unauthorized responses, and never talks to the API over plain HTTP.
void main() {
  const base = 'https://localhost:8443/api/v1';

  ApiClient clientReturning(int status, Object body, {List<bool>? expiredCalls}) {
    final api = ApiClient(
      baseUrl: base,
      httpClient: MockClient((_) async => http.Response(body is String ? body : jsonEncode(body), status)),
    )..token = 'token';
    api.onUnauthenticated = ({bool expired = false}) => expiredCalls?.add(expired);
    return api;
  }

  Map<String, dynamic> error(String code, String message) =>
      {'error': {'code': code, 'message': message, 'correlation_id': 'c-12345678'}};

  Future<ApiException> failure(ApiClient api) async {
    try {
      await api.me();
    } on ApiException catch (e) {
      return e;
    }
    fail('expected an ApiException');
  }

  test('expired token -> expired message and session ends', () async {
    final calls = <bool>[];
    final e = await failure(clientReturning(401, error('TOKEN_EXPIRED', 'Your session has expired.'), expiredCalls: calls));
    expect(e.kind, ApiErrorKind.expired);
    expect(e.userMessage, 'Your session has expired. Please sign in again.');
    expect(calls, [true]);
  });

  test('unauthorized -> sign in again, forbidden -> no access', () async {
    final calls = <bool>[];
    final e401 = await failure(clientReturning(401, error('UNAUTHENTICATED', 'x'), expiredCalls: calls));
    expect(e401.userMessage, 'Please sign in again.');
    expect(calls, [false]);

    final e403 = await failure(clientReturning(403, error('FORBIDDEN', 'internal detail')));
    expect(e403.userMessage, "You don't have access to this.");
  });

  test('wrong password shows the server message and does not end a session', () async {
    final calls = <bool>[];
    final api = clientReturning(401, error('INVALID_CREDENTIALS', 'Email or password is incorrect.'), expiredCalls: calls);
    try {
      await api.login('a@example.test', 'bad');
      fail('expected failure');
    } on ApiException catch (e) {
      expect(e.userMessage, 'Email or password is incorrect.');
    }
    expect(calls, isEmpty);
  });

  test('HTTPS_REQUIRED -> secure connection message', () async {
    final e = await failure(clientReturning(403, error('HTTPS_REQUIRED', 'Use https')));
    expect(e.kind, ApiErrorKind.insecure);
    expect(e.userMessage, contains('secure connection'));
  });

  test('invalid (400) -> safe generic message, no server internals', () async {
    final e = await failure(clientReturning(400, error('BAD_REQUEST', 'SQLSTATE parse error at line 1')));
    expect(e.kind, ApiErrorKind.invalid);
    expect(e.userMessage, isNot(contains('SQLSTATE')));
  });

  test('altered/unreadable response body -> integrity message', () async {
    final e = await failure(clientReturning(200, '{"data": {"id": 1, "na'));
    expect(e.kind, ApiErrorKind.integrity);
    expect(e.userMessage, contains('could not be verified'));
  });

  test('INTEGRITY_FAILED from server -> integrity message with reference', () async {
    final e = await failure(clientReturning(409, error('INTEGRITY_FAILED', 'hash mismatch')));
    expect(e.kind, ApiErrorKind.integrity);
    expect(e.userMessage, contains('c-12345678'));
    expect(e.userMessage, isNot(contains('hash mismatch')));
  });

  test('plain http API address is refused before anything is sent', () async {
    var sent = 0;
    final api = ApiClient(
      baseUrl: 'http://localhost:8000/api/v1',
      httpClient: MockClient((_) async {
        sent++;
        return http.Response('{}', 200);
      }),
    );
    try {
      await api.login('juan@example.test', 'Password123!');
      fail('expected failure');
    } on ApiException catch (e) {
      expect(e.kind, ApiErrorKind.insecure);
    }
    expect(sent, 0);
  });

  test('downloaded certificate must match the X-Content-SHA256 hash', () {
    final good = Uint8List.fromList(utf8.encode('%PDF-1.4\nok'));
    final hash = crypto.sha256.convert(good).toString();

    expect(() => ApiClient.verifyDownload(good, hash), returnsNormally);

    final altered = Uint8List.fromList(utf8.encode('%PDF-1.4\nXX'));
    expect(
      () => ApiClient.verifyDownload(altered, hash),
      throwsA(isA<ApiException>().having((e) => e.kind, 'kind', ApiErrorKind.integrity)),
    );
    expect(() => ApiClient.verifyDownload(good, null), throwsA(isA<ApiException>()));
  });
}
