import { Routes } from '@angular/router';

import { signedInGuard } from './core/auth.guard';

/**
 * Routes are registered flat for now. Step 4 makes navigation entitlement-driven — a
 * module's routes are not registered at all for an account that lacks it, so deep-linking
 * produces a 404 rather than an access-denied screen.
 */
export const routes: Routes = [
  {
    path: 'sign-in',
    loadComponent: () => import('./auth/sign-in.component').then((m) => m.SignInComponent),
  },
  {
    path: 'stock-exceptions',
    canActivate: [signedInGuard],
    loadComponent: () =>
      import('./stock-exceptions/stock-exceptions.component').then(
        (m) => m.StockExceptionsComponent,
      ),
  },
  { path: '**', redirectTo: 'stock-exceptions' },
];
