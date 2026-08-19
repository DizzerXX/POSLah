import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { ApiService } from '../core/api.service';
import { PosSettings } from '../core/models';

interface PendingChange {
  field: string;
  label: string;
  from: string;
  to: string;
  /** Why this one is worth a second look. Null for the ordinary ones. */
  warning: string | null;
}

/**
 * Account and store settings.
 *
 * **Every change is confirmed before it is applied**, and the confirmation says what is
 * actually at stake rather than asking "are you sure?" about everything equally — changing a
 * phone number and relabelling every historical figure are not the same act, and a dialog
 * that treats them identically trains people to dismiss it.
 *
 * Saving is per field rather than one form-wide button. A settings page that saves eight
 * things at once makes it impossible to say what a confirmation is confirming.
 */
@Component({
  selector: 'app-settings',
  standalone: true,
  imports: [FormsModule],
  template: `
    <header>
      <h1>Settings</h1>
      <p class="muted">How this business is set up. Changes reach every till on its next sync.</p>
    </header>

    @if (loading()) {
      <p class="muted">Loading…</p>
    }

    <!-- Its own block, not chained: Angular allows the alias only on a primary condition. -->
    @if (settings(); as active) {
      @if (!active.can_edit) {
        <p class="notice" data-testid="read-only">Only an owner can change these.</p>
      }

      <section class="group">
        <h2>Business</h2>

        <label class="field">
          <span>Timezone</span>
          <select [(ngModel)]="form['timezone']" [disabled]="!active.can_edit" data-testid="timezone">
            @for (zone of timezones; track zone) {
              <option [value]="zone">{{ zone }}</option>
            }
          </select>
          <button class="save" [disabled]="!dirty('timezone', active)" (click)="ask('timezone', active)">
            Save
          </button>
        </label>

        <label class="field">
          <span>Currency</span>
          <select [(ngModel)]="form['currency']" [disabled]="!active.can_edit" data-testid="currency">
            @for (code of currencies; track code) {
              <option [value]="code">{{ code }}</option>
            }
          </select>
          <button class="save" [disabled]="!dirty('currency', active)" (click)="ask('currency', active)">
            Save
          </button>
        </label>
      </section>

      <section class="group">
        <h2>Store</h2>

        @for (field of storeFields; track field.key) {
          <label class="field">
            <span>{{ field.label }}</span>
            <input
              [type]="field.type"
              [(ngModel)]="form[field.key]"
              [disabled]="!active.can_edit"
              [attr.data-testid]="field.key"
            />
            <button class="save" [disabled]="!dirty(field.key, active)" (click)="ask(field.key, active)">
              Save
            </button>
          </label>
        }
      </section>
    }

    @if (pending(); as change) {
      <div class="backdrop" (click)="pending.set(null)">
        <div class="dialog" (click)="$event.stopPropagation()" data-testid="confirm-dialog">
          <h3>Change {{ change.label }}?</h3>
          <p class="diff">
            <span class="from">{{ change.from || '—' }}</span>
            <span class="arrow">→</span>
            <span class="to">{{ change.to || '—' }}</span>
          </p>

          @if (change.warning) {
            <p class="warning" data-testid="confirm-warning">{{ change.warning }}</p>
          }

          <div class="actions">
            <button class="ghost" (click)="pending.set(null)">Cancel</button>
            <button class="primary" (click)="apply()" [disabled]="saving()" data-testid="confirm-save">
              {{ saving() ? 'Saving…' : 'Save change' }}
            </button>
          </div>
        </div>
      </div>
    }

    @if (saved(); as message) {
      <div class="toast" data-testid="saved-toast">{{ message }}</div>
    }

    @if (error()) {
      <p class="error">{{ error() }}</p>
    }
  `,
  styles: `
    :host { display: block; max-width: 720px; padding: 32px 28px; }
    h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
    header p { margin: 4px 0 0; }
    .muted { color: #64748b; font-size: 14px; }
    .notice {
      margin: 18px 0 0; padding: 10px 14px; border-radius: 10px;
      background: #f1f5f9; color: #475569; font-size: 13px;
    }
    .group { margin-top: 26px; }
    h2 { margin: 0 0 10px; font-size: 13px; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; }
    .field {
      display: grid; grid-template-columns: 160px 1fr auto; align-items: center; gap: 12px;
      padding: 12px 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px;
      margin-bottom: 8px; font-size: 14px;
    }
    .field > span { font-weight: 600; color: #334155; }
    input, select { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; font: inherit; }
    .save {
      padding: 8px 14px; border: 0; border-radius: 8px; background: #0f766e; color: #fff;
      font-weight: 600; font-size: 13px; cursor: pointer;
    }
    .save:disabled { opacity: 0.35; cursor: default; }
    .backdrop { position: fixed; inset: 0; background: rgb(15 23 42 / 45%); display: grid; place-items: center; }
    .dialog { width: min(440px, 90vw); padding: 22px; background: #fff; border-radius: 14px; display: grid; gap: 12px; }
    .dialog h3 { margin: 0; font-size: 17px; }
    .diff { display: flex; align-items: center; gap: 10px; margin: 0; font-size: 14px; }
    .from { color: #94a3b8; text-decoration: line-through; }
    .arrow { color: #94a3b8; }
    .to { font-weight: 700; }
    .warning {
      margin: 0; padding: 10px 12px; border-radius: 8px; background: #fffbeb; color: #92400e;
      font-size: 13px; line-height: 1.45;
    }
    .actions { display: flex; justify-content: flex-end; gap: 8px; }
    .primary { padding: 9px 16px; border: 0; border-radius: 8px; background: #0f766e; color: #fff; font-weight: 600; cursor: pointer; }
    .ghost { padding: 9px 14px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; cursor: pointer; }
    .toast {
      position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%);
      padding: 12px 20px; border-radius: 999px; background: #0f172a; color: #fff;
      font-size: 14px; box-shadow: 0 10px 30px rgb(0 0 0 / 25%);
    }
    .error { margin-top: 16px; color: #b91c1c; }
  `,
})
export class SettingsComponent {
  private readonly api = inject(ApiService);

