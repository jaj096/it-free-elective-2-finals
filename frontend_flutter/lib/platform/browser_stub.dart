import 'dart:typed_data';

/// Non-web fallback: keeps the token in memory only.
final Map<String, String> _memory = {};

String? readSession(String key) => _memory[key];

void writeSession(String key, String value) => _memory[key] = value;

void removeSession(String key) => _memory.remove(key);

void saveFile(Uint8List bytes, String filename, String mimeType) {
  // File download is only available in the browser.
}
