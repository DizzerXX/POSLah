import 'package:flutter/material.dart';

import '../../money.dart';
import '../../till/catalog_repository.dart';

/// Which size, which colour.
///
/// A variant is the retail stockable, so choosing one here is what decides both the price
/// and which thing gets decremented.
class VariantSheet extends StatelessWidget {
  const VariantSheet({required this.item, super.key});

  final CatalogItem item;

  @override
  Widget build(BuildContext context) {
    return SafeArea(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: Text(item.product.name, style: Theme.of(context).textTheme.titleMedium),
          ),
          for (final variant in item.variants)
            ListTile(
              key: Key('variant-${variant.id}'),
              title: Text(variant.name),
              subtitle: variant.sku == null ? null : Text(variant.sku!),
              trailing: Text(Money.format(item.priceFor(variant))),
              onTap: () => Navigator.of(context).pop(variant),
            ),
          const SizedBox(height: 8),
        ],
      ),
    );
  }
}
