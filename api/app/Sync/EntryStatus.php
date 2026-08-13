<?php

namespace App\Sync;

enum EntryStatus: string
{
    case Accepted = 'accepted';

    /**
     * The row was already here. This is a SUCCESS, not an error: the till marks the outbox
     * entry synced and moves on. It is the whole reason client-generated IDs exist — after
     * a timeout the till simply resends rather than asking whether the first attempt landed.
     */
    case Duplicate = 'duplicate';

    /**
     * A bug to fix, not a transient failure to wait out. The till must not retry these.
     */
    case Rejected = 'rejected';
}
