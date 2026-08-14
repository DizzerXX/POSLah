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
        path: 'fnb/ingredients',
        canMatch: [moduleGuard('fnb')],
        loadComponent: () => import('./settings/inventory.component').then((m) => m.InventoryComponent),
        data: { vertical: 'fnb' },
      },
      { path: '', pathMatch: 'full', redirectTo: 'stock-exceptions' },
    ],
  },
  { path: '**', redirectTo: '' },
];
