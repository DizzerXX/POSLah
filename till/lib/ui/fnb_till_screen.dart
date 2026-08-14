import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/local_database.dart';
import '../money.dart';
import '../till/providers.dart';
import 'cart_review_screen.dart';
import 'widgets/modifier_sheet.dart';
import 'widgets/module_switcher.dart';
import 'widgets/sync_indicator.dart';

/// The F&B till.
///
/// Deliberately its own screen rather than the retail one with conditionals in it. The two
/// verticals genuinely differ at the point of adding to a cart — a menu item may carry
/// modifiers and always carries a recipe — and expressing that as a second screen behind the
/// module interface is exactly what the registry is for. What they share (cart, checkout,
/// receipt) is shared as widgets, not duplicated.
class FnbTillScreen extends ConsumerWidget {
  const FnbTillScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider);
    final items = ref.watch(menuItemsProvider);
    final isWide = MediaQuery.sizeOf(context).width >= 900;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Kitchen counter'),
        actions: const [ModuleSwitcher(), SyncIndicator(), SizedBox(width: 12)],
      ),
      body: Row(
        children: [
          Expanded(
            child: items.when(
              loading: () => const Center(child: CircularProgressIndicator()),
              error: (error, _) => Center(child: Text('$error')),
              data: (menu) => menu.isEmpty
                  ? const Center(child: Text('No menu items yet'))
                  : GridView.builder(
                      padding: const EdgeInsets.all(12),
                      gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                        maxCrossAxisExtent: 200,
                        childAspectRatio: 1.15,
                        mainAxisSpacing: 8,
                        crossAxisSpacing: 8,
                      ),
                      itemCount: menu.length,
                      itemBuilder: (context, index) => _MenuTile(item: menu[index]),
                    ),
            ),
          ),
          if (isWide)
            SizedBox(
              width: 360,
              child: DecoratedBox(
                decoration: BoxDecoration(
                  border: Border(left: BorderSide(color: Theme.of(context).dividerColor)),
                ),
                child: const CartPane(),
              ),
            ),
        ],
      ),
      bottomNavigationBar: isWide || cart.isEmpty
          ? null
          : SafeArea(
              child: Padding(
                padding: const EdgeInsets.all(12),
                child: FilledButton(
                  key: const Key('open-cart'),
                  onPressed: () => Navigator.of(context).push(
                    MaterialPageRoute<void>(builder: (_) => const CartReviewScreen()),
                  ),
                  child: Text('${cart.itemCount} items · ${Money.format(cart.grandTotal)}'),
                ),
              ),
            ),
    );
  }
}

class _MenuTile extends ConsumerWidget {
  const _MenuTile({required this.item});

  final MenuItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: Key('menu-${item.product.id}'),
        onTap: () => _add(context, ref),
        child: Padding(
          padding: const EdgeInsets.all(10),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Text(
                item.product.name,
                maxLines: 3,
                overflow: TextOverflow.ellipsis,
                style: Theme.of(context).textTheme.titleSmall,
              ),
              Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  if (item.modifiers.isNotEmpty)
                    Text('${item.modifiers.length} options',
                        style: Theme.of(context).textTheme.bodySmall),
                  Text(
                    Money.format(item.product.price),
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  _MakeableHint(productId: item.product.id),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    var chosen = <Modifier>[];

    if (item.modifiers.isNotEmpty) {
      final result = await showModalBottomSheet<List<Modifier>>(
        context: context,
        builder: (context) => ModifierSheet(item: item),
      );

      if (result == null) return;

      chosen = result;
    }

    ref.read(cartProvider.notifier).addMenuItem(
          item.product,
          recipe: item.recipe,
          modifiers: chosen,
        );
  }
}

/// "How many can I make" — a minimum across ingredients, computed and cached rather than
/// recomputed on every frame.
///
/// Advisory, and it will sometimes be wrong: another till may have sold the same ingredients
/// since the last sync. That is acceptable precisely because it never blocks anything.
class _MakeableHint extends ConsumerWidget {
  const _MakeableHint({required this.productId});

  final String productId;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final hint = ref.watch(makeableHintProvider(productId)).value;

    if (hint == null || !hint.isLow) return const SizedBox.shrink();

    return Text(
      hint.quantity <= 0 ? 'Out of ingredients' : 'Only ${hint.quantity} left',
      style: Theme.of(context).textTheme.bodySmall?.copyWith(
            color: Theme.of(context).colorScheme.error,
          ),
    );
  }
}
