import { Component, computed, inject } from '@angular/core';
import { Router, RouterLink, RouterLinkActive, RouterOutlet } from '@angular/router';

import { ApiService } from '../core/api.service';

/**
 * The backoffice chrome.
 *
 * Navigation is grouped by what a merchant is actually doing — reports, the things they
 * sell, the people involved, and the system underneath — rather than by which controller
 * happens to serve it.
 *
 * It is also entitlement-driven: a vertical's section is absent because its route is not
 * registered, not because a flag hid it. Shared sections are listed unconditionally,
 * because they are the same screens in both verticals.
 */
@Component({
  selector: 'app-shell',
  standalone: true,
  imports: [RouterOutlet, RouterLink, RouterLinkActive],
  template: `
    <aside>
      <div class="brand">
        <img src="logo.png" alt="" width="46" height="46" />
        <strong>POSlah</strong>
      </div>

      <nav>
        <span class="group">Reports</span>
        <a routerLink="/dashboard" routerLinkActive="on" data-testid="nav-dashboard">
          <span class="dot"></span>Dashboard
        </a>

        <span class="group">Product Management</span>
        @if (hasRetail()) {
          <a routerLink="/retail/variants" routerLinkActive="on" data-testid="nav-retail">
            <span class="dot"></span>SKUs &amp; Variants
          </a>
        }
        @if (hasFnb()) {
          <a routerLink="/fnb/ingredients" routerLinkActive="on" data-testid="nav-fnb">
            <span class="dot"></span>Ingredients
          </a>
          <a routerLink="/fnb/recipes" routerLinkActive="on" data-testid="nav-recipes">
            <span class="dot"></span>Recipes
          </a>
        }
        <a routerLink="/stock-exceptions" routerLinkActive="on">
          <span class="dot"></span>Stock Exceptions
        </a>

        <span class="group">User Management</span>
        <a routerLink="/customers" routerLinkActive="on"><span class="dot"></span>Customers</a>

        <span class="group">System</span>
        @if (hasFnb()) {
          <a routerLink="/kds" routerLinkActive="on" data-testid="nav-kds">
            <span class="dot"></span>Kitchen Display
          </a>
        }
        <a routerLink="/settings/general" routerLinkActive="on" data-testid="nav-settings">
          <span class="dot"></span>Settings
        </a>
        <a routerLink="/settings/modules" routerLinkActive="on"><span class="dot"></span>Modules</a>
      </nav>

      <button class="sign-out" (click)="signOut()">Log out</button>
    </aside>

    <div class="frame">
      <header class="topbar">
        <span class="store">{{ account()?.name }}</span>
        <div class="who">
          <span class="avatar">{{ initials() }}</span>
          <span class="identity">
            <strong>{{ user()?.name }}</strong>
            <em>{{ user()?.role }}</em>
          </span>
        </div>
      </header>

      <main><router-outlet /></main>
    </div>
  `,
  styles: `
    :host { display: grid; grid-template-columns: 250px 1fr; min-height: 100vh; }

    aside {
      display: flex; flex-direction: column; gap: 6px; padding: 22px 14px;
      background: var(--bo-surface); border-right: 1px solid var(--bo-line);
    }
    .brand { display: grid; justify-items: center; gap: 6px; padding: 6px 0 18px; }
    .brand strong { font-size: 17px; }

    nav { display: grid; gap: 2px; flex: 1; }
    .group {
      margin-top: 16px; padding: 0 12px 4px; color: var(--bo-muted);
      font-size: 12px; font-weight: 600;
    }
    nav a {
      display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 10px;
      color: var(--bo-ink); text-decoration: none; font-size: 14px; font-weight: 500;
    }
    nav a:hover { background: var(--bo-canvas); }
    nav a.on { background: var(--bo-accent-soft); color: var(--bo-accent); font-weight: 600; }
    .dot {
      width: 20px; height: 20px; border-radius: 6px; background: var(--bo-neutral-soft); flex: none;
    }
    nav a.on .dot { background: var(--bo-accent); }

    .sign-out {
      margin-top: 10px; padding: 10px 12px; border: 0; border-radius: 10px; background: transparent;
      color: var(--bo-danger); text-align: left; font-size: 14px; font-weight: 600;
    }
    .sign-out:hover { background: var(--bo-danger-soft); }

    .frame { display: grid; grid-template-rows: 72px 1fr; min-width: 0; }
    .topbar {
      display: flex; align-items: center; justify-content: space-between; padding: 0 26px;
      background: var(--bo-surface); border-bottom: 1px solid var(--bo-line);
    }
    .store { font-size: 14px; color: var(--bo-muted); }
    .who { display: flex; align-items: center; gap: 10px; padding: 6px 8px; border-radius: 999px; background: var(--bo-canvas); }
    .avatar {
      width: 34px; height: 34px; border-radius: 50%; display: grid; place-items: center;
      background: var(--bo-accent); color: #fff; font-size: 13px; font-weight: 700;
    }
    .identity { display: grid; line-height: 1.2; }
    .identity strong { font-size: 14px; }
    .identity em { font-style: normal; font-size: 12px; color: var(--bo-muted); }

    main { overflow-y: auto; }
  `,
})
export class ShellComponent {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  readonly user = computed(() => this.api.user());
  readonly account = computed(() => this.api.account());

  readonly hasRetail = computed(() => this.api.hasModule('retail'));
  readonly hasFnb = computed(() => this.api.hasModule('fnb'));

  readonly initials = computed(() =>
    (this.user()?.name ?? '')
      .split(' ')
      .slice(0, 2)
      .map((part) => part[0] ?? '')
      .join('')
      .toUpperCase(),
  );

  signOut(): void {
    this.api.signOut();
    void this.router.navigate(['/sign-in']);
  }
}
