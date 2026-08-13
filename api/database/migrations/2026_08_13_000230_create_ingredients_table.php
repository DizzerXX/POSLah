<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F&B. The ingredient is the F&B *stockable*: a latte tracks beans and milk, not lattes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->enum('unit', ['g', 'ml', 'unit']);

            // Same unit as `unit`. Drives the low-stock hint; never blocks a sale.
            $table->decimal('low_stock_threshold', 14, 3)->default(0);

            // Naive purchase cost in minor units per one `unit`. Displayed as an indicative
            // cost-per-item in the recipe editor and nothing more — real COGS needs purchase
            // orders, weighted average cost, and wastage accounting, which is a subsystem
            // and is out of scope for v1.
            $table->bigInteger('cost_per_unit')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Pull-sync cursor: recipe expansion happens on the till, so ingredients must
            // be resident locally.
            $table->index(['account_id', 'updated_at'], 'ingredients_account_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
