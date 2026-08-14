<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dining tables.
 *
 * A deliberate reversal of a locked v1 decision. The plans ruled out table management on the
 * grounds that it implies open tabs, which implies pay-last ordering — and that reasoning
 * still holds, so this stops short of it: a table is an attachment on an order, not a tab
 * that accrues one. Availability is *derived* from whether an unfinished order references
 * the table, rather than stored as a mutable status that would drift out of step with the
 * orders themselves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();

            $table->string('label', 16);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // The floor plan of one store, in display order.
            $table->index(['store_id', 'label'], 'restaurant_tables_store_label_idx');
            $table->unique(['store_id', 'label'], 'restaurant_tables_store_label_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_tables');
    }
};
