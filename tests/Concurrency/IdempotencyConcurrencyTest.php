<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\ApiAbility;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

/*
 * Real parallel PHP processes posting one create with one Idempotency-Key through the HTTP kernel (AP-05, D-07, D-08).
 *
 * This suite has no RefreshDatabase wrapper on purpose: the workers are other processes, so only committed rows
 * are visible to them and to the assertions. Every test commits an Admin, a client and a token and removes all of
 * it again in afterEach (foreign-key order), then asserts that nothing of it remains.
 *
 * The locked run proves that 8 simultaneous requests with one key leave one time entry, and that every answer is
 * the 201 of the winner, its byte-identical replay, or 409 idempotency_in_progress. The mutation run binds a
 * store that claims nothing and holds the 8 requests at a rendezvous until all have passed the claim, so the
 * database has no claim to fall back on and all 8 creates run. That run is also the proof that the harness can
 * see the race it guards against.
 */

const IDEMPOTENCY_CONCURRENCY_WORKERS = 8;
const IDEMPOTENCY_CONCURRENCY_BARRIER_SECONDS = 4.0;
const IDEMPOTENCY_CONCURRENCY_TIMEOUT_SECONDS = 120;
const IDEMPOTENCY_CONCURRENCY_ROUNDS = 3;
const IDEMPOTENCY_CONCURRENCY_MUTATION_ROUNDS = 2;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function idempotencyConcurrencyScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'client' => null,
        'admin' => null,
        'token' => null,
        'role_created' => false,
        'dirs' => [],
    ]);
}

/**
 * Commits an Admin, a client and a token with the write ability, the way a system run would.
 *
 * @return array{admin: string, client: string, token: string, token_id: string}
 */
function idempotencyConcurrencyArrange(): array
{
    $scratch = idempotencyConcurrencyScratch();

    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    $client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create());
    $scratch['client'] = $client->id;

    $plain = $admin->createToken('example '.bin2hex(random_bytes(3)), [ApiAbility::TimeWrite->value])->plainTextToken;
    $tokenId = explode('|', $plain, 2)[0];
    $scratch['token'] = $tokenId;

    return ['admin' => $admin->id, 'client' => $client->id, 'token' => $plain, 'token_id' => $tokenId];
}

/**
 * Removes everything the test committed, in foreign-key order, and returns the number of rows of its own that
 * are left behind (zero when the cleanup is complete).
 */
function idempotencyConcurrencyCleanup(): int
{
    $scratch = idempotencyConcurrencyScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];
    $token = $scratch['token'];

    if ($admin !== null) {
        $entryIds = DB::table('time_entries')->where('user_id', $admin)->pluck('id')->all();

        foreach (array_chunk($entryIds, 500) as $chunk) {
            DB::table('activity_log')->where('subject_type', 'time_entry')->whereIn('subject_id', $chunk)->delete();
        }

        DB::table('time_entries')->where('user_id', $admin)->delete();
    }

    if ($token !== null) {
        DB::table('api_idempotency_keys')->where('token_id', $token)->delete();
        DB::table('personal_access_tokens')->where('id', $token)->delete();
    }

    if ($client !== null) {
        DB::table('contacts')->where('client_id', $client)->delete();
        DB::table('activity_log')->where('subject_id', $client)->delete();
        DB::table('clients')->where('id', $client)->delete();
    }

    if ($admin !== null) {
        DB::table('model_has_roles')->where('model_id', $admin)->delete();
        DB::table('activity_log')->where('causer_id', $admin)->orWhere('subject_id', $admin)->delete();
        DB::table('users')->where('id', $admin)->delete();
    }

    if ($scratch['role_created']) {
        DB::table('roles')->where('name', RoleName::Admin->value)->where('guard_name', 'web')->delete();
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $left = 0;
    $left += $admin === null ? 0 : DB::table('time_entries')->where('user_id', $admin)->count();
    $left += $token === null ? 0 : DB::table('api_idempotency_keys')->where('token_id', $token)->count();
    $left += $token === null ? 0 : DB::table('personal_access_tokens')->where('id', $token)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();

    foreach ($scratch['dirs'] as $dir) {
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($dir)) {
            rmdir($dir);
        }

        $left += is_dir($dir) ? 1 : 0;
    }

    $scratch['client'] = $scratch['admin'] = $scratch['token'] = null;
    $scratch['role_created'] = false;
    $scratch['dirs'] = [];

    return $left;
}

