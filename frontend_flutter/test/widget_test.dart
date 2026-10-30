import 'package:certificate_queue_web/api/api_exception.dart';
import 'package:certificate_queue_web/widgets/async_view.dart';
import 'package:certificate_queue_web/widgets/status_chip.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

/// Starter widget tests (expanded in Week 15).
void main() {
  Widget wrap(Widget child) => MaterialApp(home: Scaffold(body: child));

  testWidgets('StatusChip always shows its text label', (tester) async {
    await tester.pumpWidget(wrap(const StatusChip(status: 'needs_review', label: 'Needs review')));
    expect(find.text('Needs review'), findsOneWidget);
  });

  testWidgets('AsyncView shows the empty state', (tester) async {
    await tester.pumpWidget(wrap(AsyncView<List<int>>(
      load: () async => <int>[],
      isEmpty: (d) => d.isEmpty,
      emptyMessage: 'No requests yet',
      builder: (_, __, ___) => const Text('data'),
    )));
    await tester.pumpAndSettle();
    expect(find.text('No requests yet'), findsOneWidget);
  });

  testWidgets('AsyncView shows a safe server error with the reference only', (tester) async {
    await tester.pumpWidget(wrap(AsyncView<int>(
      load: () async => throw ApiException(
        kind: ApiErrorKind.server,
        message: 'SQLSTATE[HY000] internal detail',
        correlationId: 'c-1234abcd',
      ),
      builder: (_, __, ___) => const Text('data'),
    )));
    await tester.pumpAndSettle();
    expect(find.text('Something went wrong. Reference: c-1234abcd'), findsOneWidget);
    expect(find.textContaining('SQLSTATE'), findsNothing);
    expect(find.text('Retry'), findsOneWidget);
  });
}
