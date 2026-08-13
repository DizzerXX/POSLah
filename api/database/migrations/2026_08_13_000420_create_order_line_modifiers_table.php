<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_line_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_line_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('modifier_id')->nullable()->constrained()->nullOnDelete();

            // Same snapshot rule as the parent line.
            $table->string('name_snapshot');
            $table->bigInteger('price_delta_snapshot');

            $table->timestampsTz();

            // Always read as "the modifiers on this line" — receipt render and KDS ticket.
            $table->index('order_line_id', 'order_line_modifiers_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_line_modifiers');
    }
};
