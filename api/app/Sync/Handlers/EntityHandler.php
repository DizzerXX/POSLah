<?php

namespace App\Sync\Handlers;

use App\Enums\Module;
use App\Models\Account;
use App\Models\Device;
use App\Sync\EntryResult;
use App\Sync\PushEntry;
use App\Sync\RejectionReason;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Base for the per-entity push handlers.
 *
 * Two rules hold for every subclass:
 *
 *  - Writes are INSERT ... ON CONFLICT (id) DO NOTHING. The ID came from the till, so a
 *    resend cannot create a second row and needs no dedupe bookkeeping on either side.
 *  - recorded_at is stamped here, from the server clock, and occurred_at is taken from the
 *    payload untouched. The till's clock may be hours wrong; that is a display problem, not
 *    a financial one, precisely because these two are kept apart.
 */
abstract class EntityHandler
{
    /** @return array<string, mixed> */
    abstract protected function rules(): array;

    /**
     * @return bool true if a row was inserted, false if it was already present
     */
    abstract protected function persist(array $payload, Device $device, Carbon $recordedAt): bool;

    /**
     * Which vertical this particular entry belongs to, if any.
     *
     * Derived from the entry itself rather than from anything account-level, because that is
     * where the branch actually lives: a routed line is F&B, an ingredient movement is F&B,
     * and everything else is neither. Most entries return null and are simply not a
     * vertical's business.
     */
    protected function requiredModule(array $payload): ?Module
    {
        return null;
    }

    /**
     * Payload keys that must match the authenticated device's own tenancy.
     *
     * @return array<string, string> payload key => device attribute
     */
    protected function tenantKeys(): array
    {
        return ['account_id' => 'account_id', 'store_id' => 'store_id'];
    }

    public function handle(PushEntry $entry, Device $device): EntryResult
    {
        $validator = Validator::make($entry->payload, $this->rules());

        if ($validator->fails()) {
            return EntryResult::rejected(
                $entry->id,
                RejectionReason::Validation,
                $validator->errors()->first(),
            );
        }

        /** @var array<string, mixed> $payload */
        $payload = $validator->validated();

        // The payload's ID is the entity's identity; entity_id is the till's own index into
        // its local store. If they disagree, the outbox entry is malformed.
        if (($payload['id'] ?? null) !== $entry->entityId) {
            return EntryResult::rejected(
                $entry->id,
                RejectionReason::Validation,
                'entity_id does not match the payload id.',
            );
        }

        $module = $this->requiredModule($payload);

        if ($module !== null) {
            // Read from the accounts row, not the token: an admin disabling F&B at noon must
            // take effect on the next batch, not whenever this device's token expires.
            $account = Account::query()->find($device->account_id);

            if ($account === null || ! $account->hasModule($module)) {
                return EntryResult::rejected(
                    $entry->id,
                    RejectionReason::ModuleNotEnabled,
                    "This account does not have the {$module->value} module enabled.",
                );
            }
        }

        foreach ($this->tenantKeys() as $payloadKey => $deviceAttribute) {
            if (! array_key_exists($payloadKey, $payload)) {
                continue;
            }

            if ($payload[$payloadKey] !== $device->getAttribute($deviceAttribute)) {
                // Not a validation failure: the payload is well-formed, it simply is not
                // this device's to write.
                return EntryResult::rejected(
                    $entry->id,
                    RejectionReason::ForeignTenant,
                    "This device may not write rows for another {$payloadKey}.",
                );
            }
        }

        // Database failures are deliberately NOT caught here. A failed statement puts the
        // surrounding PostgreSQL transaction into an aborted state, and swallowing the
        // exception at this depth would let the caller commit a transaction the database
        // has already given up on — every later query on that connection then fails with
        // "current transaction is aborted" instead of the real error. The exception has to
        // escape far enough for the transaction to roll back; PushBatchProcessor catches it
        // on the outside and turns it into a per-entry rejection there.
        $inserted = $this->persist($payload, $device, Carbon::now());

        return $inserted
            ? EntryResult::accepted($entry->id)
            : EntryResult::duplicate($entry->id);
    }
}
