<?php

namespace App\Enums;

/** v1 records the method. It does not process payment. */
enum PaymentMethod: string
{
    case Cash = 'cash';
    case Card = 'card';
    case Qr = 'qr';
    case Other = 'other';
}
