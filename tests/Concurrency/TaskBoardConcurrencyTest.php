<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Identity\RoleName;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Sequences\SequenceAllocator;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;
use Tests\Support\Canary;

/*
 * Real parallel PHP processes moving cards through MoveTask (KB-02).
 *
 * Two workers move 25 cards each into one column, first into a filled one and
 * then into an empty one. With the board advisory lock every move succeeds and
 * the columns keep unique positions; the same run against a board double that
 * skips the lock must show a defect, otherwise a clean run proves nothing.
 *
 * Like the task number suite, this file has no RefreshDatabase wrapper on
 * purpose: the workers are other processes, so only committed rows are visible
 * to them and to the assertions. Everything a test commits (client, project,
 * Admin, tasks and their activity rows, the counter row) is removed again, and
 * the cleanup reports what it could not remove.
 */

const BOARD_CONCURRENCY_PER_WORKER = 25;
const BOARD_CONCURRENCY_BARRIER_SECONDS = 3.0;
const BOARD_CONCURRENCY_TIMEOUT_SECONDS = 120;
const BOARD_CONCURRENCY_MUTATION_ROUNDS = 5;

/**
 * Bookkeeping for the rows committed by the running test.
 */
function boardConcurrencyScratch(): ArrayObject
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
 * Commits a client, a project, an Admin and the cards of the board: 50 in
 * planned, 50 in review, 5 in progress and none ready to release.
 *
 * @return array{project: string, admin: string, planned: list<string>, in_review: list<string>, in_progress: list<string>}
 */
function boardConcurrencyArrange(): array
{
    $scratch = boardConcurrencyScratch();

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

    $cards = ['planned' => 50, 'in_review' => 50, 'in_progress' => 5];
    $ids = [];

    foreach ($cards as $status => $count) {
        $ids[$status] = [];

        for ($i = 1; $i <= $count; $i++) {
            $task = app(PartnerContext::class)->runAsSystem(static fn (): Task => Task::factory()->create([
                'project_id' => $project->id,
                'status' => $status,
                'assignee_id' => $admin->id,
                'requester_id' => $admin->id,
            ]));

            $ids[$status][] = $task->id;
        }
    }

    return ['project' => $project->id, 'admin' => $admin->id, ...$ids];
}

/**
 * Removes everything the test committed, in foreign-key order, and returns the
 * number of rows of its own that are left behind (zero when the cleanup is complete).
 */
