import { Component, OnDestroy, computed, inject, signal } from '@angular/core';

import { ApiService } from '../core/api.service';
import { KitchenTicket } from '../core/models';
import { KitchenSocket } from './kitchen-socket';
import { elapsedMinutes, reconcile } from './ticket-reconciler';

/**
 * The kitchen display.
 *
 * A web page, not a second app: kitchens run cheap tablets or repurposed monitors, a browser
 * is universally available, and a second Flutter app would double the release surface for a
 * read-mostly screen.
 *
 * **Three layers, in order of trust:**
 *
 * 1. Polling every 30 seconds is the source of truth.
 * 2. The socket is a fast path layered on top — an optimisation, never a dependency.
 * 3. On reconnect, discard local state and refetch rather than replaying missed events.
 *
 * That ordering is the whole point. The till solved network unreliability with a local-first
 * outbox; this solves it with polling reconciliation. Same stance, different mechanism —
 * because a KDS cannot be local-first: it is inherently a live view of other devices' state,
 * and there is nothing meaningful to show offline.
 *
 * 30 seconds is a judgement call, and a real kitchen would probably want 10 and would care
 * about the resulting load.
 */
@Component({
  selector: 'app-kds',
  standalone: true,
  template: `
    @if (disconnected()) {
      <div class="banner" data-testid="disconnected-banner">
        Disconnected — this screen is not live. Reconnecting…
      </div>
    }

    <header>
      <h1>Kitchen</h1>
      <span class="muted">{{ tickets().length }} open · checked {{ lastCheckedLabel() }}</span>
    </header>

    @if (tickets().length === 0) {
      <p class="muted empty">No tickets.</p>
    } @else {
      <div class="tickets">
        @for (ticket of tickets(); track ticket.id) {
          <article [class.late]="isLate(ticket)" [attr.data-testid]="'ticket-' + ticket.id">
            <div class="top">
              <strong>{{ ticket.order_number ?? ticket.id.slice(0, 8) }}</strong>
              <span class="timer">{{ elapsed(ticket) }}m</span>
            </div>
            <ul>
              @for (line of ticket.lines; track line.id) {
                <li
                  [class.ready]="line.status === 'ready'"
                  (click)="bump(line.id, line.status)"
                  [attr.data-testid]="'line-' + line.id"
                >
                  <span class="qty">{{ line.quantity }}×</span>
                  <span class="name">{{ line.name }}</span>
                  @if (line.modifiers.length) {
                    <span class="mods">{{ line.modifiers.join(', ') }}</span>
                  }
                </li>
              }
            </ul>
          </article>
        }
      </div>
    }
  `,
  styles: `
    :host { display: block; padding: 20px; background: #0f172a; min-height: 100vh; color: #e2e8f0; }
    .banner {
      margin: -20px -20px 20px; padding: 14px; background: #b91c1c; color: #fff;
      text-align: center; font-weight: 600;
    }
    header { display: flex; justify-content: space-between; align-items: baseline; }
    h1 { margin: 0; font-size: 22px; }
    .muted { color: #94a3b8; font-size: 13px; }
    .empty { margin-top: 40px; text-align: center; }
    .tickets {
      display: grid; grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 14px;
      margin-top: 18px;
    }
    article { background: #1e293b; border-radius: 12px; padding: 14px; border-top: 4px solid #0f766e; }
    article.late { border-top-color: #dc2626; }
    .top { display: flex; justify-content: space-between; align-items: baseline; }
    .top strong { font-size: 18px; }
    .timer { font-variant-numeric: tabular-nums; color: #94a3b8; }
    ul { list-style: none; margin: 12px 0 0; padding: 0; display: grid; gap: 6px; }
    li { display: flex; gap: 8px; padding: 8px; border-radius: 8px; background: #0f172a; cursor: pointer; }
    li.ready { opacity: 0.45; text-decoration: line-through; }
    .qty { color: #94a3b8; }
    .name { flex: 1; }
    .mods { color: #fbbf24; font-size: 12px; }
  `,
})
export class KdsComponent implements OnDestroy {
  private readonly api = inject(ApiService);

  readonly tickets = signal<KitchenTicket[]>([]);
  readonly disconnected = signal(false);
  readonly lastChecked = signal<Date | null>(null);

  /** A real kitchen would probably want 10s. 30s is the plan's judgement call. */
  static readonly pollIntervalMs = 30_000;

  private timer?: ReturnType<typeof setInterval>;
  private socket?: KitchenSocket;

  readonly lastCheckedLabel = computed(() => {
    const at = this.lastChecked();

    return at === null ? 'never' : at.toLocaleTimeString();
  });

  constructor() {
    this.poll();

    // The poll runs regardless of whether the socket ever connects. It is not a fallback
    // armed on failure — a code path that only runs when something breaks is one that is
    // almost never exercised and therefore almost always broken.
    this.timer = setInterval(() => this.poll(), KdsComponent.pollIntervalMs);

    this.connectSocket();
  }

  ngOnDestroy(): void {
    if (this.timer) clearInterval(this.timer);
    this.socket?.disconnect();
  }

  private connectSocket(): void {
    const token = this.api.token;
    const storeId = this.api.primaryStoreId;

    if (token === null || storeId === null) return;

    this.socket = new KitchenSocket(token, storeId);

    this.socket.connect({
      // Refetch rather than applying the payload: the server derives order status from its
      // lines, and reimplementing that here would be a second source of truth.
      onChange: () => this.poll(),
      onConnectionChange: (connected) => {
        if (connected) {
          // Discard and refetch on reconnect rather than replaying what was missed.
          this.poll();
        }
      },
    });
  }



  /**
   * The safety net firing. Anything the server reports that this screen does not have is
   * rendered — whether or not a socket event ever arrived for it.
   */
  poll(): void {
    this.api.activeKitchenOrders().subscribe({
      next: (response) => {
        const result = reconcile(this.tickets(), response.data);

        if (result.changed) this.tickets.set(result.tickets);

        this.disconnected.set(false);
        this.lastChecked.set(new Date());
      },
      error: () => {
        // Freeze and say so. A kitchen screen that looks normal while showing five-minute-old
        // data is worse than one that visibly admits it is broken.
        this.disconnected.set(true);
      },
    });
  }

  bump(lineId: string, current: string): void {
    this.api.setLineStatus(lineId, current === 'ready' ? 'pending' : 'ready').subscribe({
      // Refetch rather than patching local state: the server derives the order's status from
      // its lines, and guessing at that derivation here would be a second implementation of it.
      next: () => this.poll(),
      error: () => this.disconnected.set(true),
    });
  }

  elapsed(ticket: KitchenTicket): number {
    return elapsedMinutes(ticket.placed_at);
  }

  isLate(ticket: KitchenTicket): boolean {
    return this.elapsed(ticket) >= 10;
  }
}
