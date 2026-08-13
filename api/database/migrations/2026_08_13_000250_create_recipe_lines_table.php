<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The bill of materials — the actual retail/F&B fork. One burger is three rows:
 * bun x1, patty x150g, cheese x2.
 *
 * Flat, one level deep. Nesting (a sauce that is itself a recipe) needs recursive
 * expansion, cycle detection, and cross-level unit conversion; locked out of v1 and
 * documented as a known limitation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->cascadeOnDelete();

            // In the ingredient's own unit. 150 g of patty is not a count, so this is the
            // one family of quantities that is not an integer.
            $table->decimal('quantity', 14, 3);

            $table->timestampsTz();

            // Expansion happens on the till against the locally held recipe, so recipe_lines
            // are part of the pull payload: "recipes changed since <cursor>".
            $table->index(['product_id', 'updated_at'], 'recipe_lines_product_updated_idx');

            // "What uses this ingredient?" — the availability hint computes a minimum across
            // recipe lines, and the backoffice warns before deactivating an ingredient.
            $table->index('ingredient_id', 'recipe_lines_ingredient_idx');

            // One ingredient appears at most once per recipe; two rows for beans would make
            // "how much does this drink use" ambiguous.
            $table->unique(['product_id', 'ingredient_id'], 'recipe_lines_product_ingredient_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recipe_lines');
    }
};
