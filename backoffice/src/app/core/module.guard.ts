import { inject } from '@angular/core';
import { CanMatchFn, Router } from '@angular/router';

import { ApiService } from './api.service';

/**
 * Gates a vertical's navigation subtree.
 *
 * `CanMatch` rather than `CanActivate` on purpose: CanMatch makes the route *fail to match*,
 * so an unentitled module's path is not part of this build of the routing tree at all.
 * Deep-linking to it falls through to the wildcard and lands somewhere real, rather than
 * rendering an access-denied screen that implies the page exists and is being withheld.
 *
 * This is UX. The server refuses the underlying endpoints regardless of what the client
 * decides to render.
 */
export function moduleGuard(moduleId: string): CanMatchFn {
  return () => {
    const api = inject(ApiService);
    const router = inject(Router);

    if (!api.isSignedIn) return router.createUrlTree(['/sign-in']);

    return api.hasModule(moduleId);
  };
}
