<?php

namespace Database\Seeders;

use App\Enums\IngredientUnit;
use App\Models\Account;
use App\Models\Category;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeLine;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Two deliberately narrow accounts: retail-only and F&B-only.
 *
 * They exist so the entitlement work in step 4 has something honest to test against — "an
 * F&B endpoint returns 403 for a retail-only account" needs a retail-only account that was
 * not built by the test that asserts it.
 */
class SingleVerticalAccountSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRetailOnly();
        $this->seedFnbOnly();
    }

    private function seedRetailOnly(): void
    {
        $account = Account::factory()->retailOnly()->create(['name' => 'Batu Books']);
        $store = Store::factory()->for($account)->create(['name' => 'Batu Books — Main', 'tax_rate' => '0.0000']);

        User::factory()->for($account)->owner()->create([
            'name' => 'Lim Wei Ling',
            'email' => 'retail-owner@poslah.test',
        ]);

        Device::factory()->for($account)->for($store)->create([
            'device_name' => 'Counter',
            'number_prefix' => 'R1',
            // Null rather than 'retail': the account has only one module, so there is
            // nothing to switch between and nothing to pin.
            'assigned_module' => null,
        ]);

        $category = Category::factory()->for($account)->create(['name' => 'Paperbacks', 'sort_order' => 1]);

        $product = Product::factory()->for($account)->for($category)->retail()->create([
            'name' => 'The Garden of Evening Mists',
            'sku' => 'BK-GEM',
            'barcode' => null,
            'price' => 4590,
        ]);

        ProductVariant::factory()->for($product)->create([
            'name' => 'Paperback',
            'sku' => 'BK-GEM-PB',
            'barcode' => '9789671000014',
            'price_delta' => 0,
        ]);
    }

    private function seedFnbOnly(): void
    {
        $account = Account::factory()->fnbOnly()->create(['name' => 'Teh Tarik Corner']);
        $store = Store::factory()->for($account)->create(['name' => 'Teh Tarik Corner — Kajang', 'tax_rate' => '0.0600']);

        User::factory()->for($account)->owner()->create([
            'name' => 'Hafiz Ismail',
            'email' => 'fnb-owner@poslah.test',
        ]);

        Device::factory()->for($account)->for($store)->create([
            'device_name' => 'Kitchen Counter',
            'number_prefix' => 'F1',
            'assigned_module' => null,
        ]);

        $category = Category::factory()->for($account)->create(['name' => 'Drinks', 'sort_order' => 1]);

        $tea = Ingredient::factory()->for($account)->create([
            'name' => 'Tea Dust',
            'unit' => IngredientUnit::Gram,
            'low_stock_threshold' => '500.000',
            'cost_per_unit' => 2,
        ]);

        $milk = Ingredient::factory()->for($account)->create([
            'name' => 'Condensed Milk',
            'unit' => IngredientUnit::Millilitre,
            'low_stock_threshold' => '1000.000',
            'cost_per_unit' => 1,
        ]);

        $product = Product::factory()->for($account)->for($category)->menuItem()->create([
            'name' => 'Teh Tarik',
            'price' => 350,
        ]);

        RecipeLine::factory()->for($product)->for($tea)->create(['quantity' => '12.000']);
        RecipeLine::factory()->for($product)->for($milk)->create(['quantity' => '40.000']);
    }
}
