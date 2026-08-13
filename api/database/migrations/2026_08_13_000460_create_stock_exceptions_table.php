<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The output of "accept both, allow negative, flag". Reconciliation raises one of these
 * instead of rejecting a sale; the merchant resolves it with a stocktake adjustment, which
 * is itself just another append.
 *
 * Mutable by design — unlike movements, this is a workflow item, not a fact.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();

            $table->string('stockable_type');
            $table->uuid('stockable_id');

            // The negative value that was landed on when the exception was raised or last
            // updated. Signed, like the level it came from.
            $table->decimal('resulting_quantity', 14, 3);

            // Appended to as further offline batches land on an already-negative stockable.
            $table->jsonb('contributing_order_ids')->default('[]');

            $table->enum('status', ['open', 'resolved'])->default('open');
            $table->foreignUuid('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_note')->nullable();
            $table->timestampTz('resolved_at')->nullable();

            $table->timestampsTz();

            // The backoffice exceptions view: open exceptions for a store, newest first.
            $table->index(['store_id', 'status', 'created_at'], 'stock_exceptions_store_status_idx');
        });

        // At most one open exception per stockable per store: repeated negative batches
        // append to the existing row rather than producing a wall of duplicates. This is
        // what makes the reconciliation upsert in step 2 well-defined.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX stock_exceptions_open_unique
            ON stock_exceptions (store_id, stockable_type, stockable_id)
            WHERE status = 'open'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_exceptions');
    }
};
