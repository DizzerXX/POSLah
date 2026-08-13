<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only, mirroring the ledger's philosophy and enforced the same way. Every stock
 * fact is a row; stock_levels is the sum. Corrections are new rows with the opposite sign,
 * never an edit.
 *
 * As with ledger_entries, every foreign key is RESTRICT: CASCADE and SET NULL are executed
 * as a DELETE or UPDATE on this table and the append-only trigger would reject them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->restrictOnDelete();

            $table->string('stockable_type');
            $table->uuid('stockable_id');

            // Signed. Sales are negative, refunds and stocktakes may be either.
            $table->decimal('delta', 14, 3);

            $table->enum('reason', ['sale', 'refund', 'manual_adjustment', 'stocktake', 'wastage']);

            // Provenance. Two lattes on one order write two separate movements on purpose —
            // per-line provenance is what makes reconciliation debuggable months later.
            $table->foreignUuid('source_order_id')->nullable()->constrained('orders')->restrictOnDelete();
            $table->foreignUuid('source_order_line_id')->nullable()->constrained('order_lines')->restrictOnDelete();
            $table->foreignUuid('source_device_id')->nullable()->constrained('devices')->restrictOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();

            $table->text('note')->nullable();

            $table->timestampTz('occurred_at');  // client clock — an offline sale happens long before it is recorded
            $table->timestampTz('recorded_at');  // server clock

            $table->timestampTz('created_at')->nullable();

            // The reconciliation query: SUM(delta) for one stockable in one store. This is
            // run on every sync batch, so it is the index that matters most on this table.
            $table->index(['store_id', 'stockable_type', 'stockable_id'], 'stock_movements_stockable_idx');

            // Ingredient-consumption reporting: SUM(delta) grouped by ingredient over a
            // period. The F&B reporting dimension that has no retail equivalent, and it needs
            // no new machinery precisely because movements are append-only.
            $table->index(['store_id', 'stockable_type', 'stockable_id', 'recorded_at'], 'stock_movements_stockable_recorded_idx');

            // "Which orders drove this level negative?" — populating a stock exception.
            $table->index('source_order_id', 'stock_movements_source_order_idx');

            // "Which device did this?" — sync debugging, per the devices table's reason for
            // existing.
            $table->index(['source_device_id', 'occurred_at'], 'stock_movements_device_occurred_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
