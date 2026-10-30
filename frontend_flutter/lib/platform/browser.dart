/// Browser-only helpers (session storage, file download).
///
/// The real implementation is used on the web; a no-op stub is used elsewhere so that
/// `flutter test` can still compile the app on the Dart VM.
library;

export 'browser_stub.dart' if (dart.library.js_interop) 'browser_web.dart';
