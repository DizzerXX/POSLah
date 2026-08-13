<?php

namespace Tests\Feature\Schema;

use App\Enums\Module;
use App\Models\Account;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * An account with no modules is a state we prevent rather than handle.
 *
 * The value of pushing this into the schema is that the login flow, the module registry on
 * the till, and the backoffice navigation all get to assume at least one module exists —
 * which removes an error branch from three places instead of adding a validator to one.
 */
class AccountEntitlementConstraintTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_cannot_be_created_with_zero_enabled_modules(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_enabled_modules_non_empty');

        Account::factory()->create(['enabled_modules' => []]);
    }

    /**
     * Straight through the query builder, to prove the refusal is the database's and not a
     * model observer's.
     */
    public function test_the_constraint_holds_against_a_raw_insert(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_enabled_modules_non_empty');

        DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'No Modules Bhd',
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'MYR',
            'enabled_modules' => '[]',
            'default_fulfillment_type' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * jsonb_array_length() raises rather than returning false when handed a non-array,
     * which would surface as a confusing internal error instead of a constraint violation.
     * The jsonb_typeof check in front of it is what makes this case land cleanly.
     */
    public function test_enabled_modules_must_be_a_json_array(): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_enabled_modules_non_empty');

        DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'name' => 'Wrong Shape Bhd',
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'MYR',
            'enabled_modules' => '{"retail": true}',
            'default_fulfillment_type' => 'direct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_account_may_not_be_emptied_of_modules_after_creation(): void
    {
        $account = Account::factory()->bothModules()->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('accounts_enabled_modules_non_empty');

        $account->update(['enabled_modules' => []]);
    }

    public function test_one_module_is_enough(): void
    {
        $account = Account::factory()->retailOnly()->create();

        $this->assertTrue($account->hasModule(Module::Retail));
        $this->assertFalse($account->hasModule(Module::Fnb));
    }

    public function test_an_account_may_hold_both_verticals(): void
    {
        $account = Account::factory()->bothModules()->create();

        $this->assertTrue($account->hasModule(Module::Retail));
        $this->assertTrue($account->hasModule(Module::Fnb));
        $this->assertEqualsCanonicalizing([Module::Retail, Module::Fnb], $account->modules());
    }
}
