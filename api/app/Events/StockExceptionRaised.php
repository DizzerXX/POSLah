<?php

namespace App\Events;

use App\Models\StockException;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Stock for some item went negative, and a merchant needs to know.
 *
 * A plain event for now. The sync plan wants this broadcast to the backoffice so the
 * exceptions view updates without a refresh, but Reverb arrives in step 6 — so this is the
 * seam that step will implement ShouldBroadcast on, rather than a broadcast wired up now
 * against a channel design that does not exist yet.
 */
class StockExceptionRaised
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public StockException $exception,
        /** False when an already-open exception was updated by a later batch. */
        public bool $isNew = true,
    ) {}
}
