<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * kokpit:deploy:verify is the readiness gate of the app service (D-18): a new
 * version only takes traffic while it exits 0, so a pending migration, an
 * unreachable database or an unreachable Redis keeps the previous version
 * serving. The output names checks and results, never connection details.
 */

/**
 * @return array{int, string}
 */
function runDeployVerify(): array
{
    $exit = Artisan::call('kokpit:deploy:verify');

    return [$exit, Artisan::output()];
}

/**
 * Registers a directory holding one migration that has not run, the same way a
 * package registers its migration path on the migrator.
 */
function registerPendingMigration(): string
{
    $directory = sys_get_temp_dir().'/kokpit-pending-'.Str::lower(Str::random(12));
    File::makeDirectory($directory);

    File::put($directory.'/2099_01_01_000000_pending_deploy_verify_probe.php', <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;

        return new class extends Migration
        {
            public function up(): void {}
        };
        PHP);

    app('migrator')->path($directory);

    return $directory;
}

it('passes on the migrated database with Redis reachable and prints three ok lines', function (): void {
    [$exit, $output] = runDeployVerify();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'v pořádku'))->toBe(3)
        ->and($output)->not->toContain('selhalo');
});

it('fails and names the number of pending migrations', function (): void {
    $directory = registerPendingMigration();

    try {
        [$exit, $output] = runDeployVerify();
    } finally {
        File::deleteDirectory($directory);
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain('Migrace: selhalo')
        ->and($output)->toContain('čekajících migrací: 1')
        ->and($output)->toContain('Databáze: v pořádku')
        ->and($output)->toContain('Kontrola nasazení selhala');
});

it('reads the settings migrations registered on the migrator as well', function (): void {
    // The settings package registers its path on the migrator; the command must read the
    // migrator's paths and not only database/migrations, otherwise a pending settings
    // migration could go live. They are all recorded as run on the migrated test database.
    expect(app('migrator')->paths())->toContain(database_path('settings'));

    [$exit] = runDeployVerify();

    expect($exit)->toBe(0);
});

it('fails the Redis check without printing any connection value', function (): void {
    $password = implode('-', ['not', 'a', 'real', 'password']);

    config([
        'database.redis.default.host' => '127.0.0.1',
        'database.redis.default.port' => '1',
        'database.redis.default.password' => $password,
        'database.redis.default.max_retries' => 0,
    ]);
    Redis::purge('default');

    [$exit, $output] = runDeployVerify();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Redis: selhalo')
        ->and($output)->toContain('Databáze: v pořádku')
        ->and($output)->not->toContain('127.0.0.1')
        ->and($output)->not->toContain($password);
});

it('prints no database or Redis connection value on success or failure', function (): void {
    $directory = registerPendingMigration();

    try {
        [, $failing] = runDeployVerify();
    } finally {
        File::deleteDirectory($directory);
    }

    [, $passing] = runDeployVerify();

    $values = [];

    foreach (['host', 'port', 'username', 'password'] as $key) {
        $values[] = (string) config('database.connections.pgsql.'.$key);
    }

    foreach (['host', 'port', 'password'] as $key) {
        $values[] = (string) config('database.redis.default.'.$key);
    }

    $values = array_filter(array_unique($values), fn (string $value): bool => $value !== '');

    expect($values)->not->toBe([]);

    // Whole-word match, so a short value such as "db" is still caught without matching inside other words.
    foreach ($values as $value) {
        $pattern = '/(?<![\p{L}\p{N}])'.preg_quote($value, '/').'(?![\p{L}\p{N}])/u';

        expect($failing)->not->toMatch($pattern)
            ->and($passing)->not->toMatch($pattern);
    }
});
