<?php

namespace App\Sync;

use App\Models\Device;
use App\Stock\StockReconciler;
use App\Sync\Handlers\CustomerHandler;
use App\Sync\Handlers\EntityHandler;
use App\Sync\Handlers\LedgerEntryHandler;
use App\Sync\Handlers\OrderHandler;
use App\Sync\Handlers\StockMovementHandler;
use App\Sync\Handlers\TimeEntryHandler;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drains one pushed batch.
 *
 * The single most important property of this class is that **each entry succeeds or fails
 * alone**. Every entry runs in its own transaction, so one malformed row rolls back only
 * itself and the other forty-nine sales still land. A batch-level transaction would let a
 * single poison entry wedge a till's queue permanently — the till would resend the batch,
 * the same entry would fail, and nothing behind it would ever sync again.
 */
class PushBatchProcessor
{
    public function __construct(private readonly StockReconciler $reconciler) {}

    /**
     * @param  list<array>  $rawEntries
     * @return list<EntryResult>
     */
    public function process(Device $device, array $rawEntries): array
    {
        $entries = $this->sortByDependency($rawEntries);

        $results = [];
        $affectedStockables = [];

        foreach ($entries as $entry) {
            $result = $this->processEntry($device, $entry);
            $results[$entry->position] = $result;

            if ($result->status === EntryStatus::Accepted && $entry->entityType === EntityType::StockMovement) {
                $affectedStockables[] = $entry->payload;
            }
        }

        $this->reconcileAffectedStock($affectedStockables);

        // Back into the order the till sent them, so a till can zip results against its own
        // outbox without matching on id.
        ksort($results);

        return array_values($results);
    }

    private function processEntry(Device $device, PushEntry $entry): EntryResult
    {
        if ($entry->entityType === null) {
            return EntryResult::rejected($entry->id, RejectionReason::UnknownEntityType);
        }

        // v1 has no delete: the ledger is append-only and catalog edits travel downward.
        if (! in_array($entry->operation, ['create', 'update'], strict: true)) {
            return EntryResult::rejected(
                $entry->id,
                RejectionReason::UnsupportedOperation,
                "Operation '{$entry->operation}' is not supported.",
            );
        }

        $handler = $this->handlerFor($entry->entityType);

        try {
            // One transaction per entry. Under a test's RefreshDatabase this nests as a
            // savepoint, which is the same guarantee at a smaller scope.
            return DB::transaction(fn () => $handler->handle($entry, $device));
        } catch (QueryException $exception) {
            // Caught out here, outside the transaction, so the rollback has already
            // happened and the connection is usable for the next entry. 23503 is a foreign
            // key violation: something this row points at never arrived — usually a
            // dependency that was itself rejected earlier in this same batch.
            if ($exception->getCode() === '23503') {
                return EntryResult::rejected(
                    $entry->id,
                    RejectionReason::MissingDependency,
                    'A referenced row is not present on the server.',
                );
            }

            Log::error('Sync entry failed', [
                'entry_id' => $entry->id,
                'device_id' => $device->id,
                'exception' => $exception->getMessage(),
            ]);

            return EntryResult::rejected($entry->id, RejectionReason::ServerError, $exception->getMessage());
        } catch (Throwable $exception) {
            // The entry is rejected, the batch keeps draining, and the till surfaces it in
            // its debug screen rather than retrying forever.
            Log::error('Sync entry failed', [
                'entry_id' => $entry->id,
                'device_id' => $device->id,
                'exception' => $exception->getMessage(),
            ]);

            return EntryResult::rejected(
                $entry->id,
                RejectionReason::ServerError,
                $exception->getMessage(),
            );
        }
    }

    /**
     * An order must land before the ledger entry and movements that point at it. The plan
     * offers dependency sorting or deferred FK checks and prefers the former: explicit
     * beats clever, and deferred checks would need the whole batch in one transaction,
     * which is precisely what per-entry results rule out.
     *
     * @param  list<array>  $rawEntries
     * @return list<PushEntry>
     */
    private function sortByDependency(array $rawEntries): array
    {
        $entries = [];

        foreach (array_values($rawEntries) as $position => $raw) {
            $entries[] = PushEntry::fromArray($raw, $position);
        }

        usort($entries, static function (PushEntry $a, PushEntry $b): int {
            $depth = ($a->entityType?->dependencyDepth() ?? PHP_INT_MAX)
                <=> ($b->entityType?->dependencyDepth() ?? PHP_INT_MAX);

            // Ties keep the till's own ordering, which is outbox created_at order.
            return $depth !== 0 ? $depth : $a->position <=> $b->position;
        });

        return $entries;
    }

    /**
     * Reconciliation runs once per stockable after the batch, not once per movement: a cart
     * with four lattes touches beans four times, and recomputing the level four times would
     * be three wasted scans for an identical answer.
     *
     * @param  list<array>  $movementPayloads
     */
    private function reconcileAffectedStock(array $movementPayloads): void
    {
        /** @var array<string, array{store: string, type: string, id: string, orders: list<string>}> $grouped */
        $grouped = [];

        foreach ($movementPayloads as $payload) {
            $key = implode('|', [$payload['store_id'], $payload['stockable_type'], $payload['stockable_id']]);

            $grouped[$key] ??= [
                'store' => $payload['store_id'],
                'type' => $payload['stockable_type'],
                'id' => $payload['stockable_id'],
                'orders' => [],
            ];

            if (! empty($payload['source_order_id'])) {
                $grouped[$key]['orders'][] = $payload['source_order_id'];
            }
        }

        foreach ($grouped as $stockable) {
            try {
                $this->reconciler->reconcile(
                    $stockable['store'],
                    $stockable['type'],
                    $stockable['id'],
                    $stockable['orders'],
                );
            } catch (Throwable $exception) {
                // A failed projection must not retroactively fail accepted sales. The
                // movements are already recorded, and the level is recomputed from them
                // whenever anything else touches this stockable.
                Log::error('Stock reconciliation failed', [
                    'stockable_id' => $stockable['id'],
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function handlerFor(EntityType $type): EntityHandler
    {
        return match ($type) {
            EntityType::Customer => app(CustomerHandler::class),
            EntityType::Order => app(OrderHandler::class),
            EntityType::LedgerEntry => app(LedgerEntryHandler::class),
            EntityType::StockMovement => app(StockMovementHandler::class),
            EntityType::TimeEntry => app(TimeEntryHandler::class),
        };
    }
}
