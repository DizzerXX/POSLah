import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';

import { PosApiService } from './pos-api.service';

export const posAuthInterceptor: HttpInterceptorFn = (request, next) => {
  const api = inject(PosApiService);
  const router = inject(Router);
  const token = api.token;

  const authorised = token
    ? request.clone({ setHeaders: { Authorization: `Bearer ${token}` } })
    : request;

  return next(authorised).pipe(
    catchError((error) => {
      // 403 here usually means the account lost its F&B module rather than a bad token, so
      // it is left to the screen to explain rather than silently signing the cashier out
      // mid-service.
      if (error.status === 401) {
        api.signOut();
        void router.navigate(['/sign-in']);
      }

      return throwError(() => error);
    }),
  );
};
