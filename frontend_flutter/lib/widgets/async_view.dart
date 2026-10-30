import 'dart:async';

import 'package:flutter/material.dart';

import '../api/api_exception.dart';
import '../config.dart';

/// Wraps an API call and always renders exactly one of:
/// loading, error (network / server / forbidden / ...), empty, or data.
///
/// If [pollWhile] returns true for the loaded data, it reloads every few seconds,
/// so automation progress (queued -> generating -> retrying -> generated) shows live.
class AsyncView<T> extends StatefulWidget {
  const AsyncView({
    super.key,
    required this.load,
    required this.builder,
    this.isEmpty,
    this.emptyMessage = 'Nothing here yet.',
    this.emptyAction,
    this.pollWhile,
  });

  final Future<T> Function() load;
  final Widget Function(BuildContext context, T data, Future<void> Function() reload) builder;
  final bool Function(T data)? isEmpty;
  final String emptyMessage;
  final Widget? emptyAction;
  final bool Function(T data)? pollWhile;

  @override
  State<AsyncView<T>> createState() => AsyncViewState<T>();
}

class AsyncViewState<T> extends State<AsyncView<T>> {
  T? _data;
  ApiException? _error;
  bool _loading = true;
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    reload();
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  Future<void> reload({bool quiet = false}) async {
    _timer?.cancel();
    if (!quiet) {
      setState(() {
        _loading = true;
        _error = null;
      });
    }
    try {
      final data = await widget.load();
      if (!mounted) return;
      setState(() {
        _data = data;
        _error = null;
        _loading = false;
      });
      if (widget.pollWhile?.call(data) ?? false) {
        _timer = Timer(AppConfig.pollInterval, () => reload(quiet: true));
      }
    } on ApiException catch (e) {
      if (!mounted) return;
      setState(() {
        _error = e;
        _loading = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    if (_loading && _data == null) {
      return const Center(
        child: Semantics(label: 'Loading', child: CircularProgressIndicator()),
      );
    }

    final error = _error;
    if (error != null && _data == null) {
      return ErrorPanel(error: error, onRetry: reload);
    }

    final data = _data as T;
    if (widget.isEmpty?.call(data) ?? false) {
      return EmptyPanel(message: widget.emptyMessage, action: widget.emptyAction, onRefresh: reload);
    }

    return Column(
      children: [
        if (_loading) const LinearProgressIndicator(minHeight: 2),
        if (error != null) ErrorBanner(error: error, onRetry: reload),
        Expanded(child: widget.builder(context, data, reload)),
      ],
    );
  }
}

/// Full-area error state with a retry button.
class ErrorPanel extends StatelessWidget {
  const ErrorPanel({super.key, required this.error, required this.onRetry});

  final ApiException error;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    final icon = switch (error.kind) {
      ApiErrorKind.network => Icons.wifi_off,
      ApiErrorKind.forbidden => Icons.lock_outline,
      ApiErrorKind.notFound => Icons.search_off,
      _ => Icons.error_outline,
    };
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(icon, size: 48, color: Theme.of(context).colorScheme.error),
            const SizedBox(height: 12),
            Text(error.userMessage, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 16),
            if (error.kind != ApiErrorKind.forbidden)
              FilledButton.icon(onPressed: onRetry, icon: const Icon(Icons.refresh), label: const Text('Retry')),
          ],
        ),
      ),
    );
  }
}

/// Thin banner shown above existing data when a background refresh fails.
class ErrorBanner extends StatelessWidget {
  const ErrorBanner({super.key, required this.error, required this.onRetry});

  final ApiException error;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return MaterialBanner(
      content: Text(error.userMessage),
      leading: const Icon(Icons.warning_amber),
      actions: [TextButton(onPressed: onRetry, child: const Text('Retry'))],
    );
  }
}

class EmptyPanel extends StatelessWidget {
  const EmptyPanel({super.key, required this.message, this.action, required this.onRefresh});

  final String message;
  final Widget? action;
  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(Icons.inbox_outlined, size: 48, color: Theme.of(context).colorScheme.outline),
            const SizedBox(height: 12),
            Text(message, textAlign: TextAlign.center, style: Theme.of(context).textTheme.titleMedium),
            const SizedBox(height: 16),
            Wrap(
              spacing: 12,
              children: [
                if (action != null) action!,
                OutlinedButton.icon(onPressed: onRefresh, icon: const Icon(Icons.refresh), label: const Text('Refresh')),
              ],
            ),
          ],
        ),
      ),
    );
  }
}
