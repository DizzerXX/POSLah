<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Retail. A variant is the retail *stockable*: stock is held against "Large / Blue",
 * not against the product.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();

            // Signed minor units: a smaller size may be a negative delta on the base price.
            $table->bigInteger('price_delta')->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Pull sync fans out from products; this index serves both the join and the
            // "variants changed since <cursor>" delta.
            $table->index(['product_id', 'updated_at'], 'product_variants_product_updated_idx');

            // Barcode scan resolves here before falling back to products.
            $table->index('barcode', 'product_variants_barcode_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
