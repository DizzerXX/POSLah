<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            // Minted by the till, before any network call. Time-sortable, so ledger
            // ordering is free, and it doubles as the sync idempotency key.
            $table->uuid('id')->primary();

            $table->foreignUuid('account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('store_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('cashier_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('customer_id')->nullable()->constrained()->nullOnDelete();

            // "T1-0042" — composed on the till from the device prefix at sale time, because
            // a customer walks away with a printed receipt and "pending" is not a number.
            // Stored as received; the server does not assign it.
            $table->string('order_number')->nullable();

            // Fulfilment state only. One machine for both verticals: it *collapses*
            // (created -> paid) when no line is routed rather than branching.
            // Always derived from line status, never set directly from a UI action.
            $table->enum('status', ['created', 'sent', 'preparing', 'ready', 'voided'])
                ->default('created');

            // Payment is an orthogonal axis to fulfilment, not another value of `status`.
            // F&B quick-service is pay-at-counter-then-cook: status='sent' with
            // payment_status='paid' simultaneously, which a single enum cannot express.
            $table->enum('payment_status', ['unpaid', 'paid', 'refunded'])->default('unpaid');

            // Minor units, all four.
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('tax_total')->default(0);
            $table->bigInteger('discount_total')->default(0);
            $table->bigInteger('grand_total')->default(0);

            // Snapshot, same reasoning as price_snapshot on the lines: stores.tax_rate is
            // mutable, and a receipt must stay reproducible after the merchant changes it.
            $table->decimal('tax_rate_snapshot', 6, 4)->default(0);

            $table->timestampTz('occurred_at');            // client clock — when the sale happened
            $table->timestampTz('recorded_at')->nullable(); // server clock — when we learned of it

            // Mirrored from the till for audit: how the device believed the sync went.
            $table->enum('sync_status', ['pending', 'synced', 'conflicted'])->default('synced');

            $table->timestampsTz();

            // Day totals, reporting, and the backoffice order list. recorded_at, not
            // occurred_at: the client clock is untrusted, so anything financial orders by
            // the server clock.
            $table->index(['store_id', 'recorded_at'], 'orders_store_recorded_idx');

            // Reconstructing the merchant's trading day for display, where the client clock
            // is the correct one to use.
            $table->index(['store_id', 'occurred_at'], 'orders_store_occurred_idx');

            // "Which orders came from the till that drove stock negative?" — the first
            // question asked when investigating a stock exception.
            $table->index(['device_id', 'occurred_at'], 'orders_device_occurred_idx');

            // KDS active-ticket polling: open routed orders for a store.
            $table->index(['store_id', 'status'], 'orders_store_status_idx');
        });

        // The device prefix is what makes a locally generated number collision-free, so
        // uniqueness is scoped per store. Partial: an order may legitimately have no number
        // yet if it was created outside the till path.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX orders_store_number_unique
            ON orders (store_id, order_number)
            WHERE order_number IS NOT NULL
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
