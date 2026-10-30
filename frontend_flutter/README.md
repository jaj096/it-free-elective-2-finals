# Flutter Web client – Certificate Generation Queue

```bash
flutter pub get
flutter run -d chrome --web-port=5000 --dart-define=API_BASE_URL=http://localhost:8000/api/v1
```

Build for deployment: `flutter build web --dart-define=API_BASE_URL=https://your-api/api/v1`

No secrets are stored in this app. The API URL is the only build-time setting.
See the main README in the repository root for the full setup.
