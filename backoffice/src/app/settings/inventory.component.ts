import { Component, inject, input, signal } from '@angular/core';
import { Observable } from 'rxjs';

/**
 * The overlap between an ingredient and a variant, which is only what this list renders.
 * Deliberately not a shared domain interface: the two are different things, and pretending
 * otherwise is how a shared abstraction starts leaking.
 */
interface InventoryRow {
  id: string;
  name: string;
  unit?: string;
  sku?: string | null;
}

import { ApiService } from '../core/api.service';

/**
 * The two vertical-specific inventory lists.
 *
 * These are the screens that genuinely differ: retail holds stock against a sellable variant,
 * F&B against ingredients that are never sold. Their routes are registered only for an
 * entitled account, and the endpoints behind them 403 regardless of what the client renders.
 */
@Component({
  selector: 'app-inventory',
  standalone: true,
  template: `
    <header>
      <h1>{{ vertical() === 'fnb' ? 'Ingredients' : 'SKUs & variants' }}</h1>
      <p class="muted">
        {{
          vertical() === 'fnb'
            ? 'What menu items are made from. Never sold directly.'
            : 'What a sale decrements. One SKU, one stockable.'
        }}
      </p>
    </header>

    @if (loading()) {
      <p class="muted">Loading…</p>
    } @else {
      <ul>
        @for (row of rows(); track row.id) {
          <li>
            <span class="name">{{ row.name }}</span>
            <span class="muted">{{ row.detail }}</span>
          </li>
        }
      </ul>
    }
  `,
  styles: `
    :host { display: block; max-width: 720px; padding: 32px 28px; }
    h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
    header p { margin: 4px 0 0; }
    .muted { color: var(--bo-muted); font-size: 14px; }
    ul { list-style: none; margin: 24px 0 0; padding: 0; display: grid; gap: 8px; }
    li {
      display: flex; justify-content: space-between; padding: 14px 16px; background: var(--bo-surface);
      border: 1px solid var(--bo-line); border-radius: 10px;
    }
    .name { font-weight: 600; font-size: 14px; }
  `,
})
export class InventoryComponent {
  private readonly api = inject(ApiService);

  readonly vertical = input.required<'retail' | 'fnb'>();

  readonly rows = signal<{ id: string; name: string; detail: string }[]>([]);
  readonly loading = signal(true);

  ngOnInit(): void {
    // Typed loosely on purpose: the two endpoints return genuinely different shapes, and
    // narrowing them into one interface would invent a shared concept that does not exist.
    const request: Observable<{ data: InventoryRow[] }> =
      this.vertical() === 'fnb' ? this.api.ingredients() : this.api.productVariants();

    request.subscribe({
      next: (response) => {
        this.rows.set(
          response.data.map((row) => ({
            id: row.id,
            name: row.name,
            detail: this.vertical() === 'fnb' ? (row.unit ?? '') : (row.sku ?? '—'),
          })),
        );
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
