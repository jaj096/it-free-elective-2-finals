import 'dart:convert';

import 'package:certificate_queue_web/api/api_client.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

/// Week 15 (W5): the client follows every page of GET /requests, so nothing is silently cut off.
void main() {
  Map<String, dynamic> request(int id) => {
        'id': id,
        'certificate_type': 'enrollment',
        'certificate_type_label': 'Certificate of Enrollment',
        'purpose': 'Scholarship application',
        'status': 'submitted',
        'status_label': 'Submitted',
        'status_message': 'Waiting for approval',
        'attempts': 0,
        'max_attempts': 3,
      };

  test('reads all pages using meta.last_page', () async {
    final pagesAsked = <String>[];
    final api = ApiClient(
      baseUrl: 'https://localhost:8443/api/v1',
      httpClient: MockClient((req) async {
        final page = req.url.queryParameters['page']!;
        pagesAsked.add(page);
        expect(req.url.queryParameters['per_page'], '100');
        final ids = page == '1' ? [1, 2] : (page == '2' ? [3, 4] : [5]);
        return http.Response(
          jsonEncode({
            'data': ids.map(request).toList(),
            'meta': {'current_page': int.parse(page), 'last_page': 3, 'total': 5},
          }),
          200,
        );
      }),
    )..token = 'token';

    final items = await api.listRequests();

    expect(pagesAsked, ['1', '2', '3']);
    expect(items.map((r) => r.id), [1, 2, 3, 4, 5]);
  });

  test('a response without meta (older API) is read as a single page', () async {
    var calls = 0;
    final api = ApiClient(
      baseUrl: 'https://localhost:8443/api/v1',
      httpClient: MockClient((_) async {
        calls++;
        return http.Response(jsonEncode({'data': [request(7)]}), 200);
      }),
    )..token = 'token';

    final items = await api.listRequests();

    expect(calls, 1);
    expect(items.single.id, 7);
  });
}
