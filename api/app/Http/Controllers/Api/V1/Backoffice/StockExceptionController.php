<?php

namespace App\Http\Controllers\Api\V1\Backoffice;

use App\Enums\StockableType;
use App\Enums\StockExceptionStatus;
use App\Enums\StockMovementReason;
use App\Http\Controllers\Controller;
use App\Models\StockException;
use App\Models\User;
use App\Stock\StockReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Negative stock, and the human who resolves it.
 *
 * This screen is the other half of "accept both, allow negative, flag". The sync engine
 * refuses to reject a sale that already happened, which means somebody has to be told — and
 * being told is worth nothing without somewhere to act on it. This is that somewhere.
 */
class StockExceptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['nullable', Rule::enum(StockExceptionStatus::class)],
        ]);

        /** @var User $user */
        $user = $request->user();

        $exceptions = StockException::query()
            ->whereIn('store_id', $this->storeIds($user))
            ->when(
                $request->filled('status'),
                fn ($query) => $query->where('status', $request->string('status')),
            )
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data' => $exceptions->map(fn (StockException $exception) => $this->present($exception))->all(),
        ]);
    }

    /**
     * Resolve by stocktake.
     *
     * The merchant counts what is actually on the shelf, and the difference is recorded as a
     * `manual_adjustment` movement — an append, exactly like every other stock fact. Nothing
     * here edits history: the sales that drove the level negative stay where they are, and
     * the correction sits alongside them with its own provenance.
     */
    public function resolve(Request $request, StockException $stockException, StockReconciler $reconciler): JsonResponse
    {
        $validated = $request->validate([
            // Signed: a stocktake may legitimately find less than nothing if goods are owed.
            'counted_quantity' => ['required', 'numeric'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var User $user */
        $user = $request->user();

        abort_unless(
            in_array($stockException->store_id, $this->storeIds($user), strict: true),
            403,
            'That exception belongs to another account.',
        );

        abort_if(
            $stockException->status === StockExceptionStatus::Resolved,
            422,
            'That exception has already been resolved.',
        );

        $now = Carbon::now();

        $level = DB::table('stock_levels')
            ->where('store_id', $stockException->store_id)
            ->where('stockable_type', $stockException->stockable_type)
            ->where('stockable_id', $stockException->stockable_id)
            ->value('quantity_on_hand') ?? '0';

        $delta = bcsub((string) $validated['counted_quantity'], (string) $level, 3);

        DB::transaction(function () use ($stockException, $delta, $user, $validated, $now) {
            DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid7(),
                'store_id' => $stockException->store_id,
                'stockable_type' => $stockException->stockable_type,
                'stockable_id' => $stockException->stockable_id,
                'delta' => $delta,
                'reason' => StockMovementReason::ManualAdjustment->value,
                'created_by_user_id' => $user->id,
                'note' => $validated['note'] ?? 'Stocktake',
                // A stocktake happens in the shop and is recorded at the same moment, so
                // both clocks agree here — unlike a sale, which happens long before it syncs.
                'occurred_at' => $now,
                'recorded_at' => $now,
                'created_at' => $now,
            ]);

            $stockException->update([
                'status' => StockExceptionStatus::Resolved,
                'resolved_by_user_id' => $user->id,
                'resolution_note' => $validated['note'] ?? null,
                'resolved_at' => $now,
            ]);
        });

        // Recomputed from the movement log, like every other level. If the count itself was
        // negative this will raise a fresh exception, which is the correct outcome rather
        // than a special case.
        $reconciler->reconcile(
            $stockException->store_id,
            $stockException->stockable_type,
            $stockException->stockable_id,
        );

        return response()->json(['data' => $this->present($stockException->fresh())]);
    }

    /** @return list<string> */
    private function storeIds(User $user): array
    {
        return DB::table('stores')->where('account_id', $user->account_id)->pluck('id')->all();
    }

    private function present(StockException $exception): array
    {
        return [
            'id' => $exception->id,
            'store_id' => $exception->store_id,
            'stockable_type' => $exception->stockable_type,
            'stockable_id' => $exception->stockable_id,
            'stockable_name' => $this->stockableName($exception),
            'unit' => $this->unit($exception),
            'resulting_quantity' => $exception->resulting_quantity,
            'current_quantity' => DB::table('stock_levels')
                ->where('store_id', $exception->store_id)
                ->where('stockable_type', $exception->stockable_type)
                ->where('stockable_id', $exception->stockable_id)
                ->value('quantity_on_hand'),
            'status' => $exception->status->value,
            'contributing_orders' => $this->contributingOrders($exception),
            'devices' => $this->devices($exception),
            'resolved_by' => $exception->resolved_by_user_id === null
                ? null
                : DB::table('users')->where('id', $exception->resolved_by_user_id)->value('name'),
            'resolution_note' => $exception->resolution_note,
            'resolved_at' => $exception->resolved_at?->toIso8601String(),
            'created_at' => $exception->created_at?->toIso8601String(),
        ];
    }

    /**
     * Retail holds stock against a variant, F&B against an ingredient. The two verticals
     * diverge here and nowhere else in this screen.
     */
    private function stockableName(StockException $exception): string
    {
        if ($exception->stockable_type === StockableType::Ingredient->value) {
            return (string) DB::table('ingredients')->where('id', $exception->stockable_id)->value('name');
        }

        $variant = DB::table('product_variants')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->where('product_variants.id', $exception->stockable_id)
            ->selectRaw("products.name || ' — ' || product_variants.name as label")
            ->value('label');

        return (string) ($variant ?? 'Unknown item');
    }

    private function unit(StockException $exception): string
    {
        if ($exception->stockable_type !== StockableType::Ingredient->value) {
            return 'unit';
        }

        return (string) DB::table('ingredients')->where('id', $exception->stockable_id)->value('unit');
    }

    /**
     * Which sales landed while this was negative — a lead for the investigation, not an
     * exhaustive causal set. The exhaustive answer is the movement log.
     */
    private function contributingOrders(StockException $exception): array
    {
        $ids = $exception->contributing_order_ids ?? [];

        if ($ids === []) {
            return [];
        }

        return DB::table('orders')
            ->whereIn('id', $ids)
            ->select('id', 'order_number', 'occurred_at', 'grand_total')
            ->orderBy('occurred_at')
            ->get()
            ->map(fn ($order) => (array) $order)
            ->all();
    }

    /**
     * Which till did it. The reason the devices table exists at all.
     */
    private function devices(StockException $exception): array
    {
        return DB::table('stock_movements')
            ->join('devices', 'devices.id', '=', 'stock_movements.source_device_id')
            ->where('stock_movements.store_id', $exception->store_id)
            ->where('stock_movements.stockable_id', $exception->stockable_id)
            ->distinct()
            ->pluck('devices.device_name')
            ->all();
    }
}
