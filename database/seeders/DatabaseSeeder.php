<?php
// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            BusinessCategorySeeder::class,
            PlansSeeder::class,
            DemoSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
