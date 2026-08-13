<?php

namespace App\Enums;

/**
 * A vertical an account may be entitled to. Read from accounts.enabled_modules in the
 * database on every request that authorises on it — never from a token claim.
 */
enum Module: string
{
    case Retail = 'retail';
    case Fnb = 'fnb';
}
