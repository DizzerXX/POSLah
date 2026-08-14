import { Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { PosApiService } from '../core/pos-api.service';

/**
 * The till's chrome.
 *
 * A thin top bar rather than a sidebar: horizontal space on a counter screen belongs to the
 * menu and the order, and there are only five destinations. The ordering screen keeps its
 * own three-column layout underneath.
 */
@Component({
  selector: 'app-pos-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <header class="bar">
      <a class="brand" routerLink="/order">
        <img src="logo.png" alt="POSlah" width="26" height="26" />
        <strong>POSlah</strong>
      </a>

      <nav>
        <a routerLink="/order" routerLinkActive="on" data-testid="nav-order">Order</a>
        <a routerLink="/tables" routerLinkActive="on" data-testid="nav-tables">Table</a>
        <a routerLink="/history" routerLinkActive="on" data-testid="nav-history">History</a>
        <a routerLink="/report" routerLinkActive="on" data-testid="nav-report">Report</a>
        <a routerLink="/supply" routerLinkActive="on" data-testid="nav-supply">Supply</a>
      </nav>

      <div class="who">
        <span>{{ cashier() }}</span>
        <button class="pos-btn-ghost" (click)="signOut()">Close</button>
      </div>
    </header>

    <div class="body"><router-outlet /></div>
  `,
  styles: `
    :host { display: grid; grid-template-rows: 56px 1fr; height: 100vh; }
    .bar {
      display: flex; align-items: center; gap: 20px; padding: 0 16px;
      background: var(--pos-surface); border-bottom: 1px solid var(--pos-line);
    }
    .brand { display: flex; align-items: center; gap: 8px; text-decoration: none; color: inherit; }
    nav { display: flex; gap: 4px; flex: 1; }
    nav a {
      padding: 8px 14px; border-radius: 999px; text-decoration: none; color: var(--pos-muted);
      font-size: 14px; font-weight: 600;
    }
    nav a:hover { background: var(--pos-canvas); }
    nav a.on { background: var(--pos-accent); color: #fff; }
    .who { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--pos-muted); }
    .body { overflow: hidden; }
  `,
})
export class PosShellComponent {
  private readonly api = inject(PosApiService);
  private readonly router = inject(Router);

  readonly cashier = computed(() => this.api.cashierName());

  signOut(): void {
    this.api.signOut();
    void this.router.navigate(['/sign-in']);
  }
}
