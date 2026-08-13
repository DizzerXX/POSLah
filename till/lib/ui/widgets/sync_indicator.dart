import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../till/providers.dart';

/// The only place sync is visible to a cashier.
///
/// A small count in the app chrome, and nothing more. No spinner on checkout, no "syncing…"
/// state, no retry button — the cashier must never wait on or think about sync, and if the
/// checkout screen ever knows the network state then something has leaked. Anything beyond
/// this count belongs in the debug screen behind settings.
class SyncIndicator extends ConsumerWidget {
  const SyncIndicator({super.key});

  @override
  Widget build(BuildContext context, WidgetRef ref) {
    final pending = ref.watch(pendingSyncCountProvider).value ?? 0;

    if (pending == 0) return const SizedBox.shrink();

    return Center(
      child: Chip(
        key: const Key('sync-indicator'),
        visualDensity: VisualDensity.compact,
        avatar: const Icon(Icons.cloud_upload_outlined, size: 16),
        label: Text('$pending pending'),
      ),
    );
  }
}
