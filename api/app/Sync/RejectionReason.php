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

    case ServerError = 'server_error';
}
