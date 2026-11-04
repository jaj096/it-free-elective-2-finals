/// Build-time configuration. No secrets live in the Flutter client.
///
/// Set the API URL when running or building (Week 14: HTTPS through Caddy):
///   flutter run -d chrome --dart-define=API_BASE_URL=https://localhost:8443/api/v1
class AppConfig {
  static const String apiBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://localhost:8443/api/v1',
  );

  /// Escape hatch for a plain-HTTP test server only. Never set this for a real build.
  static const bool allowInsecureApi = bool.fromEnvironment('ALLOW_INSECURE_API');

  /// True when the API address uses TLS (or the developer explicitly allowed plain HTTP).
  static bool isSecureUrl(String url) => url.toLowerCase().startsWith('https://') || allowInsecureApi;

  /// How often screens with work in progress refresh themselves.
  static const Duration pollInterval = Duration(seconds: 5);

  /// Breakpoint between the narrow (bottom bar) and wide (side rail) layouts.
  static const double wideLayoutWidth = 900;
}
