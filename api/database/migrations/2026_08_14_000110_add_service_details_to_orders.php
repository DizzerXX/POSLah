<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an order is being eaten, and any note attached to it.
 *
 * Both nullable, because the retail till has no opinion about either and must not be made to
 * carry one. `service_type` is not a vertical flag: a café takes dine-in and takeaway orders
 * with the same items, which is why this sits on the order rather than on the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->foreignUuid('restaurant_table_id')->nullable()->after('customer_id')
                ->constrained('restaurant_tables')->nullOnDelete();

            $table->enum('service_type', ['dine_in', 'takeaway'])->nullable()->after('restaurant_table_id');

            $table->text('customer_note')->nullable()->after('service_type');
        });

        Schema::table('orders', function (Blueprint $table) {
            // "Which table is occupied right now" — the query the floor plan runs on every
            // render, answered from orders rather than from a status column that could
            // disagree with them.
            $table->index(['restaurant_table_id', 'status'], 'orders_table_status_idx');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_table_status_idx');
            $table->dropConstrainedForeignId('restaurant_table_id');
            $table->dropColumn(['service_type', 'customer_note']);
        });
    }
};
