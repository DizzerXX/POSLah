import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { environment } from '../../environments/environment';

import {
  Account,
  AuthenticatedUser,
  Customer,
  Ingredient,
  KitchenTicket,
  LoginResponse,
  PosSettings,
  Recipe,
  StockException,
  StoreSummary,
  Variant,
} from './models';

const TOKEN_KEY = 'poslah.token';
const USER_KEY = 'poslah.user';
const ACCOUNT_KEY = 'poslah.account';
const STORES_KEY = 'poslah.stores';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  /// Comes from the environment, swapped at build time. Never a literal in a component.
  readonly baseUrl = environment.apiUrl;

  readonly user = signal<AuthenticatedUser | null>(readStored(USER_KEY));
  readonly account = signal<Account | null>(readStored(ACCOUNT_KEY));
  readonly stores = signal<StoreSummary[]>(readStored(STORES_KEY) ?? []);

  /// v1 is single-store per account; the KDS watches that one.
  get primaryStoreId(): string | null {
    return this.stores()[0]?.id ?? null;
  }

  get token(): string | null {
    return localStorage.getItem(TOKEN_KEY);
  }

  get isSignedIn(): boolean {
    return this.token !== null;
  }

  login(email: string, password: string): Observable<LoginResponse> {
    return this.http
      .post<LoginResponse>(`${this.baseUrl}/api/v1/auth/login`, { email, password })
      .pipe(
        tap((response) => {
          localStorage.setItem(TOKEN_KEY, response.token);
          localStorage.setItem(USER_KEY, JSON.stringify(response.user));
          localStorage.setItem(ACCOUNT_KEY, JSON.stringify(response.account));
          localStorage.setItem(STORES_KEY, JSON.stringify(response.stores));
          this.stores.set(response.stores);
          this.user.set(response.user);
          this.account.set(response.account);
        }),
      );
  }

  signOut(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(ACCOUNT_KEY);
    localStorage.removeItem(STORES_KEY);
    this.user.set(null);
    this.account.set(null);
    this.stores.set([]);
  }

  /** Reads straight off the signed-in account. Rendering only — the server authorises. */
  hasModule(moduleId: string): boolean {
    return this.account()?.enabled_modules.includes(moduleId) ?? false;
  }

  customers(search?: string) {
    const query = search ? `?search=${encodeURIComponent(search)}` : '';

    return this.http.get<{ data: Customer[] }>(`${this.baseUrl}/api/v1/customers${query}`);
  }

  ingredients() {
    return this.http.get<{ data: Ingredient[] }>(`${this.baseUrl}/api/v1/ingredients`);
  }

  productVariants() {
    return this.http.get<{ data: Variant[] }>(`${this.baseUrl}/api/v1/product-variants`);
  }

  recipes() {
    return this.http.get<{ data: Recipe[] }>(`${this.baseUrl}/api/v1/recipes`);
  }

  saveRecipe(productId: string, lines: { ingredient_id: string; quantity: string }[]) {
    return this.http.put<{ data: Recipe[] }>(`${this.baseUrl}/api/v1/recipes/${productId}`, { lines });
  }

  activeKitchenOrders() {
    return this.http.get<{ server_time: string; data: KitchenTicket[] }>(
      `${this.baseUrl}/api/v1/kitchen/active-orders`,
    );
  }

  setLineStatus(lineId: string, status: 'pending' | 'ready') {
    return this.http.patch<{ data: KitchenTicket }>(
      `${this.baseUrl}/api/v1/kitchen/lines/${lineId}`,
      { status },
    );
  }

  settings() {
    return this.http.get<{ data: PosSettings }>(`${this.baseUrl}/api/v1/settings`);
  }

  saveSettings(changes: Record<string, string | null>) {
    return this.http.patch<{ data: PosSettings }>(`${this.baseUrl}/api/v1/settings`, changes);
  }

  updateModules(modules: string[]) {
    return this.http
      .patch<{ data: { enabled_modules: string[] } }>(`${this.baseUrl}/api/v1/account/modules`, {
        enabled_modules: modules,
      })
      .pipe(
        tap((response) => {
          const account = this.account();
          if (account) {
            const updated = { ...account, enabled_modules: response.data.enabled_modules };
            localStorage.setItem(ACCOUNT_KEY, JSON.stringify(updated));
            this.account.set(updated);
          }
        }),
      );
  }

  stockExceptions(status?: 'open' | 'resolved'): Observable<{ data: StockException[] }> {
    const query = status ? `?status=${status}` : '';

    return this.http.get<{ data: StockException[] }>(
      `${this.baseUrl}/api/v1/stock-exceptions${query}`,
    );
  }

  /**
   * Resolve by stocktake. The server records the difference as a manual_adjustment movement —
   * an append, like every other stock fact — and recomputes the level from the log.
   */
  resolveException(
    id: string,
    countedQuantity: string,
    note: string | null,
  ): Observable<{ data: StockException }> {
    return this.http.post<{ data: StockException }>(
      `${this.baseUrl}/api/v1/stock-exceptions/${id}/resolve`,
      { counted_quantity: countedQuantity, note },
    );
  }
}

function readStored<T>(key: string): T | null {
  const raw = localStorage.getItem(key);

  return raw === null ? null : (JSON.parse(raw) as T);
}
