<?php

namespace App\Http\Controllers\Api\V1\Pos;

use App\Enums\FulfillmentType;
use App\Enums\LedgerEntryType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\StockMovementReason;
use App\Events\OrderSentToKitchen;
use App\Http\Controllers\Controller;
use App\Kitchen\OrderStatusDeriver;
use App\Models\Order;
use App\Models\RestaurantTable;
use App\Models\User;
use App\Stock\StockReconciler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * The web till.
 *
 * **This one is online-only, and that is a real difference from the Flutter till rather than
 * an implementation detail.** The mobile till is local-first: it writes to SQLite and returns
 * to the cashier whether or not the network is up. A browser cannot honestly make that claim
 * without a second full outbox implementation in a second language, so this one writes
 * through and stops selling when the connection does. Stated plainly rather than papered over.
 *
 * One consequence worth naming: recipe expansion happens **here**, at the same instant the
 * order is placed. On the mobile till expansion is deliberately client-side, so a sale made
 * on Monday and synced on Wednesday still decrements Monday's recipe. There is no such window
 * here — the browser is holding the recipe it rendered a second ago — so expanding server-side
 * is equivalent rather than a shortcut.
 */
class PosController extends Controller
{
    /** The menu: routed products, their modifiers, and what they can be made from. */
    public function menu(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);

