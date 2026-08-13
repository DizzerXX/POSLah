<?php

namespace App\Enums;

enum StockExceptionStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
}
