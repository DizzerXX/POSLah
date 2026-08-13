import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/money.dart';

/// Money arithmetic, in integer minor units.
///
/// The plan singles this out as one of the two places this step is most likely to go wrong,
/// and it is right to: floating point money looks correct in every test that does not use
/// the input which exposes it, and then is wrong by a cent on a receipt someone is holding.
/// There is no double anywhere in `money.dart` and these tests are what keeps it that way.
void main() {
  group('formatting', () {
    test('renders minor units as currency', () {
      expect(Money.format(1250), 'RM 12.50');
      expect(Money.format(5), 'RM 0.05');
      expect(Money.format(0), 'RM 0.00');
      expect(Money.format(100000), 'RM 1,000.00');
      expect(Money.format(123456789), 'RM 1,234,567.89');
    });

    test('renders reversals as negative', () {
      expect(Money.format(-4770), '-RM 47.70');
    });
  });

  group('parsing cashier input', () {
    test('accepts the shapes a cashier actually types', () {
      expect(Money.parse('12.50'), 1250);
      expect(Money.parse('12.5'), 1250);
      expect(Money.parse('12'), 1200);
      expect(Money.parse('0.05'), 5);
      expect(Money.parse('.5'), 50);
      expect(Money.parse(' 1,000.00 '), 100000);
    });

    test('returns null rather than throwing on half-typed or bad input', () {
      // A text field is mid-edit most of the time. That is an ordinary state, not an error.
      expect(Money.parse(''), isNull);
      expect(Money.parse('.'), isNull);
      expect(Money.parse('abc'), isNull);
      expect(Money.parse('12.345'), isNull, reason: 'more precision than the currency has');
    });
  });

  group('five sen rounding', () {
    /// Malaysia withdrew 1 and 5 sen coins, so a cash total is rounded to the nearest 5 sen.
    /// Card and QR are not rounded, because no coins are involved.
    test('rounds to the nearest five sen, halves up', () {
      expect(CashRounding.toNearestFiveSen(1240), 1240);
      expect(CashRounding.toNearestFiveSen(1241), 1240);
      expect(CashRounding.toNearestFiveSen(1242), 1240);
      expect(CashRounding.toNearestFiveSen(1243), 1245);
      expect(CashRounding.toNearestFiveSen(1244), 1245);
      expect(CashRounding.toNearestFiveSen(1245), 1245);
      expect(CashRounding.toNearestFiveSen(1247), 1245);
      expect(CashRounding.toNearestFiveSen(1248), 1250);
    });

    test('reports the adjustment so a day still reconciles', () {
      expect(CashRounding.adjustmentFor(1242), -2);
      expect(CashRounding.adjustmentFor(1248), 2);
      expect(CashRounding.adjustmentFor(1245), 0);
    });
  });

  group('change due', () {
    test('computes change on an exact tender', () {
      final tender = CashRounding.tender(grandTotal: 1250, tendered: 1250);

      expect(tender.due, 1250);
      expect(tender.change, 0);
      expect(tender.isSufficient, isTrue);
    });

    test('computes change on an overpayment', () {
      final tender = CashRounding.tender(grandTotal: 4770, tendered: 10000);

      expect(tender.due, 4770);
      expect(tender.change, 5230);
    });

    /// The awkward case the plan names: an amount that does not land on a 5 sen boundary.
    test('rounds the amount due before computing change', () {
      // RM 47.72 becomes RM 47.70 in cash, so RM 50 leaves RM 2.30 change — not RM 2.28,
      // which is not payable in coins that exist.
      final tender = CashRounding.tender(grandTotal: 4772, tendered: 5000);

      expect(tender.due, 4770);
      expect(tender.change, 230);
    });

    test('rounds up when the total is nearer the higher five sen', () {
      final tender = CashRounding.tender(grandTotal: 4773, tendered: 5000);

      expect(tender.due, 4775);
      expect(tender.change, 225);
    });

    test('an underpayment yields no change and is refused', () {
      final tender = CashRounding.tender(grandTotal: 4770, tendered: 4000);

      expect(tender.isSufficient, isFalse);
      // Never negative: change is what the till hands back, and it cannot hand back less
      // than nothing. Expressing a shortfall as negative change reads as the customer owing
      // the drawer money.
      expect(tender.change, 0);
    });

    test('a five sen sale is payable exactly', () {
      final tender = CashRounding.tender(grandTotal: 5, tendered: 5);

      expect(tender.due, 5);
      expect(tender.change, 0);
    });
  });
}
