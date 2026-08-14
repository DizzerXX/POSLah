import { Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { ApiService } from '../core/api.service';

/**
 * The backoffice chrome.
 *
 * Navigation is entitlement-driven: a vertical's section is not listed because its route is
 * not registered, not because a flag hid it. Shared sections — customers, stock exceptions —
 * are listed unconditionally, because they are the same screens in both verticals.
 */
@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <aside>
      <div class="brand">
        <strong>POSlah</strong>
        <span class="muted">{{ account()?.name }}</span>
      </div>

      <nav>
        <span class="group">Shared</span>
        <a routerLink="/stock-exceptions" routerLinkActive="on">Stock exceptions</a>
        <a routerLink="/customers" routerLinkActive="on">Customers</a>

        @if (hasRetail() || hasFnb()) {
          <span class="group">Inventory</span>
        }
        @if (hasRetail()) {
          <a routerLink="/retail/variants" routerLinkActive="on" data-testid="nav-retail">
            SKUs &amp; variants
          </a>
        }
        @if (hasFnb()) {
          <a routerLink="/fnb/ingredients" routerLinkActive="on" data-testid="nav-fnb">
            Ingredients
          </a>
          <a routerLink="/fnb/recipes" routerLinkActive="on" data-testid="nav-recipes">Recipes</a>
          <a routerLink="/kds" routerLinkActive="on" data-testid="nav-kds">Kitchen display</a>
        }

        <span class="group">Account</span>
        <a routerLink="/settings/modules" routerLinkActive="on">Modules</a>
      </nav>

      <div class="who">
        <span>{{ user()?.name }}</span>
        <button class="ghost" (click)="signOut()">Sign out</button>
      </div>
    </aside>

    <main><router-outlet /></main>
  `,
  styles: `
    :host { display: grid; grid-template-columns: 236px 1fr; min-height: 100vh; }
    aside {
      display: flex; flex-direction: column; gap: 20px; padding: 24px 16px;
      background: #0f172a; color: #e2e8f0;
    }
    .brand { display: grid; gap: 2px; }
    .brand strong { font-size: 18px; }
    .muted { color: #94a3b8; font-size: 13px; }
    nav { display: grid; gap: 2px; flex: 1; }
    .group {
      margin-top: 14px; padding: 0 10px; color: #64748b; font-size: 11px;
      text-transform: uppercase; letter-spacing: 0.08em;
    }
    a {
      padding: 8px 10px; border-radius: 8px; color: #cbd5e1; text-decoration: none;
      font-size: 14px;
    }
    a:hover { background: #1e293b; }
    a.on { background: #0f766e; color: #fff; }
    .who { display: grid; gap: 8px; font-size: 13px; }
    .ghost {
      padding: 7px 10px; border: 1px solid #334155; border-radius: 8px; background: transparent;
      color: #e2e8f0; cursor: pointer; font-size: 13px;
    }
    main { background: #f8fafc; overflow: auto; }
  `,
})
export class ShellComponent {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  readonly user = computed(() => this.api.user());
  readonly account = computed(() => this.api.account());

  readonly hasRetail = computed(() => this.account()?.enabled_modules.includes('retail') ?? false);
  readonly hasFnb = computed(() => this.account()?.enabled_modules.includes('fnb') ?? false);

  signOut(): void {
    this.api.signOut();
    void this.router.navigate(['/sign-in']);
  }
}
