import { KitchenTicket } from '../core/models';

/**
 * Reconciles a poll against what the screen currently believes.
 *
 * **This is the safety net the whole KDS design rests on.** WebSocket delivery is not
 * guaranteed: sockets drop on network blips, suspended tabs and server restarts. A screen
 * that only listens silently misses tickets — and silently is the operative word, because a
 * missed ticket looks exactly like no ticket, and a customer waits for food nobody is
 * cooking.
 *
 * So the poll is the source of truth and the socket is an optimisation on top. Anything the
 * server reports that the screen does not have gets rendered; anything the screen has that
 * the server no longer reports gets dropped.
 *
 * Pure and synchronous on purpose — the recovery behaviour is the thing most worth testing,
 * and it should not require a socket, a timer or a browser to test it.
 */
export interface Reconciliation {
  tickets: KitchenTicket[];
  /** Tickets the socket never delivered. Non-empty means the socket missed something. */
  recovered: KitchenTicket[];
  /** Tickets that are finished or gone server-side. */
  cleared: string[];
  changed: boolean;
}

export function reconcile(local: KitchenTicket[], fromServer: KitchenTicket[]): Reconciliation {
  const localById = new Map(local.map((ticket) => [ticket.id, ticket]));
  const serverIds = new Set(fromServer.map((ticket) => ticket.id));

  const recovered = fromServer.filter((ticket) => !localById.has(ticket.id));

  const cleared = local.filter((ticket) => !serverIds.has(ticket.id)).map((t) => t.id);

  // Server state wins wholesale rather than being merged field by field. A merge would need
  // to know which side is newer, and the honest answer is that the server always is.
  const changed =
    recovered.length > 0 ||
    cleared.length > 0 ||
    fromServer.some((ticket) => !sameStatus(ticket, localById.get(ticket.id)));

  return { tickets: fromServer, recovered, cleared, changed };
}

function sameStatus(ticket: KitchenTicket, previous: KitchenTicket | undefined): boolean {
  if (previous === undefined) return false;
  if (previous.status !== ticket.status) return false;
  if (previous.lines.length !== ticket.lines.length) return false;

  return ticket.lines.every(
    (line, index) =>
      previous.lines[index]?.id === line.id && previous.lines[index]?.status === line.status,
  );
}

/** Minutes since the customer ordered — not since the server heard about it. */
export function elapsedMinutes(placedAt: string | null, now: Date = new Date()): number {
  if (placedAt === null) return 0;

  return Math.max(0, Math.floor((now.getTime() - new Date(placedAt).getTime()) / 60000));
}
