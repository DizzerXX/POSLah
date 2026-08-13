<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            // Pull-sync cursor. Catalog flows downward only; the till never edits it.
            $table->index(['account_id', 'updated_at'], 'categories_account_updated_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
