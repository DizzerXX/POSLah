import { Routes } from '@angular/router';

import { signedInGuard } from './core/auth.guard';
import { moduleGuard } from './core/module.guard';

/**
 * Navigation is entitlement-driven, the same principle the till applies.
 *
 * A vertical's routes use `canMatch`, so for an unentitled account the path does not match
 * at all and falls through to the wildcard. That is deliberately different from an
 * access-denied screen: the screen is *not there*, rather than there and withheld.
 *
 * Shared sections carry no module guard, because they are the same screens in both
 * verticals. If one ever needs splitting, the design has gone wrong.
 */
export const routes: Routes = [
  {
    path: 'sign-in',
    loadComponent: () => import('./auth/sign-in.component').then((m) => m.SignInComponent),
  },
  {
    path: '',
    canActivate: [signedInGuard],
    loadComponent: () => import('./shell/shell.component').then((m) => m.ShellComponent),
    children: [
      {
        path: 'dashboard',
        loadComponent: () => import('./dashboard/dashboard.component').then((m) => m.DashboardComponent),
      },
      {
        path: 'stock-exceptions',
        loadComponent: () =>
          import('./stock-exceptions/stock-exceptions.component').then(
            (m) => m.StockExceptionsComponent,
          ),
      },
      {
        path: 'customers',
        loadComponent: () => import('./customers/customers.component').then((m) => m.CustomersComponent),
      },
      {
        path: 'settings/general',
        loadComponent: () => import('./settings/settings.component').then((m) => m.SettingsComponent),
      },
      {
        path: 'settings/modules',
        loadComponent: () => import('./settings/modules.component').then((m) => m.ModulesComponent),
      },
      {
        path: 'retail/variants',
        canMatch: [moduleGuard('retail')],
        loadComponent: () => import('./settings/inventory.component').then((m) => m.InventoryComponent),
        data: { vertical: 'retail' },
      },
      {
        path: 'fnb/recipes',
        canMatch: [moduleGuard('fnb')],
        loadComponent: () => import('./recipes/recipes.component').then((m) => m.RecipesComponent),
      },
      {
        path: 'fnb/ingredients',
        canMatch: [moduleGuard('fnb')],
        loadComponent: () => import('./settings/inventory.component').then((m) => m.InventoryComponent),
        data: { vertical: 'fnb' },
      },
      { path: '', pathMatch: 'full', redirectTo: 'dashboard' },
    ],
  },
  // The KDS sits outside the backoffice shell: it runs full-screen on a kitchen tablet and
  // has no business showing navigation. Still module-gated — a retail account has no kitchen.
  {
    path: 'kds',
    canActivate: [signedInGuard],
    canMatch: [moduleGuard('fnb')],
    loadComponent: () => import('./kds/kds.component').then((m) => m.KdsComponent),
  },
  { path: '**', redirectTo: '' },
];
