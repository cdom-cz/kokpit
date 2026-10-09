<?php

declare(strict_types=1);

use App\Domain\Shared\Database\CzechCollation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

/*
 * kokpit:deploy:verify is the readiness gate (D-18). It runs as an init command of
 * every container right after the execOnce migration, and Zerops ends the deploy at
 * the first failing init command, so a pending migration, an unreachable database,
 * an unreachable Redis, a broken session connection or a database server without the
 * Czech collation keeps the previous version serving. The output names checks and results, never connection details.
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

it('passes on the migrated database with Redis reachable and prints four ok lines including the collation', function (): void {
    [$exit, $output] = runDeployVerify();

    expect($exit)->toBe(0)
        ->and(substr_count($output, 'v pořádku'))->toBe(4)
        ->and($output)->toContain('České řazení: v pořádku')
        ->and($output)->toContain('všechny čtyři kontroly prošly')
        ->and($output)->not->toContain('selhalo');
});

it('tells a present collation from an absent one', function (): void {
    expect(CzechCollation::isAvailable())->toBeTrue()
        ->and(CzechCollation::isAvailable(CzechCollation::NAME))->toBeTrue()
        ->and(CzechCollation::isAvailable('no-such-collation'))->toBeFalse()
        ->and(CzechCollation::isAvailable("x' or true --"))->toBeFalse();
});

it('fails the readiness gate when the Czech collation is missing, without a fallback and without a connection value', function (): void {
    // The check asks the catalogue for the collation by name. A temporary view named pg_collation, found first on
    // the search path, stands in for a server whose catalogue lacks the Czech collation.
    DB::statement('create temporary table pg_collation_probe (collname text)');
    $original = DB::selectOne('select current_setting(\'search_path\') as path')->path;
    DB::statement('set search_path to pg_temp, pg_catalog, '.$original);
    DB::statement('create temporary view pg_collation as select collname from pg_collation_probe');

    try {
        [$exit, $output] = runDeployVerify();
    } finally {
        DB::statement('drop view if exists pg_temp.pg_collation');
        DB::statement('drop table if exists pg_temp.pg_collation_probe');
        DB::statement('set search_path to '.$original);
    }

    expect($exit)->toBe(1)
        ->and($output)->toContain('České řazení: selhalo')
        ->and($output)->toContain(CzechCollation::NAME)
        ->and($output)->toContain('Databáze: v pořádku')
        ->and($output)->toContain('Kontrola nasazení selhala');
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

it('fails the Redis check when a ping returns a falsy answer instead of throwing', function (): void {
    $connection = Mockery::mock();
    $connection->shouldReceive('ping')->andReturn(false);

    Redis::partialMock()->shouldReceive('connection')->andReturn($connection);

    [$exit, $output] = runDeployVerify();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Redis: selhalo')
        ->and($output)->toContain('ping nevrátil platnou odpověď')
        ->and($output)->toContain('Databáze: v pořádku');
});

/**
 * Registers a Redis connection that cannot be reached and returns the password it was given.
 */
function brokenSessionRedisConnection(): string
{
    $password = implode('-', ['not', 'a', 'real', 'password']);

    config(['database.redis.kokpit-probe-session' => array_merge(
        (array) config('database.redis.default'),
        ['host' => '127.0.0.1', 'port' => '1', 'password' => $password, 'max_retries' => 0],
    )]);
    Redis::purge('kokpit-probe-session');

    return $password;
}

it('fails the Redis check when the redis session connection is unreachable', function (): void {
    $password = brokenSessionRedisConnection();
    config(['session.driver' => 'redis', 'session.connection' => 'kokpit-probe-session']);

    [$exit, $output] = runDeployVerify();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Redis: selhalo')
        ->and($output)->toContain('Databáze: v pořádku')
        ->and($output)->not->toContain('127.0.0.1')
        ->and($output)->not->toContain($password);
});

it('ignores the session connection unless the session driver is redis or database', function (): void {
    brokenSessionRedisConnection();
    config(['session.driver' => 'array', 'session.connection' => 'kokpit-probe-session']);

    [$exit] = runDeployVerify();

    expect($exit)->toBe(0);
});

/**
 * Registers a database connection that cannot be reached and returns the password it was given.
 */
function brokenSessionDatabaseConnection(): string
{
    $password = implode('-', ['not', 'a', 'real', 'password']);

    config(['database.connections.kokpit_probe_session' => array_merge(
        (array) config('database.connections.pgsql'),
        ['host' => '127.0.0.1', 'port' => '1', 'password' => $password],
    )]);
    DB::purge('kokpit_probe_session');

    return $password;
}

it('fails the database check when the database session connection is unreachable', function (): void {
    $password = brokenSessionDatabaseConnection();
    config(['session.driver' => 'database', 'session.connection' => 'kokpit_probe_session']);

    [$exit, $output] = runDeployVerify();

    expect($exit)->toBe(1)
        ->and($output)->toContain('Databáze: selhalo')
        ->and($output)->toContain('Redis: v pořádku')
        ->and($output)->not->toContain('127.0.0.1')
        ->and($output)->not->toContain($password);
});

it('ignores a broken database session connection unless the session driver is database', function (): void {
    brokenSessionDatabaseConnection();
    config(['session.driver' => 'array', 'session.connection' => 'kokpit_probe_session']);

    [$exit] = runDeployVerify();

    expect($exit)->toBe(0);
});
