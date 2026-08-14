import { Routes } from '@angular/router';
import { inject } from '@angular/core';
import { Router } from '@angular/router';

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
    path: 'order',
    canActivate: [signedIn],
    loadComponent: () => import('./order/order.component').then((m) => m.OrderComponent),
  },
  { path: '**', redirectTo: 'order' },
];
