<?php

namespace App\Stock;

use App\Enums\StockExceptionStatus;
use App\Events\StockExceptionRaised;
use App\Models\StockException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Where "accept both, allow negative, flag" is actually implemented.
 *
 * The contract, in order: movements are already in the table by the time this runs; the
 * level is recomputed from them; a negative result raises an exception row. Nothing here
 * can reject anything. There is no return path by which this class could tell the till its
 * sale was refused, and that absence is the design.
 */
class StockReconciler
{
    /**
     * Recompute one stockable's level in one store, and flag it if it landed below zero.
     *
     * @param  list<string>  $contributingOrderIds  orders from the batch that touched this stockable
     */
    public function reconcile(
        string $storeId,
        string $stockableType,
        string $stockableId,
        array $contributingOrderIds = [],
    ): string {
        [$quantity, $exception, $isNew] = DB::transaction(
            fn () => $this->project($storeId, $stockableType, $stockableId, $contributingOrderIds),
        );

        // Dispatched after commit, never inside the transaction. A listener that throws
        // must not roll back the projection it was told about — the level and the exception
        // row are the durable facts, and notifying the backoffice is a consequence of them.
        if ($exception !== null) {
            StockExceptionRaised::dispatch($exception, $isNew);
        }

        return $quantity;
    }

    /**
     * @return array{0: string, 1: StockException|null, 2: bool}
     */
    private function project(
        string $storeId,
        string $stockableType,
        string $stockableId,
        array $contributingOrderIds,
    ): array {
        $now = Carbon::now();

        // Ensure the row exists before locking it. insertOrIgnore rather than firstOrCreate
        // because two devices can reconcile the same stockable concurrently, and the unique
        // index is the arbiter.
        DB::table('stock_levels')->insertOrIgnore([[
            'id' => (string) Str::uuid7(),
            'store_id' => $storeId,
            'stockable_type' => $stockableType,
            'stockable_id' => $stockableId,
            'quantity_on_hand' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]]);

        // The row lock serialises concurrent reconciliation of the same stockable. It is
        // emphatically not a reservation: the offline sale path never reaches this code,
        // which is exactly why stock can already be negative by the time we get here.
        $level = DB::table('stock_levels')
            ->where('store_id', $storeId)
            ->where('stockable_type', $stockableType)
            ->where('stockable_id', $stockableId)
            ->lockForUpdate()
            ->first();

        // A full SUM over the movements, not an incremental += delta. Recomputing from the
        // append-only log makes the projection self-healing: a resent batch, a movement that
        // arrived by some other path, or a level someone corrected by hand all converge on
        // the same answer. It costs a scan of one stockable's movements, which is the trade
        // the plan chose — and it is what makes a duplicate push incapable of double
        // decrementing, rather than that safety resting on duplicate detection being perfect.
        $quantity = DB::table('stock_movements')
            ->where('store_id', $storeId)
            ->where('stockable_type', $stockableType)
            ->where('stockable_id', $stockableId)
            ->sum('delta');

        $quantity = number_format((float) $quantity, 3, '.', '');

        DB::table('stock_levels')
            ->where('id', $level->id)
            ->update(['quantity_on_hand' => $quantity, 'updated_at' => $now]);

        if (bccomp($quantity, '0', 3) !== -1) {
            return [$quantity, null, false];
        }

        [$exception, $isNew] = $this->flag($storeId, $stockableType, $stockableId, $quantity, $contributingOrderIds);

        return [$quantity, $exception, $isNew];
    }

    /**
     * One open exception per stockable per store, enforced by a partial unique index.
     * Later batches landing on an already-negative stockable append their orders to the
     * existing row rather than producing a wall of duplicates.
     *
     * @return array{0: StockException, 1: bool}
     */
    private function flag(
        string $storeId,
        string $stockableType,
        string $stockableId,
        string $quantity,
        array $contributingOrderIds,
    ): array {
        $exception = StockException::query()
            ->where('store_id', $storeId)
            ->where('stockable_type', $stockableType)
            ->where('stockable_id', $stockableId)
            ->where('status', StockExceptionStatus::Open)
            ->lockForUpdate()
            ->first();

        if ($exception === null) {
            return [
                StockException::query()->create([
                    'store_id' => $storeId,
                    'stockable_type' => $stockableType,
                    'stockable_id' => $stockableId,
                    'resulting_quantity' => $quantity,
                    'contributing_order_ids' => array_values(array_unique($contributingOrderIds)),
                    'status' => StockExceptionStatus::Open,
                ]),
                true,
            ];
        }

        $exception->update([
            'resulting_quantity' => $quantity,
            'contributing_order_ids' => array_values(array_unique(
                [...$exception->contributing_order_ids, ...$contributingOrderIds],
            )),
        ]);

        return [$exception, false];
    }
}
