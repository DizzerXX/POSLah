import 'package:flutter/material.dart';

import '../../money.dart';
import '../../till/providers.dart';

/// Which extras, before the item joins the cart.
///
/// A modifier that consumes an ingredient decrements it *on top of* the base recipe — an
/// extra shot is nine more grams of beans — so choosing here is a stock decision as well as
/// a pricing one.
class ModifierSheet extends StatefulWidget {
  const ModifierSheet({required this.item, super.key});

  final MenuItem item;

  @override
  State<ModifierSheet> createState() => _ModifierSheetState();
}

class _ModifierSheetState extends State<ModifierSheet> {
  final _chosen = <String>{};

  @override
  Widget build(BuildContext context) {
    final extra = widget.item.modifiers
        .where((m) => _chosen.contains(m.id))
        .fold(0, (sum, m) => sum + m.priceDelta);

    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(widget.item.product.name, style: Theme.of(context).textTheme.titleMedium),
          ),
          for (final modifier in widget.item.modifiers)
            CheckboxListTile(
              key: Key('modifier-${modifier.id}'),
              value: _chosen.contains(modifier.id),
              title: Text(modifier.name),
              subtitle: modifier.priceDelta == 0
                  ? null
                  : Text('+${Money.format(modifier.priceDelta)}'),
              onChanged: (selected) => setState(() {
                selected == true ? _chosen.add(modifier.id) : _chosen.remove(modifier.id);
              }),
            ),
          Padding(
            padding: const EdgeInsets.all(12),
            child: FilledButton(
              key: const Key('confirm-modifiers'),
              onPressed: () => Navigator.of(context).pop(
                widget.item.modifiers.where((m) => _chosen.contains(m.id)).toList(),
              ),
              child: Text('Add · ${Money.format(widget.item.product.price + extra)}'),
            ),
          ),
        ],
      ),
    );
  }
}
