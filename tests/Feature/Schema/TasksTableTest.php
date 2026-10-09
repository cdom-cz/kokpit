<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Enums\ProjectPriority;
use App\Domain\Projects\Enums\ProjectStatus;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RawSql;

/*
 * Database-level invariants of the tasks table (TA-01, TA-02, D-01, D-02, D-11).
 * Every case uses raw SQL, so no model, cast, Action or form rule stands between
 * the value and the constraint. Values are fictional.
 *
 * RefreshDatabase never commits, so the deferred position exclusion is only
 * checked where a case runs SET CONSTRAINTS ... IMMEDIATE itself.
 */

/**
 * A fresh project row (factory) and its id.
 */
function tasksSchemaProjectId(): string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): string => Project::factory()->create()->id);
}

/**
 * Inserts one task row with valid defaults; overrides replace single columns. The
 * number, reference and position are unique per call unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 */
function tasksSchemaInsert(array $overrides = []): string
{
    static $counter = 0;
    $counter++;

    $row = [
        'id' => (string) Str::uuid7(),
        'project_id' => test()->tasksSchemaProjectId,
        'parent_id' => null,
        'depth' => 0,
        'number' => $counter,
        'reference' => 'TT-'.$counter,
        'title' => 'Example task '.$counter,
        'status' => 'planned',
        'priority' => 'normal',
        'position' => $counter,
        'assignee_id' => test()->tasksSchemaUserId,
        'requester_id' => test()->tasksSchemaUserId,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ];

    $columns = array_keys($row);

    DB::insert(
        sprintf('INSERT INTO tasks (%s) VALUES (%s)', implode(', ', $columns), implode(', ', array_fill(0, count($columns), '?'))),
        array_values($row),
    );

    return (string) $row['id'];
}

beforeEach(function (): void {
    $this->tasksSchemaUserId = User::factory()->create()->id;
    $this->tasksSchemaProjectId = tasksSchemaProjectId();
});

/**
 * Makes the deferred position exclusion check what was written so far.
 */
function tasksSchemaCheckPositions(): void
{
    DB::statement('SET CONSTRAINTS tasks_position_exclusion IMMEDIATE');
}

it('refuses a second task with the same reference with SQLSTATE 23505', function (): void {
    tasksSchemaInsert(['reference' => 'TT-900', 'number' => 900]);

    RawSql::expectSqlState('23505', fn () => tasksSchemaInsert(['reference' => 'TT-900', 'number' => 901]));
});

it('refuses a second task with the same number in one project with SQLSTATE 23505', function (): void {
    $projectId = tasksSchemaProjectId();
    tasksSchemaInsert(['project_id' => $projectId, 'number' => 7, 'reference' => 'TT-7']);

    RawSql::expectSqlState('23505', fn () => tasksSchemaInsert(['project_id' => $projectId, 'number' => 7, 'reference' => 'TT-8']));
});

it('allows the same number in two projects', function (): void {
    tasksSchemaInsert(['project_id' => tasksSchemaProjectId(), 'number' => 5, 'reference' => 'AA-5']);

    RawSql::expectAllowed(fn () => tasksSchemaInsert(['project_id' => tasksSchemaProjectId(), 'number' => 5, 'reference' => 'BB-5']));
});

it('refuses a row that breaks a CHECK constraint with SQLSTATE 23514', function (string $overrides): void {
    $columns = json_decode($overrides, true, flags: JSON_THROW_ON_ERROR);

    RawSql::expectSqlState('23514', fn () => tasksSchemaInsert($columns));
})->with([
    'status outside the list' => ['{"status": "blocked"}'],
    'priority outside the list' => ['{"priority": "critical"}'],
    'depth 1 without a parent' => ['{"depth": 1}'],
    'number 0' => ['{"number": 0}'],
    'lower-case reference' => ['{"reference": "abc-1"}'],
    'seven-letter key in the reference' => ['{"reference": "ABCDEFG-1"}'],
    'reference without a number' => ['{"reference": "ABC-"}'],
    'due date before start date' => ['{"start_date": "2026-05-10", "due_date": "2026-05-09"}'],
    'done without completion time' => ['{"status": "done"}'],
    'completion time on a task that is not done' => ['{"status": "planned", "completed_at": "2026-05-09 10:00:00+00"}'],
    'escalation without its author' => ['{"escalated_at": "2026-05-09 10:00:00+00"}'],
    'negative position' => ['{"position": -1}'],
]);

