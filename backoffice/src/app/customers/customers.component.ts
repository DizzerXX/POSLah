import { DatePipe } from '@angular/common';
import { Component, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { ApiService } from '../core/api.service';
import { Customer } from '../core/models';

/**
 * The customer list — **one component, rendering in both verticals**.
 *
 * This is the point of the whole design, and the place the temptation actually arrives. A
 * RetailCustomersComponent beside an FnbCustomersComponent would be two implementations of
 * one screen, kept in sync by hand forever. Switching the backoffice to F&B filters
 * inventory and reporting; it does not load a different system.
 *
 * There is no module check anywhere in this file, and there must not be one.
 */
@Component({
  selector: 'app-customers',
  standalone: true,
  imports: [FormsModule, DatePipe],
  template: `
    <header>
      <h1>Customers</h1>
      <p class="muted">Shared across {{ verticals() }}</p>
    </header>

    <input
      class="search"
      type="search"
      placeholder="Search name or phone"
      [(ngModel)]="search"
      (ngModelChange)="load()"
      data-testid="customer-search"
    />

    @if (loading()) {
      <p class="muted">Loading…</p>
    } @else if (customers().length === 0) {
      <div class="empty">
        <p><strong>No customers yet.</strong></p>
        <p class="muted">Cashiers create these at checkout; they sync up like any other fact.</p>
      </div>
    } @else {
      <table>
        <thead>
          <tr><th>Name</th><th>Phone</th><th>Email</th><th>Added</th></tr>
        </thead>
        <tbody>
          @for (customer of customers(); track customer.id) {
            <tr>
              <td class="name">{{ customer.name }}</td>
              <td>{{ customer.phone ?? '—' }}</td>
              <td>{{ customer.email ?? '—' }}</td>
              <td class="muted">{{ customer.created_at | date: 'd MMM y' }}</td>
            </tr>
          }
        </tbody>
      </table>
    }
  `,
  styles: `
    :host { display: block; max-width: 900px; padding: 32px 28px; }
    h1 { margin: 0; font-size: 24px; letter-spacing: -0.02em; }
    .muted { color: #64748b; font-size: 14px; }
    header p { margin: 4px 0 0; }
    .search {
      width: 100%; margin: 20px 0; padding: 10px 12px; border: 1px solid #cbd5e1;
      border-radius: 8px; font-size: 14px;
    }
    table { width: 100%; border-collapse: collapse; background: #fff; border-radius: 12px; overflow: hidden; }
    th, td { padding: 12px 14px; text-align: left; font-size: 14px; border-bottom: 1px solid #e2e8f0; }
    th { background: #f1f5f9; font-size: 12px; text-transform: uppercase; letter-spacing: 0.04em; color: #475569; }
    .name { font-weight: 600; }
    .empty { padding: 40px; border: 1px dashed #cbd5e1; border-radius: 12px; text-align: center; }
  `,
})
export class CustomersComponent {
  private readonly api = inject(ApiService);

  readonly customers = signal<Customer[]>([]);
  readonly loading = signal(true);

  search = '';

  /** Cosmetic only — it names the verticals, it does not change what is rendered. */
  readonly verticals = computed(() =>
    (this.api.account()?.enabled_modules ?? []).map((m) => (m === 'fnb' ? 'F&B' : 'retail')).join(' and '),
  );

  constructor() {
    this.load();
  }

  load(): void {
    this.loading.set(true);

    this.api.customers(this.search).subscribe({
      next: (response) => {
        this.customers.set(response.data);
        this.loading.set(false);
      },
      error: () => this.loading.set(false),
    });
  }
}
