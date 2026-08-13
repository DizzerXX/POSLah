<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v1 is single-store per account. The foreign key exists anyway so multi-store is
 * additive later rather than a migration of every downstream table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->text('address')->nullable();

            // Fraction, not a percentage: 0.0600 is 6%. Snapshotted onto each order at
            // checkout, because changing it must not rewrite March's receipts.
            $table->decimal('tax_rate', 6, 4)->default(0);

            $table->timestampsTz();

            // Pull-sync cursor: "stores for this account changed since <cursor>".
            $table->index(['account_id', 'updated_at'], 'stores_account_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stores');
    }
};