        $products = DB::table('products')
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'category_id', 'name', 'price', 'fulfillment_type', 'tracks_stock']);

        $categories = DB::table('categories')
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['id', 'name']);

        $modifiers = DB::table('modifiers')
            ->whereIn('product_id', $products->pluck('id'))
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'product_id', 'name', 'price_delta', 'consumes_ingredient_id', 'consumes_quantity']);

        return response()->json([
            'categories' => $categories->map(fn ($row) => (array) $row)->all(),
            'products' => $products->map(fn ($product) => [
                ...(array) $product,
                'modifiers' => $modifiers->where('product_id', $product->id)
                    ->map(fn ($row) => (array) $row)->values()->all(),
            ])->all(),
        ]);
    }

    /**
     * The floor plan.
     *
     * Availability is derived from unfinished orders rather than read from a status column,
     * so it cannot disagree with the orders themselves.
     */
    public function tables(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);

        $tables = RestaurantTable::query()
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->orderBy('label')
            ->get();

        $occupied = DB::table('orders')
            ->whereIn('restaurant_table_id', $tables->pluck('id'))
            ->whereIn('status', array_map(fn ($s) => $s->value, RestaurantTable::OCCUPYING_STATUSES))
            ->pluck('restaurant_table_id')
            ->all();

        return response()->json([
            'data' => $tables->map(fn (RestaurantTable $table) => [
                'id' => $table->id,
                'label' => $table->label,
                'seats' => $table->seats,
                'is_available' => ! in_array($table->id, $occupied, strict: true),
            ])->all(),
        ]);
    }

    /** Orders still on the pass, for the order-list rail. */
    public function activeOrders(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->whereIn('store_id', $this->storeIds($request))
            ->whereIn('status', array_map(fn ($s) => $s->value, RestaurantTable::OCCUPYING_STATUSES))
            ->with(['lines', 'table'])
            ->orderByDesc('occurred_at')
            ->limit(40)
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $o) => $this->present($o))->all()]);
    }

    /**
     * Places a sale.
     *
     * Written in one transaction for the same reason the mobile checkout is: a ledger entry
     * with no order, or an order with no stock movement, is the ugliest bug class available.
     */
    public function placeOrder(Request $request, StockReconciler $reconciler): JsonResponse
    {
        $accountId = $this->accountId($request);
        $storeIds = $this->storeIds($request);

        $validated = $request->validate([
            'store_id' => ['required', Rule::in($storeIds)],
            'restaurant_table_id' => ['nullable', 'uuid'],
            'service_type' => ['nullable', Rule::in(['dine_in', 'takeaway'])],
            'customer_note' => ['nullable', 'string', 'max:1000'],
            'payment_method' => ['required', Rule::enum(PaymentMethod::class)],
            // Minor units, like everywhere else. `integer`, not `numeric`: a decimal here
            // means somebody put floating point money on the wire.
            'tendered' => ['nullable', 'integer', 'min:0'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.modifier_ids' => ['sometimes', 'array'],
            'lines.*.modifier_ids.*' => ['uuid'],
        ]);

        /** @var User $user */
        $user = $request->user();

        $store = DB::table('stores')->where('id', $validated['store_id'])->first();
        $taxRate = (string) $store->tax_rate;

        $orderId = (string) Str::uuid7();
        $now = Carbon::now();

        $result = DB::transaction(function () use (
            $validated, $accountId, $user, $orderId, $now, $taxRate
        ) {
            $priced = $this->priceLines($validated['lines'], $accountId);

            $subtotal = array_sum(array_column($priced, 'line_total'));
            $tax = (int) round($subtotal * (float) $taxRate);
            $grandTotal = $subtotal + $tax;

            $routed = collect($priced)->contains(
                fn ($line) => $line['fulfillment_type'] === FulfillmentType::Routed->value,
            );

            Order::query()->create([
                'id' => $orderId,
                'account_id' => $accountId,
                'store_id' => $validated['store_id'],
                // No device: this till is a browser session, not a registered device. The
                // cashier is recorded instead, which is the honest provenance available.
                'device_id' => null,
                'cashier_user_id' => $user->id,
                'restaurant_table_id' => $validated['restaurant_table_id'] ?? null,
                'service_type' => $validated['service_type'] ?? null,
                'customer_note' => $validated['customer_note'] ?? null,
                'order_number' => $this->nextOrderNumber($validated['store_id']),
                // Derived from the lines, never chosen: with nothing routed the machine
                // collapses and the order never enters the kitchen states.
                'status' => $routed ? OrderStatus::Sent : OrderStatus::Created,
                'payment_status' => PaymentStatus::Paid,
                'subtotal' => $subtotal,
                'tax_total' => $tax,
                'discount_total' => 0,
                'grand_total' => $grandTotal,
                'tax_rate_snapshot' => $taxRate,
                'occurred_at' => $now,
                'recorded_at' => $now,
            ]);

            $movements = $this->writeLines($priced, $orderId, $validated['store_id'], $user, $now);

            DB::table('ledger_entries')->insert([
                'id' => (string) Str::uuid7(),
                'account_id' => $accountId,
                'store_id' => $validated['store_id'],
                'order_id' => $orderId,
                'entry_type' => LedgerEntryType::Sale->value,
                'amount' => $grandTotal,
                'payment_method' => $validated['payment_method'],
                'occurred_at' => $now,
                'recorded_at' => $now,
                'created_at' => $now,
            ]);

            return $movements;
        });

        // Reconciled after commit, and allowed to drive stock negative: the food has been
        // handed over, so a shortfall is an exception for a human rather than a refusal.
        foreach ($result as $stockable) {
            $reconciler->reconcile(
                $validated['store_id'],
                $stockable['type'],
                $stockable['id'],
                [$orderId],
            );
        }

        $order = Order::query()->with(['lines.modifiers', 'table'])->findOrFail($orderId);

        if ($order->status !== OrderStatus::Created) {
            try {
                app(OrderStatusDeriver::class)->apply($order);
                OrderSentToKitchen::dispatch($order->fresh(['lines.modifiers']));
            } catch (Throwable) {
                // A broadcast failure must never fail a sale. The kitchen polls regardless.
            }
        }

        return response()->json(['data' => $this->present($order)], 201);
    }

    /** Past transactions. */
    public function history(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->whereIn('store_id', $this->storeIds($request))
            ->with(['lines', 'table', 'cashier'])
            // Financial ordering uses the server clock, because the client's is untrusted.
            ->orderByDesc('recorded_at')
            ->limit(200)
            ->get();

        return response()->json(['data' => $orders->map(fn (Order $o) => $this->present($o))->all()]);
    }

    /** Raw materials and how much is left. */
    public function supply(Request $request): JsonResponse
    {
        $accountId = $this->accountId($request);
        $storeIds = $this->storeIds($request);

        $ingredients = DB::table('ingredients')
            ->where('account_id', $accountId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'unit', 'low_stock_threshold']);

        $levels = DB::table('stock_levels')
            ->whereIn('store_id', $storeIds)
            ->where('stockable_type', 'ingredient')
            ->pluck('quantity_on_hand', 'stockable_id');

        return response()->json([
            'data' => $ingredients->map(function ($ingredient) use ($levels) {
                $onHand = (float) ($levels[$ingredient->id] ?? 0);
                $threshold = (float) $ingredient->low_stock_threshold;

                return [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'unit' => $ingredient->unit,
                    'quantity' => $levels[$ingredient->id] ?? '0.000',
                    // Three states, matching the design. Negative is a legitimate value here
                    // and reads as out of stock rather than being clamped away.
                    'state' => match (true) {
                        $onHand <= 0 => 'out_of_stock',
                        $onHand <= $threshold => 'running_low',
                        default => 'safe',
                    },
                ];
            })->all(),
        ]);
    }

    // ---- internals --------------------------------------------------------

    private function priceLines(array $lines, string $accountId): array
    {
        $productIds = array_column($lines, 'product_id');

        $products = DB::table('products')
            ->where('account_id', $accountId)
            ->whereIn('id', $productIds)
            ->get()
            ->keyBy('id');

        abort_if($products->count() !== count(array_unique($productIds)), 422, 'Unknown product.');

        $priced = [];

        foreach ($lines as $line) {
            $product = $products[$line['product_id']];

            $modifiers = DB::table('modifiers')
                ->where('product_id', $product->id)
                ->whereIn('id', $line['modifier_ids'] ?? [])
                ->get();

            $unitPrice = (int) $product->price + (int) $modifiers->sum('price_delta');

            $priced[] = [
                'product_id' => $product->id,
                'name_snapshot' => $product->name,
                'price_snapshot' => $unitPrice,
                'fulfillment_type' => $product->fulfillment_type,
                'tracks_stock' => (bool) $product->tracks_stock,
                'quantity' => $line['quantity'],
                'line_total' => $unitPrice * $line['quantity'],
                'modifiers' => $modifiers,
            ];
        }

        return $priced;
    }

    /** Writes lines and expands each into the stock it consumes. */
    private function writeLines(array $priced, string $orderId, string $storeId, User $user, Carbon $now): array
    {
        $affected = [];

        foreach ($priced as $line) {
            $lineId = (string) Str::uuid7();

            DB::table('order_lines')->insert([
                'id' => $lineId,
                'order_id' => $orderId,
                'product_id' => $line['product_id'],
                'product_variant_id' => null,
                // Snapshots, so a rename or a reprice never rewrites this receipt.
                'name_snapshot' => $line['name_snapshot'],
                'price_snapshot' => $line['price_snapshot'],
                'fulfillment_type' => $line['fulfillment_type'],
                'quantity' => $line['quantity'],
                'line_total' => $line['line_total'],
                'fulfillment_status' => $line['fulfillment_type'] === FulfillmentType::Routed->value
                    ? LineFulfillmentStatus::Pending->value
                    : LineFulfillmentStatus::Fulfilled->value,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($line['modifiers'] as $modifier) {
                DB::table('order_line_modifiers')->insert([
                    'id' => (string) Str::uuid7(),
                    'order_line_id' => $lineId,
                    'modifier_id' => $modifier->id,
                    'name_snapshot' => $modifier->name,
                    'price_delta_snapshot' => $modifier->price_delta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($this->expand($line, $lineId, $orderId, $storeId, $user, $now) as $stockable) {
                $affected[$stockable['type'].$stockable['id']] = $stockable;
            }
        }

        return array_values($affected);
    }

    /**
     * One movement per recipe ingredient, plus one per consuming modifier — and none at all
     * for something that tracks no stock, which is a legitimate outcome rather than a
     * missing recipe.
     */
    private function expand(array $line, string $lineId, string $orderId, string $storeId, User $user, Carbon $now): array
    {
        $recipe = DB::table('recipe_lines')->where('product_id', $line['product_id'])->get();

        $planned = [];

        foreach ($recipe as $component) {
            $planned[] = [
                'id' => $component->ingredient_id,
                'quantity' => bcmul((string) $component->quantity, (string) $line['quantity'], 3),
            ];
        }

        foreach ($line['modifiers'] as $modifier) {
            if ($modifier->consumes_ingredient_id === null) {
                continue;
            }

            $planned[] = [
                'id' => $modifier->consumes_ingredient_id,
                'quantity' => bcmul((string) ($modifier->consumes_quantity ?? '1.000'), (string) $line['quantity'], 3),
            ];
        }

        $affected = [];

        foreach ($planned as $movement) {
            DB::table('stock_movements')->insert([
                'id' => (string) Str::uuid7(),
                'store_id' => $storeId,
                'stockable_type' => 'ingredient',
                'stockable_id' => $movement['id'],
                'delta' => '-'.$movement['quantity'],
                'reason' => StockMovementReason::Sale->value,
                'source_order_id' => $orderId,
                'source_order_line_id' => $lineId,
                'created_by_user_id' => $user->id,
                'occurred_at' => $now,
                'recorded_at' => $now,
                'created_at' => $now,
            ]);

            $affected[] = ['type' => 'ingredient', 'id' => $movement['id']];
        }

        return $affected;
    }

    /**
     * Web tills share one server-side series per store, prefixed so they can never collide
     * with a mobile till's locally generated numbers.
     */
    private function nextOrderNumber(string $storeId): string
    {
        $count = DB::table('orders')
            ->where('store_id', $storeId)
            ->where('order_number', 'like', 'W-%')
            ->count();

        return sprintf('W-%04d', $count + 1);
    }

    private function present(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'service_type' => $order->service_type,
            'customer_note' => $order->customer_note,
            'table' => $order->table?->label,
            'subtotal' => $order->subtotal,
            'tax_total' => $order->tax_total,
            'grand_total' => $order->grand_total,
            'occurred_at' => $order->occurred_at?->toIso8601String(),
            'lines' => $order->lines->map(fn ($line) => [
                'id' => $line->id,
                'name' => $line->name_snapshot,
                'quantity' => $line->quantity,
                'price' => $line->price_snapshot,
                'line_total' => $line->line_total,
                'status' => $line->fulfillment_status->value,
                'fulfillment_type' => $line->fulfillment_type->value,
            ])->all(),
        ];
    }

    private function accountId(Request $request): string
    {
        /** @var User $user */
        $user = $request->user();

        return $user->account_id;
    }

    /** @return list<string> */
    private function storeIds(Request $request): array
    {
        return DB::table('stores')->where('account_id', $this->accountId($request))->pluck('id')->all();
    }
}
