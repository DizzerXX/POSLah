<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A picture of the thing being sold.
 *
 * The POS design is mostly imagery — a cashier finds a burger by looking, not by reading —
 * so the menu grid needs somewhere for one. A URL rather than an uploaded file: v1 has no
 * storage story, and inventing one here would be a subsystem rather than a column. Null is
 * expected and common, and the grid renders a typographic tile for it rather than a broken
 * image or an empty box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->text('image_url')->nullable()->after('barcode');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('image_url');
        });
    }
};
