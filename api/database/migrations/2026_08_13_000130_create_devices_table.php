<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A registered till. Provenance for sync debugging: when stock goes negative you need
 * to know which device did it, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('registered_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('device_name');

            // Receipt numbering (step 3). The prefix is assigned by the backoffice at
            // registration and guarantees no cross-device collision without coordination;
            // the till composes "T1-0042" locally, before any network call.
            $table->string('number_prefix', 8)->nullable();

            // Last sequence the server has *seen*, not an assignment. The till owns this
            // counter — it must be able to number a receipt while offline — so this column
            // is an observation for support and debugging, never a source of truth.
            $table->unsignedInteger('last_seen_sequence')->nullable();

            // Pins a physical till to one vertical (front counter = retail). Null means
            // "unpinned: show the switcher if the account has both". Step 4.
            $table->enum('assigned_module', ['retail', 'fnb'])->nullable();

            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_sync_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Two devices in one store cannot share a receipt prefix, or "T1-0042" stops
            // being unique. Partial: an unregistered device may have no prefix yet.
            $table->index(['store_id'], 'devices_store_idx');
        });

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX devices_store_prefix_unique
            ON devices (store_id, number_prefix)
            WHERE number_prefix IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
