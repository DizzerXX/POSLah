import { Component, computed, inject, signal } from '@angular/core';
import { UpperCasePipe } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PosApiService } from '../core/pos-api.service';
import {
  DraftLine,
  PosCategory,
  PosModifier,
  PosOrder,
  PosProduct,
  PosTable,
  formatMoney,
  lineTotal,
  unitPrice,
} from '../core/models';

type Stage = 'ordering' | 'paying' | 'success' | 'failed';

/**
 * The counter.
 *
 * Three columns, matching the design: live orders on the left rail, the menu in the middle,
 * and the order being built on the right. A cashier never navigates away mid-sale.
 *
 * Prices are displayed from the catalog but **recomputed server-side** when the order is
 * placed — the browser sends ids and quantities, never amounts. What is shown here is a
 * quote; what is charged is the server's arithmetic.
 */
@Component({
  selector: 'app-order',
  standalone: true,
  imports: [FormsModule, UpperCasePipe],
  templateUrl: './order.component.html',
  styleUrl: './order.component.scss',
})
export class OrderComponent {
  // Public: the template shows the signed-in cashier's name.
  readonly api = inject(PosApiService);

  readonly categories = signal<PosCategory[]>([]);
  readonly products = signal<PosProduct[]>([]);
  readonly tables = signal<PosTable[]>([]);
  readonly activeOrders = signal<PosOrder[]>([]);

  readonly selectedCategory = signal<string | null>(null);
  readonly search = signal('');

  readonly draft = signal<DraftLine[]>([]);
  readonly stage = signal<Stage>('ordering');
  readonly error = signal<string | null>(null);
  readonly placed = signal<PosOrder | null>(null);
  readonly busy = signal(false);

  // Signals, not plain fields. `tendered`, `change` and `canPay` are computed over these,
  // and a computed cannot track a mutation to an ordinary property — the button simply
  // never re-evaluates, which is exactly how it stayed disabled with cash on the counter.
  readonly serviceType = signal<'dine_in' | 'takeaway'>('dine_in');
  readonly tableId = signal<string | null>(null);
  readonly note = signal('');
  readonly paymentMethod = signal('cash');
  readonly tenderedInput = signal('');

  readonly formatMoney = formatMoney;
  readonly unitPrice = unitPrice;
  readonly lineTotal = lineTotal;

  /** Modifier sheet state, for the product currently being configured. */
  readonly configuring = signal<PosProduct | null>(null);
  readonly chosenModifiers = signal<Set<string>>(new Set());

  readonly visibleProducts = computed(() => {
    const category = this.selectedCategory();
    const term = this.search().trim().toLowerCase();

    return this.products().filter((product) => {
      if (category !== null && product.category_id !== category) return false;
      if (term !== '' && !product.name.toLowerCase().includes(term)) return false;

      return true;
    });
  });

  readonly subtotal = computed(() =>
    this.draft().reduce((sum, line) => sum + lineTotal(line), 0),
  );

  /** Quoted only. The server recomputes it from the store's own rate. */
  readonly tax = computed(() => Math.round(this.subtotal() * 0.06));

  readonly total = computed(() => this.subtotal() + this.tax());

  readonly tendered = computed(() => {
    const parsed = Number.parseFloat(this.tenderedInput());

    return Number.isFinite(parsed) ? Math.round(parsed * 100) : 0;
  });

  readonly change = computed(() => Math.max(0, this.tendered() - this.total()));

  readonly canPay = computed(() => {
    if (this.draft().length === 0 || this.busy()) return false;

    return this.paymentMethod() !== 'cash' || this.tendered() >= this.total();
  });

  constructor() {
    this.refresh();
  }

  refresh(): void {
    this.api.menu().subscribe({
      next: (response) => {
        this.categories.set(response.categories);
        this.products.set(response.products);
      },
      error: () => this.error.set('Could not load the menu.'),
    });

    this.api.tables().subscribe({ next: (r) => this.tables.set(r.data) });
    this.api.activeOrders().subscribe({ next: (r) => this.activeOrders.set(r.data) });
  }

  // ---- building the order ----------------------------------------------

  tap(product: PosProduct): void {
    if (product.modifiers.length > 0) {
      this.configuring.set(product);
      this.chosenModifiers.set(new Set());

      return;
    }

    this.add(product, []);
  }

  toggleModifier(id: string): void {
    const next = new Set(this.chosenModifiers());
    next.has(id) ? next.delete(id) : next.add(id);
    this.chosenModifiers.set(next);
  }

  confirmModifiers(): void {
    const product = this.configuring();
    if (product === null) return;

    this.add(
      product,
      product.modifiers.filter((m) => this.chosenModifiers().has(m.id)),
    );

    this.configuring.set(null);
  }

  /**
   * Each tap is its own line rather than incrementing a matching one.
   *
   * Two burgers ordered separately may carry different modifiers, and merging them would
   * make "remove that one" ambiguous — the same reasoning the mobile cart uses.
   */
  private add(product: PosProduct, modifiers: PosModifier[]): void {
    this.draft.set([
      ...this.draft(),
      { key: crypto.randomUUID(), product, quantity: 1, modifiers },
    ]);
  }

  setQuantity(key: string, quantity: number): void {
    if (quantity <= 0) {
      this.draft.set(this.draft().filter((line) => line.key !== key));

      return;
    }

    this.draft.set(
      this.draft().map((line) => (line.key === key ? { ...line, quantity } : line)),
    );
  }

  clear(): void {
    this.draft.set([]);
    this.note.set('');
    this.tableId.set(null);
    this.tenderedInput.set('');
    this.stage.set('ordering');
    this.placed.set(null);
  }

  // ---- payment ----------------------------------------------------------

  pay(): void {
    this.busy.set(true);
    this.error.set(null);

    this.api
      .placeOrder({
        lines: this.draft(),
        paymentMethod: this.paymentMethod(),
        tableId: this.serviceType() === 'dine_in' ? this.tableId() : null,
        serviceType: this.serviceType(),
        note: this.note().trim() === '' ? null : this.note().trim(),
        tendered: this.paymentMethod() === 'cash' ? this.tendered() : null,
      })
      .subscribe({
        next: (response) => {
          this.busy.set(false);
          this.placed.set(response.data);
          this.stage.set('success');
          // Tables and the order rail both change as a result of this sale.
          this.refresh();
        },
        error: (response) => {
          // Online-only means a network failure is a failed sale, not a queued one. Saying
          // so is the honest thing; pretending otherwise would promise a guarantee this
          // client cannot keep.
          this.busy.set(false);
          this.stage.set('failed');
          this.error.set(response.error?.message ?? 'The sale did not go through.');
        },
      });
  }

  availableTables(): PosTable[] {
    return this.tables().filter((table) => table.is_available);
  }

  initials(name: string): string {
    return name
      .split(' ')
      .slice(0, 2)
      .map((word) => word[0])
      .join('')
      .toUpperCase();
  }

  lineStatusClass(order: PosOrder): string {
    if (order.lines.every((line) => line.status !== 'pending')) return 'pos-pill--ready';

    return 'pos-pill--cooking';
  }

  lineStatusLabel(order: PosOrder): string {
    return order.lines.every((line) => line.status !== 'pending') ? 'Ready' : 'Being Cooked';
  }
}
