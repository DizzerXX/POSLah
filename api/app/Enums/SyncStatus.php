<?php

namespace App\Enums;

/**
 * The till's own view of whether a row reached the server, mirrored up for audit.
 */
enum SyncStatus: string
{
    case Pending = 'pending';
    case Synced = 'synced';
    case Conflicted = 'conflicted';
}
