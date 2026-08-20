import { DraftLine, PosModifier, PosProduct, formatMoney, lineTotal, unitPrice } from './models';

function product(price: number, modifiers: PosModifier[] = []): PosProduct {
  return {
    id: 'p1',
    category_id: null,
    name: 'Cheeseburger',
    price,
    image_url: null,
    fulfillment_type: 'routed',
    tracks_stock: false,
    modifiers,
  };
}

function modifier(price_delta: number): PosModifier {
  return {
    id: `m${price_delta}`,
    product_id: 'p1',
    name: 'Extra Cheese',
    price_delta,
    consumes_ingredient_id: null,
    consumes_quantity: null,
  };
}

function line(price: number, quantity: number, modifiers: PosModifier[] = []): DraftLine {
  return { key: 'k1', product: product(price, modifiers), quantity, modifiers };
}

/**
 * Cart arithmetic on the web till.
 *
 * These are integer minor units throughout, and the reason to test them is that the till
 * *displays* these numbers while the server *charges* its own — so a divergence here is a
 * cashier quoting one price and the customer being charged another.
 */
describe('cart arithmetic', () => {
  it('adds modifier deltas into the unit price', () => {
    expect(unitPrice(line(2200, 1, [modifier(300)]))).toBe(2500);
  });

  it('multiplies the modified unit price by quantity', () => {
    expect(lineTotal(line(2200, 3, [modifier(300)]))).toBe(7500);
  });

  it('handles a line with no modifiers', () => {
    expect(unitPrice(line(1300, 1))).toBe(1300);
    expect(lineTotal(line(1300, 2))).toBe(2600);
  });

  /** A negative delta is legitimate — a smaller size costs less than the base item. */
  it('accepts a negative modifier delta', () => {
    expect(unitPrice(line(1300, 1, [modifier(-200)]))).toBe(1100);
  });

  it('sums several modifiers', () => {
    expect(unitPrice(line(2200, 1, [modifier(300), modifier(150)]))).toBe(2650);
  });
});

/**
 * Money never becomes a float on its way to the screen. Every one of these would look fine
 * with division and be wrong by a cent on some input.
 */
describe('money formatting', () => {
  it('renders minor units as currency', () => {
    expect(formatMoney(0)).toBe('$0.00');
    expect(formatMoney(5)).toBe('$0.05');
    expect(formatMoney(1250)).toBe('$12.50');
    expect(formatMoney(100000)).toBe('$1000.00');
  });

  it('renders reversals as negative', () => {
    expect(formatMoney(-4770)).toBe('-$47.70');
  });

  /** The classic: cents that round badly under floating point. */
  it('never loses a cent', () => {
    expect(formatMoney(1999)).toBe('$19.99');
    expect(formatMoney(2119)).toBe('$21.19');
    expect(formatMoney(3604)).toBe('$36.04');
  });
});
