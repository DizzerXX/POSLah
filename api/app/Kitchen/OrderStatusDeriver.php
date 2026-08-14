<?php

namespace App\Kitchen;

use App\Enums\FulfillmentType;
use App\Enums\LineFulfillmentStatus;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Models\Order;

/**
 * An order's status is **derived from its lines**, never set from a UI action.
 *
 * Storing order status as an independently mutable field alongside line statuses creates two
 * sources of truth that will eventually disagree — the same class of bug as storing derived
 * cart totals in state. The stored column is a materialised copy of what this class computes,
 * kept for query performance and recomputed on every transition.
 */
class OrderStatusDeriver
{
    /** Recomputes, persists if it changed, and announces it. Returns the derived status. */
    public function apply(Order $order): OrderStatus
    {
        $order->loadMissing('lines');

        $derived = $this->derive($order);

        if ($derived === $order->status) {
            return $derived;
        }

        $order->forceFill(['status' => $derived])->save();

        OrderStatusChanged::dispatch($order);

        return $derived;
    }

    public function derive(Order $order): OrderStatus
    {
        // A void is a decision about the whole order, not something its lines can undo.
        if ($order->status === OrderStatus::Voided) {
            return OrderStatus::Voided;
        }

        $routed = $order->lines->filter(
            fn ($line) => $line->fulfillment_type === FulfillmentType::Routed,
        );

        // The collapse: with nothing routed there is no kitchen state to be in, so the order
        // never enters sent / preparing / ready. One machine, not two.
        if ($routed->isEmpty()) {
            return OrderStatus::Created;
        }

        if ($routed->every(fn ($line) => $line->fulfillment_status === LineFulfillmentStatus::Ready)) {
            return OrderStatus::Ready;
        }

        // Anything started but not finished is "preparing"; nothing started yet is "sent".
        $anyStarted = $routed->contains(
            fn ($line) => $line->fulfillment_status !== LineFulfillmentStatus::Pending,
        );

        return $anyStarted ? OrderStatus::Preparing : OrderStatus::Sent;
    }
}
