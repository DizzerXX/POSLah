<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            // Both verticals in one account, one store, one catalog.
            DemoAccountSeeder::class,
            // Retail-only and F&B-only, for the entitlement work in step 4.
            SingleVerticalAccountSeeder::class,
        ]);
    }
}
