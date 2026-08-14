import { Component, inject, signal } from '@angular/core';

import { PosApiService } from '../core/pos-api.service';
import { PosTable } from '../core/models';

/**
 * The floor plan.
 *
 * Availability is **derived server-side** from whether an unfinished order references the
 * table — there is no status to set here, and no way for this screen to put a table into a
 * state its orders disagree with. Closing out an order frees its table with nothing to press.
 */
@Component({
  selector: 'app-tables',
  standalone: true,
  template: `
    <section>
      <header>
        <div>
          <h1>Table</h1>
          <p class="muted">
            Availability follows the orders — a table is occupied while an order on it is
            still open, and frees itself when that order closes.
          </p>
        </div>
        <button class="pos-btn-ghost" (click)="load()">Refresh</button>
      </header>

      @if (loading()) {
        <p class="muted">Loading…</p>
      } @else if (tables().length === 0) {
        <div class="empty">
          <p><strong>No tables yet.</strong></p>
          <p class="muted">Add them in the backoffice and they appear here.</p>
        </div>
      } @else {
        <div class="grid">
          @for (table of tables(); track table.id) {
            <article class="card" [class.free]="table.is_available" [attr.data-testid]="'table-' + table.label">
              <div class="label">Table {{ table.label }}</div>
              <div class="muted small">{{ table.seats }} seats</div>
              <span class="state">{{ table.is_available ? 'Available' : 'Used' }}</span>
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
    p { margin: 4px 0 0; max-width: 62ch; }
    .muted { color: var(--pos-muted); font-size: 13px; }
    .small { font-size: 12px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 12px; margin-top: 18px; }
    .card {
      display: grid; gap: 4px; padding: 14px; border-radius: var(--pos-radius);
      background: var(--pos-surface); border: 1px solid var(--pos-line);
    }
    .label { font-weight: 700; }
    .state {
      margin-top: 8px; padding: 7px; border-radius: 8px; text-align: center;
      background: var(--pos-canvas); color: var(--pos-muted); font-size: 12px; font-weight: 700;
    }
    .card.free .state { background: var(--pos-accent); color: #fff; }
    .empty { margin-top: 20px; padding: 40px; border: 1px dashed var(--pos-line); border-radius: 12px; text-align: center; }
  `,
})
export class TablesComponent {
  private readonly api = inject(PosApiService);

  readonly tables = signal<PosTable[]>([]);
  readonly loading = signal(true);

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.tables().subscribe({
      next: (response) => {
        this.tables.set(response.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
