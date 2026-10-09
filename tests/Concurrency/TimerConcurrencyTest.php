<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\StopTimer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

/*
 * Real parallel PHP processes starting timers through StartTimer (TI-07, D-02).
 *
 * This suite has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions.
 * Every test commits an Admin and a client and removes all of it again in
 * afterEach (foreign-key order), then asserts that nothing of it remains.
 *
 * The locked run proves that 200 starts of one user from 8 processes leave one
 * running timer and a gap-free chain of stopped ones. The mutation run removes
 * the advisory lock and forces every worker to read "nothing running" before any
 * of them inserts, so the partial unique index alone must let exactly one start
 * win and the other seven surface as the typed TimerRaceLost. That run is also the
 * proof that the harness can see the race it guards against.
 */

const TIMER_CONCURRENCY_WORKERS = 8;
const TIMER_CONCURRENCY_PER_WORKER = 25;
const TIMER_CONCURRENCY_BARRIER_SECONDS = 3.0;
const TIMER_CONCURRENCY_TIMEOUT_SECONDS = 120;
const TIMER_CONCURRENCY_MUTATION_ROUNDS = 5;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function timerConcurrencyScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'client' => null,
        'admin' => null,
        'role_created' => false,
        'dirs' => [],
    ]);
}

/**
 * Commits an Admin and a client, the way a system run would.
 *
 * @return array{admin: string, client: string}
 */
function timerConcurrencyArrange(): array
{
    $scratch = timerConcurrencyScratch();

    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    $client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create());
    $scratch['client'] = $client->id;

    return ['admin' => $admin->id, 'client' => $client->id];
}

/**
 * Removes everything the test committed, in foreign-key order, and returns the
 * number of rows of its own that are left behind (zero when the cleanup is complete).
 */