it('refuses an escalation author without the escalation time with SQLSTATE 23514', function (): void {
    $userId = User::factory()->create()->id;

    RawSql::expectSqlState('23514', fn () => tasksSchemaInsert(['escalated_by_id' => $userId]));
});

it('refuses a depth 0 row that names a parent with SQLSTATE 23514', function (): void {
    $parentId = tasksSchemaInsert();

    RawSql::expectSqlState('23514', fn () => tasksSchemaInsert(['parent_id' => $parentId, 'depth' => 0]));
});

it('accepts a due date on the start date and a done task with its completion time', function (): void {
    RawSql::expectAllowed(fn () => tasksSchemaInsert(['start_date' => '2026-05-10', 'due_date' => '2026-05-10']));
    RawSql::expectAllowed(fn () => tasksSchemaInsert(['status' => 'done', 'completed_at' => '2026-05-10 10:00:00+00']));
    RawSql::expectAllowed(fn () => tasksSchemaInsert([
        'escalated_at' => '2026-05-10 10:00:00+00',
        'escalated_by_id' => User::factory()->create()->id,
    ]));
});

it('accepts a subtask of a root task of the same project (one level)', function (): void {
    $projectId = tasksSchemaProjectId();
    $rootId = tasksSchemaInsert(['project_id' => $projectId]);

    RawSql::expectAllowed(fn () => tasksSchemaInsert(['project_id' => $projectId, 'parent_id' => $rootId, 'depth' => 1]));
});

it('refuses a sub-subtask with SQLSTATE 23503', function (): void {
    $projectId = tasksSchemaProjectId();
    $rootId = tasksSchemaInsert(['project_id' => $projectId]);
    $subId = tasksSchemaInsert(['project_id' => $projectId, 'parent_id' => $rootId, 'depth' => 1]);

    RawSql::expectSqlState('23503', fn () => tasksSchemaInsert(['project_id' => $projectId, 'parent_id' => $subId, 'depth' => 1]));
});

it('refuses a subtask whose parent belongs to another project with SQLSTATE 23503', function (): void {
    $rootId = tasksSchemaInsert(['project_id' => tasksSchemaProjectId()]);

    RawSql::expectSqlState('23503', fn () => tasksSchemaInsert(['project_id' => tasksSchemaProjectId(), 'parent_id' => $rootId, 'depth' => 1]));
});

it('refuses a subtask of a parent that does not exist with SQLSTATE 23503', function (): void {
    RawSql::expectSqlState('23503', fn () => tasksSchemaInsert(['parent_id' => (string) Str::uuid7(), 'depth' => 1]));
});

it('refuses turning a parent that has subtasks into a subtask with SQLSTATE 23503', function (): void {
    $projectId = tasksSchemaProjectId();
    $parentId = tasksSchemaInsert(['project_id' => $projectId]);
    $otherRootId = tasksSchemaInsert(['project_id' => $projectId]);
    tasksSchemaInsert(['project_id' => $projectId, 'parent_id' => $parentId, 'depth' => 1]);

    RawSql::expectSqlState('23503', fn () => DB::update('UPDATE tasks SET parent_id = ?, depth = 1 WHERE id = ?', [$otherRootId, $parentId]));
});

it('refuses a hard delete of a parent that has subtasks with SQLSTATE 23001', function (): void {
    $projectId = tasksSchemaProjectId();
    $parentId = tasksSchemaInsert(['project_id' => $projectId]);
    tasksSchemaInsert(['project_id' => $projectId, 'parent_id' => $parentId, 'depth' => 1]);

    RawSql::expectSqlState('23001', fn () => DB::delete('DELETE FROM tasks WHERE id = ?', [$parentId]));
});

it('refuses a hard delete of a project that has tasks with SQLSTATE 23001', function (): void {
    $projectId = tasksSchemaProjectId();
    tasksSchemaInsert(['project_id' => $projectId]);

    RawSql::expectSqlState('23001', fn () => DB::delete('DELETE FROM projects WHERE id = ?', [$projectId]));
});

