/** Money is integer minor units on the wire, exactly as it is everywhere else. */
export type MinorUnits = number;

export interface AuthenticatedUser {
  id: string;
  name: string;
  email: string;
  role: 'owner' | 'manager' | 'cashier';
}

export interface Account {
  id: string;
  name: string;
  currency: string;
  /**
   * For rendering only. Authorising against a module reads the database per request on the
   * server; this copy is stale the moment an admin changes it.
   */
  enabled_modules: string[];
}

export interface LoginResponse {
  token: string;
  user: AuthenticatedUser;
  account: Account;
}

export interface ContributingOrder {
  id: string;
  order_number: string | null;
  occurred_at: string;
  grand_total: MinorUnits;
}

/**
 * Negative stock, awaiting a human.
 *
 * Quantities are decimal strings rather than numbers: they come from a numeric(14,3) column
 * and JavaScript's number type would quietly round some of them. Nothing here does arithmetic
 * on them, so they stay strings all the way to the screen.
 */
export interface StockException {
  id: string;
  store_id: string;
  stockable_type: 'product_variant' | 'ingredient';
  stockable_id: string;
  stockable_name: string;
  unit: string;
  resulting_quantity: string;
  current_quantity: string | null;
  status: 'open' | 'resolved';
  contributing_orders: ContributingOrder[];
  devices: string[];
  resolved_by: string | null;
  resolution_note: string | null;
  resolved_at: string | null;
  created_at: string | null;
}
