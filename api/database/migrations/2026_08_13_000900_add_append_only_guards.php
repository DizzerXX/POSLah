<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Append-only" enforced literally, at the database, rather than by convention.
 *
 * A convention holds until the first person in a hurry. A trigger holds against an Eloquent
 * save(), a raw query builder update, a tinker session, and a hand-typed psql statement
 * identically — which is the point: the ledger's credibility is the entire reason the
 * project stores money as a ledger at all.
 *
 * The alternative — REVOKE UPDATE, DELETE from the application role — was rejected because
 * it depends on the deployment connecting as a role that was set up correctly. A trigger
 * travels with the schema and is exercised by the test suite.
 *
 * TRUNCATE is deliberately not guarded: `migrate:fresh` and truncation-based test resets
 * must keep working, and wiping a table wholesale is a schema-level act, not a correction
 * smuggled in as an edit.
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = ['ledger_entries', 'stock_movements'];

    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION poslah_forbid_mutation() RETURNS trigger AS $$
            BEGIN
                RAISE EXCEPTION
                    '% is append-only: % is not permitted. Write a reversing row instead.',
                    TG_TABLE_NAME, TG_OP
                    USING ERRCODE = 'restrict_violation';
            END;
            $$ LANGUAGE plpgsql;
        SQL);

        foreach ($this->tables as $table) {
            DB::unprepared(<<<SQL
                CREATE TRIGGER {$table}_append_only
                BEFORE UPDATE OR DELETE ON {$table}
                FOR EACH ROW EXECUTE FUNCTION poslah_forbid_mutation();
            SQL);
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$table}_append_only ON {$table};");
        }

        DB::unprepared('DROP FUNCTION IF EXISTS poslah_forbid_mutation();');
    }
};
