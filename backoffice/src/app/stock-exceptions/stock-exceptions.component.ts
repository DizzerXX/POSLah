import { DatePipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { ApiService } from '../core/api.service';
import { StockException } from '../core/models';

/**
 * Negative stock, and the human who resolves it.
 *
 * This screen is the visible half of the locked decision "accept both, allow negative, flag".
 * The sync engine refuses to reject a sale that has already happened — a customer walked out
 * with the goods — so the merchant is told instead, and told *specifically*: which item, how
 * far below zero, which orders landed while it was negative, and which till took them.
 *
 * The resolution is a stocktake, not a button that makes the number look right. There is no
 * algorithm that can know what is actually on the shelf.
 */
@Component({
  selector: 'app-stock-exceptions',
  standalone: true,
  imports: [FormsModule, DatePipe],
  template: `
    <header>
      <div>
        <h1>Stock exceptions</h1>
        <p class="sub">{{ account()?.name }}</p>
      </div>
      <div class="who">
        <span>{{ user()?.name }}</span>
        <button class="ghost" (click)="signOut()">Sign out</button>
      </div>
    </header>

    <nav>
      @for (option of filters; track option.value) {
        <button
          class="chip"
          [class.on]="filter() === option.value"
          (click)="setFilter(option.value)"
        >
          {{ option.label }}
        </button>
      }
    </nav>

    @if (loading()) {
      <p class="muted">Loading…</p>
    } @else if (exceptions().length === 0) {
      <div class="empty">
        <p><strong>Nothing to reconcile.</strong></p>
        <p class="muted">
          Exceptions appear here when a sale drives stock below zero — usually two tills
          selling the last unit while offline. The sale stands; this is where the count is
          put right.
        </p>
      </div>
    } @else {
      <ul class="list">
        @for (exception of exceptions(); track exception.id) {
          <li class="row" [class.resolved]="exception.status === 'resolved'">
            <div class="main">
              <div class="title">
                <span class="name">{{ exception.stockable_name }}</span>
                <span class="badge" [class.open]="exception.status === 'open'">
                  {{ exception.status }}
                </span>
                <span class="kind">{{
                  exception.stockable_type === 'ingredient' ? 'ingredient' : 'retail variant'
                }}</span>
              </div>

              <div class="figures">
                <span class="qty">{{ exception.resulting_quantity }}</span>
                <span class="muted">when raised</span>
                @if (exception.current_quantity !== null) {
                  <span class="sep">·</span>
                  <span class="qty" [class.negative]="isNegative(exception.current_quantity)">
                    {{ exception.current_quantity }}
                  </span>
                  <span class="muted">now</span>
                }
              </div>

              <div class="meta muted">
                @if (exception.devices.length) {
                  <span>{{ exception.devices.join(', ') }}</span>
                  <span class="sep">·</span>
                }
                <span>{{ exception.created_at | date: 'd MMM, HH:mm' }}</span>
              </div>

              @if (exception.contributing_orders.length) {
                <div class="orders">
                  <span class="muted">Landed while negative:</span>
                  @for (order of exception.contributing_orders; track order.id) {
                    <span class="order">{{ order.order_number ?? order.id.slice(0, 8) }}</span>
                  }
                </div>
              }

              @if (exception.status === 'resolved') {
                <p class="muted resolution">
                  Counted by {{ exception.resolved_by }}
                  {{ exception.resolved_at | date: 'd MMM, HH:mm' }}
                  @if (exception.resolution_note) {
                    — “{{ exception.resolution_note }}”
                  }
                </p>
              }
            </div>

            @if (exception.status === 'open') {
              <div class="action">
                @if (resolving() === exception.id) {
                  <div class="stocktake">
                    <label>
                      Counted
                      <input
                        type="text"
                        inputmode="decimal"
                        [(ngModel)]="counted"
                        placeholder="0"
                      />
                    </label>
                    <label>
                      Note
                      <input type="text" [(ngModel)]="note" placeholder="optional" />
                    </label>
                    <div class="buttons">
                      <button class="ghost" (click)="cancel()">Cancel</button>
                      <button class="primary" (click)="confirm(exception)" [disabled]="busy()">
                        Record stocktake
                      </button>
                    </div>
                    <p class="muted tiny">
                      Records a manual adjustment for the difference. The sales that caused
                      this are not altered.
                    </p>
                  </div>
                } @else {
                  <button class="primary" (click)="start(exception)">Record stocktake</button>
                }
              </div>
            }
          </li>
        }
      </ul>
    }

    @if (error()) {
      <p class="error">{{ error() }}</p>
    }
  `,
  styles: `
    :host { display: block; max-width: 1000px; margin: 0 auto; padding: 32px 24px 64px; }
    header { display: flex; justify-content: space-between; align-items: flex-start; }
    h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
    .sub { margin: 4px 0 0; color: #64748b; font-size: 14px; }
    .who { display: flex; align-items: center; gap: 12px; font-size: 14px; color: #475569; }
    nav { display: flex; gap: 8px; margin: 24px 0 16px; }
    .chip {
      padding: 6px 14px; border: 1px solid #cbd5e1; border-radius: 999px; cursor: pointer;
      background: #fff; font-size: 13px; color: #334155;
    }
    .chip.on { background: #0f766e; border-color: #0f766e; color: #fff; }
    .list { list-style: none; margin: 0; padding: 0; display: grid; gap: 12px; }
    .row {
      display: flex; justify-content: space-between; gap: 24px; padding: 18px 20px;
      border: 1px solid #e2e8f0; border-left: 4px solid #dc2626; border-radius: 12px;
      background: #fff;
    }
    .row.resolved { border-left-color: #16a34a; opacity: 0.75; }
    .title { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
    .name { font-weight: 600; font-size: 16px; }
    .badge {
      padding: 2px 8px; border-radius: 999px; background: #dcfce7; color: #166534;
      font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;
    }
    .badge.open { background: #fee2e2; color: #991b1b; }
    .kind { font-size: 12px; color: #94a3b8; }
    .figures { display: flex; align-items: baseline; gap: 6px; margin-top: 8px; font-size: 14px; }
    .qty { font-variant-numeric: tabular-nums; font-weight: 600; }
    .qty.negative { color: #b91c1c; }
    .meta { margin-top: 6px; font-size: 13px; }
    .orders { margin-top: 8px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 13px; }
    .order {
      padding: 2px 8px; border-radius: 6px; background: #f1f5f9; font-family: ui-monospace, monospace;
      font-size: 12px;
    }
    .resolution { margin: 10px 0 0; font-size: 13px; }
    .action { display: flex; align-items: flex-start; }
    .stocktake { display: grid; gap: 8px; width: 260px; }
    .stocktake label { display: grid; gap: 4px; font-size: 12px; font-weight: 600; color: #334155; }
    .stocktake input { padding: 8px 10px; border: 1px solid #cbd5e1; border-radius: 8px; }
    .buttons { display: flex; gap: 8px; justify-content: flex-end; }
    button { cursor: pointer; font-size: 13px; }
    .primary {
      padding: 9px 14px; border: 0; border-radius: 8px; background: #0f766e; color: #fff;
      font-weight: 600;
    }
    .primary:disabled { opacity: 0.6; cursor: default; }
    .ghost { padding: 8px 12px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
    .empty { padding: 40px; border: 1px dashed #cbd5e1; border-radius: 12px; text-align: center; }
    .muted { color: #64748b; }
    .tiny { font-size: 11px; margin: 0; }
    .sep { color: #cbd5e1; margin: 0 6px; }
    .error { margin-top: 16px; color: #b91c1c; }
  `,
})
export class StockExceptionsComponent {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  readonly filters = [
    { label: 'All', value: null },
    { label: 'Open', value: 'open' as const },
    { label: 'Resolved', value: 'resolved' as const },
  ];

  readonly user = computed(() => this.api.user());
  readonly account = computed(() => this.api.account());

  readonly exceptions = signal<StockException[]>([]);
  readonly loading = signal(true);
  readonly busy = signal(false);
  readonly error = signal<string | null>(null);
  readonly filter = signal<'open' | 'resolved' | null>(null);
  readonly resolving = signal<string | null>(null);

  counted = '';
  note = '';

  constructor() {
    this.load();
  }

  setFilter(value: 'open' | 'resolved' | null): void {
    this.filter.set(value);
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.stockExceptions(this.filter() ?? undefined).subscribe({
      next: (response) => {
        this.exceptions.set(response.data);
        this.loading.set(false);
      },
      error: () => {
        this.loading.set(false);
        this.error.set('Could not load exceptions.');
      },
    });
  }

  isNegative(quantity: string): boolean {
    return quantity.trim().startsWith('-');
  }

  start(exception: StockException): void {
    this.resolving.set(exception.id);
    // Pre-filled with zero rather than the current negative figure: the merchant is being
    // asked what is on the shelf, not asked to confirm what the system already believes.
    this.counted = '0';
    this.note = '';
  }

  cancel(): void {
    this.resolving.set(null);
  }

  confirm(exception: StockException): void {
    this.busy.set(true);
    this.error.set(null);

    this.api.resolveException(exception.id, this.counted.trim() || '0', this.note || null).subscribe({
      next: () => {
        this.busy.set(false);
        this.resolving.set(null);
        this.load();
      },
      error: (response) => {
        this.busy.set(false);
        this.error.set(response.error?.message ?? 'Could not record that stocktake.');
      },
    });
  }

  signOut(): void {
    this.api.signOut();
    void this.router.navigate(['/sign-in']);
  }
}
