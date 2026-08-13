<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Envelope validation only.
 *
 * This is deliberately the thinnest possible set of rules. Anything validated here fails
 * the *whole batch* with a 422, and a batch-level failure is the exact outcome per-entry
 * results exist to avoid — a till would resend, fail identically, and never drain again.
 *
 * So: only the things that make a batch uninterpretable are checked here. A malformed
 * entity_type, a negative quantity, money as a float — all of those are per-entry
 * rejections handled downstream, where forty-nine good sales can still land alongside them.
 */
class SyncPushRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'batch_id' => ['required', 'uuid'],
            'device_id' => ['required', 'uuid'],
            // The plan's batch ceiling. A till sending more than this has a client bug,
            // not a bad sale.
            'entries' => ['present', 'array', 'max:50'],
            // String, not uuid: an unparseable id still deserves a per-entry result rather
            // than taking the batch down with it.
            'entries.*.id' => ['required', 'string'],
            'entries.*.entity_type' => ['present'],
            'entries.*.entity_id' => ['present'],
            'entries.*.operation' => ['present'],
            'entries.*.payload' => ['present', 'array'],
        ];
    }
}
