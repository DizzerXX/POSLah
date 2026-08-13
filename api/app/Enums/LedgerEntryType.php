<?php

namespace App\Enums;

enum LedgerEntryType: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case Void = 'void';

    /** Reversals carry a negative amount and point at the entry they reverse. */
    public function isReversal(): bool
    {
        return $this !== self::Sale;
    }
}
