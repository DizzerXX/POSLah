<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a store can be reached.
 *
 * On the store rather than the account, because a merchant with two outlets has two phone
 * numbers and one company — and v1 being single-store is not a reason to model it as though
 * it always will be.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->string('email')->nullable()->after('address');
            $table->string('phone', 32)->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
            $table->dropColumn(['email', 'phone']);
        });
    }
};
