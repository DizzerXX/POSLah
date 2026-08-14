import { Component, inject, signal } from '@angular/core';

import { PosApiService } from '../core/pos-api.service';
import { SupplyItem } from '../core/models';

/**
 * Raw materials, and how much is left of each.
 *
 * These are the *ingredients* a menu item is made from, not the menu items themselves — an
 * F&B product holds no stock of its own. The three states come from the server comparing the
 * level against each ingredient's own low-stock threshold, because "low" means something
 * different for espresso beans than for burger buns.
 *
 * A negative level reads as out of stock rather than being clamped to zero: it is the honest
 * record of having sold more than was on hand, and hiding it would hide the thing a stocktake
 * exists to fix.
 */
@Component({
  selector: 'app-supply',
  standalone: true,
  template: `
    <section>
      <header>
        <div>
          <h1>Supply</h1>
          <p class="muted">Raw materials and the amount of stock available.</p>
        </div>
        <div class="views">
          <button class="pos-btn-ghost" [class.on]="view() === 'list'" (click)="view.set('list')">List</button>
          <button class="pos-btn-ghost" [class.on]="view() === 'grid'" (click)="view.set('grid')">Grid</button>
        </div>
      </header>

      @if (loading()) {
        <p class="muted">Loading…</p>
      } @else if (items().length === 0) {
        <div class="empty"><p><strong>No ingredients yet.</strong></p></div>
      } @else if (view() === 'list') {
        <table>
          <thead><tr><th>Item</th><th>Quantity</th><th>Status</th></tr></thead>
          <tbody>
            @for (item of items(); track item.id) {
              <tr [attr.data-testid]="'supply-' + item.id">
                <td class="strong">{{ item.name }}</td>
                <td>{{ item.quantity }} {{ item.unit }}</td>
                <td><span class="pos-pill" [class]="pill(item)">{{ label(item) }}</span></td>
              </tr>
            }
          </tbody>
        </table>
      } @else {
        <div class="grid">
          @for (item of items(); track item.id) {
            <article class="card">
              <span class="initials">{{ item.name.slice(0, 2).toUpperCase() }}</span>
              <strong>{{ item.name }}</strong>
              <span class="muted small">{{ item.quantity }} {{ item.unit }}</span>
              <span class="pos-pill" [class]="pill(item)">{{ label(item) }}</span>
            </article>
          }
        </div>
      }
    </section>
  `,
  styles: `
    :host { display: block; height: 100%; overflow-y: auto; }
    section { padding: 22px 24px; max-width: 1100px; }
    header { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
    h1 { margin: 0; font-size: 22px; }
    header p { margin: 4px 0 0; }
    .muted { color: var(--pos-muted); font-size: 13px; }
    .small { font-size: 12px; }
    .views { display: flex; gap: 6px; }
    .views .on { border-color: var(--pos-accent); color: var(--pos-accent); }
    table {
      width: 100%; margin-top: 18px; border-collapse: collapse; background: var(--pos-surface);
      border: 1px solid var(--pos-line); border-radius: var(--pos-radius); overflow: hidden;
    }
    th, td { padding: 11px 14px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--pos-line); }
    th { background: var(--pos-canvas); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pos-muted); }
    .strong { font-weight: 700; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 18px; }
    .card {
      display: grid; gap: 6px; justify-items: start; padding: 14px;
      background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: var(--pos-radius);
    }
    .initials {
      width: 100%; aspect-ratio: 4 / 3; border-radius: 8px; display: grid; place-items: center;
      background: var(--pos-accent-soft); color: var(--pos-accent); font-size: 24px; font-weight: 800;
    }
    .empty { margin-top: 20px; padding: 40px; border: 1px dashed var(--pos-line); border-radius: 12px; text-align: center; }
  `,
})
export class SupplyComponent {
  private readonly api = inject(PosApiService);

  readonly items = signal<SupplyItem[]>([]);
  readonly loading = signal(true);
  readonly view = signal<'list' | 'grid'>('list');

  constructor() {
    this.api.supply().subscribe({
      next: (response) => {
        this.items.set(response.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  pill(item: SupplyItem): string {
    return {
      safe: 'pos-pill--ready',
      running_low: 'pos-pill--cooking',
      out_of_stock: 'pos-pill--done',
    }[item.state];
  }

  label(item: SupplyItem): string {
    return {
      safe: 'Stock is safe',
      running_low: 'Running low',
      out_of_stock: 'Out of stock',
    }[item.state];
  }
}
