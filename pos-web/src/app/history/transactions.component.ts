import { DatePipe } from '@angular/common';
import { Component, computed, inject, input, signal } from '@angular/core';

import { PosApiService } from '../core/pos-api.service';
import { PosOrder, formatMoney } from '../core/models';

/**
 * Past transactions.
 *
 * History and Report render the same rows because they *are* the same rows — the difference
 * is that one is a working list and the other is something you take away. Building two
 * components would mean keeping two copies of the same table in step forever, which is the
 * mistake the backoffice's shared customer list exists to avoid.
 */
@Component({
  selector: 'app-transactions',
  standalone: true,
  imports: [DatePipe],
  template: `
    <section>
      <header>
        <div>
          <h1>{{ mode() === 'report' ? 'Report' : 'History' }}</h1>
          <p class="muted">
            {{
              mode() === 'report'
                ? 'Takings by order, ordered by when the server recorded them.'
                : "Today's transactions with status, payment and customer details."
            }}
          </p>
        </div>

        @if (mode() === 'report') {
          <div class="exports">
            <button class="pos-btn-ghost" (click)="downloadCsv()" data-testid="download-csv">
              CSV
            </button>
            <button class="pos-btn-ghost" (click)="downloadPdf()" data-testid="download-pdf">
              PDF
            </button>
          </div>
        }
      </header>

      @if (mode() === 'report') {
        <div class="totals">
          <div class="stat"><span class="muted">Orders</span><strong>{{ orders().length }}</strong></div>
          <div class="stat"><span class="muted">Takings</span><strong>{{ money(takings()) }}</strong></div>
          <div class="stat"><span class="muted">Tax</span><strong>{{ money(tax()) }}</strong></div>
        </div>
      }

      @if (loading()) {
        <p class="muted">Loading…</p>
      } @else if (orders().length === 0) {
        <div class="empty"><p><strong>Nothing yet.</strong></p></div>
      } @else {
        <table>
          <thead>
            <tr>
              <th>Order</th><th>Date</th><th>Service</th><th>Table</th>
              <th>Items</th><th class="right">Total</th><th>Status</th>
            </tr>
          </thead>
          <tbody>
            @for (order of orders(); track order.id) {
              <tr>
                <td class="mono">{{ order.order_number }}</td>
                <td>{{ order.occurred_at | date: 'd MMM, HH:mm' }}</td>
                <td>{{ order.service_type === 'takeaway' ? 'Takeaway' : 'Dine in' }}</td>
                <td>{{ order.table ?? '—' }}</td>
                <td>{{ itemCount(order) }}</td>
                <td class="right strong">{{ money(order.grand_total) }}</td>
                <td><span class="pos-pill" [class]="pill(order)">{{ order.status }}</span></td>
              </tr>
            }
          </tbody>
        </table>
      }
    </section>
  `,
  styles: `
    :host { display: block; height: 100%; overflow-y: auto; }
    section { padding: 22px 24px; max-width: 1100px; }
    header { display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; }
    h1 { margin: 0; font-size: 22px; }
    header p { margin: 4px 0 0; max-width: 62ch; }
    .muted { color: var(--pos-muted); font-size: 13px; }
    .exports { display: flex; gap: 8px; }
    .totals { display: flex; gap: 12px; margin-top: 16px; }
    .stat {
      display: grid; gap: 2px; padding: 12px 16px; min-width: 130px;
      background: var(--pos-surface); border: 1px solid var(--pos-line); border-radius: var(--pos-radius);
    }
    .stat strong { font-size: 18px; }
    table {
      width: 100%; margin-top: 18px; border-collapse: collapse; background: var(--pos-surface);
      border: 1px solid var(--pos-line); border-radius: var(--pos-radius); overflow: hidden;
    }
    th, td { padding: 11px 14px; text-align: left; font-size: 13px; border-bottom: 1px solid var(--pos-line); }
    th { background: var(--pos-canvas); font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; color: var(--pos-muted); }
    .right { text-align: right; }
    .strong { font-weight: 700; }
    .mono { font-family: ui-monospace, monospace; font-size: 12px; }
    .empty { margin-top: 20px; padding: 40px; border: 1px dashed var(--pos-line); border-radius: 12px; text-align: center; }
  `,
})
export class TransactionsComponent {
  private readonly api = inject(PosApiService);

