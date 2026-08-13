<?php

namespace App\Enums;

enum UserRole: string
{
    case Owner = 'owner';
    case Manager = 'manager';
    case Cashier = 'cashier';

    /** Voids and refunds require this, verified server-side and not merely prompted for. */
    public function canAuthorizeReversal(): bool
    {
        return $this === self::Owner || $this === self::Manager;
    }
}
