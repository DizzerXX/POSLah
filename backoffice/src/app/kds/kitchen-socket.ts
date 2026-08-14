import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

import { environment } from '../../environments/environment';

/**
 * The fast path — and only the fast path.
 *
 * Nothing here is allowed to be load-bearing. Reverb drops messages on network blips, tab
 * suspension and server restarts, so this exists to make tickets appear in about a second
 * under normal conditions, while the 30-second poll remains the thing that is actually
 * correct. If this file were deleted, the kitchen would still work — a little slower, and
 * that is the whole design.
 *
 * On reconnect it deliberately does **not** replay missed events. It reports the reconnect
 * and lets the caller refetch, because working out what was missed is harder and less
 * reliable than simply asking again.
 */
export interface KitchenSocketHandlers {
  onChange: () => void;
  onConnectionChange: (connected: boolean) => void;
}

export class KitchenSocket {
  private echo?: Echo<'reverb'>;

  constructor(
    private readonly token: string,
    private readonly storeId: string,
  ) {}

  get isConfigured(): boolean {
    return environment.reverb.key !== '';
  }

  connect(handlers: KitchenSocketHandlers): void {
    // An unconfigured deployment simply runs on the poll. That is a supported state, not a
    // degraded one.
    if (!this.isConfigured) return;

    (window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher;

    this.echo = new Echo({
      broadcaster: 'reverb',
      key: environment.reverb.key,
      wsHost: environment.reverb.host,
      wsPort: environment.reverb.port,
      wssPort: environment.reverb.port,
      forceTLS: environment.reverb.scheme === 'https',
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${environment.apiUrl}/broadcasting/auth`,
      auth: { headers: { Authorization: `Bearer ${this.token}` } },
    });

    const connection = (this.echo.connector as { pusher: Pusher }).pusher.connection;

    connection.bind('connected', () => handlers.onConnectionChange(true));
    connection.bind('unavailable', () => handlers.onConnectionChange(false));
    connection.bind('failed', () => handlers.onConnectionChange(false));
    connection.bind('disconnected', () => handlers.onConnectionChange(false));

    this.echo
      .private(`store.${this.storeId}.kitchen`)
      // Every event means the same thing to this screen: something changed, go and ask.
      // Applying payloads directly would be a second implementation of the server's
      // derivation, and the two would eventually disagree.
      .listen('.order.sent', handlers.onChange)
      .listen('.line.status', handlers.onChange);
  }

  disconnect(): void {
    this.echo?.disconnect();
    this.echo = undefined;
  }
}
