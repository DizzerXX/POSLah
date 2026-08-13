<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            // Unique globally rather than per account: it is the login identifier, so a
            // per-account unique would make "which account is this login for?" ambiguous.
            $table->string('email')->unique();
            $table->timestampTz('email_verified_at')->nullable();
            $table->string('password');

            $table->enum('role', ['owner', 'manager', 'cashier']);

            // Distinct from the login password. Gates manager override on void and refund,
            // which the server verifies independently of the client's PIN prompt.
            $table->string('pin_hash')->nullable();

            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestampsTz();

            // Pull-sync cursor: staff list for this account changed since <cursor>.
            // Deactivated users must appear in that delta, so is_active is not filtered here.
            $table->index(['account_id', 'updated_at'], 'users_account_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
