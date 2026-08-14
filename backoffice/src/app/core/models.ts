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

/**
 * Shared by both verticals, and there is exactly one of these.
 *
 * If a RetailCustomer and an FnbCustomer ever appear beside each other, the architecture has
 * been lost — switching the backoffice to F&B filters inventory and reporting, not this.
 */
export interface Customer {
  id: string;
  name: string;
  phone: string | null;
  email: string | null;
  created_at: string | null;
}

/** F&B only. The stockable a menu item decrements; never sold on its own. */
export interface Ingredient {
  id: string;
  name: string;
  unit: string;
  low_stock_threshold: string;
  cost_per_unit: number | null;
  is_active: boolean;
}

/** Retail only. The stockable a SKU decrements. */
export interface Variant {
  id: string;
  name: string;
  sku: string | null;
  barcode: string | null;
  is_active: boolean;
}

export interface RecipeLine {
  id: string;
  product_id: string;
  ingredient_id: string;
  /** Decimal string in the ingredient's own unit. No unit conversion exists in v1. */
  quantity: string;
  ingredient_name: string;
  unit: string;
  cost_per_unit: number | null;
}

/** A menu item and its bill of materials. Flat: one level, no sub-recipes. */
export interface Recipe {
  id: string;
  name: string;
  price: MinorUnits;
  is_active: boolean;
  lines: RecipeLine[];
  /** Naive and indicative. Real COGS is out of scope for v1. */
  indicative_cost: MinorUnits;
}

export interface KitchenLine {
  id: string;
  name: string;
  quantity: number;
  status: 'pending' | 'ready' | 'fulfilled';
  modifiers: string[];
}

/**
 * A kitchen ticket. Routed lines only — the filter is applied server-side, so a mixed order
 * is correct here from the first request.
 */
export interface KitchenTicket {
  id: string;
  order_number: string | null;
  status: string;
  /** The client clock, deliberately: a ticket's age runs from when the customer ordered. */
  placed_at: string | null;
  lines: KitchenLine[];
}
