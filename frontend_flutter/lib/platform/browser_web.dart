import 'dart:js_interop';
import 'dart:typed_data';

import 'package:web/web.dart' as web;

/// The token is kept in sessionStorage: it survives a page refresh but is cleared
/// when the tab closes. (Limitation: any script injected into the page could read it;
/// see the security notes in the README.)
String? readSession(String key) => web.window.sessionStorage.getItem(key);

void writeSession(String key, String value) => web.window.sessionStorage.setItem(key, value);

void removeSession(String key) => web.window.sessionStorage.removeItem(key);

/// Saves bytes as a file by creating a temporary object URL and clicking a hidden link.
void saveFile(Uint8List bytes, String filename, String mimeType) {
  final blob = web.Blob(<JSAny>[bytes.toJS].toJS, web.BlobPropertyBag(type: mimeType));
  final url = web.URL.createObjectURL(blob);
  final anchor = web.HTMLAnchorElement()
    ..href = url
    ..download = filename
    ..style.display = 'none';
  web.document.body?.appendChild(anchor);
  anchor.click();
  anchor.remove();
  web.URL.revokeObjectURL(url);
}
