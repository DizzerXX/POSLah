<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The audit backbone. Append-only, enforced by trigger in a later migration — there is no
 * UPDATE and no DELETE on this table, ever. A refund or void is a *new reversing row*
 * pointing at what it reverses; the original stays byte-identical.
 *
 * Every foreign key here is RESTRICT, never CASCADE or SET NULL. Those referential actions
 * are implemented as a DELETE or an UPDATE on this table, which the append-only trigger
 * would reject with a confusing error at an unrelated call site. RESTRICT states the real
 * rule directly: you cannot delete a store out from under its financial history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained()->restrictOnDelete();

            $table->enum('entry_type', ['sale', 'refund', 'void']);

            // Signed minor units. Refunds and voids are negative, so a day's takings is a
            // plain SUM with no case analysis.
            $table->bigInteger('amount');

            // A refund points at the sale it reverses. Constrained below: a self-referencing
            // foreign key cannot be declared inside the CREATE TABLE that defines its target.
            $table->uuid('reverses_entry_id')->nullable();

            // v1 records the method; it does not process payment.
            $table->enum('payment_method', ['cash', 'card', 'qr', 'other']);

            // The manager who approved a void or refund. The server verifies this user's
            // role independently — the client's PIN prompt alone is theatre.
            $table->foreignUuid('authorized_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            // Line attribution for partial refunds. Movements alone cannot answer "which
            // lines came back" for untracked products, which produce no movements at all.
            $table->jsonb('metadata')->nullable();

            $table->timestampTz('occurred_at');  // client clock — display, and the merchant's day
            $table->timestampTz('recorded_at');  // server clock — everything financial orders by this

            // No updated_at: the row is immutable by construction.
            $table->timestampTz('created_at')->nullable();

            // Financial ordering and day totals. recorded_at leads because the client clock
            // is untrusted and may be non-monotonic across devices.
            $table->index(['store_id', 'recorded_at'], 'ledger_entries_store_recorded_idx');

            // "Show me everything that happened to this order" — sale plus its reversals.
            $table->index('order_id', 'ledger_entries_order_idx');

            // "Has this sale already been reversed?" — checked before allowing a refund.
            $table->index('reverses_entry_id', 'ledger_entries_reverses_idx');

            // Payment-method breakdown per period: the most common backoffice report after
            // gross takings.
            $table->index(['store_id', 'payment_method', 'recorded_at'], 'ledger_entries_store_method_recorded_idx');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE ledger_entries
            ADD CONSTRAINT ledger_entries_reverses_entry_id_foreign
            FOREIGN KEY (reverses_entry_id) REFERENCES ledger_entries (id)
            ON DELETE RESTRICT
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ledger_entries');
    }
};