  readonly settings = signal<PosSettings | null>(null);
  readonly loading = signal(true);
  readonly saving = signal(false);
  readonly saved = signal<string | null>(null);
  readonly error = signal<string | null>(null);
  readonly pending = signal<PendingChange | null>(null);

  /** A short list rather than all 400-odd zones: this is a shop, not a travel booking. */
  readonly timezones = [
    'Asia/Kuala_Lumpur', 'Asia/Singapore', 'Asia/Jakarta', 'Asia/Bangkok',
    'Asia/Manila', 'Asia/Hong_Kong', 'Australia/Sydney', 'Europe/London', 'UTC',
  ];

  readonly currencies = ['MYR', 'SGD', 'IDR', 'THB', 'PHP', 'USD', 'GBP', 'EUR', 'AUD'];

  readonly storeFields = [
    { key: 'store_name', label: 'Store name', type: 'text' },
    { key: 'store_email', label: 'Email', type: 'email' },
    { key: 'store_phone', label: 'Phone', type: 'text' },
    { key: 'store_address', label: 'Address', type: 'text' },
  ] as const;

  form: Record<string, string> = {};

  private readonly labels: Record<string, string> = {
    timezone: 'timezone',
    currency: 'currency',
    store_name: 'store name',
    store_email: 'store email',
    store_phone: 'store phone',
    store_address: 'store address',
  };

  constructor() {
    this.load();
  }

  load(): void {
    this.api.settings().subscribe({
      next: (response) => {
        this.settings.set(response.data);
        this.form = {
          timezone: response.data.account.timezone,
          currency: response.data.account.currency,
          store_name: response.data.store.name,
          store_email: response.data.store.email ?? '',
          store_phone: response.data.store.phone ?? '',
          store_address: response.data.store.address ?? '',
        };
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load settings.');
      },
    });
  }

  current(field: string, settings: PosSettings): string {
    return {
      timezone: settings.account.timezone,
      currency: settings.account.currency,
      store_name: settings.store.name,
      store_email: settings.store.email ?? '',
      store_phone: settings.store.phone ?? '',
      store_address: settings.store.address ?? '',
    }[field] ?? '';
  }

  dirty(field: string, settings: PosSettings): boolean {
    return settings.can_edit && (this.form[field] ?? '') !== this.current(field, settings);
  }

  /** Opens the confirmation, carrying whatever the server says is at stake. */
  ask(field: string, settings: PosSettings): void {
    this.pending.set({
      field,
      label: this.labels[field] ?? field,
      from: this.current(field, settings),
      to: this.form[field] ?? '',
      warning: this.warningFor(field, settings),
    });
  }

  private warningFor(field: string, settings: PosSettings): string | null {
    if (field === 'currency') {
      const { orders, note } = settings.impact.currency;

      // Only worth warning about if there is history to relabel.
      return orders > 0 ? `${orders} recorded orders. ${note}` : null;
    }

    if (field === 'timezone' && settings.impact.timezone.orders > 0) {
      return settings.impact.timezone.note;
    }

    return null;
  }

  apply(): void {
    const change = this.pending();
    if (change === null) return;

    this.saving.set(true);
    this.error.set(null);

    // One field per request, so a failure is unambiguous about what did not save.
    this.api.saveSettings({ [change.field]: change.to === '' ? null : change.to }).subscribe({
      next: (response) => {
        this.settings.set(response.data);
        this.saving.set(false);
        this.pending.set(null);
        this.confirmSaved(`Saved ${change.label}.`);
      },
      error: (response) => {
        this.saving.set(false);
        this.pending.set(null);
        this.error.set(response.error?.message ?? `Could not save the ${change.label}.`);
      },
    });
  }

  private confirmSaved(message: string): void {
    this.saved.set(message);
    setTimeout(() => this.saved.set(null), 2600);
  }
}
