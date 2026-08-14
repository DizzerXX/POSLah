import 'pos_module.dart';

/// The modules this till may show.
///
/// Built at sign-in from the account's entitlements and the device's pinning, and rebuilt on
/// every pull-sync. Screen code asks this for behaviour; it never asks what kind of shop it
/// is in.
class ModuleRegistry {
  ModuleRegistry(this._modules, {String? activeId})
      : _activeId = activeId ?? (_modules.isEmpty ? null : _modules.first.id);

  final List<PosModule> _modules;
  String? _activeId;

  /// Only the modules that were registered. An unentitled vertical is absent entirely —
  /// there is no flag to check and nothing to hide.
  List<PosModule> get modules => List.unmodifiable(_modules);

  bool get isEmpty => _modules.isEmpty;

  PosModule? get current {
    if (_modules.isEmpty) return null;

    return _modules.firstWhere((m) => m.id == _activeId, orElse: () => _modules.first);
  }

  bool has(String moduleId) => _modules.any((m) => m.id == moduleId);

  /// The switcher renders only when there is genuinely a choice to make. A device pinned to
  /// one vertical registers one module, so this is false there for the same reason it is
  /// false for a single-vertical account — one rule, not two.
  bool get showsSwitcher => _modules.length > 1;

  void activate(String moduleId) {
    if (!has(moduleId)) {
      throw StateError('Module $moduleId is not registered on this till.');
    }

    _activeId = moduleId;
  }

  /// The routes of every registered module, flattened.
  ///
  /// Deep-linking to an unregistered module's route produces a not-found, because the route
  /// does not exist in this build of the navigation tree — not an access-denied screen,
  /// which would imply the screen is there and merely withheld.
  Map<String, ModuleRoute> get routes {
    return {
      for (final module in _modules)
        for (final route in module.routes) route.path: route,
    };
  }

  /// Builds a registry from what the server said and what the device is pinned to.
  ///
  /// [assignedModule] wins over the account's list: a merchant running both verticals
  /// usually dedicates a physical till to one of them, and pinning removes the switcher from
  /// that till entirely along with a whole class of cashier error.
  static ModuleRegistry build({
    required List<String> enabledModules,
    required Map<String, PosModule> available,
    String? assignedModule,
    String? activeId,
  }) {
    var ids = enabledModules.where(available.containsKey).toList();

    if (assignedModule != null && ids.contains(assignedModule)) {
      ids = [assignedModule];
    }

    return ModuleRegistry(
      [for (final id in ids) available[id]!],
      activeId: activeId != null && ids.contains(activeId) ? activeId : null,
    );
  }
}
