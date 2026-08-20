<?php
// database/seeders/DatabaseSeeder.php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            BusinessCategorySeeder::class,
            PlansSeeder::class,
        ];

        // Demo accounts use predictable credentials and must never be created
        // by a production `db:seed` run.
        if (app()->environment('local', 'testing')) {
            $seeders[] = DemoSeeder::class;
        }

        // AdminSeeder can also be run explicitly. Including it here only when
        // configured keeps the default seeding command safe and repeatable.
        if (trim((string) config('admin.seed.email')) !== '') {
            $seeders[] = AdminSeeder::class;
        }

        $this->call($seeders);
    }
}
