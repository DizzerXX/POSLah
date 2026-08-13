/// Money, in integer minor units, everywhere.
///
/// There is no `double` in this file and there must not be one anywhere downstream of it.
/// Floating point money looks correct in every test that does not use the input which
/// exposes it, and then is wrong by a cent in a shop, on a receipt a customer is holding.
///
/// The rule is cents in, cents out, and format only at the moment of render.
class Money {
  const Money._();

  /// "RM 12.50" from 1250.
  static String format(int minorUnits, {String symbol = 'RM ', int fractionDigits = 2}) {
    final negative = minorUnits < 0;
    final magnitude = minorUnits.abs();
    final divisor = _pow10(fractionDigits);

    final whole = magnitude ~/ divisor;
    final fraction = magnitude % divisor;

    final buffer = StringBuffer()
      ..write(negative ? '-' : '')
      ..write(symbol)
      ..write(_withThousands(whole));

    if (fractionDigits > 0) {
      buffer
        ..write('.')
        ..write(fraction.toString().padLeft(fractionDigits, '0'));
    }

    return buffer.toString();
  }

  /// Parses cashier input ("12.50", "12.5", "12") into minor units.
  ///
  /// Returns null rather than throwing: this is fed by a text field, so bad input is an
  /// ordinary state to be in halfway through typing, not an exception.
  static int? parse(String input, {int fractionDigits = 2}) {
    final trimmed = input.trim().replaceAll(',', '');
    if (trimmed.isEmpty) return null;

    final match = RegExp(r'^(-?)(\d*)(?:\.(\d*))?$').firstMatch(trimmed);
    if (match == null) return null;

    final whole = match.group(2) ?? '';
    final fraction = match.group(3) ?? '';

    if (whole.isEmpty && fraction.isEmpty) return null;
    if (fraction.length > fractionDigits) return null;

    final value = (whole.isEmpty ? 0 : int.parse(whole)) * _pow10(fractionDigits) +
        (fraction.isEmpty ? 0 : int.parse(fraction.padRight(fractionDigits, '0')));

    return match.group(1) == '-' ? -value : value;
  }

  static int _pow10(int exponent) {
    var result = 1;
    for (var i = 0; i < exponent; i++) {
      result *= 10;
    }

    return result;
  }

  static String _withThousands(int value) {
    final digits = value.toString();
    final buffer = StringBuffer();

    for (var i = 0; i < digits.length; i++) {
      if (i > 0 && (digits.length - i) % 3 == 0) buffer.write(',');
      buffer.write(digits[i]);
    }

    return buffer.toString();
  }
}

/// The result of taking cash.
class CashTender {
  const CashTender({required this.due, required this.tendered, required this.change});

  /// What the customer owes, after any rounding.
  final int due;
  final int tendered;

  /// Never negative: an underpayment is not change, it is a refusal.
  final int change;

  bool get isSufficient => tendered >= due;
}

/// Cash rounding and change.
///
/// Malaysia withdrew 1 and 5 sen coins from circulation, so a cash total is rounded to the
/// nearest 5 sen while the recorded sale is not. That distinction matters: the *ledger* must
/// record what the customer actually handed over and what they were actually charged, and
/// silently rounding the sale itself would put a receipt and a ledger entry a few sen apart.
///
/// Card and QR payments are not rounded — there are no coins involved.
class CashRounding {
  const CashRounding._();

  /// Rounds to the nearest 5 sen, halves away from zero.
  ///
  /// 1247 -> 1245, 1248 -> 1250, 1242 -> 1240, 1243 -> 1245.
  static int toNearestFiveSen(int minorUnits) {
    final negative = minorUnits < 0;
    final magnitude = minorUnits.abs();

    final remainder = magnitude % 5;
    final rounded = remainder >= 3 ? magnitude + (5 - remainder) : magnitude - remainder;

    return negative ? -rounded : rounded;
  }

  /// The adjustment applied to reach the rounded figure, positive when the customer pays
  /// more. Recorded so a day's takings still reconcile against the sum of order totals.
  static int adjustmentFor(int minorUnits) => toNearestFiveSen(minorUnits) - minorUnits;

  static CashTender tender({required int grandTotal, required int tendered}) {
    final due = toNearestFiveSen(grandTotal);

    return CashTender(
      due: due,
      tendered: tendered,
      // Clamped at zero: an underpayment is refused rather than expressed as negative
      // change, which would read as the customer owing the till money.
      change: tendered >= due ? tendered - due : 0,
    );
  }
}
