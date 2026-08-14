<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Events\OrderLineStatusChanged;
use App\Http\Controllers\Controller;
use App\Kitchen\OrderStatusDeriver;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * The kitchen display's HTTP surface.
 *
 * `activeOrders` is not a fallback that only runs when something breaks — it is the KDS's
 * **primary source of truth**, polled every 30 seconds, with the socket as an optimisation on
 * top. WebSocket delivery is not guaranteed, and a missed ticket is a customer waiting for
 * food nobody is cooking. A screen that only listens to a socket silently misses orders, and
 * it does so invisibly, because local development never drops a connection.
 */
class KitchenController extends Controller
{
    /** Every ticket with work outstanding. */
    public function activeOrders(Request $request): JsonResponse
    {
        $storeIds = $this->storeIds($request);

        $orders = Order::query()
            ->whereIn('store_id', $storeIds)
            // Ready tickets stay listed so a late-reconnecting screen still learns they were
            // finished; the client clears the card once it knows.
            ->whereIn('status', [OrderStatus::Sent, OrderStatus::Preparing, OrderStatus::Ready])
            ->with(['lines.modifiers'])
            ->orderBy('occurred_at')
            ->limit(100)
            ->get();

        return response()->json([
            'server_time' => now()->toIso8601String(),
            'data' => $orders->map(fn (Order $order) => $this->present($order))->values()->all(),
        ]);
    }

    /** Bump a line. */
    public function updateLine(Request $request, OrderLine $orderLine, OrderStatusDeriver $deriver): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(LineFulfillmentStatus::class)],
        ]);

        $order = $orderLine->order()->with('lines')->sole();

        abort_unless(
            in_array($order->store_id, $this->storeIds($request), strict: true),
            403,
            'That order belongs to another store.',
        );

        // A direct line was handed over at ring-up; there is nothing for a kitchen to bump.
        abort_if(
            $orderLine->fulfillment_type !== FulfillmentType::Routed,
            422,
            'Only routed lines move through the kitchen.',
        );

        $orderLine->forceFill(['fulfillment_status' => $validated['status']])->save();

        OrderLineStatusChanged::dispatch($orderLine, $order->store_id);

        // The order's status follows from its lines. It is never set here directly.
        $order->load('lines');
        $deriver->apply($order);

        return response()->json(['data' => $this->present($order->fresh(['lines.modifiers']))]);
    }

    /** @return list<string> */
    private function storeIds(Request $request): array
    {
        $actor = $request->user();

        if ($actor instanceof Device) {
            return [$actor->store_id];
        }

        /** @var User $actor */
        return DB::table('stores')->where('account_id', $actor->account_id)->pluck('id')->all();
    }

    /**
     * Routed lines only.
     *
     * The filter lives here rather than on the screen, so a mixed order is correct from the
     * first request — even though v1's till cannot yet create one.
     */
    private function present(Order $order): array
    {
        $routed = $order->lines->filter(
            fn ($line) => $line->fulfillment_type === FulfillmentType::Routed,
        );

        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            // Client clock on purpose: a ticket's age is measured from when the customer
            // ordered. An order that synced late shows a large number, which is true.
            'placed_at' => $order->occurred_at?->toIso8601String(),
            'lines' => $routed->map(fn ($line) => [
                'id' => $line->id,
                'name' => $line->name_snapshot,
                'quantity' => $line->quantity,
                'status' => $line->fulfillment_status->value,
                'modifiers' => $line->modifiers->map(fn ($m) => $m->name_snapshot)->values()->all(),
            ])->values()->all(),
        ];
    }
}
