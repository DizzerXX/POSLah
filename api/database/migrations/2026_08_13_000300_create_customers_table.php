<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identical for both verticals. No fork — if a RetailCustomer and an FnbCustomer ever
 * appear, the architecture has been lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('notes')->nullable();

            // Customers are created *on the till* (quick-create at checkout), so unlike the
            // catalog this table syncs upward and carries both clocks.
            $table->timestampTz('occurred_at')->nullable();
            $table->timestampTz('recorded_at')->nullable();
            $table->foreignUuid('source_device_id')->nullable()->constrained('devices')->nullOnDelete();

            $table->timestampsTz();

            // Customer attach at the till: search by phone within the account.
            $table->index(['account_id', 'phone'], 'customers_account_phone_idx');

            // Pull-sync cursor: customers created on one till must reach the others.
            $table->index(['account_id', 'updated_at'], 'customers_account_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
