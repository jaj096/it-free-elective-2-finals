import 'dart:convert';

import 'package:flutter/widgets.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../api/models.dart';
import '../platform/browser.dart';

/// Holds the signed-in user and token, and tells the app when that changes.
class Session extends ChangeNotifier {
  Session(this.api) {
    api.onUnauthenticated = () => _clear(message: 'Your session ended. Please sign in again.');
    _restore();
  }

  final ApiClient api;
  static const _tokenKey = 'cq_token';
  static const _userKey = 'cq_user';

  AppUser? user;

  /// Shown once on the login screen (e.g. after the session expired).
  String? notice;

  bool get isSignedIn => user != null && api.token != null;

  void _restore() {
    final token = readSession(_tokenKey);
    final userJson = readSession(_userKey);
    if (token == null || userJson == null) return;
    try {
      api.token = token;
      user = AppUser.fromJson(jsonDecode(userJson) as Map<String, dynamic>);
    } catch (_) {
      _clear();
    }
  }

  /// Throws [ApiException] with a user-facing message on failure.
  Future<void> signIn(String email, String password) async {
    final result = await api.login(email.trim().toLowerCase(), password);
    api.token = result.token;
    user = result.user;
    notice = null;
    writeSession(_tokenKey, result.token);
    writeSession(_userKey, jsonEncode(result.user.toJson()));
    notifyListeners();
  }

  Future<void> signOut() async {
    await api.logout();
    _clear();
  }

  void _clear({String? message}) {
    final wasSignedIn = user != null;
    api.token = null;
    user = null;
    notice = message;
    removeSession(_tokenKey);
    removeSession(_userKey);
    if (wasSignedIn || message != null) notifyListeners();
  }
}

/// Makes the [Session] available to any widget below it.
class SessionScope extends InheritedNotifier<Session> {
  const SessionScope({super.key, required Session session, required super.child}) : super(notifier: session);

  static Session of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<SessionScope>();
    assert(scope != null, 'SessionScope not found');
    return scope!.notifier!;
  }

  /// Read the session without rebuilding when it changes (for callbacks).
  static Session read(BuildContext context) {
    final scope = context.getInheritedWidgetOfExactType<SessionScope>();
    return scope!.notifier!;
  }
}
