<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff clock in/out. Identical for both verticals. No fork.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('time_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();

            $table->timestampTz('clocked_in_at');
            $table->timestampTz('clocked_out_at')->nullable(); // null while the shift is open

            $table->foreignUuid('source_device_id')->nullable()->constrained('devices')->nullOnDelete();

            // Clocking in happens on the till, offline included.
            $table->timestampTz('recorded_at')->nullable();

            $table->timestampsTz();

            // Timesheet report: one user's entries over a period.
            $table->index(['user_id', 'clocked_in_at'], 'time_entries_user_clocked_in_idx');

            // "Who is on shift right now?" — the store's open entries.
            $table->index(['store_id', 'clocked_in_at'], 'time_entries_store_clocked_in_idx');
        });

        // A user cannot be clocked in twice at once; the second clock-in is a bug, not a
        // second shift. Partial so closed entries are unconstrained.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX time_entries_one_open_per_user
            ON time_entries (user_id)
            WHERE clocked_out_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
    }
};
