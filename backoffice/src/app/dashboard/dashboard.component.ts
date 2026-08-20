import { Component, computed, inject, signal } from '@angular/core';

import { ApiService } from '../core/api.service';
import { DashboardData } from '../core/models';

/**
 * The dashboard.
 *
 * Every figure here comes from the server's clock, not a till's. A day's takings computed
 * from `occurred_at` would move depending on which device ran fast, and an offline sale
 * synced on Wednesday would land in Monday's total.
 *
 * The chart is hand-drawn SVG rather than a charting library: it is one series of twelve
 * points, and a dependency that ships a hundred kilobytes to draw a polyline is a poor trade
 * — especially for a screen that must stay readable when every value is zero, which is
 * exactly what a new merchant sees.
 */
@Component({
  selector: 'app-dashboard',
  standalone: true,
  template: `
    <header>
      <h1>Dashboard</h1>
      <span class="bo-muted">Figures use the server clock, so an offline sale counts when it lands.</span>
    </header>

    @if (loading()) {
      <p class="bo-muted">Loading…</p>
    }

    <!-- Its own block, not chained: the alias is only allowed on a primary condition. -->
    @if (data(); as d) {
      <div class="stats">
        <article class="bo-card stat">
          <span class="bo-muted">Today's Sales</span>
          <strong>{{ d.today.sales }}</strong>
          <em>orders</em>
        </article>
        <article class="bo-card stat">
          <span class="bo-muted">Income</span>
          <strong>{{ money(d.today.income) }}</strong>
          <em>today</em>
        </article>
        <article class="bo-card stat" [class.alert]="d.open_stock_exceptions > 0">
          <span class="bo-muted">Stock Exceptions</span>
          <strong>{{ d.open_stock_exceptions }}</strong>
          <em>open</em>
        </article>
        <article class="bo-card stat">
          <span class="bo-muted">Staff</span>
          <strong>{{ d.staff }}</strong>
          <em>active</em>
        </article>
      </div>

      <div class="split">
        <section class="bo-card panel">
          <h2>Income, last 12 months</h2>

          @if (peak() === 0) {
            <p class="bo-muted empty-note">
              No takings recorded yet. Ring up a sale on a till and it appears here.
            </p>
          }

          <svg [attr.viewBox]="'0 0 ' + chartWidth + ' ' + chartHeight" class="chart">
            <!-- Baseline, so an all-zero chart still reads as a chart rather than a blank. -->
            <line [attr.x1]="0" [attr.y1]="chartHeight - 24" [attr.x2]="chartWidth"
                  [attr.y2]="chartHeight - 24" class="axis" />
            <polyline [attr.points]="points()" class="series" />
            @for (point of plotted(); track point.key) {
              <circle [attr.cx]="point.x" [attr.cy]="point.y" r="3.5" class="node" />
              <text [attr.x]="point.x" [attr.y]="chartHeight - 6" class="tick">{{ point.month }}</text>
            }
          </svg>
        </section>

        <section class="bo-card panel">
          <h2>Inventory</h2>
          <dl>
            <div><dt>Products on sale</dt><dd>{{ d.inventory.products }}</dd></div>
            <div><dt>Stockables tracked</dt><dd>{{ d.inventory.stockables_tracked }}</dd></div>
            <div>
              <dt>Below zero</dt>
              <dd [class.danger]="d.inventory.below_zero > 0">{{ d.inventory.below_zero }}</dd>
            </div>
          </dl>
          <p class="bo-muted note">
            Negative stock is expected, not an error — a sale is never refused on stock. It is
            resolved by a stocktake.
          </p>
        </section>
      </div>

      <section class="bo-card panel">
        <h2>How customers paid</h2>

        @if (d.payment_mix.length === 0) {
          <p class="bo-muted empty-note">Nothing recorded yet.</p>
        }

        @for (row of d.payment_mix; track row.method) {
          <div class="mix">
            <span class="method">{{ row.method }}</span>
            <div class="bar"><span [style.width.%]="share(row.total)"></span></div>
            <span class="amount">{{ money(row.total) }}</span>
            <span class="bo-muted count">{{ row.orders }} orders</span>
          </div>
        }
        <p class="bo-muted note">v1 records how someone paid. It does not process payment.</p>
      </section>
    }
  `,
  styles: `
    :host { display: block; padding: 26px 28px 60px; }
    header { display: flex; align-items: baseline; gap: 14px; flex-wrap: wrap; }
    h1 { margin: 0; font-size: 26px; letter-spacing: -0.02em; }
    h2 { margin: 0 0 14px; font-size: 15px; }

    .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 14px; margin-top: 20px; }
    .stat { display: grid; gap: 2px; padding: 18px 20px; }
    .stat span { font-size: 13px; }
    .stat strong { font-size: 26px; letter-spacing: -0.02em; }
    .stat em { font-style: normal; font-size: 12px; color: var(--bo-muted); }
    .stat.alert strong { color: var(--bo-danger); }

    .split { display: grid; grid-template-columns: 2fr 1fr; gap: 14px; margin-top: 14px; }
    .panel { padding: 20px; margin-top: 14px; }
    .split .panel { margin-top: 0; }

    .chart { width: 100%; height: 240px; overflow: visible; }
    .axis { stroke: var(--bo-line); stroke-width: 1; }
    .series { fill: none; stroke: var(--bo-accent); stroke-width: 2.5; stroke-linejoin: round; }
    .node { fill: var(--bo-accent); }
    .tick { font-size: 11px; fill: var(--bo-muted); text-anchor: middle; }
    .empty-note { margin: 0 0 8px; font-size: 13px; }

    dl { margin: 0; display: grid; gap: 10px; }
    dl div { display: flex; justify-content: space-between; font-size: 14px; }
    dt { color: var(--bo-muted); }
    dd { margin: 0; font-weight: 700; }
    dd.danger { color: var(--bo-danger); }
    .note { margin: 14px 0 0; font-size: 12px; line-height: 1.5; }

    .mix { display: grid; grid-template-columns: 70px 1fr 110px 90px; align-items: center; gap: 12px; margin-bottom: 10px; }
    .method { text-transform: uppercase; font-size: 12px; font-weight: 700; }
    .bar { height: 10px; border-radius: 999px; background: var(--bo-neutral-soft); overflow: hidden; }
    .bar span { display: block; height: 100%; background: var(--bo-accent); }
    .amount { text-align: right; font-weight: 700; font-size: 14px; }
    .count { font-size: 12px; text-align: right; }
  `,
})
export class DashboardComponent {
  private readonly api = inject(ApiService);

