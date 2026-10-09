<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\Canary;
use Tests\Support\UnlockedSequenceAllocator;

/*
 * Real parallel PHP processes creating tasks through CreateTask (TA-02).
 *
 * This suite has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions.
 * Every test commits a client, a project with a random fictional key and an
 * Admin, and removes all of it again in afterEach (foreign-key order), then
 * asserts that nothing of it remains.
 *
 * A passing run proves gap-free numbering only because the mutation test shows
 * the same harness failing once the counter row lock is removed. The board
 * advisory lock serializes every creation by itself, so the mutation run
 * removes it as well and widens the window between reading and advancing the
 * counter; otherwise the defect would stay hidden behind the board lock.
 */

const TASK_CONCURRENCY_WORKERS = 8;
const TASK_CONCURRENCY_PER_WORKER = 25;
const TASK_CONCURRENCY_BARRIER_SECONDS = 3.0;
const TASK_CONCURRENCY_TIMEOUT_SECONDS = 120;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function taskConcurrencyScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject([
        'client' => null,
        'project' => null,
        'admin' => null,
        'role_created' => false,
    ]);
}

/**
 * Commits a client, a project and an Admin, the way a system run would.
 *
 * @return array{project: string, key: string, admin: string}
 */
function taskConcurrencyArrange(): array
{
    $scratch = taskConcurrencyScratch();

    $scratch['role_created'] = ! Role::query()->where('name', RoleName::Admin->value)->where('guard_name', 'web')->exists();
    Role::findOrCreate(RoleName::Admin->value, 'web');

    $admin = User::factory()->create(['email' => exampleEmail()]);
    $admin->assignRole(RoleName::Admin->value);
    $scratch['admin'] = $admin->id;

    $client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create());
    $scratch['client'] = $client->id;

    $project = app(PartnerContext::class)->runAsSystem(static fn (): Project => Project::factory()->create([
        'client_id' => $client->id,
        'key' => Canary::projectKey(),
    ]));
    $scratch['project'] = $project->id;

    return ['project' => $project->id, 'key' => $project->key, 'admin' => $admin->id];
}

/**
 * Removes everything the test committed, in foreign-key order, and returns the
 * number of rows of its own that are left behind (zero when the cleanup is complete).
 */
function taskConcurrencyCleanup(): int
{
    $scratch = taskConcurrencyScratch();
    $project = $scratch['project'];
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($project !== null) {
        DB::table('tasks')->where('project_id', $project)->whereNotNull('parent_id')->delete();
        DB::table('tasks')->where('project_id', $project)->delete();
        DB::table('number_sequences')->where('scope_key', 'task:'.$project)->delete();
        DB::table('project_billing')->where('project_id', $project)->delete();
        DB::table('activity_log')->where('subject_id', $project)->delete();
        DB::table('projects')->where('id', $project)->delete();
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
    $left += $project === null ? 0 : DB::table('tasks')->where('project_id', $project)->count();
    $left += $project === null ? 0 : DB::table('number_sequences')->where('scope_key', 'task:'.$project)->count();
    $left += $project === null ? 0 : DB::table('projects')->where('id', $project)->count();
    $left += $client === null ? 0 : DB::table('clients')->where('id', $client)->count();
    $left += $admin === null ? 0 : DB::table('users')->where('id', $admin)->count();
    $left += $admin === null ? 0 : DB::table('model_has_roles')->where('model_id', $admin)->count();

    $scratch['project'] = $scratch['client'] = $scratch['admin'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * Starts the workers behind one shared barrier and waits for all of them.
 *
 * @param  class-string<SequenceAllocator>  $allocator
 * @param  array<string, string>  $envOverrides
 * @return list<array{exit: int|null, summary: array{pid: int, created: int, failed: int, last_error: string|null}|null, stderr: string}>
 */
function taskConcurrencyRun(string $projectId, string $adminId, string $allocator, string $board = 'locked', int $widenMicros = 0, array $envOverrides = []): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + TASK_CONCURRENCY_BARRIER_SECONDS, 6, '.', '');

    $env = array_merge([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
        'KOKPIT_BARRIER_AT' => $barrier,
    ], $envOverrides);

    $processes = [];
    for ($i = 0; $i < TASK_CONCURRENCY_WORKERS; $i++) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/task-worker.php', $barrier, (string) TASK_CONCURRENCY_PER_WORKER, $projectId, $adminId, $allocator, $board, (string) $widenMicros],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(TASK_CONCURRENCY_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $decoded = json_decode(trim($process->getOutput()), true);

        $results[] = [
            'exit' => $process->getExitCode(),
            'summary' => is_array($decoded) && isset($decoded['pid'], $decoded['created'], $decoded['failed']) ? $decoded : null,
            'stderr' => $process->getErrorOutput(),
        ];
    }

    return $results;
}

