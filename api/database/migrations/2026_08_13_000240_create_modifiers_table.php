<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F&B. Attached directly to a product, per the schema plan.
 *
 * Known limitation: "Extra shot" must be recreated per product rather than shared through
 * a modifier group, so renaming it means editing N rows. Accepted for v1 — it is one table
 * fewer and no join — and named in the README rather than left as an unremarked shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->bigInteger('price_delta')->default(0);

            // "Extra shot" decrements beans on top of the base recipe. Null means the
            // modifier is priced but consumes nothing.
            $table->foreignUuid('consumes_ingredient_id')->nullable()->constrained('ingredients')->nullOnDelete();

            // How much of that ingredient one application consumes, in the ingredient's unit.
            $table->decimal('consumes_quantity', 14, 3)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Pull-sync delta, and the modifier sheet on the till.
            $table->index(['product_id', 'updated_at'], 'modifiers_product_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modifiers');
    }
};
