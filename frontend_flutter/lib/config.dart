/// Build-time configuration. No secrets live in the Flutter client.
///
/// Set the API URL when running or building:
///   flutter run -d chrome --dart-define=API_BASE_URL=http://localhost:8000/api/v1
class AppConfig {
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://localhost:8000/api/v1',
  );

  /// How often screens with work in progress refresh themselves.
  static const Duration pollInterval = Duration(seconds: 5);

  /// Breakpoint between the narrow (bottom bar) and wide (side rail) layouts.
  static const double wideLayoutWidth = 900;
}
