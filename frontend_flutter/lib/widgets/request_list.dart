import 'package:flutter/material.dart';

import '../api/models.dart';
import '../config.dart';
import 'async_view.dart';
import 'request_card.dart';
import 'request_detail.dart';

/// Master-detail list used by every queue screen.
///
/// Wide screens (>= 900 px): list on the left, detail panel on the right.
/// Narrow screens: tapping a card opens the detail as a full page.
class RequestList extends StatefulWidget {
  const RequestList({
    super.key,
    required this.load,
    required this.emptyMessage,
    this.emptyAction,
    this.showRequester = false,
    this.header,
  });

  final Future<List<CertRequest>> Function() load;
  final String emptyMessage;
  final Widget? emptyAction;
  final bool showRequester;

  /// Optional filter row shown above the list.
  final Widget? header;

  @override
  State<RequestList> createState() => _RequestListState();
}

class _RequestListState extends State<RequestList> {
  final _listKey = GlobalKey<AsyncViewState<List<CertRequest>>>();
  int? _selectedId;

  void _refreshList() => _listKey.currentState?.reload(quiet: true);

  void _open(BuildContext context, CertRequest r, bool wide) {
    if (wide) {
      setState(() => _selectedId = r.id);
      return;
    }
    Navigator.of(context).push(MaterialPageRoute<void>(
      builder: (_) => Scaffold(
        appBar: AppBar(title: Text('Request #${r.id}')),
        body: RequestDetail(requestId: r.id, onChanged: _refreshList),
      ),
    ));
  }

  @override
  Widget build(BuildContext context) {
    return LayoutBuilder(builder: (context, constraints) {
      final wide = constraints.maxWidth >= AppConfig.wideLayoutWidth;

      final list = Column(
        children: [
          if (widget.header != null) widget.header!,
          Expanded(
            child: AsyncView<List<CertRequest>>(
              key: _listKey,
              load: widget.load,
              isEmpty: (items) => items.isEmpty,
              emptyMessage: widget.emptyMessage,
              emptyAction: widget.emptyAction,
              pollWhile: (items) => items.any((r) => r.isInProgress),
              builder: (context, items, reload) => ListView.separated(
                padding: const EdgeInsets.all(12),
                itemCount: items.length,
                separatorBuilder: (_, __) => const SizedBox(height: 8),
                itemBuilder: (context, i) => RequestCard(
                  request: items[i],
                  selected: wide && items[i].id == _selectedId,
                  showRequester: widget.showRequester,
                  onTap: () => _open(context, items[i], wide),
                ),
              ),
            ),
          ),
        ],
      );

      if (!wide) return list;

      return Row(
        children: [
          SizedBox(width: 420, child: list),
          const VerticalDivider(width: 1),
          Expanded(
            child: _selectedId == null
                ? Center(
                    child: Text('Select a request to see details.',
                        style: Theme.of(context).textTheme.titleMedium),
                  )
                : RequestDetail(requestId: _selectedId!, onChanged: _refreshList),
          ),
        ],
      );
    });
  }
}
