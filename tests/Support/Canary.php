<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use Database\Seeders\RoleSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Helpers of the canary harness (D-04): the test-only table, two fictional
 * clients, runtime canary strings and a user for every state of the
 * fail-closed matrix. Nothing here is a real value; everything is generated
 * when the test runs.
 */
final class Canary
{
    /**
     * Creates the canary table inside the test transaction.
     */
    public static function createTable(): void
    {
        Schema::create('canary_records', function (Blueprint $table) {
            $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
            $table->uuid('client_id');
            $table->text('secret');
            $table->timestampsTz();
        });
    }

    /**
     * Two fictional client ids.
     *
     * @return array{0: string, 1: string}
     */
    public static function twoClients(): array
    {
        return [Str::uuid7()->toString(), Str::uuid7()->toString()];
    }

    /**
     * A canary string assembled from fragments, unique per call.
     */
    public static function canary(string $label): string
    {
        return implode('_', ['CANARY', $label, bin2hex(random_bytes(4))]);
    }

    public static function record(string $clientId, string $secret): CanaryRecord
    {
        return CanaryRecord::query()->create(['client_id' => $clientId, 'secret' => $secret]);
    }

    public static function partnerFor(?string $clientId): User
    {
        return self::userWithRole(RoleName::Partner->value, $clientId);
    }

    public static function admin(): User
    {
        return self::userWithRole(RoleName::Admin->value, null);
    }

    public static function userWithoutRole(?string $clientId): User
    {
        self::seedRoles();

        return User::factory()->create(['email' => exampleEmail(), 'client_id' => $clientId]);
    }

    /**
     * A user with the named role, which is created ad hoc when it is not one of
     * the two real roles.
     */
    public static function userWithRole(string $role, ?string $clientId): User
    {
        self::seedRoles();
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create(['email' => exampleEmail(), 'client_id' => $clientId]);
        $user->assignRole($role);

        return $user;
    }

    private static function seedRoles(): void
    {
        (new RoleSeeder)->run();
    }
}
