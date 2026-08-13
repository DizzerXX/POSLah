<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Client-generated UUIDv7 primary keys.
 *
 * The database never mints an ID — no bigserial, no gen_random_uuid() default. A till that
 * is offline must be able to create an order, its lines, its ledger entry and its stock
 * movements with no coordination whatsoever, and the ID it chooses doubles as the sync
 * idempotency key: every server-side insert is ON CONFLICT (id) DO NOTHING, so resending a
 * batch after a timeout is inherently safe and the till never has to ask whether the first
 * attempt landed.
 *
 * v7 rather than v4 because it is time-sortable, which makes ledger ordering free and keeps
 * B-tree inserts local rather than scattering them across the index.
 *
 * Server-side creation (seeders, the backoffice) generates the key here rather than in the
 * database. That is the same rule, not an exception to it.
 */
trait HasUuidV7Key
{
    public static function bootHasUuidV7Key(): void
    {
        static::creating(function (Model $model): void {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), (string) Str::uuid7());
            }
        });
    }

    public function initializeHasUuidV7Key(): void
    {
        $this->keyType = 'string';
        $this->incrementing = false;
    }
}
