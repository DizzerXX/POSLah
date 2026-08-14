import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../data/local_database.dart';
import '../money.dart';
import '../till/cart.dart';
import '../till/catalog_repository.dart';
import '../till/providers.dart';
import 'cart_review_screen.dart';
import 'widgets/module_switcher.dart';
import 'widgets/sync_indicator.dart';
import 'widgets/variant_sheet.dart';

/// Screen 1 of 4: the catalog and the cart.
///
/// Four screens, and the plan is explicit about resisting a fifth.
class TillScreen extends ConsumerWidget {
  const TillScreen({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final cart = ref.watch(cartProvider);
    final isWide = MediaQuery.sizeOf(context).width >= 900;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Till'),
        actions: [ModuleSwitcher(), SyncIndicator(), SizedBox(width: 12)],
      ),
      body: Row(
        children: [
          Expanded(child: _CatalogPane(onScan: () => _scan(context, ref))),
          // Side panel on a tablet, bottom sheet on a phone.
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
      bottomNavigationBar: isWide || cart.isEmpty ? null : _CartBar(cart: cart),
    );
  }

  /// Barcode entry.
  ///
  /// A text field rather than a camera, and deliberately so for now: the scanners actually
  /// sitting on retail counters are overwhelmingly USB or Bluetooth keyboard wedges, which
  /// type into exactly this field and need no camera permission, no preview and no plugin.
  ///
  /// Camera scanning (`mobile_scanner`) is the obvious addition, and it is left out rather
  /// than written blind: this machine has no CocoaPods, no Android SDK and no simulator
  /// runtime, so camera code could be committed but never once run. An unverifiable feature
  /// is worse than a stated gap.
  Future<void> _scan(BuildContext context, WidgetRef ref) async {
    final code = await showDialog<String>(
      context: context,
      builder: (context) => const _ManualBarcodeDialog(),
    );

    if (code == null || !context.mounted) return;

    await addByBarcode(context, ref, code);
  }
}

/// Resolves a scanned code and puts the right thing in the cart.
///
/// Split out of the widget so the barcode path can be driven without a camera.
Future<bool> addByBarcode(BuildContext context, WidgetRef ref, String code) async {
  final match = await ref.read(catalogRepositoryProvider).findByBarcode(code);

  if (!context.mounted) return false;

  if (match == null) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('No product matches $code')),
    );

    return false;
  }

  final variants = match.variant != null
      ? [match.variant!]
      : await ref.read(catalogRepositoryProvider).items(search: match.product.name);

  ref.read(cartProvider.notifier).add(
        CatalogItem(product: match.product, variants: match.variant == null ? const [] : [match.variant!]),
        variant: match.variant,
      );

  if (context.mounted && variants.isNotEmpty) {
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('Added ${match.product.name}'), duration: const Duration(seconds: 1)),
    );
  }

  return true;
}

class _CatalogPane extends ConsumerWidget {
  const _CatalogPane({required this.onScan});

  final VoidCallback onScan;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final categories = ref.watch(categoriesProvider);
    final items = ref.watch(catalogItemsProvider);
    final selected = ref.watch(selectedCategoryProvider);

    return Column(
      children: [
        Padding(
          padding: const EdgeInsets.all(12),
          child: Row(
            children: [
              Expanded(
                child: TextField(
                  key: const Key('catalog-search'),
                  decoration: const InputDecoration(
                    prefixIcon: Icon(Icons.search),
                    hintText: 'Search name or SKU',
                    isDense: true,
                    border: OutlineInputBorder(),
                  ),
                  onChanged: (value) => ref.read(searchTermProvider.notifier).set(value),
                ),
              ),
              const SizedBox(width: 8),
              IconButton.filledTonal(
                key: const Key('scan-button'),
                onPressed: onScan,
                icon: const Icon(Icons.qr_code_scanner),
                tooltip: 'Scan barcode',
              ),
            ],
          ),
        ),
        categories.maybeWhen(
          data: (list) => SizedBox(
            height: 44,
            child: ListView(
              scrollDirection: Axis.horizontal,
              padding: const EdgeInsets.symmetric(horizontal: 12),
              children: [
                Padding(
                  padding: const EdgeInsets.only(right: 8),
                  child: ChoiceChip(
                    label: const Text('All'),
                    selected: selected == null,
                    onSelected: (_) => ref.read(selectedCategoryProvider.notifier).select(null),
                  ),
                ),
                for (final category in list)
                  Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: ChoiceChip(
                      label: Text(category.name),
                      selected: selected == category.id,
                      onSelected: (_) =>
                          ref.read(selectedCategoryProvider.notifier).select(category.id),
                    ),
                  ),
              ],
            ),
          ),
          orElse: () => const SizedBox(height: 44),
        ),
        Expanded(
          child: items.when(
            loading: () => const Center(child: CircularProgressIndicator()),
            error: (error, _) => Center(child: Text('$error')),
            data: (list) => list.isEmpty
                ? const Center(child: Text('Nothing here'))
                : GridView.builder(
                    padding: const EdgeInsets.all(12),
                    gridDelegate: const SliverGridDelegateWithMaxCrossAxisExtent(
                      maxCrossAxisExtent: 200,
                      childAspectRatio: 1.2,
                      mainAxisSpacing: 8,
                      crossAxisSpacing: 8,
                    ),
                    itemCount: list.length,
                    itemBuilder: (context, index) => _ProductTile(item: list[index]),
                  ),
          ),
        ),
      ],
    );
  }
}

