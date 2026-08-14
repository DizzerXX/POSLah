/** Money is integer minor units on the wire, as everywhere else in this project. */
export type MinorUnits = number;

export interface PosModifier {
  id: string;
  product_id: string;
  name: string;
  price_delta: MinorUnits;
  consumes_ingredient_id: string | null;
  consumes_quantity: string | null;
}

export interface PosProduct {
  id: string;
  category_id: string | null;
  name: string;
  price: MinorUnits;
  /** Often null. The grid renders a typographic tile rather than a broken image. */
  image_url: string | null;
  fulfillment_type: 'direct' | 'routed';
  tracks_stock: boolean;
  modifiers: PosModifier[];
}

export interface PosCategory {
  id: string;
  name: string;
}

export interface PosTable {
  id: string;
  label: string;
  seats: number;
  /** Derived server-side from unfinished orders — never a stored status. */
  is_available: boolean;
}

export interface PosOrderLine {
  id: string;
  name: string;
  quantity: number;
  price: MinorUnits;
  line_total: MinorUnits;
  status: 'pending' | 'ready' | 'fulfilled';
  fulfillment_type: 'direct' | 'routed';
}

export interface PosOrder {
  id: string;
  order_number: string | null;
  status: string;
  payment_status: string;
  service_type: 'dine_in' | 'takeaway' | null;
  customer_note: string | null;
  table: string | null;
  subtotal: MinorUnits;
  tax_total: MinorUnits;
  grand_total: MinorUnits;
  occurred_at: string | null;
  lines: PosOrderLine[];
}

export interface SupplyItem {
  id: string;
  name: string;
  unit: string;
  quantity: string;
  state: 'safe' | 'running_low' | 'out_of_stock';
}

/** A line being built at the counter, before it becomes an order. */
export interface DraftLine {
  key: string;
  product: PosProduct;
  quantity: number;
  modifiers: PosModifier[];
}

/**
 * Derived on read, never stored. Two sources of truth for one number is how the display and
 * the charge end up disagreeing.
 */
export function unitPrice(line: DraftLine): MinorUnits {
  return line.product.price + line.modifiers.reduce((sum, m) => sum + m.price_delta, 0);
}

export function lineTotal(line: DraftLine): MinorUnits {
  return unitPrice(line) * line.quantity;
}

export function formatMoney(minorUnits: MinorUnits): string {
  const negative = minorUnits < 0;
  const abs = Math.abs(minorUnits);

  return `${negative ? '-' : ''}$${Math.floor(abs / 100)}.${String(abs % 100).padStart(2, '0')}`;
}