  readonly data = signal<DashboardData | null>(null);
  readonly loading = signal(true);

  readonly chartWidth = 640;
  readonly chartHeight = 240;

  /** The tallest month, and never zero — dividing by it must be safe on an empty account. */
  readonly peak = computed(() =>
    Math.max(0, ...(this.data()?.monthly_income ?? []).map((m) => m.total)),
  );

  readonly plotted = computed(() => {
    const series = this.data()?.monthly_income ?? [];
    const peak = this.peak() || 1;
    const usable = this.chartHeight - 40;

    return series.map((month, index) => ({
      ...month,
      x: series.length === 1 ? 0 : (index / (series.length - 1)) * this.chartWidth,
      y: this.chartHeight - 24 - (month.total / peak) * usable,
    }));
  });

  readonly points = computed(() =>
    this.plotted().map((p) => `${p.x},${p.y}`).join(' '),
  );

  constructor() {
    this.api.dashboard().subscribe({
      next: (response) => {
        this.data.set(response.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  share(total: number): number {
    const all = (this.data()?.payment_mix ?? []).reduce((sum, row) => sum + row.total, 0);

    return all === 0 ? 0 : Math.round((total / all) * 100);
  }

  money(minorUnits: number): string {
    const currency = this.data()?.currency ?? 'MYR';

    return `${currency} ${(minorUnits / 100).toFixed(2)}`;
  }
}
