import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { PosApiService } from '../core/pos-api.service';

@Component({
  selector: 'app-pos-sign-in',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="shell">
      <form class="card" (ngSubmit)="submit()">
        <img src="logo.png" alt="POSlah" width="56" height="56" />
        <h1>POSlah</h1>
        <p class="muted">Point of sale</p>

        <label>Email<input name="email" type="email" [(ngModel)]="email" required /></label>
        <label>Password<input name="password" type="password" [(ngModel)]="password" required /></label>

        @if (error()) { <p class="error">{{ error() }}</p> }

        <button class="pos-btn" type="submit" [disabled]="busy()">
          {{ busy() ? 'Signing in…' : 'Sign in' }}
        </button>
        <p class="hint">Seeded: manager&#64;poslah.test / password</p>
      </form>
    </div>
  `,
  styles: `
    .shell { display: grid; place-items: center; min-height: 100vh; }
    .card {
      display: grid; gap: 12px; justify-items: stretch; width: min(360px, 90vw); padding: 30px;
      background: var(--pos-surface); border-radius: 16px; box-shadow: var(--pos-shadow);
      text-align: center;
    }
    img { justify-self: center; }
    h1 { margin: 0; font-size: 24px; }
    .muted { margin: -8px 0 6px; color: var(--pos-muted); font-size: 14px; }
    label { display: grid; gap: 5px; text-align: left; font-size: 12px; font-weight: 700; color: var(--pos-muted); }
    input { padding: 10px 12px; border: 1px solid var(--pos-line); border-radius: 10px; font: inherit; }
    .error { margin: 0; color: var(--pos-danger); font-size: 13px; }
    .hint { margin: 0; color: var(--pos-muted); font-size: 11px; }
  `,
})
export class PosSignInComponent {
  private readonly api = inject(PosApiService);
  private readonly router = inject(Router);

  email = 'manager@poslah.test';
  password = 'password';

  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    this.busy.set(true);
    this.error.set(null);

    this.api.login(this.email, this.password).subscribe({
      next: () => void this.router.navigate(['/order']),
      error: () => {
        this.busy.set(false);
        this.error.set('Those credentials do not match.');
      },
    });
  }
}
