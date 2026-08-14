<?php

namespace App\Events;

use App\Enums\FulfillmentType;
use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A ticket for the kitchen.
 *
 * **Routed lines only.** A kitchen screen has no business displaying a bag of coffee beans
 * somebody bought alongside their latte, and filtering at the payload rather than at the
 * screen means the mixed-order case is correct from the start — even though v1's till cannot
 * yet create one.
 */
class OrderSentToKitchen implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Order $order) {}

    /** @return array<int, Channel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("store.{$this->order->store_id}.kitchen")];
    }

    public function broadcastAs(): string
    {
        return 'order.sent';
    }

    public function broadcastWith(): array
    {
        $lines = $this->order->lines
            ->filter(fn ($line) => $line->fulfillment_type === FulfillmentType::Routed)
            ->values();

        return [
            'id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'status' => $this->order->status->value,
            // The client clock, and correct to use here: a ticket's elapsed time is measured
            // from when the customer ordered, not from when the server heard about it. An
            // order synced late shows a large number, which is informative rather than wrong.
            'placed_at' => $this->order->occurred_at?->toIso8601String(),
            'lines' => $lines->map(fn ($line) => [
                'id' => $line->id,
                'name' => $line->name_snapshot,
                'quantity' => $line->quantity,
                'status' => $line->fulfillment_status->value,
                'modifiers' => $line->modifiers->map(fn ($modifier) => $modifier->name_snapshot)->all(),
            ])->all(),
        ];
    }
}
