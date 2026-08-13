<?php

namespace App\Enums;

enum StockMovementReason: string
{
    case Sale = 'sale';
    case Refund = 'refund';
    case ManualAdjustment = 'manual_adjustment';
    case Stocktake = 'stocktake';
    case Wastage = 'wastage';
}