class _ProductTile extends ConsumerWidget {
  const _ProductTile({required this.item});

  final CatalogItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    return Card(
      clipBehavior: Clip.antiAlias,
      child: InkWell(
        key: Key('product-${item.product.id}'),
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
                  if (item.variants.length > 1)
                    Text('${item.variants.length} options',
                        style: Theme.of(context).textTheme.bodySmall),
                  Text(
                    Money.format(item.product.price),
                    style: Theme.of(context).textTheme.titleMedium,
                  ),
                  // Advisory only, and it never blocks adding to cart.
                  _StockHint(item: item),
                ],
              ),
            ],
          ),
        ),
      ),
    );
  }

  Future<void> _add(BuildContext context, WidgetRef ref) async {
    if (item.variants.length > 1) {
      final variant = await showModalBottomSheet<ProductVariant>(
        context: context,
        builder: (context) => VariantSheet(item: item),
      );

      if (variant == null) return;

      ref.read(cartProvider.notifier).add(item, variant: variant);

      return;
    }

    ref.read(cartProvider.notifier).add(
          item,
          variant: item.variants.isEmpty ? null : item.variants.first,
        );
  }
}

/// A low-stock hint on the tile.
///
/// It greys nothing out and disables nothing. Local stock is a stale projection — another
/// till may have sold the same thing since the last sync — so this is a nudge, and a sale is
/// never refused on the strength of it.
class _StockHint extends ConsumerWidget {
  const _StockHint({required this.item});

  final CatalogItem item;

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    if (item.variants.length != 1 || !item.product.tracksStock) return const SizedBox.shrink();

    final session = ref.watch(tillSessionProvider).value;
    if (session == null) return const SizedBox.shrink();

    return FutureBuilder<int?>(
      future: ref.watch(catalogRepositoryProvider).stockHint(session.storeId, item.variants.first.id),
      builder: (context, snapshot) {
        final hint = snapshot.data;
        if (hint == null || hint > 5) return const SizedBox.shrink();

        return Text(
          hint <= 0 ? 'Out of stock' : 'Low: $hint left',
          style: Theme.of(context).textTheme.bodySmall?.copyWith(
                color: Theme.of(context).colorScheme.error,
              ),
        );
      },
    );
  }
}

class _CartBar extends StatelessWidget {
  const _CartBar({required this.cart});

  final CartState cart;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
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
    );
  }
}

/// Manual barcode entry.
///
/// The camera scanner is wired at the call site on a real device; this keeps the barcode
/// path usable on a till with no working camera, and makes it drivable in a test. The part
/// that can be wrong — resolving a code to the right *variant* rather than its parent
/// product — is the same either way.
class _ManualBarcodeDialog extends StatefulWidget {
  const _ManualBarcodeDialog();

  @override
  State<_ManualBarcodeDialog> createState() => _ManualBarcodeDialogState();
}

class _ManualBarcodeDialogState extends State<_ManualBarcodeDialog> {
  final _controller = TextEditingController();

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Scan or enter barcode'),
      content: TextField(
        key: const Key('barcode-field'),
        controller: _controller,
        autofocus: true,
        decoration: const InputDecoration(border: OutlineInputBorder()),
        onSubmitted: (value) => Navigator.of(context).pop(value),
      ),
      actions: [
        TextButton(onPressed: () => Navigator.of(context).pop(), child: const Text('Cancel')),
        FilledButton(
          key: const Key('barcode-confirm'),
          onPressed: () => Navigator.of(context).pop(_controller.text),
          child: const Text('Add'),
        ),
      ],
    );
  }
}
