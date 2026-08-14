import { provideHttpClient, withInterceptors } from '@angular/common/http';
import { ApplicationConfig, provideZoneChangeDetection } from '@angular/core';
import { provideRouter, withComponentInputBinding } from '@angular/router';

import { routes } from './app.routes';
import { posAuthInterceptor } from './core/auth.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideZoneChangeDetection({ eventCoalescing: true }),
    // Route `data` binds to component inputs, so Transactions receives its mode
    // without reading the router itself.
    provideRouter(routes, withComponentInputBinding()),
    provideHttpClient(withInterceptors([posAuthInterceptor])),
  ],
};
