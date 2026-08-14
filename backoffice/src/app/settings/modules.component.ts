import { Component, computed, inject, signal } from '@angular/core';

import { ApiService } from '../core/api.service';

/**
 * Turning a vertical on or off.
 *
 * Toggling one off names what becomes inaccessible, because "F&B" on its own does not tell a
 * merchant that their recipes and kitchen screens are about to disappear from every till.
 *
 * Nothing is pushed to the devices. Each till re-reads its entitlements on its next pull,
 * which is the same mechanism that stops a till left signed in for three weeks running on
 * three-week-old permissions.
 */
@Component({
  selector: 'app-modules',
  standalone: true,
  template: `
    <header>
      <h1>Modules</h1>
      <p class="muted">What this account sells, and therefore what every till shows.</p>
    </header>

    <div class="cards">
      @for (module of modules; track module.id) {
        <label class="card" [class.on]="isEnabled(module.id)">
          <input
            type="checkbox"
            [checked]="isEnabled(module.id)"
            [disabled]="busy() || !canEdit()"
            (change)="toggle(module.id)"
            [attr.data-testid]="'toggle-' + module.id"
          />
          <div>
            <strong>{{ module.name }}</strong>
            <p class="muted">{{ module.blurb }}</p>
            @if (isEnabled(module.id)) {
              <p class="warn">Turning this off hides {{ module.hides }} on every till.</p>
            }
          </div>
        </label>
      }
    </div>

    @if (!canEdit()) {
      <p class="muted">Only an owner can change this.</p>
    }

    @if (error()) {
      <p class="error" data-testid="modules-error">{{ error() }}</p>
    }
  `,
  styles: `
    :host { display: block; max-width: 720px; padding: 32px 28px; }
    h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
    header p { margin: 4px 0 0; }
    .muted { color: #64748b; font-size: 14px; }
    .cards { display: grid; gap: 12px; margin-top: 24px; }
    .card {
      display: flex; gap: 14px; padding: 18px; background: #fff; border: 1px solid #e2e8f0;
      border-radius: 12px; cursor: pointer;
    }
    .card.on { border-color: #0f766e; box-shadow: inset 3px 0 0 #0f766e; }
    .card p { margin: 4px 0 0; }
    .warn { color: #b45309; font-size: 13px; }
    .error { margin-top: 16px; color: #b91c1c; }
  `,
})
export class ModulesComponent {
  private readonly api = inject(ApiService);

  readonly modules = [
    {
      id: 'retail',
      name: 'Retail',
      blurb: 'SKUs and variants. One product, one stockable, one movement.',
      hides: 'SKU and variant stock',
    },
    {
      id: 'fnb',
      name: 'F&B',
      blurb: 'Menu items made from ingredients. One product, several stockables.',
      hides: 'ingredients, recipes and the kitchen screen',
    },
  ];

  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  readonly canEdit = computed(() => this.api.user()?.role === 'owner');

  isEnabled(moduleId: string): boolean {
    return this.api.hasModule(moduleId);
  }

  toggle(moduleId: string): void {
    const current = this.api.account()?.enabled_modules ?? [];

    const next = current.includes(moduleId)
      ? current.filter((m) => m !== moduleId)
      : [...current, moduleId];

    // Refused locally *and* by the API *and* by a database CHECK. An account with no modules
    // is a state that cannot exist, rather than one every screen has to handle.
    if (next.length === 0) {
      this.error.set('An account must keep at least one module.');

      return;
    }

    this.busy.set(true);
    this.error.set(null);

    this.api.updateModules(next).subscribe({
      next: () => this.busy.set(false),
      error: (response) => {
        this.busy.set(false);
        this.error.set(response.error?.message ?? 'Could not change modules.');
      },
    });
  }
}
