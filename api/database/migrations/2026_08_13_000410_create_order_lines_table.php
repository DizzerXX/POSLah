<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();

            // Kept for reporting joins ("units sold per SKU"). Never read to render a
            // receipt — that is what the snapshot columns below are for. The rows must
            // therefore never be hard-deleted, which is why the catalog uses is_active.
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('product_variant_id')->nullable()->constrained()->nullOnDelete();

            // Load-bearing duplication, not redundancy. A receipt from March must not change
            // when the product is renamed, repriced, or switched to `routed` in June.
            $table->string('name_snapshot');
            $table->bigInteger('price_snapshot');
            $table->enum('fulfillment_type', ['direct', 'routed']);

            $table->integer('quantity');
            $table->bigInteger('line_total');

            // `direct` lines are `fulfilled` at ring-up; `routed` lines move through the KDS.
            // The order's status is derived from these, never the other way round.
            $table->enum('fulfillment_status', ['pending', 'ready', 'fulfilled'])->default('fulfilled');

            $table->timestampsTz();

            // Every read of a line is "the lines of this order" — receipt render, KDS ticket,
            // refund selection.
            $table->index('order_id', 'order_lines_order_idx');

            // Product-level sales reporting, and the F&B "menu item" reporting dimension.
            $table->index('product_id', 'order_lines_product_idx');

            // KDS: the routed lines of a store's open tickets, and the derivation of order
            // status from line status.
            $table->index(['order_id', 'fulfillment_type', 'fulfillment_status'], 'order_lines_routing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_lines');
    }
};