it('refuses two active planned tasks at one position with SQLSTATE 23P01 once the constraint is checked', function (): void {
    RawSql::expectSqlState('23P01', function (): void {
        tasksSchemaInsert(['position' => 4000]);
        tasksSchemaInsert(['position' => 4000]);

        tasksSchemaCheckPositions();
    });
});

it('allows the same position in two different status columns', function (): void {
    RawSql::expectAllowed(function (): void {
        tasksSchemaInsert(['status' => 'planned', 'position' => 4001]);
        tasksSchemaInsert(['status' => 'in_progress', 'position' => 4001]);

        tasksSchemaCheckPositions();
    });
});

it('lets Done tasks share a position, they hold no slot', function (): void {
    RawSql::expectAllowed(function (): void {
        tasksSchemaInsert(['status' => 'done', 'completed_at' => now(), 'position' => 0]);
        tasksSchemaInsert(['status' => 'done', 'completed_at' => now(), 'position' => 0]);

        tasksSchemaCheckPositions();
    });
});

it('lets an archived task share the position of an active one, it holds no slot', function (): void {
    RawSql::expectAllowed(function (): void {
        tasksSchemaInsert(['position' => 4002]);
        tasksSchemaInsert(['position' => 4002, 'deleted_at' => now()]);

        tasksSchemaCheckPositions();
    });
});

it('lets sequential position rewrites collide in between and resolve inside one transaction (deferral)', function (): void {
    $first = tasksSchemaInsert(['position' => 4101]);
    $second = tasksSchemaInsert(['position' => 4102]);
    $third = tasksSchemaInsert(['position' => 4103]);
    tasksSchemaCheckPositions();

    // Back to the deferred default for the rewrite, as inside a normal transaction.
    DB::statement('SET CONSTRAINTS tasks_position_exclusion DEFERRED');

    RawSql::expectAllowed(function () use ($first, $second, $third): void {
        // Every statement collides with a neighbour; only the end state is unique.
        DB::update('UPDATE tasks SET position = 4102 WHERE id = ?', [$first]);
        DB::update('UPDATE tasks SET position = 4103 WHERE id = ?', [$second]);
        DB::update('UPDATE tasks SET position = 4101 WHERE id = ?', [$third]);

        tasksSchemaCheckPositions();
    });

    expect(DB::table('tasks')->whereIn('id', [$first, $second, $third])->orderBy('position')->pluck('id')->all())
        ->toBe([$third, $first, $second]);
});

it('refuses a rewrite that ends with a duplicate position with SQLSTATE 23P01', function (): void {
    $first = tasksSchemaInsert(['position' => 4201]);
    tasksSchemaInsert(['position' => 4202]);
    tasksSchemaCheckPositions();
    DB::statement('SET CONSTRAINTS tasks_position_exclusion DEFERRED');

    RawSql::expectSqlState('23P01', function () use ($first): void {
        DB::update('UPDATE tasks SET position = 4202 WHERE id = ?', [$first]);

        tasksSchemaCheckPositions();
    });
});

it('pins the status and priority CHECK lists against the project enums (D-01)', function (): void {
    $listOf = static function (string $constraint): array {
        $definition = (string) DB::scalar('SELECT pg_get_constraintdef(oid) FROM pg_constraint WHERE conname = ?', [$constraint]);
        preg_match_all("/'([a-z_]+)'::/", $definition, $matches);

        $values = $matches[1];
        sort($values, SORT_STRING);

        return $values;
    };

    $statuses = array_map(static fn (ProjectStatus $case): string => $case->value, ProjectStatus::cases());
    $priorities = array_map(static fn (ProjectPriority $case): string => $case->value, ProjectPriority::cases());
    sort($statuses, SORT_STRING);
    sort($priorities, SORT_STRING);

    expect($listOf('tasks_status_check'))->toBe($statuses)->toBe($listOf('projects_status_check'))
        ->and($listOf('tasks_priority_check'))->toBe($priorities)->toBe($listOf('projects_priority_check'));
});
