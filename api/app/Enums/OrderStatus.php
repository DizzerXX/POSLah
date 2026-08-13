<?php

namespace App\Enums;

/**
 * Fulfilment state only — payment lives on PaymentStatus, because the two are orthogonal
 * axes. One machine for both verticals: it collapses (created -> paid) when no line is
 * routed rather than branching.
 *
 * Always derived from line status, never set directly from a UI action.
 */
enum OrderStatus: string
{
    case Created = 'created';
    case Sent = 'sent';
    case Preparing = 'preparing';
    case Ready = 'ready';
    case Voided = 'voided';
}
