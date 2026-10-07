<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Only the roles are seeded. The single Admin is created by
     * `php artisan kokpit:install`, never with a default password.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
    }
}
