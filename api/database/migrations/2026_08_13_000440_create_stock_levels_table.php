<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A materialised projection of SUM(stock_movements.delta), not a record of fact. This is
 * the one stock table that is mutable, precisely because it is derived.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();

            // Polymorphic: `product_variant` for retail, `ingredient` for F&B. The two
            // verticals hold stock against different things, which is the whole divergence.
            $table->string('stockable_type');
            $table->uuid('stockable_id');

            // SIGNED, and deliberately without a >= 0 check. Two tills selling the last unit
            // offline both made a sale; -1 is the correct record of what happened, and an
            // exception for the merchant to resolve, not an error to reject.
            $table->decimal('quantity_on_hand', 14, 3)->default(0);

            $table->timestampsTz();

            // One level per stockable per store. Also the lookup for the reconciliation
            // row lock: SELECT ... FOR UPDATE on exactly this key.
            $table->unique(['store_id', 'stockable_type', 'stockable_id'], 'stock_levels_stockable_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_levels');
    }
};
