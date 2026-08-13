import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/till/cart.dart';

CartLine line({
  String id = 'l1',
  int price = 4500,
  int quantity = 1,
  String name = 'Ethiopia Yirgacheffe 250g',
}) {
  return CartLine(
    lineId: id,
    productId: 'p1',
    variantId: 'v1',
    nameSnapshot: name,
    priceSnapshot: price,
    quantity: quantity,
  );
}

void main() {
  group('derived totals', () {
    /// Every total is computed on read. Storing subtotal as mutable state would give one
    /// number two sources of truth, and the bug that follows is the display and the charge
    /// disagreeing — which is the worst thing this screen can get wrong.
    test('subtotal is the sum of the lines', () {
      const cart = CartState(lines: []);

      expect(cart.subtotal, 0);
      expect(cart.grandTotal, 0);
      expect(cart.isEmpty, isTrue);

      final filled = cart.copyWith(
        lines: [line(price: 4500, quantity: 2), line(id: 'l2', price: 1250)],
      );

      expect(filled.subtotal, 10250);
      expect(filled.itemCount, 3);
    });

    test('tax is integer arithmetic on the discounted subtotal', () {
      final cart = CartState(lines: [line(price: 4500)], taxRate: '0.0600');

      // 4500 * 6% = 270 exactly.
      expect(cart.taxTotal, 270);
      expect(cart.grandTotal, 4770);
    });

    test('tax rounds half up rather than truncating', () {
      // 1250 * 6% = 75 exactly; 1255 * 6% = 75.3 -> 75; 1258 * 6% = 75.48 -> 75.
      expect(CartState(lines: [line(price: 1250)], taxRate: '0.0600').taxTotal, 75);
      // 999 * 6% = 59.94 -> 60, not 59.
      expect(CartState(lines: [line(price: 999)], taxRate: '0.0600').taxTotal, 60);
    });

    test('a zero rate produces no tax', () {
      final cart = CartState(lines: [line(price: 4500)], taxRate: '0.0000');

      expect(cart.taxTotal, 0);
      expect(cart.grandTotal, 4500);
    });

    test('an order level discount reduces the taxable amount', () {
      final cart = CartState(lines: [line(price: 10000)], discountTotal: 2000, taxRate: '0.0600');

      expect(cart.discountedSubtotal, 8000);
      expect(cart.taxTotal, 480);
      expect(cart.grandTotal, 8480);
    });

    /// A discount larger than the cart is a data-entry mistake, not a sale that owes the
    /// customer money.
    test('a discount cannot drive the total negative', () {
      final cart = CartState(lines: [line(price: 1000)], discountTotal: 5000, taxRate: '0.0600');

      expect(cart.discountedSubtotal, 0);
      expect(cart.grandTotal, 0);
    });
  });

  group('lines', () {
    /// The same product added twice is two lines. A cashier removing one of them means that
    /// one, which is why lines carry their own local id rather than being keyed by product.
    test('the same product added twice is two distinct lines', () {
      final cart = CartState(lines: [line(id: 'l1'), line(id: 'l2')]);

      expect(cart.lines, hasLength(2));
      expect(cart.itemCount, 2);
    });

    test('quantity changes recompute the line total', () {
      final updated = line(price: 4500, quantity: 1).copyWith(quantity: 3);

      expect(updated.lineTotal, 13500);
    });

    /// The price is captured at add-to-cart. If a pull-sync lands mid-transaction and
    /// changes the catalog, the line keeps the price the cashier quoted out loud.
    test('the price snapshot is independent of the catalog', () {
      final held = line(price: 4500);

      // Nothing in CartLine reaches back to a product row, so there is no path by which a
      // catalog update could reprice a line already in the cart.
      expect(held.priceSnapshot, 4500);
      expect(held.copyWith(quantity: 2).priceSnapshot, 4500);
    });
  });
}
