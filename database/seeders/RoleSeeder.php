<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\RoleName;
use Illuminate\Database\Seeder;

/**
 * Creates both roles; safe to run any number of times.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoleName::cases() as $role) {
            Role::findOrCreate($role->value, 'web');
        }
    }
}