/**
 * The environment of a worker: the test database of this process and the barrier.
 *
 * @return array<string, string>
 */
function idempotencyConcurrencyEnv(string $barrier): array
{
    $connection = (string) config('database.default');

    return [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
        'KOKPIT_BARRIER_AT' => $barrier,
    ];
}

/**
 * A fresh scratch directory for one rendezvous, removed again by the cleanup.
 */
function idempotencyConcurrencyRendezvousDir(): string
{
    $dir = sys_get_temp_dir().'/kokpit-idempotency-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    idempotencyConcurrencyScratch()['dirs'] = [...idempotencyConcurrencyScratch()['dirs'], $dir];

    return $dir;
}

/**
 * Starts the workers behind one shared barrier and waits for all of them.
 *
 * @param  'IdempotencyStore'|'UnclaimedIdempotencyStore'  $store
 * @return list<array{exit: int|null, summary: array{pid: int, status: int, replayed: bool, body: string, error: string|null}|null, stderr: string}>
 */
function idempotencyConcurrencyRun(string $token, string $key, string $clientId, int $workers, string $store, ?string $rendezvousDir = null): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $barrier = number_format(microtime(true) + IDEMPOTENCY_CONCURRENCY_BARRIER_SECONDS, 6, '.', '');
    $env = idempotencyConcurrencyEnv($barrier);

    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $process = new Process(
            [
                PHP_BINARY, __DIR__.'/idempotency-worker.php', $token, $key, $clientId, $store, $barrier,
                ...($rendezvousDir === null ? [] : [$rendezvousDir, (string) $workers]),
            ],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(IDEMPOTENCY_CONCURRENCY_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $decoded = json_decode(trim($process->getOutput()), true);

        $results[] = [
            'exit' => $process->getExitCode(),
            'summary' => is_array($decoded) && isset($decoded['pid'], $decoded['status'], $decoded['replayed'], $decoded['body']) ? $decoded : null,
            'stderr' => $process->getErrorOutput(),
        ];
    }

    return $results;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = idempotencyConcurrencyCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows of its own behind');
});

