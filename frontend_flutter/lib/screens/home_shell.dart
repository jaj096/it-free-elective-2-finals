import 'package:flutter/material.dart';

import '../api/api_client.dart';
import '../api/api_exception.dart';
import '../api/models.dart';
import '../config.dart';
import '../state/session.dart';
import '../widgets/dialogs.dart';
import '../widgets/request_list.dart';
import 'automation_screen.dart';
import 'new_request_screen.dart';

/// One destination in the role-based navigation.
class _Destination {
  const _Destination(this.label, this.icon, this.builder);

  final String label;
  final IconData icon;
  final Widget Function(BuildContext context, void Function(int) goTo) builder;
}

/// Responsive shell: side navigation rail on wide screens, bottom bar on narrow ones.
/// Each role sees only its own menu; the server enforces the same rules.
class HomeShell extends StatefulWidget {
  const HomeShell({super.key});

  @override
  State<HomeShell> createState() => _HomeShellState();
}

class _HomeShellState extends State<HomeShell> {
  int _index = 0;
  int _unread = 0;

  @override
  void initState() {
    super.initState();
    _loadUnread();
  }

  Future<void> _loadUnread() async {
    try {
      final result = await SessionScope.read(context).api.notifications();
      if (mounted) setState(() => _unread = result.unread);
    } on ApiException {
      // The badge is optional; ignore failures here.
    }
  }

  List<_Destination> _destinationsFor(UserRole role) {
    final api = SessionScope.read(context).api;
    switch (role) {
      case UserRole.requester:
        return [
          _Destination('My requests', Icons.list_alt, (context, goTo) => RequestList(
                load: () => api.listRequests(),
                emptyMessage: 'You have no certificate requests yet.',
                emptyAction: FilledButton.icon(
                  onPressed: () => goTo(1),
                  icon: const Icon(Icons.add),
                  label: const Text('New request'),
                ),
              )),
          _Destination('New request', Icons.add_circle_outline, (context, goTo) => NewRequestScreen(onSubmitted: () => goTo(0))),
        ];
      case UserRole.approver:
        return [
          _Destination('Approvals', Icons.fact_check_outlined, (context, goTo) => const _StaffRequests(
                filters: {'submitted': 'Waiting for approval', '': 'All requests'},
              )),
          _Destination('Review queue', Icons.report_problem_outlined, (context, goTo) => RequestList(
                load: api.reviewQueue,
                emptyMessage: 'Nothing needs review. Permanent failures will appear here.',
                showRequester: true,
              )),
          _Destination('Automation', Icons.monitor_heart_outlined, (context, goTo) => const AutomationScreen()),
        ];
      case UserRole.custodian:
        return [
          _Destination('Verify & release', Icons.verified_outlined, (context, goTo) => const _StaffRequests(
                filters: {
                  'generated': 'To verify',
                  'verified': 'To release',
                  'acknowledged': 'To archive',
                  'released': 'Released',
                },
              )),
          _Destination('Automation', Icons.monitor_heart_outlined, (context, goTo) => const AutomationScreen()),
        ];
    }
  }

  Future<void> _showNotifications() async {
    final api = SessionScope.read(context).api;
    await showDialog<void>(context: context, builder: (_) => _NotificationsDialog(api: api));
    try {
      await api.markNotificationsRead();
    } on ApiException {
      // Not critical.
    }
    if (mounted) setState(() => _unread = 0);
  }

