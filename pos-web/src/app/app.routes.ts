import { inject } from '@angular/core';
import { Routes, Router } from '@angular/router';

import { PosApiService } from './core/pos-api.service';

const signedIn = () => {
  const api = inject(PosApiService);

  return api.isSignedIn ? true : inject(Router).createUrlTree(['/sign-in']);
};

export const routes: Routes = [
  {
    path: 'sign-in',
    loadComponent: () => import('./shell/sign-in.component').then((m) => m.PosSignInComponent),
  },
  {
    path: '',
    canActivate: [signedIn],
    loadComponent: () => import('./shell/pos-shell.component').then((m) => m.PosShellComponent),
    children: [
      {
        path: 'order',
        loadComponent: () => import('./order/order.component').then((m) => m.OrderComponent),
      },
      {
        path: 'tables',
        loadComponent: () => import('./tables/tables.component').then((m) => m.TablesComponent),
      },
      // History and Report are the same component with different framing: the rows are
      // literally the same rows, and two copies of one table is two things to keep in step.
      {
        path: 'history',
        loadComponent: () =>
          import('./history/transactions.component').then((m) => m.TransactionsComponent),
        data: { mode: 'history' },
      },
      {
        path: 'report',
        loadComponent: () =>
          import('./history/transactions.component').then((m) => m.TransactionsComponent),
        data: { mode: 'report' },
      },
      {
        path: 'supply',
        loadComponent: () => import('./supply/supply.component').then((m) => m.SupplyComponent),
      },
      { path: '', pathMatch: 'full', redirectTo: 'order' },
    ],
  },
  { path: '**', redirectTo: '' },
];