function boardConcurrencyCleanup(): int
{
    $scratch = boardConcurrencyScratch();
    $project = $scratch['project'];
    $client = $scratch['client'];
    $admin = $scratch['admin'];

    if ($project !== null) {
        $taskIds = DB::table('tasks')->where('project_id', $project)->pluck('id')->all();

        foreach (array_chunk($taskIds, 500) as $chunk) {
            DB::table('activity_log')->whereIn('subject_id', $chunk)->delete();
        }

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
    $left += $admin === null ? 0 : DB::table('activity_log')->where('causer_id', $admin)->count();

    $scratch['project'] = $scratch['client'] = $scratch['admin'] = null;
    $scratch['role_created'] = false;

    return $left;
}

/**
 * Starts one worker per assignment behind one shared barrier and waits for all
 * of them.
 *
 * @param  list<array{ids: list<string>, status: string, index: int}>  $assignments
 * @param  'TaskBoard'|'UnlockedTaskBoard'  $board
 * @return list<array{exit: int|null, summary: array{pid: int, moved: int, failed: int, last_error: string|null}|null, stderr: string}>
 */
function boardConcurrencyRun(string $projectId, string $adminId, array $assignments, string $board): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + BOARD_CONCURRENCY_BARRIER_SECONDS, 6, '.', '');

    $env = [
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

    $processes = [];
    foreach ($assignments as $assignment) {
        $process = new Process(
            [
                PHP_BINARY, __DIR__.'/task-worker.php', $barrier, (string) count($assignment['ids']), $projectId, $adminId,
                SequenceAllocator::class, 'locked', '0', 'move', $board, $assignment['status'], (string) $assignment['index'],
                implode(',', $assignment['ids']),
            ],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(BOARD_CONCURRENCY_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $decoded = json_decode(trim($process->getOutput()), true);

        $results[] = [
            'exit' => $process->getExitCode(),
            'summary' => is_array($decoded) && isset($decoded['pid'], $decoded['moved'], $decoded['failed']) ? $decoded : null,
            'stderr' => $process->getErrorOutput(),
        ];
    }

    return $results;
}

/**
 * The defects a round left behind, as readable lines (empty when the board is
 * consistent): a worker that crashed, failed a move or lost a move, and a
 * status column holding one position twice among the active, non-done tasks.
 *
 * @param  list<array{exit: int|null, summary: array{pid: int, moved: int, failed: int, last_error: string|null}|null, stderr: string}>  $results
 * @param  list<array{ids: list<string>, status: string, index: int}>  $assignments
 * @return list<string>
 */
function boardConcurrencyDefects(array $results, array $assignments, string $projectId): array
{
    $defects = [];

    foreach ($results as $i => $result) {
        $summary = $result['summary'];
        $attempts = count($assignments[$i]['ids']);

        if ($summary === null) {
            $defects[] = "worker {$i} produced no summary: {$result['stderr']}";

            continue;
        }

        if ($attempts !== $summary['moved'] + $summary['failed']) {
            $defects[] = "worker {$i} did not account for all of its attempts";
        }

        if ($summary['failed'] > 0) {
            $defects[] = "worker {$i} failed {$summary['failed']} moves: {$summary['last_error']}";
        }

        $landed = DB::table('tasks')->whereIn('id', $assignments[$i]['ids'])->where('status', $assignments[$i]['status'])->count();

        if ($landed !== $summary['moved']) {
            $defects[] = "worker {$i} reported {$summary['moved']} moves but {$landed} cards sit in {$assignments[$i]['status']}";
        }
    }

    $duplicates = DB::table('tasks')
        ->where('project_id', $projectId)
        ->whereNull('deleted_at')
        ->where('status', '<>', 'done')
        ->groupBy('status', 'position')
        ->havingRaw('count(*) > 1')
        ->get(['status', 'position']);

    foreach ($duplicates as $duplicate) {
        $defects[] = "duplicate position {$duplicate->position} in {$duplicate->status}";
    }

    return $defects;
}

/**
 * Both rounds of the scenario against the given board: round A moves the
 * planned and the review cards into the filled in-progress column at index 1,
 * round B the next cards into the empty ready-to-release column at index 0.
 *
 * @param  array{project: string, admin: string, planned: list<string>, in_review: list<string>, in_progress: list<string>}  $arranged
 * @param  'TaskBoard'|'UnlockedTaskBoard'  $board
 * @return array{defects: list<string>, rounds: list<array{assignments: list<array{ids: list<string>, status: string, index: int}>, results: list<array{exit: int|null, summary: array{pid: int, moved: int, failed: int, last_error: string|null}|null, stderr: string}>}>}
 */
function boardConcurrencyScenario(array $arranged, string $board): array
{
    $perWorker = BOARD_CONCURRENCY_PER_WORKER;
    $rounds = [];
    $defects = [];

    $plan = [
        [
            ['ids' => array_slice($arranged['planned'], 0, $perWorker), 'status' => 'in_progress', 'index' => 1],
            ['ids' => array_slice($arranged['in_review'], 0, $perWorker), 'status' => 'in_progress', 'index' => 1],
        ],
        [
            ['ids' => array_slice($arranged['planned'], $perWorker, $perWorker), 'status' => 'ready_to_release', 'index' => 0],
            ['ids' => array_slice($arranged['in_review'], $perWorker, $perWorker), 'status' => 'ready_to_release', 'index' => 0],
        ],
    ];

    foreach ($plan as $assignments) {
        $results = boardConcurrencyRun($arranged['project'], $arranged['admin'], $assignments, $board);

        $rounds[] = ['assignments' => $assignments, 'results' => $results];
        $defects = [...$defects, ...boardConcurrencyDefects($results, $assignments, $arranged['project'])];
    }

    return ['defects' => $defects, 'rounds' => $rounds];
}

/**
 * The positions of the active, non-done tasks of one column, ordered.
 *
 * @return list<int>
 */
function boardConcurrencyPositions(string $projectId, string $status): array
{
    return array_map(
        intval(...),
        DB::table('tasks')->where('project_id', $projectId)->whereNull('deleted_at')->where('status', $status)->orderBy('position')->pluck('position')->all(),
    );
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $left = boardConcurrencyCleanup();

    expect($left)->toBe(0, 'the concurrency test left rows of its own behind');
});

it('keeps every column consistent when two processes move 25 cards each into a filled and into an empty column', function () {
    $arranged = boardConcurrencyArrange();
    $projectId = $arranged['project'];

    $outcome = boardConcurrencyScenario($arranged, 'TaskBoard');

    expect($outcome['defects'])->toBe([]);

    // Every worker really ran: two distinct processes per round, each with all of its moves done.
    foreach ($outcome['rounds'] as $round) {
        expect($round['results'])->toHaveCount(2)
            ->and(collect($round['results'])->pluck('summary.pid')->unique()->count())->toBe(2)
            ->and(collect($round['results'])->every(static fn (array $result): bool => $result['exit'] === 0 && $result['summary']['moved'] === BOARD_CONCURRENCY_PER_WORKER))->toBeTrue();
    }

    // The filled column: the 5 old cards and 50 moved ones, positions 0..54 with no hole and no duplicate.
    // The empty column: 50 moved cards, positions 0..49.
    expect(boardConcurrencyPositions($projectId, 'in_progress'))->toBe(range(0, 54))
        ->and(boardConcurrencyPositions($projectId, 'ready_to_release'))->toBe(range(0, 49));

    // The two rounds emptied the source columns, and no card was lost on the way.
    expect(boardConcurrencyPositions($projectId, 'planned'))->toBe([])
        ->and(boardConcurrencyPositions($projectId, 'in_review'))->toBe([])
        ->and(DB::table('tasks')->where('project_id', $projectId)->count())->toBe(105);
});

it('detects the defect when the board has no advisory lock (mutation run)', function () {
    $found = [];
    $rounds = 0;

    for ($attempt = 1; $attempt <= BOARD_CONCURRENCY_MUTATION_ROUNDS && $found === []; $attempt++) {
        $arranged = boardConcurrencyArrange();

        $outcome = boardConcurrencyScenario($arranged, 'UnlockedTaskBoard');
        $rounds++;

        // Guard against a vacuous pass: both workers of every round ran and accounted for all of their attempts.
        foreach ($outcome['rounds'] as $round) {
            foreach ($round['results'] as $i => $result) {
                expect($result['summary'])->not->toBeNull("worker produced no summary: {$result['stderr']}")
                    ->and($result['summary']['moved'] + $result['summary']['failed'])->toBe(count($round['assignments'][$i]['ids']));
            }
        }

        $found = $outcome['defects'];

        expect(boardConcurrencyCleanup())->toBe(0, 'the mutation round left rows of its own behind');
    }

    expect($found)->not->toBe([], "the harness did not notice the missing board lock in {$rounds} rounds");
});
