import 'package:flutter/material.dart';

import 'api/api_client.dart';
import 'screens/home_shell.dart';
import 'screens/login_screen.dart';
import 'state/session.dart';

void main() {
  final session = Session(ApiClient());
  runApp(CertificateQueueApp(session: session));
}

class CertificateQueueApp extends StatelessWidget {
  const CertificateQueueApp({super.key, required this.session});

  final Session session;

  @override
  Widget build(BuildContext context) {
    return SessionScope(
      session: session,
      child: MaterialApp(
        title: 'Certificate Queue',
        debugShowCheckedModeBanner: false,
        theme: ThemeData(
          colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF1E4E8C)),
          useMaterial3: true,
        ),
        // Rebuilds when the session changes: signed in -> HomeShell, otherwise -> LoginScreen.
        home: const _Root(),
      ),
    );
  }
}

class _Root extends StatelessWidget {
  const _Root();

  @override
  Widget build(BuildContext context) {
    final session = SessionScope.of(context);
    return session.isSignedIn
        ? HomeShell(key: ValueKey('shell-${session.user!.id}'))
        : const LoginScreen();
  }
}
