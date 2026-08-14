import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';

import { ApiService } from './api.service';

export const signedInGuard: CanActivateFn = () => {
  const api = inject(ApiService);
  const router = inject(Router);

  return api.isSignedIn ? true : router.createUrlTree(['/sign-in']);
};
