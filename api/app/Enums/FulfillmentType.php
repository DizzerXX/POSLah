<?php

namespace App\Enums;

/**
 * The hinge of the whole design, and the only vertical branch that is allowed to exist:
 * per line item, never per store or per account.
 */
enum FulfillmentType: string
{
    case Direct = 'direct';   // handed over at ring-up
    case Routed = 'routed';   // goes to the kitchen, moves through the KDS
}
