<?php

namespace Tests\Feature\Schema;

use App\Models\Account;
use App\Models\Order;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Keys are minted by the client, and the database has no opinion about them.
 *
 * This matters twice over: an offline till must be able to create an order with no
 * coordination, and the ID it chooses is the sync idempotency key — the server's
 * ON CONFLICT (id) DO NOTHING only makes resends safe if the ID came from the till.
 */
class ClientGeneratedKeysTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The load-bearing assertion of the whole set: if a column default ever appears, the
     * database starts inventing IDs and every resend becomes a duplicate row.
     */
    public function test_no_primary_key_column_has_a_database_default(): void
    {
        $defaults = DB::table('information_schema.columns')
            ->select('table_name', 'column_default')
            ->where('table_schema', 'public')
            ->where('column_name', 'id')
            ->where('data_type', 'uuid')
            ->whereNotNull('column_default')
            ->pluck('column_default', 'table_name')
            ->all();

        $this->assertSame(
            [],
            $defaults,
            'A uuid primary key with a database default means the DB is minting IDs.',
        );
    }

    public function test_no_table_uses_an_auto_incrementing_key(): void
    {
        $identityColumns = DB::table('information_schema.columns')
            ->select('table_name', 'column_name')
            ->where('table_schema', 'public')
            ->where('column_name', 'id')
            ->where(function ($query) {
                $query->where('is_identity', 'YES')
                    ->orWhere('column_default', 'like', 'nextval%');
            })
            // Laravel's own supporting tables are not part of the sync surface.
            ->whereNotIn('table_name', ['migrations', 'jobs', 'job_batches', 'failed_jobs'])
            ->get();

        $this->assertCount(0, $identityColumns, 'Syncable tables must not auto-increment.');
    }

    public function test_a_model_created_without_an_id_receives_a_uuid_v7(): void
    {
        $account = Account::factory()->create();

        $this->assertTrue(Str::isUuid($account->id));
        // Version nibble: the 13th hex digit of a v7 UUID is '7'.
        $this->assertSame('7', $account->id[14]);
    }

    /**
     * The till's ID must survive untouched — it is what the till will resend, and what the
     * server will match on to recognise a duplicate.
     */
    public function test_an_id_supplied_by_the_client_is_used_verbatim(): void
    {
        $account = Account::factory()->create();
        $store = Store::factory()->for($account)->create();

        $tillGeneratedId = (string) Str::uuid7();

        $order = Order::factory()->for($account)->for($store)->create(['id' => $tillGeneratedId]);

        $this->assertSame($tillGeneratedId, $order->id);
        $this->assertDatabaseHas('orders', ['id' => $tillGeneratedId]);
    }

    /**
     * Resending a batch is a no-op rather than a duplicate. Step 2 builds the endpoint that
     * relies on this; the property itself belongs to the schema.
     */
    public function test_reinserting_the_same_id_is_a_no_op(): void
    {
        $account = Account::factory()->create();
        $store = Store::factory()->for($account)->create();
        $order = Order::factory()->for($account)->for($store)->create();

        $row = (array) DB::table('orders')->where('id', $order->id)->first();

        $inserted = DB::table('orders')->insertOrIgnore([$row]);

        $this->assertSame(0, $inserted, 'A resent row must be ignored, not duplicated.');
        $this->assertSame(1, DB::table('orders')->where('id', $order->id)->count());
    }

    public function test_uuid_v7_keys_sort_by_creation_time(): void
    {
        $ids = collect(range(1, 5))->map(fn () => (string) Str::uuid7())->all();

        $sorted = $ids;
        sort($sorted);

        // Time-sortable keys are why ledger ordering is free and why index inserts stay
        // local instead of scattering across the B-tree.
        $this->assertSame($ids, $sorted);
    }
}