  /** 'history' or 'report' — same rows, different framing. */
  readonly mode = input<'history' | 'report'>('history');

  readonly orders = signal<PosOrder[]>([]);
  readonly loading = signal(true);

  readonly money = formatMoney;

  readonly takings = computed(() =>
    this.orders().reduce((sum, order) => sum + order.grand_total, 0),
  );

  readonly tax = computed(() => this.orders().reduce((sum, order) => sum + order.tax_total, 0));

  constructor() {
    this.api.history().subscribe({
      next: (response) => {
        this.orders.set(response.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }

  itemCount(order: PosOrder): number {
    return order.lines.reduce((sum, line) => sum + line.quantity, 0);
  }

  pill(order: PosOrder): string {
    if (order.status === 'ready') return 'pos-pill--ready';
    if (order.status === 'voided') return 'pos-pill--done';

    return 'pos-pill--cooking';
  }

  /**
   * CSV alongside PDF.
   *
   * A spreadsheet is what a merchant reconciles with; a PDF is what they hand to someone.
   * Both are generated in the browser rather than on the server — the rows are already here,
   * and a server round trip would add a rendering stack for something the client can do.
   *
   * Money is written in major units here because that is what a spreadsheet expects, and it
   * is the only place in the codebase that converts.
   */
  downloadCsv(): void {
    const header = ['Order', 'Date', 'Service', 'Table', 'Items', 'Subtotal', 'Tax', 'Total', 'Status'];

    const rows = this.orders().map((order) => [
      order.order_number ?? order.id,
      order.occurred_at ?? '',
      order.service_type ?? '',
      order.table ?? '',
      String(this.itemCount(order)),
      (order.subtotal / 100).toFixed(2),
      (order.tax_total / 100).toFixed(2),
      (order.grand_total / 100).toFixed(2),
      order.status,
    ]);

    const csv = [header, ...rows]
      .map((row) => row.map((cell) => `"${String(cell).replaceAll('"', '""')}"`).join(','))
      .join('\n');

    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8;' }));
    const link = document.createElement('a');

    link.href = url;
    link.download = `poslah-report-${new Date().toISOString().slice(0, 10)}.csv`;
    link.click();

    URL.revokeObjectURL(url);
  }

  /**
   * The same rows as the CSV, laid out to be read rather than reconciled.
   *
   * Totals are drawn from the same computed signals the screen uses, so the document and
   * the page can never disagree about what a day took.
   */
  async downloadPdf(): Promise<void> {
    // Loaded on demand: a till that never exports should not pay for a PDF engine on boot.
    const { jsPDF } = await import('jspdf');
    const autoTable = (await import('jspdf-autotable')).default;

    const doc = new jsPDF({ orientation: 'landscape' });
    const generated = new Date();

    doc.setFontSize(16);
    doc.text('POSlah — sales report', 14, 18);

    doc.setFontSize(10);
    doc.setTextColor(110);
    doc.text(
      [
        `Generated ${generated.toLocaleString()}`,
        `${this.orders().length} orders · takings ${this.money(this.takings())} · tax ${this.money(this.tax())}`,
      ].join('    '),
      14,
      25,
    );

    autoTable(doc, {
      startY: 32,
      head: [['Order', 'Date', 'Service', 'Table', 'Items', 'Subtotal', 'Tax', 'Total', 'Status']],
      body: this.orders().map((order) => [
        order.order_number ?? order.id.slice(0, 8),
        order.occurred_at ? new Date(order.occurred_at).toLocaleString() : '—',
        order.service_type === 'takeaway' ? 'Takeaway' : 'Dine in',
        order.table ?? '—',
        String(this.itemCount(order)),
        this.money(order.subtotal),
        this.money(order.tax_total),
        this.money(order.grand_total),
        order.status,
      ]),
      styles: { fontSize: 9, cellPadding: 3 },
      headStyles: { fillColor: [244, 81, 30] },
      columnStyles: { 5: { halign: 'right' }, 6: { halign: 'right' }, 7: { halign: 'right' } },
    });

    doc.save(`poslah-report-${generated.toISOString().slice(0, 10)}.pdf`);
  }
}
