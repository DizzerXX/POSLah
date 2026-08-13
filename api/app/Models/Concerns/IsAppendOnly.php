<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A second lock on the append-only tables, in front of the database trigger.
 *
 * The trigger in 2026_08_13_000900_add_append_only_guards is the real enforcement — it holds
 * against raw SQL and against code that never loads a model. This trait exists so the
 * failure arrives as a clear RuntimeException at the offending line rather than as a
 * PostgreSQL error from inside a query, and so that reading the model tells you the rule.
 *
 * If you are here because you want to correct a ledger entry: write a reversing row.
 */
trait IsAppendOnly
{
    public static function bootIsAppendOnly(): void
    {
        static::updating(function (Model $model): void {
            throw new RuntimeException(sprintf(
                '%s is append-only: update the record by appending a reversing row instead.',
                $model->getTable(),
            ));
        });

        static::deleting(function (Model $model): void {
            throw new RuntimeException(sprintf(
                '%s is append-only: rows are never deleted.',
                $model->getTable(),
            ));
        });
    }
}
