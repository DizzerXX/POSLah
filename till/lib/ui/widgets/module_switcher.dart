import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../till/providers.dart';

/// The vertical switcher.
///
/// Renders **only** when there is genuinely a choice: the account has more than one module
/// and the device is not pinned to one. A pinned till registers exactly one module, so this
/// is absent there for the same reason it is absent on a single-vertical account — one rule,
/// not a special case.
class ModuleSwitcher extends ConsumerWidget {
  const ModuleSwitcher({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final registry = ref.watch(moduleRegistryProvider).value;

    if (registry == null || !registry.showsSwitcher) return const SizedBox.shrink();

    final current = registry.current;

    return PopupMenuButton<String>(
      key: const Key('module-switcher'),
      tooltip: 'Switch vertical',
      onSelected: (moduleId) => _switch(context, ref, moduleId),
      itemBuilder: (context) => [
        for (final module in registry.modules)
          PopupMenuItem(
            key: Key('module-option-${module.id}'),
            value: module.id,
            child: Row(
              children: [
                Icon(module.icon, size: 18),
                const SizedBox(width: 10),
                Text(module.displayName),
                if (module.id == current?.id) ...[
                  const Spacer(),
                  const Icon(Icons.check, size: 16),
                ],
              ],
            ),
          ),
      ],
      child: Padding(
        padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(current?.icon ?? Icons.apps, size: 18),
            const SizedBox(width: 8),
            Text(current?.displayName ?? ''),
            const Icon(Icons.arrow_drop_down),
          ],
        ),
      ),
    );
  }

  Future<void> _switch(BuildContext context, WidgetRef ref, String moduleId) async {
    try {
      await ref.read(moduleSwitcherProvider).switchTo(moduleId);
    } on ModuleSwitchBlocked {
      if (!context.mounted) return;

      // Complete or discard, but do not carry a cart across. Migrating it would be a
      // half-implemented mixed cart, and a half-implementation is worse than its absence.
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          key: Key('switch-blocked'),
          content: Text('Finish or clear the current sale before switching.'),
        ),
      );
    }
  }
}