/**
 * Asserts that every worker created all of its tasks and the project holds
 * exactly the numbers 1..200 with matching references and distinct positions.
 *
 * @param  list<array{exit: int|null, summary: array{pid: int, created: int, failed: int, last_error: string|null}|null, stderr: string}>  $results
 */
function taskConcurrencyExpectGapFree(array $results, string $projectId, string $key): void
{
    $total = TASK_CONCURRENCY_WORKERS * TASK_CONCURRENCY_PER_WORKER;

    foreach ($results as $result) {
        expect($result['exit'])->toBe(0, "worker failed: {$result['stderr']} ".json_encode($result['summary']))
            ->and($result['summary'])->not->toBeNull()
            ->and($result['summary']['created'] ?? null)->toBe(TASK_CONCURRENCY_PER_WORKER)
            ->and($result['summary']['failed'] ?? null)->toBe(0);
    }

    $rows = DB::table('tasks')->where('project_id', $projectId)->orderBy('number')->get(['number', 'reference', 'position']);

    expect($rows)->toHaveCount($total)
        ->and($rows->pluck('number')->map(static fn (mixed $n): int => (int) $n)->all())->toBe(range(1, $total))
        ->and($rows->every(static fn (object $row): bool => $row->reference === $key.'-'.$row->number))->toBeTrue()
        ->and($rows->pluck('position')->unique()->count())->toBe($total)
        ->and((int) DB::table('number_sequences')->where('scope_key', 'task:'.$projectId)->value('next_value'))->toBe($total + 1);
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = taskConcurrencyCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows of its own behind');
});

it('hands out exactly 1..200 to 8 parallel workers creating tasks with no duplicate and no gap', function () {
    ['project' => $projectId, 'key' => $key, 'admin' => $adminId] = taskConcurrencyArrange();

    $results = taskConcurrencyRun($projectId, $adminId, SequenceAllocator::class);

    taskConcurrencyExpectGapFree($results, $projectId, $key);

    expect(collect($results)->pluck('summary.pid')->unique()->count())->toBe(TASK_CONCURRENCY_WORKERS);
});

it('keeps the numbers gap-free on the counter row lock alone, without the board lock and with a widened race window', function () {
    ['project' => $projectId, 'key' => $key, 'admin' => $adminId] = taskConcurrencyArrange();

    $results = taskConcurrencyRun($projectId, $adminId, SequenceAllocator::class, 'unlocked', 2000);

    taskConcurrencyExpectGapFree($results, $projectId, $key);
});

it('detects the defect when the allocator has no row lock (mutation run)', function () {
    ['project' => $projectId, 'admin' => $adminId] = taskConcurrencyArrange();
    $total = TASK_CONCURRENCY_WORKERS * TASK_CONCURRENCY_PER_WORKER;

    $results = taskConcurrencyRun($projectId, $adminId, UnlockedSequenceAllocator::class, 'unlocked', 3000);

    // Guard against a vacuous pass: every worker must have run and accounted for all of its attempts.
    $created = 0;
    $failed = 0;
    foreach ($results as $result) {
        expect($result['summary'])->not->toBeNull("worker produced no summary: {$result['stderr']}")
            ->and($result['summary']['created'] + $result['summary']['failed'])->toBe(TASK_CONCURRENCY_PER_WORKER);

        $created += $result['summary']['created'];
        $failed += $result['summary']['failed'];
    }

    $rows = DB::table('tasks')->where('project_id', $projectId)->count();
    $numbers = DB::table('tasks')->where('project_id', $projectId)->distinct()->count('number');

    expect($created + $failed)->toBe($total)
        ->and($rows)->toBe($created)
        ->and($numbers)->toBe($rows);

    // The database still refuses a duplicate, so the defect shows up as failed creations and missing tasks.
    expect($failed)->toBeGreaterThan(0, 'the harness did not notice the missing row lock')
        ->and($rows)->toBeLessThan($total);
});

it('refuses to run against a database that is not a test database', function () {
    ['project' => $projectId, 'admin' => $adminId] = taskConcurrencyArrange();

    $process = new Process(
        [PHP_BINARY, __DIR__.'/task-worker.php', number_format(microtime(true), 6, '.', ''), '1', $projectId, $adminId, SequenceAllocator::class],
        dirname(__DIR__, 2),
        ['APP_ENV' => 'testing', 'DB_DATABASE' => 'scratch_db', 'DB_URL' => ''],
    );
    $process->setTimeout(TASK_CONCURRENCY_TIMEOUT_SECONDS);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('_test')
        ->and(DB::table('tasks')->where('project_id', $projectId)->count())->toBe(0);
});
