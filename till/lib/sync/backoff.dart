/// Retry schedule for a failed push.
///
/// Deliberately coarse and capped. The point of backoff here is not to be clever about when
/// the network returns — a connectivity listener and the periodic timer handle that — it is
/// to stop a till hammering a server that is having a bad afternoon.
class Backoff {
  /// 2s, 4s, 8s, 30s, 2m, 10m, then 15m forever.
  static const _schedule = <Duration>[
    Duration(seconds: 2),
    Duration(seconds: 4),
    Duration(seconds: 8),
    Duration(seconds: 30),
    Duration(minutes: 2),
    Duration(minutes: 10),
  ];

  static const cap = Duration(minutes: 15);

  /// Attempts that have already failed, so 1 means "the first attempt failed".
  static Duration delayForAttempt(int attempts) {
    if (attempts <= 0) return Duration.zero;
    if (attempts > _schedule.length) return cap;

    return _schedule[attempts - 1];
  }

  /// After this many failures the till stops being quiet about it. A sale that never
  /// reaches the server and never says so is worse than a loud failure — the merchant
  /// closes their day on figures that are wrong and has no reason to suspect it.
  static const alertAfterAttempts = 10;
}
