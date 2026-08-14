import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { environment } from '../../environments/environment';
import { DraftLine, PosCategory, PosOrder, PosProduct, PosTable, SupplyItem } from './models';

const TOKEN_KEY = 'poslah.pos.token';
const STORE_KEY = 'poslah.pos.store';
const USER_KEY = 'poslah.pos.user';

/**
 * The till's API client.
 *
 * **This till is online-only and does not pretend otherwise.** The Flutter till is
 * local-first: it writes to SQLite and returns to the cashier whether or not the network is
 * up. A browser cannot honestly make that claim without a second full outbox implementation
 * in a second language, so this one writes through — and when it fails, it says so instead
 * of quietly queuing something it cannot guarantee.
 */
@Injectable({ providedIn: 'root' })
export class PosApiService {
  private readonly http = inject(HttpClient);

  readonly baseUrl = environment.apiUrl;

  readonly cashierName = signal<string | null>(localStorage.getItem(USER_KEY));

  get token(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  get storeId(): string | null {
    return localStorage.getItem(STORE_KEY);
  }

  get isSignedIn(): boolean {
    return this.token !== null && this.storeId !== null;
  }

  login(email: string, password: string): Observable<unknown> {
    return this.http
      .post<{
        token: string;
        user: { name: string };
        stores: { id: string; name: string }[];
      }>(`${this.baseUrl}/api/v1/auth/login`, { email, password })
      .pipe(
        tap((response) => {
          localStorage.setItem(TOKEN_KEY, response.token);
          localStorage.setItem(USER_KEY, response.user.name);
          this.cashierName.set(response.user.name);

          // v1 is single-store per account; the till serves that one.
          if (response.stores[0]) localStorage.setItem(STORE_KEY, response.stores[0].id);
        }),
      );
  }

  signOut(): void {
    [TOKEN_KEY, STORE_KEY, USER_KEY].forEach((key) => localStorage.removeItem(key));
    this.cashierName.set(null);
  }

  menu(): Observable<{ categories: PosCategory[]; products: PosProduct[] }> {
    return this.http.get<{ categories: PosCategory[]; products: PosProduct[] }>(
      `${this.baseUrl}/api/v1/pos/menu`,
    );
  }

  tables(): Observable<{ data: PosTable[] }> {
    return this.http.get<{ data: PosTable[] }>(`${this.baseUrl}/api/v1/pos/tables`);
  }

  activeOrders(): Observable<{ data: PosOrder[] }> {
    return this.http.get<{ data: PosOrder[] }>(`${this.baseUrl}/api/v1/pos/orders/active`);
  }

  history(): Observable<{ data: PosOrder[] }> {
    return this.http.get<{ data: PosOrder[] }>(`${this.baseUrl}/api/v1/pos/history`);
  }

  supply(): Observable<{ data: SupplyItem[] }> {
    return this.http.get<{ data: SupplyItem[] }>(`${this.baseUrl}/api/v1/pos/supply`);
  }

  placeOrder(input: {
    lines: DraftLine[];
    paymentMethod: string;
    tableId: string | null;
    serviceType: 'dine_in' | 'takeaway';
    note: string | null;
    tendered: number | null;
  }): Observable<{ data: PosOrder }> {
    return this.http.post<{ data: PosOrder }>(`${this.baseUrl}/api/v1/pos/orders`, {
      store_id: this.storeId,
      restaurant_table_id: input.tableId,
      service_type: input.serviceType,
      customer_note: input.note,
      payment_method: input.paymentMethod,
      tendered: input.tendered,
      // Only ids and quantities cross the wire. Prices are recomputed server-side from the
      // catalog, so a tampered browser cannot decide what something costs.
      lines: input.lines.map((line) => ({
        product_id: line.product.id,
        quantity: line.quantity,
        modifier_ids: line.modifiers.map((m) => m.id),
      })),
    });
  }
}
