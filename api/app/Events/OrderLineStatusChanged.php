<?php

namespace App\Events;

use App\Models\OrderLine;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** One line was bumped. Goes to the kitchen and to the tills. */
class OrderLineStatusChanged implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public OrderLine $line, public string $storeId) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("store.{$this->storeId}.kitchen"),
            new PrivateChannel("store.{$this->storeId}.tills"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'line.status';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->line->id,
            'order_id' => $this->line->order_id,
            'status' => $this->line->fulfillment_status->value,
        ];
    }
}
