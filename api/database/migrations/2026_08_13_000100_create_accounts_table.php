<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tenant root. Every other table hangs off an account, directly or through a parent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            // Client-generated UUIDv7. No database default: the DB never mints an ID.
            $table->uuid('id')->primary();

            $table->string('name');
            $table->string('timezone')->default('Asia/Kuala_Lumpur');
            $table->string('currency', 3)->default('MYR');

            // Which verticals this account may use. Authorisation reads this column on
            // every request that needs it — never a token claim.
            $table->jsonb('enabled_modules');

            // Pre-sets fulfillment_type on newly created products. A UX convenience only:
            // the authoritative branch is per product, never per account.
            $table->enum('default_fulfillment_type', ['direct', 'routed'])->default('direct');

            $table->timestampsTz();
        });

        // An account with zero modules is a state we prevent, not one the auth flow has to
        // handle. jsonb_typeof is checked first because jsonb_array_length() errors rather
        // than returning false when handed an object or a scalar.
        DB::statement(<<<'SQL'
            ALTER TABLE accounts
            ADD CONSTRAINT accounts_enabled_modules_non_empty
            CHECK (
                jsonb_typeof(enabled_modules) = 'array'
                AND jsonb_array_length(enabled_modules) >= 1
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }
};
