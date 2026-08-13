<?php

namespace Database\Seeders;

use App\Enums\IngredientUnit;
use App\Enums\Module;
use App\Enums\StockMovementReason;
use App\Models\Account;
use App\Models\Category;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\Modifier;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RecipeLine;
use App\Models\StockLevel;
use App\Models\StockMovement;
use App\Models\Store;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;

/**
 * One account with *both* verticals enabled, so the divergence the architecture exists to
 * handle is present from the first `artisan migrate:fresh --seed`:
 *
 *   retail  — bags of beans and brewing gear: variants carry the stock, one SKU one movement
 *   F&B     — drinks and food: the menu item carries no stock at all, its recipe does
 *
 * Both sit in one store, on one till, in one catalog. That is the point of the project.
 */
class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        $account = Account::factory()->bothModules()->create([
            'name' => 'Kedai Kopi Lah',
            'timezone' => 'Asia/Kuala_Lumpur',
            'currency' => 'MYR',
        ]);

        $store = Store::factory()->for($account)->create([
            'name' => 'Bangsar Outlet',
            'address' => '12 Jalan Telawi 3, Bangsar Baru, 59100 Kuala Lumpur',
            'tax_rate' => '0.0600',
        ]);

        $this->seedStaff($account);
        $this->seedDevices($account, $store);
        $this->seedRetailCatalog($account, $store);
        $this->seedFnbCatalog($account, $store);
    }

    private function seedStaff(Account $account): void
    {
        User::factory()->for($account)->owner()->create([
            'name' => 'Aisyah Rahman',
            'email' => 'owner@poslah.test',
        ]);

        User::factory()->for($account)->manager()->create([
            'name' => 'Daniel Tan',
            'email' => 'manager@poslah.test',
        ]);

        User::factory()->for($account)->cashier()->create([
            'name' => 'Priya Kumar',
            'email' => 'cashier@poslah.test',
        ]);
    }

    private function seedDevices(Account $account, Store $store): void
    {
        // A merchant running both verticals usually dedicates a physical till to one of
        // them. A pinned device shows no module switcher at all, which removes a whole
        // class of cashier error.
        Device::factory()->for($account)->for($store)->create([
            'device_name' => 'Front Counter',
            'number_prefix' => 'T1',
            'assigned_module' => Module::Retail,
        ]);

        Device::factory()->for($account)->for($store)->create([
            'device_name' => 'Cafe Counter',
            'number_prefix' => 'T2',
            'assigned_module' => Module::Fnb,
        ]);

        // Unpinned: this one gets the switcher, because the account has both modules.
        Device::factory()->for($account)->for($store)->create([
            'device_name' => 'Roaming iPad',
            'number_prefix' => 'T3',
            'assigned_module' => null,
        ]);
    }

    /**
     * Retail: the variant is the stockable. Selling one 250g bag decrements that one bag.
     */
    private function seedRetailCatalog(Account $account, Store $store): void
    {
        $beans = Category::factory()->for($account)->create(['name' => 'Coffee Beans', 'sort_order' => 1]);
        $gear = Category::factory()->for($account)->create(['name' => 'Brewing Gear', 'sort_order' => 2]);
        $merch = Category::factory()->for($account)->create(['name' => 'Merchandise', 'sort_order' => 3]);

        $catalog = [
            [$beans, 'Ethiopia Yirgacheffe', 'BEAN-ETH', 4500, [
                ['250g Bag', 'BEAN-ETH-250', '9557001000011', 0, 24],
                ['1kg Bag', 'BEAN-ETH-1K', '9557001000028', 12000, 6],
            ]],
            [$beans, 'Sumatra Mandheling', 'BEAN-SUM', 3800, [
                ['250g Bag', 'BEAN-SUM-250', '9557001000035', 0, 18],
                ['1kg Bag', 'BEAN-SUM-1K', '9557001000042', 10000, 4],
            ]],
            [$gear, 'Hario V60 Dripper', 'GEAR-V60', 6500, [
                ['Size 01', 'GEAR-V60-01', '9557001000059', 0, 9],
                ['Size 02', 'GEAR-V60-02', '9557001000066', 800, 7],
            ]],
            [$gear, 'Gooseneck Kettle', 'GEAR-KET', 18900, [
                ['Matte Black', 'GEAR-KET-BLK', '9557001000073', 0, 3],
            ]],
            [$merch, 'Kedai Kopi Tee', 'MERCH-TEE', 5900, [
                ['S', 'MERCH-TEE-S', '9557001000080', 0, 12],
                ['M', 'MERCH-TEE-M', '9557001000097', 0, 15],
                ['L', 'MERCH-TEE-L', '9557001000103', 0, 11],
                ['XL', 'MERCH-TEE-XL', '9557001000110', 300, 5],
            ]],
        ];

        foreach ($catalog as [$category, $name, $sku, $price, $variants]) {
            $product = Product::factory()->for($account)->for($category)->retail()->create([
                'name' => $name,
                'sku' => $sku,
                'barcode' => null,
                'price' => $price,
            ]);

            foreach ($variants as [$variantName, $variantSku, $barcode, $delta, $onHand]) {
                $variant = ProductVariant::factory()->for($product)->create([
                    'name' => $variantName,
                    'sku' => $variantSku,
                    'barcode' => $barcode,
                    'price_delta' => $delta,
                ]);

                $this->openStock($store, $variant, (string) $onHand);
            }
        }

        // A discontinued line. It stays in the catalog rather than being deleted, because
        // historical order lines hold a foreign key to it — and it must ride along in the
        // pull-sync delta as is_active:false, or it lives forever on the tills.
        Product::factory()->for($account)->for($gear)->retail()->inactive()->create([
            'name' => 'Aeropress (discontinued)',
            'sku' => 'GEAR-AER',
            'barcode' => null,
            'price' => 12900,
        ]);
    }

    /**
     * F&B: the menu item is not a stockable at all. Its recipe is.
     */
    private function seedFnbCatalog(Account $account, Store $store): void
    {
        $espresso = Category::factory()->for($account)->create(['name' => 'Espresso Bar', 'sort_order' => 10]);
        $kitchen = Category::factory()->for($account)->create(['name' => 'Kitchen', 'sort_order' => 11]);
        $extras = Category::factory()->for($account)->create(['name' => 'Extras', 'sort_order' => 12]);

        /** @var array<string, Ingredient> $ingredients */
        $ingredients = [];

        // name => [unit, low stock threshold, cost per unit in sen, opening quantity]
        $pantry = [
            'House Espresso Blend' => [IngredientUnit::Gram, '1000.000', 8, '8000.000'],
            'Fresh Milk' => [IngredientUnit::Millilitre, '4000.000', 1, '24000.000'],
            'Oat Milk' => [IngredientUnit::Millilitre, '2000.000', 2, '9000.000'],
            'Chocolate Powder' => [IngredientUnit::Gram, '500.000', 6, '2500.000'],
            'Burger Bun' => [IngredientUnit::Unit, '20.000', 120, '48.000'],
            'Beef Patty' => [IngredientUnit::Gram, '2000.000', 5, '9000.000'],
            'Cheese Slice' => [IngredientUnit::Unit, '30.000', 90, '96.000'],
            'Jasmine Rice' => [IngredientUnit::Gram, '3000.000', 1, '15000.000'],
            'Fried Chicken Thigh' => [IngredientUnit::Unit, '15.000', 450, '40.000'],
            'Sambal' => [IngredientUnit::Gram, '500.000', 3, '3000.000'],
            'Egg' => [IngredientUnit::Unit, '24.000', 60, '60.000'],
        ];

        foreach ($pantry as $name => [$unit, $threshold, $cost, $onHand]) {
            $ingredient = Ingredient::factory()->for($account)->create([
                'name' => $name,
                'unit' => $unit,
                'low_stock_threshold' => $threshold,
                'cost_per_unit' => $cost,
            ]);

            $ingredients[$name] = $ingredient;
            $this->openStock($store, $ingredient, $onHand);
        }

        // Menu items: routed to the kitchen, tracks_stock false, stock lives in the recipe.
        $menu = [
            [$espresso, 'Flat White', 1200, [
                'House Espresso Blend' => '18.000',
                'Fresh Milk' => '180.000',
            ]],
            [$espresso, 'Latte', 1300, [
                'House Espresso Blend' => '18.000',
                'Fresh Milk' => '220.000',
            ]],
            [$espresso, 'Mocha', 1500, [
                'House Espresso Blend' => '18.000',
                'Fresh Milk' => '200.000',
                'Chocolate Powder' => '25.000',
            ]],
            [$kitchen, 'Cheeseburger', 2200, [
                'Burger Bun' => '1.000',
                'Beef Patty' => '150.000',
                'Cheese Slice' => '2.000',
            ]],
            [$kitchen, 'Nasi Lemak Ayam', 1800, [
                'Jasmine Rice' => '200.000',
                'Fried Chicken Thigh' => '1.000',
                'Sambal' => '50.000',
                'Egg' => '1.000',
            ]],
        ];

        /** @var array<string, Product> $products */
        $products = [];

        foreach ($menu as [$category, $name, $price, $recipe]) {
            $product = Product::factory()->for($account)->for($category)->menuItem()->create([
                'name' => $name,
                'price' => $price,
            ]);

            $products[$name] = $product;

            foreach ($recipe as $ingredientName => $quantity) {
                RecipeLine::factory()->for($product)->for($ingredients[$ingredientName])->create([
                    'quantity' => $quantity,
                ]);
            }
        }

        // Modifiers consume on top of the base recipe: an extra shot is 9g more beans.
        Modifier::factory()->for($products['Latte'])->create([
            'name' => 'Extra Shot',
            'price_delta' => 250,
            'consumes_ingredient_id' => $ingredients['House Espresso Blend']->id,
            'consumes_quantity' => '9.000',
        ]);

        Modifier::factory()->for($products['Latte'])->create([
            'name' => 'Oat Milk',
            'price_delta' => 200,
            'consumes_ingredient_id' => $ingredients['Oat Milk']->id,
            'consumes_quantity' => '220.000',
        ]);

        Modifier::factory()->for($products['Cheeseburger'])->create([
            'name' => 'Extra Cheese',
            'price_delta' => 300,
            'consumes_ingredient_id' => $ingredients['Cheese Slice']->id,
            'consumes_quantity' => '1.000',
        ]);

        // Priced, but consumes nothing.
        Modifier::factory()->for($products['Nasi Lemak Ayam'])->create([
            'name' => 'Less Spicy',
            'price_delta' => 0,
            'consumes_ingredient_id' => null,
            'consumes_quantity' => null,
        ]);

        // The third tracks_stock case, and the reason it cannot be one boolean with
        // "has a recipe": a sellable product that touches no inventory whatsoever and
        // expands to zero stock movements.
        Product::factory()->for($account)->for($extras)->untracked()->create([
            'name' => 'Corkage',
            'sku' => null,
            'price' => 3000,
        ]);
    }

    /**
     * Opening stock, recorded the same way every other stock fact is: an append to
     * stock_movements, with stock_levels holding the projected sum. There is no
     * "set the quantity" path anywhere in the system, including here.
     */
    private function openStock(Store $store, Model $stockable, string $quantity): void
    {
        StockMovement::factory()->for($store)->create([
            'stockable_type' => $stockable->getMorphClass(),
            'stockable_id' => $stockable->getKey(),
            'delta' => $quantity,
            'reason' => StockMovementReason::ManualAdjustment,
            'note' => 'Opening stock',
        ]);

        StockLevel::factory()->for($store)->create([
            'stockable_type' => $stockable->getMorphClass(),
            'stockable_id' => $stockable->getKey(),
            'quantity_on_hand' => $quantity,
        ]);
    }
}
