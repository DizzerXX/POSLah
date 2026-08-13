<?php

namespace App\Sync;

/**
 * The entity kinds a till may push. Catalog is absent on purpose: it flows downward only,
 * and the till never edits a product.
 *
 * Each type carries a dependency depth. The plan offers two ways to make an order land
 * before its ledger entry — sort by dependency, or lean on deferred FK checks inside one
 * transaction — and takes the first, because per-entry results mean entries are *not*
 * processed in one transaction, so there is nothing for deferred checks to defer to.
 */
enum EntityType: string
{
    case Customer = 'customer';
    case Order = 'order';
    case LedgerEntry = 'ledger_entry';
    case StockMovement = 'stock_movement';
    case TimeEntry = 'time_entry';

    public function dependencyDepth(): int
    {
        return match ($this) {
            // Referenced by orders, so it must land first.
            self::Customer, self::TimeEntry => 0,
            self::Order => 1,
            // Both point at an order.
            self::LedgerEntry, self::StockMovement => 2,
        };
    }
}
