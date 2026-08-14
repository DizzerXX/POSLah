import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { ApiService } from './api.service';

/**
 * Attaches the user token, and treats a 401 as "signed out" rather than as an error the user
 * has to interpret. A token revoked server-side — someone deactivated in the backoffice —
 * lands here on the very next request.
 */
export const authInterceptor: HttpInterceptorFn = (request, next) => {
  const api = inject(ApiService);
  const router = inject(Router);

  const token = api.token;

  const authorised = token
    ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : request;

  return next(authorised).pipe(
    catchError((error) => {
      if (error.status === 401 || error.status === 403) {
        api.signOut();
        void router.navigate(['/sign-in']);
      }

      return throwError(() => error);
    }),
  );
};
