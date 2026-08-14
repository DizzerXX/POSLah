import { HttpClient } from '@angular/common/http';
import { Injectable, inject, signal } from '@angular/core';
import { Observable, tap } from 'rxjs';

import { Account, AuthenticatedUser, LoginResponse, StockException } from './models';

const TOKEN_KEY = 'poslah.token';
const USER_KEY = 'poslah.user';
const ACCOUNT_KEY = 'poslah.account';

@Injectable({ providedIn: 'root' })
export class ApiService {
  private readonly http = inject(HttpClient);

  readonly baseUrl = 'http://127.0.0.1:8123';

  readonly user = signal<AuthenticatedUser | null>(readStored(USER_KEY));
  readonly account = signal<Account | null>(readStored(ACCOUNT_KEY));

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
          this.user.set(response.user);
          this.account.set(response.account);
        }),
      );
  }

  signOut(): void {
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
    localStorage.removeItem(ACCOUNT_KEY);
    this.user.set(null);
    this.account.set(null);
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
