<?php

namespace App\Enums;

enum LineFulfillmentStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Fulfilled = 'fulfilled';
}