it('creates exactly one entry when 8 processes post the same create with the same key at the same moment', function () {
    ['admin' => $adminId, 'client' => $clientId, 'token' => $token, 'token_id' => $tokenId] = idempotencyConcurrencyArrange();

    for ($round = 1; $round <= IDEMPOTENCY_CONCURRENCY_ROUNDS; $round++) {
        $key = "concurrent-key-{$round}";
        $results = idempotencyConcurrencyRun($token, $key, $clientId, IDEMPOTENCY_CONCURRENCY_WORKERS, 'IdempotencyStore');

        foreach ($results as $result) {
            expect($result['summary'])->not->toBeNull("round {$round}: worker produced no summary: {$result['stderr']}")
                ->and($result['exit'])->toBe(0, "round {$round}: worker failed: {$result['stderr']} ".json_encode($result['summary']));
        }

        // Every worker really ran: eight distinct processes.
        $summaries = collect($results)->pluck('summary');
        expect($summaries->pluck('pid')->unique()->count())->toBe(IDEMPOTENCY_CONCURRENCY_WORKERS);

        // Every answer is the created entry (or its replay) or "the first request is still running".
        foreach ($summaries as $summary) {
            expect($summary['status'])->toBeIn([201, 409], "round {$round}: unexpected answer ".json_encode($summary));
        }

        $created = $summaries->where('status', 201);
        $original = $created->where('replayed', false);
        $busy = $summaries->where('status', 409);

        expect($original)->toHaveCount(1, "round {$round}: exactly one request must run the create")
            ->and($created->count() + $busy->count())->toBe(IDEMPOTENCY_CONCURRENCY_WORKERS);

        foreach ($busy as $summary) {
            expect(json_decode($summary['body'], true)['code'] ?? null)->toBe('idempotency_in_progress');
        }

        // A replay is the very answer of the original, byte for byte.
        $originalBody = $original->first()['body'];
        foreach ($created->where('replayed', true) as $summary) {
            expect($summary['body'])->toBe($originalBody);
        }

        expect(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe($round, "round {$round}: one entry per key")
            ->and(DB::table('api_idempotency_keys')->where('token_id', $tokenId)->where('key', $key)->whereNotNull('completed_at')->count())->toBe(1);
    }
});

it('creates 8 entries when the claim is removed and 8 processes post the same create with the same key (mutation run)', function () {
    ['admin' => $adminId, 'client' => $clientId, 'token' => $token] = idempotencyConcurrencyArrange();

    for ($round = 1; $round <= IDEMPOTENCY_CONCURRENCY_MUTATION_ROUNDS; $round++) {
        $results = idempotencyConcurrencyRun(
            $token,
            "unclaimed-key-{$round}",
            $clientId,
            IDEMPOTENCY_CONCURRENCY_WORKERS,
            'UnclaimedIdempotencyStore',
            idempotencyConcurrencyRendezvousDir(),
        );

        // Guard against a vacuous pass: every worker ran, was a distinct process and got the create through.
        foreach ($results as $result) {
            expect($result['summary'])->not->toBeNull("round {$round}: worker produced no summary: {$result['stderr']}")
                ->and($result['summary']['status'])->toBe(201, "round {$round}: ".json_encode($result['summary']))
                ->and($result['summary']['replayed'])->toBeFalse();
        }

        expect(collect($results)->pluck('summary.pid')->unique()->count())->toBe(IDEMPOTENCY_CONCURRENCY_WORKERS)
            ->and(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe(IDEMPOTENCY_CONCURRENCY_WORKERS * $round, "round {$round}: without the claim every request creates an entry")
            ->and(DB::table('api_idempotency_keys')->count())->toBe(0);
    }
});

it('refuses the rendezvous together with the real store, and a rendezvous of fewer than two parties', function () {
    ['admin' => $adminId, 'client' => $clientId, 'token' => $token] = idempotencyConcurrencyArrange();
    $dir = idempotencyConcurrencyRendezvousDir();
    $env = idempotencyConcurrencyEnv(number_format(microtime(true), 6, '.', ''));

    foreach ([['IdempotencyStore', '2'], ['UnclaimedIdempotencyStore', '1']] as [$store, $parties]) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/idempotency-worker.php', $token, 'refused-key', $clientId, $store, number_format(microtime(true), 6, '.', ''), $dir, $parties],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(IDEMPOTENCY_CONCURRENCY_TIMEOUT_SECONDS);
        $process->run();

        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('rendezvous');
    }

    expect(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe(0);
});

it('refuses to run against a database that is not a test database', function () {
    ['admin' => $adminId, 'client' => $clientId, 'token' => $token] = idempotencyConcurrencyArrange();

    $process = new Process(
        [PHP_BINARY, __DIR__.'/idempotency-worker.php', $token, 'refused-key', $clientId, 'IdempotencyStore', number_format(microtime(true), 6, '.', '')],
        dirname(__DIR__, 2),
        ['APP_ENV' => 'testing', 'DB_DATABASE' => 'scratch_db', 'DB_URL' => ''],
    );
    $process->setTimeout(IDEMPOTENCY_CONCURRENCY_TIMEOUT_SECONDS);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('_test')
        ->and(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe(0);
});