  Future<void> _signOut() async {
    if (await confirmAction(context, title: 'Sign out?', message: 'You will need to sign in again.', confirmLabel: 'Sign out')) {
      if (!mounted) return;
      await SessionScope.read(context).signOut();
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = SessionScope.of(context).user!;
    final destinations = _destinationsFor(user.role);
    final int index = _index < destinations.length ? _index : 0;
    void goTo(int i) => setState(() => _index = i);

    final body = KeyedSubtree(
      key: ValueKey('page-$index'),
      child: destinations[index].builder(context, goTo),
    );

    return LayoutBuilder(builder: (context, constraints) {
      final wide = constraints.maxWidth >= AppConfig.wideLayoutWidth;
      return Scaffold(
        appBar: AppBar(
          title: Text(wide ? 'Certificate Queue · ${destinations[index].label}' : destinations[index].label),
          actions: [
            IconButton(
              tooltip: 'Notifications${_unread > 0 ? ' ($_unread unread)' : ''}',
              onPressed: _showNotifications,
              icon: Badge(
                isLabelVisible: _unread > 0,
                label: Text('$_unread'),
                child: const Icon(Icons.notifications_outlined),
              ),
            ),
            if (wide)
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 8),
                child: Center(child: Text('${user.name} · ${user.roleLabel}')),
              ),
            IconButton(tooltip: 'Sign out', onPressed: _signOut, icon: const Icon(Icons.logout)),
          ],
        ),
        body: wide
            ? Row(
                children: [
                  NavigationRail(
                    selectedIndex: index,
                    onDestinationSelected: goTo,
                    labelType: NavigationRailLabelType.all,
                    destinations: [
                      for (final d in destinations) NavigationRailDestination(icon: Icon(d.icon), label: Text(d.label)),
                    ],
                  ),
                  const VerticalDivider(width: 1),
                  Expanded(child: body),
                ],
              )
            : body,
        bottomNavigationBar: wide
            ? null
            : NavigationBar(
                selectedIndex: index,
                onDestinationSelected: goTo,
                destinations: [
                  for (final d in destinations) NavigationDestination(icon: Icon(d.icon), label: d.label),
                ],
              ),
      );
    });
  }
}

/// Staff list with filter chips (one API status filter at a time).
class _StaffRequests extends StatefulWidget {
  const _StaffRequests({required this.filters});

  /// status value -> chip label. An empty status means "all".
  final Map<String, String> filters;

  @override
  State<_StaffRequests> createState() => _StaffRequestsState();
}

class _StaffRequestsState extends State<_StaffRequests> {
  late String _status = widget.filters.keys.first;

  @override
  Widget build(BuildContext context) {
    final api = SessionScope.read(context).api;
    return RequestList(
      // A new key per filter makes the list reload with the new filter.
      key: ValueKey('staff-$_status'),
      load: () => api.listRequests(status: _status.isEmpty ? null : _status),
      emptyMessage: 'No requests in "${widget.filters[_status]}".',
      showRequester: true,
      header: Padding(
        padding: const EdgeInsets.fromLTRB(12, 12, 12, 0),
        child: Wrap(
          spacing: 8,
          runSpacing: 8,
          children: [
            for (final entry in widget.filters.entries)
              ChoiceChip(
                label: Text(entry.value),
                selected: _status == entry.key,
                onSelected: (_) => setState(() => _status = entry.key),
              ),
          ],
        ),
      ),
    );
  }
}

class _NotificationsDialog extends StatelessWidget {
  const _NotificationsDialog({required this.api});

  final ApiClient api;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Notifications'),
      content: SizedBox(
        width: 420,
        height: 360,
        child: FutureBuilder<({List<AppNotification> items, int unread})>(
          future: api.notifications(),
          builder: (context, snapshot) {
            if (snapshot.connectionState != ConnectionState.done) {
              return const Center(child: CircularProgressIndicator());
            }
            if (snapshot.hasError) {
              final e = snapshot.error;
              return Center(child: Text(e is ApiException ? e.userMessage : 'Could not load notifications.'));
            }
            final items = snapshot.data!.items;
            if (items.isEmpty) return const Center(child: Text('No notifications yet.'));
            return ListView.separated(
              itemCount: items.length,
              separatorBuilder: (_, __) => const Divider(height: 1),
              itemBuilder: (context, i) => ListTile(
                leading: Icon(items[i].read ? Icons.notifications_none : Icons.notifications_active,
                    color: items[i].read ? null : Theme.of(context).colorScheme.primary),
                title: Text(items[i].message),
                subtitle: Text(formatDateTime(items[i].at)),
              ),
            );
          },
        ),
      ),
      actions: [TextButton(onPressed: () => Navigator.pop(context), child: const Text('Close'))],
    );
  }
}
