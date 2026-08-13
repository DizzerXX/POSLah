import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../../data/local_database.dart';
import '../../till/providers.dart';
import '../../till/reversal_service.dart';

/// Manager override.
///
/// Returns the authorising user's id, or null if the prompt was dismissed or refused.
///
/// **This is a UX gate and nothing more.** A rebuilt app removes it entirely, so no
/// correctness anywhere may depend on it having run — the server re-reads the authorising
/// user's role when the reversal syncs and rejects the entry if it is not a manager or
/// owner. What this buys is a shop-floor control that stops a cashier voiding sales to
/// pocket the cash, and it has to work with no network, because that is when it is needed.
class ManagerPinDialog extends ConsumerStatefulWidget {
  const ManagerPinDialog({super.key});

  @override
  ConsumerState<ManagerPinDialog> createState() => _ManagerPinDialogState();
}

class _ManagerPinDialogState extends ConsumerState<ManagerPinDialog> {
  final _pinController = TextEditingController();
  String? _selectedUserId;
  String? _error;
  bool _checking = false;

  @override
  void dispose() {
    _pinController.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Manager approval'),
      content: FutureBuilder<List<User>>(
        future: ref.read(reversalServiceProvider).authorisers(),
        builder: (context, snapshot) {
          final authorisers = snapshot.data;
          if (authorisers == null) return const SizedBox(height: 80, child: Center(child: CircularProgressIndicator()));

          if (authorisers.isEmpty) {
            return const Text('No manager is available on this till.');
          }

          _selectedUserId ??= authorisers.first.id;

          return Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<String>(
                key: const Key('authoriser-select'),
                initialValue: _selectedUserId,
                decoration: const InputDecoration(labelText: 'Authorised by'),
                items: [
                  for (final user in authorisers)
                    DropdownMenuItem(value: user.id, child: Text(user.name)),
                ],
                onChanged: (value) => setState(() => _selectedUserId = value),
              ),
              const SizedBox(height: 12),
              TextField(
                key: const Key('pin-field'),
                controller: _pinController,
                autofocus: true,
                obscureText: true,
                keyboardType: TextInputType.number,
                decoration: InputDecoration(
                  labelText: 'PIN',
                  errorText: _error,
                  border: const OutlineInputBorder(),
                ),
                onSubmitted: (_) => _submit(),
              ),
            ],
          );
        },
      ),
      actions: [
        TextButton(
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('Cancel'),
        ),
        FilledButton(
          key: const Key('pin-confirm'),
          onPressed: _checking ? null : _submit,
          child: const Text('Approve'),
        ),
      ],
    );
  }

  Future<void> _submit() async {
    final userId = _selectedUserId;
    if (userId == null) return;

    setState(() {
      _checking = true;
      _error = null;
    });

    final check = await ref.read(reversalServiceProvider).verifyManagerPin(userId, _pinController.text);

    if (!mounted) return;

    if (check.isGranted) {
      Navigator.of(context).pop(userId);

      return;
    }

    setState(() {
      _checking = false;
      _error = switch (check.refusal) {
        PinRefusal.wrongPin => 'Incorrect PIN',
        PinRefusal.notAuthorised => 'That user cannot authorise a void',
        PinRefusal.inactive => 'That user is no longer active',
        PinRefusal.noPinSet => 'No PIN has been set for that user',
        _ => 'Not approved',
      };
    });
  }
}
