import 'package:flutter_test/flutter_test.dart';
import 'package:poslah_till/data/local_database.dart';
import 'package:poslah_till/sync/outbox.dart';

import 'support/fake_server.dart';
import 'support/till_fixture.dart';

/// Scenario 3 from the sync plan: **kill the app mid-batch**.
///
/// This is the failure the outbox exists to survive, and it is invisible in ordinary use.
/// A batch is marked `in_flight` the moment it is handed to the network. If the process
/// dies in that window — force-quit, battery, iOS reclaiming memory — nothing else in the
/// system will ever move those rows again. They are not pending, so no drain picks them up;
/// they are not synced, so nothing is on the server; and no error was ever raised, so the
/// till shows a clean queue while several sales sit in limbo.
///
/// The recovery is deliberately blunt: on launch, everything `in_flight` goes back to
/// `pending` and is sent again. It does not, and must not, try to work out whether the batch
/// landed — that question is expensive, racy, and unnecessary, because client-generated ids
/// make a second arrival a duplicate rather than a second sale.
void main() {
  late LocalDatabase db;
  late FakeServer server;
  late TillFixture till;

  setUp(() {
    db = LocalDatabase.memory();
    server = FakeServer();
    till = TillFixture(db, server);
  });

  tearDown(() => till.close());

  test('entries stranded in flight are recovered on the next launch', () async {
    await enqueueSale(till.outbox, variantId: 'variant-1');

    // Hand the batch to the network, then die before the response.
    final batch = await till.outbox.nextBatch();
    await till.outbox.markInFlight(batch);

    expect(await till.outbox.countByStatus(OutboxStatus.inFlight), 3);
    expect(await till.outbox.nextBatch(), isEmpty, reason: 'in_flight entries are invisible to a drain');

    // Relaunch.
    final recovered = await till.engine.recoverOnLaunch();

    expect(recovered, 3);
    expect(await till.outbox.countByStatus(OutboxStatus.pending), 3);
    expect(await till.outbox.nextBatch(), hasLength(3));
  });

  test('resending after a crash produces duplicates, not a second sale', () async {
    await enqueueSale(till.outbox, variantId: 'variant-1');

    // The server commits the batch and then the connection dies, so the till never learns
    // that it worked. This is the genuinely dangerous case: the naive reaction is to assume
    // the sale did not land and to send it as a new one.
    server.loseResponse = true;

    final first = await till.engine.drain();

    expect(first.transientFailure, isNotNull);
    expect(server.storedEntities, hasLength(3), reason: 'the server did the work');

    // A crash here would leave them in_flight; a transient failure leaves them pending.
    // Either way the next launch resends exactly the same ids.
    await till.engine.recoverOnLaunch();

    server.loseResponse = false;
    final second = await till.engine.drain(now: DateTime.now().toUtc().add(const Duration(minutes: 20)));

    expect(second.duplicate, 3, reason: 'the server recognises its own ids');
    expect(second.accepted, 0);
    expect(second.synced, 3, reason: 'a duplicate is a success');

    // The decisive assertion: one sale on the server, not two.
    expect(server.storedEntities, hasLength(3));
    expect(await till.outbox.countByStatus(OutboxStatus.synced), 3);
    expect(await till.outbox.countByStatus(OutboxStatus.pending), 0);
  });

  test('a kill between two batches leaves the first synced and the second pending', () async {
    await enqueueSale(till.outbox, variantId: 'variant-1', orderNumber: 'T1-0001');

    // The first batch goes out cleanly.
    await till.engine.drain();
    expect(await till.outbox.countByStatus(OutboxStatus.synced), 3);

    // A second sale is rung up, handed to the network, and the app dies.
    await enqueueSale(till.outbox, variantId: 'variant-1', orderNumber: 'T1-0002');
    await till.outbox.markInFlight(await till.outbox.nextBatch());

    await till.engine.recoverOnLaunch();

    // Recovery touches only the stranded batch. Already-synced entries are left alone —
    // otherwise every launch would resend the till's entire history.
    expect(await till.outbox.countByStatus(OutboxStatus.synced), 3);
    expect(await till.outbox.countByStatus(OutboxStatus.pending), 3);
  });

  test('recovery on a clean launch changes nothing', () async {
    await enqueueSale(till.outbox, variantId: 'variant-1');

    expect(await till.engine.recoverOnLaunch(), 0);
    expect(await till.outbox.countByStatus(OutboxStatus.pending), 3);
  });
}
