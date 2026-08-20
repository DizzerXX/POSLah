import { KitchenTicket } from '../core/models';
import { elapsedMinutes, reconcile } from './ticket-reconciler';

function ticket(id: string, status = 'sent', lineStatus = 'pending'): KitchenTicket {
  return {
    id,
    order_number: `T2-${id}`,
    status,
    placed_at: '2026-08-14T09:00:00+00:00',
    lines: [
      { id: `${id}-l1`, name: 'Latte', quantity: 1, status: lineStatus as 'pending', modifiers: [] },
    ],
  };
}

/**
 * **The failure this whole step exists to handle.**
 *
 * Reverb will drop messages on network blips, tab suspension and server restarts. A KDS that
 * only listens to the socket silently misses orders — and it is silent, because a missed
 * ticket looks exactly like no ticket. It is also invisible in development, where a local
 * connection never drops.
 *
 * These tests kill the socket by simply never delivering anything through it, and assert the
 * poll recovers regardless. That is the whole safety net, tested without needing a socket, a
 * timer or a browser.
 */
describe('kitchen ticket reconciliation', () => {
  it('recovers a ticket the socket never delivered', () => {
    // The screen believes there is one ticket. The kitchen actually has two: the second was
    // created while the socket was down.
    const local = [ticket('a')];
    const server = [ticket('a'), ticket('b')];

    const result = reconcile(local, server);

    expect(result.recovered.map((t) => t.id)).toEqual(['b']);
    expect(result.tickets.map((t) => t.id)).toEqual(['a', 'b']);
    expect(result.changed).toBe(true);
  });

  it('recovers every missed ticket after a long outage', () => {
    const result = reconcile([], [ticket('a'), ticket('b'), ticket('c')]);

    expect(result.recovered).toHaveSize(3);
    expect(result.tickets).toHaveSize(3);
  });

  it('clears tickets that are finished server-side', () => {
    const result = reconcile([ticket('a'), ticket('b')], [ticket('b')]);

    expect(result.cleared).toEqual(['a']);
    expect(result.tickets.map((t) => t.id)).toEqual(['b']);
    expect(result.changed).toBe(true);
  });

  it('notices a line bumped by another screen', () => {
    const result = reconcile([ticket('a')], [ticket('a', 'preparing', 'ready')]);

    expect(result.recovered).toHaveSize(0);
    expect(result.changed).toBe(true);
    expect(result.tickets[0].lines[0].status).toBe('ready');
  });

  /**
   * A poll that changes nothing must not churn the screen — a kitchen display that reflows
   * every 30 seconds is one nobody can read mid-service.
   */
  it('reports no change when the server agrees with the screen', () => {
    const result = reconcile([ticket('a'), ticket('b')], [ticket('a'), ticket('b')]);

    expect(result.changed).toBe(false);
    expect(result.recovered).toHaveSize(0);
    expect(result.cleared).toHaveSize(0);
  });

  /** On reconnect the screen discards what it had rather than replaying missed events. */
  it('server state wins wholesale', () => {
    const stale = [ticket('a', 'sent', 'ready')];
    const truth = [ticket('a', 'sent', 'pending')];

    expect(reconcile(stale, truth).tickets[0].lines[0].status).toBe('pending');
  });
});

describe('elapsed time', () => {
  /**
   * Measured from when the customer ordered, not from when the server heard about it. An
   * order made offline and synced late shows a large number, which is true and useful rather
   * than a bug.
   */
  it('runs from the order time, so a late-synced ticket shows its real age', () => {
    const now = new Date('2026-08-14T09:37:00+00:00');

    expect(elapsedMinutes('2026-08-14T09:00:00+00:00', now)).toBe(37);
  });

  it('never reports a negative age when a till clock runs fast', () => {
    const now = new Date('2026-08-14T09:00:00+00:00');

    expect(elapsedMinutes('2026-08-14T09:05:00+00:00', now)).toBe(0);
  });

  it('treats an unknown order time as zero', () => {
    expect(elapsedMinutes(null)).toBe(0);
  });
});
