<?php

namespace App\Sync;

enum RejectionReason: string
{
    case Validation = 'validation';
    case UnknownEntityType = 'unknown_entity_type';
    case UnsupportedOperation = 'unsupported_operation';

    /**
     * The entry claims to belong to an account or store that is not this device's. A till
     * is authenticated as a device, and a device belongs to exactly one store — so this is
     * either a serious client bug or someone probing with a stolen token.
     */
    case ForeignTenant = 'foreign_tenant';

    /** A referenced row (order, user, customer) is not present and was not in this batch. */
    case MissingDependency = 'missing_dependency';

    /**
     * A void or refund arrived without a manager or owner authorising it. The till prompts
     * for a PIN, but a rebuilt app can skip that prompt entirely — this is the check that
     * makes the requirement real.
     */
    case UnauthorizedReversal = 'unauthorized_reversal';

    /**
     * The entry belongs to a vertical this account is not entitled to — an F&B order
     * arriving from a retail-only account. Rejected on its own; the other entries in the
     * batch still land.
     */
    case ModuleNotEnabled = 'module_not_enabled';

    case ServerError = 'server_error';
}
