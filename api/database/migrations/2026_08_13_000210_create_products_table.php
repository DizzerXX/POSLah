<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            // Both nullable: F&B menu items routinely have neither.
            $table->string('sku')->nullable();
            $table->string('barcode')->nullable();

            // Minor units. Never float, never decimal in transport.
            $table->bigInteger('price');

            // The hinge of the whole design. `routed` lines go to the kitchen and drive the
            // order state machine; `direct` lines are fulfilled at ring-up. Copied onto each
            // order line at sale time, never joined back from here.
            $table->enum('fulfillment_type', ['direct', 'routed'])->default('direct');

            // Deliberately independent of "has recipe lines". Three real cases:
            //   retail SKU     tracks_stock=true,  no recipe   -> decrement the variant
            //   F&B menu item  tracks_stock=false, has recipe  -> decrement each ingredient
            //   corkage fee    tracks_stock=false, no recipe   -> no movements at all
            // One boolean cannot express the third.
            $table->boolean('tracks_stock')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Pull-sync cursor: the single hottest query in the system, run by every till
            // on every pull. Deactivated products must ride along in the delta, so is_active
            // is deliberately not part of the index predicate.
            $table->index(['account_id', 'updated_at'], 'products_account_updated_idx');

            // Barcode scan at the till resolves against products and product_variants.
            // Not unique: a merchant may legitimately reuse a code across accounts.
            $table->index(['account_id', 'barcode'], 'products_account_barcode_idx');

            // Catalog screen: active products in a category, in sort order.
            $table->index(['category_id', 'is_active'], 'products_category_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