function timerConcurrencyCleanup(): int
{
    $scratch = timerConcurrencyScratch();
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($admin !== null) {
        $entryIds = DB::table('time_entries')->where('user_id', $admin)->pluck('id')->all();

        foreach (array_chunk($entryIds, 500) as $chunk) {
            DB::table('activity_log')->where('subject_type', 'time_entry')->whereIn('subject_id', $chunk)->delete();
        }

        DB::table('time_entries')->where('user_id', $admin)->delete();
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

    $scratch['client'] = $scratch['admin'] = null;
    $scratch['role_created'] = false;
    $scratch['dirs'] = [];

    return $left;
}

/**
 * The environment of a worker: the test database of this process and the barrier.
 *
 * @return array<string, string>
 */
function timerConcurrencyEnv(string $barrier): array
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
 * Starts the workers behind one shared barrier and waits for all of them.
 *
 * @param  'TimerLock'|'UnlockedTimerLock'  $lock
 * @return list<array{exit: int|null, summary: array{pid: int, started: int, raced: int, failed: int, last_error: string|null}|null, stderr: string}>
 */
function timerConcurrencyRun(string $adminId, string $clientId, int $workers, int $perWorker, string $lock, ?string $rendezvousDir = null): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $barrier = number_format(microtime(true) + TIMER_CONCURRENCY_BARRIER_SECONDS, 6, '.', '');
    $env = timerConcurrencyEnv($barrier);

    $processes = [];
    for ($i = 0; $i < $workers; $i++) {
        $process = new Process(
            [
                PHP_BINARY, __DIR__.'/timer-worker.php', $barrier, (string) $perWorker, $adminId, $clientId, $lock,
                ...($rendezvousDir === null ? [] : [$rendezvousDir, (string) $workers]),
            ],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(TIMER_CONCURRENCY_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $decoded = json_decode(trim($process->getOutput()), true);

        $results[] = [
            'exit' => $process->getExitCode(),
            'summary' => is_array($decoded) && isset($decoded['pid'], $decoded['started'], $decoded['raced'], $decoded['failed']) ? $decoded : null,
            'stderr' => $process->getErrorOutput(),
        ];
    }

    return $results;
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = timerConcurrencyCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows of its own behind');
});

it('leaves exactly one running timer and a gap-free chain when 8 processes start 25 timers each for one user', function () {
    ['admin' => $adminId, 'client' => $clientId] = timerConcurrencyArrange();
    $total = TIMER_CONCURRENCY_WORKERS * TIMER_CONCURRENCY_PER_WORKER;

    $results = timerConcurrencyRun($adminId, $clientId, TIMER_CONCURRENCY_WORKERS, TIMER_CONCURRENCY_PER_WORKER, 'TimerLock');

    foreach ($results as $result) {
        expect($result['exit'])->toBe(0, "worker failed: {$result['stderr']} ".json_encode($result['summary']))
            ->and($result['summary'])->not->toBeNull()
            ->and($result['summary']['started'] ?? null)->toBe(TIMER_CONCURRENCY_PER_WORKER)
            ->and($result['summary']['raced'] ?? null)->toBe(0)
            ->and($result['summary']['failed'] ?? null)->toBe(0);
    }

    // Every worker really ran: eight distinct processes.
    expect(collect($results)->pluck('summary.pid')->unique()->count())->toBe(TIMER_CONCURRENCY_WORKERS);

    expect(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe($total)
        ->and(DB::table('time_entries')->where('user_id', $adminId)->whereNull('ended_at')->count())->toBe(1)
        ->and(DB::table('time_entries')->where('user_id', $adminId)->whereNotNull('ended_at')->whereColumn('ended_at', '<', 'started_at')->count())->toBe(0);

    // No gap and no overlap: every stop instant is the start instant of another entry of the user.
    $unchained = DB::selectOne(
        'select count(*) as n from time_entries e where e.user_id = ? and e.ended_at is not null
            and not exists (select 1 from time_entries s where s.user_id = e.user_id and s.id <> e.id and s.started_at = e.ended_at)',
        [$adminId],
    );

    expect((int) $unchained->n)->toBe(0);
});

it('lets exactly one of 8 simultaneous starts win and reports the other 7 as a lost race without the lock (mutation run)', function () {
    ['admin' => $adminId, 'client' => $clientId] = timerConcurrencyArrange();
    $admin = User::query()->findOrFail($adminId);
    auth()->setUser($admin);

    for ($round = 1; $round <= TIMER_CONCURRENCY_MUTATION_ROUNDS; $round++) {
        // Each round starts with no running entry, so no party blocks on a row lock before the rendezvous.
        expect(DB::table('time_entries')->where('user_id', $adminId)->whereNull('ended_at')->count())->toBe(0, "round {$round} must start with nothing running");

        $dir = sys_get_temp_dir().'/kokpit-timer-'.bin2hex(random_bytes(6));
        mkdir($dir, 0700);
        timerConcurrencyScratch()['dirs'] = [...timerConcurrencyScratch()['dirs'], $dir];

        $results = timerConcurrencyRun($adminId, $clientId, TIMER_CONCURRENCY_WORKERS, 1, 'UnlockedTimerLock', $dir);

        // Guard against a vacuous pass: every worker ran, was a distinct process and reported a summary.
        foreach ($results as $result) {
            expect($result['summary'])->not->toBeNull("round {$round}: worker produced no summary: {$result['stderr']}")
                ->and($result['summary']['failed'])->toBe(0, "round {$round}: ".json_encode($result['summary']));
        }

        expect(collect($results)->pluck('summary.pid')->unique()->count())->toBe(TIMER_CONCURRENCY_WORKERS)
            ->and(collect($results)->sum('summary.started'))->toBe(1, "round {$round}: exactly one start must win")
            ->and(collect($results)->sum('summary.raced'))->toBe(TIMER_CONCURRENCY_WORKERS - 1, "round {$round}: every other start must lose the race")
            ->and(collect($results)->sum('summary.failed'))->toBe(0);

        // The lost races rolled back completely: one entry per round, and exactly one of them running.
        expect(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe($round)
            ->and(DB::table('time_entries')->where('user_id', $adminId)->whereNull('ended_at')->count())->toBe(1);

        app(StopTimer::class)->handle($admin);
    }

    expect(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe(TIMER_CONCURRENCY_MUTATION_ROUNDS)
        ->and(DB::table('time_entries')->where('user_id', $adminId)->whereNull('ended_at')->count())->toBe(0);
});

it('refuses the rendezvous together with the real lock or with more than one start', function () {
    ['admin' => $adminId, 'client' => $clientId] = timerConcurrencyArrange();

    $dir = sys_get_temp_dir().'/kokpit-timer-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    timerConcurrencyScratch()['dirs'] = [$dir];

    $env = timerConcurrencyEnv(number_format(microtime(true), 6, '.', ''));

    foreach ([['TimerLock', '1'], ['UnlockedTimerLock', '2']] as [$lock, $count]) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/timer-worker.php', number_format(microtime(true), 6, '.', ''), $count, $adminId, $clientId, $lock, $dir, '2'],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(TIMER_CONCURRENCY_TIMEOUT_SECONDS);
        $process->run();

        expect($process->getExitCode())->not->toBe(0)
            ->and($process->getErrorOutput())->toContain('rendezvous');
    }

    expect(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe(0);
});

it('refuses to run against a database that is not a test database', function () {
    ['admin' => $adminId, 'client' => $clientId] = timerConcurrencyArrange();

    $process = new Process(
        [PHP_BINARY, __DIR__.'/timer-worker.php', number_format(microtime(true), 6, '.', ''), '1', $adminId, $clientId, 'TimerLock'],
        dirname(__DIR__, 2),
        ['APP_ENV' => 'testing', 'DB_DATABASE' => 'scratch_db', 'DB_URL' => ''],
    );
    $process->setTimeout(TIMER_CONCURRENCY_TIMEOUT_SECONDS);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('_test')
        ->and(DB::table('time_entries')->where('user_id', $adminId)->count())->toBe(0);
});
