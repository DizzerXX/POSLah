import { Component, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';

import { ApiService } from '../core/api.service';

@Component({
  selector: 'app-sign-in',
  standalone: true,
  imports: [FormsModule],
  template: `
    <div class="shell">
      <form class="card" (ngSubmit)="submit()">
        <h1>POSlah</h1>
        <p class="sub">Backoffice</p>

        <label>
          Email
          <input name="email" type="email" [(ngModel)]="email" autocomplete="username" required />
        </label>

        <label>
          Password
          <input
            name="password"
            type="password"
            [(ngModel)]="password"
            autocomplete="current-password"
            required
          />
        </label>

        @if (error()) {
          <p class="error">{{ error() }}</p>
        }

        <button type="submit" [disabled]="busy()">
          {{ busy() ? 'Signing in…' : 'Sign in' }}
        </button>

        <p class="hint">Seeded: manager&#64;poslah.test / password</p>
      </form>
    </div>
  `,
  styles: `
    .shell { display: grid; place-items: center; min-height: 100vh; background: #0f172a; }
    .card {
      display: grid; gap: 14px; width: min(380px, 90vw); padding: 32px;
      background: #fff; border-radius: 14px; box-shadow: 0 20px 60px rgb(0 0 0 / 35%);
    }
    h1 { margin: 0; font-size: 26px; letter-spacing: -0.02em; }
    .sub { margin: -10px 0 6px; color: #64748b; font-size: 14px; }
    label { display: grid; gap: 6px; font-size: 13px; font-weight: 600; color: #334155; }
    input {
      padding: 10px 12px; border: 1px solid #cbd5e1; border-radius: 8px; font-size: 15px;
    }
    button {
      margin-top: 6px; padding: 11px; border: 0; border-radius: 8px; cursor: pointer;
      background: #0f766e; color: #fff; font-size: 15px; font-weight: 600;
    }
    button:disabled { opacity: 0.6; cursor: default; }
    .error { margin: 0; color: #b91c1c; font-size: 13px; }
    .hint { margin: 0; color: #94a3b8; font-size: 12px; }
  `,
})
export class SignInComponent {
  private readonly api = inject(ApiService);
  private readonly router = inject(Router);

  email = 'manager@poslah.test';
  password = 'password';

  readonly busy = signal(false);
  readonly error = signal<string | null>(null);

  submit(): void {
    this.busy.set(true);
    this.error.set(null);

    this.api.login(this.email, this.password).subscribe({
      next: () => void this.router.navigate(['/dashboard']),
      error: (response) => {
        this.busy.set(false);
        // One message for every failure mode, matching the server: distinguishing them
        // turns the sign-in form into a directory of who works here.
        this.error.set(
          response.error?.message ?? 'Those credentials do not match.',
        );
      },
    });
  }
}
