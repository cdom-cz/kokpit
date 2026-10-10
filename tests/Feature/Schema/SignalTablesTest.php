<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * The planner tables refuse what the Actions and the owner scope cannot: every rule below is a
 * database constraint, so a forged request or a second writer cannot get around it. Each case runs
 * in its own savepoint, because a failed statement aborts a PostgreSQL transaction.
 */

/**
 * Runs the statement and returns the SQLSTATE it failed with, or null when it succeeded.
 */
function signalSchemaState(Closure $statement): ?string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $exception) {
        return $exception->errorInfo[0] ?? null;
    }

    return null;
}

/**
 * @param  array<string, mixed>  $overrides
 */
function signalSchemaTask(string $userId, array $overrides = []): bool
{
    return DB::table('signal_tasks')->insert(array_merge([
        'user_id' => $userId,
        'title' => 'Example task',
        'for_date' => '2026-10-12',
        'category' => 'main',
        'is_done' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

/**
 * @param  array<string, mixed>  $overrides
 */
function signalSchemaTemplate(string $userId, array $overrides = []): string
{
    $id = (string) Str::uuid7();

    DB::table('signal_recurring_tasks')->insert(array_merge([
        'id' => $id,
        'user_id' => $userId,
        'title' => 'Example template',
        'category' => 'main',
        'weekday_mask' => 1,
        'active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));

    return $id;
}

beforeEach(function (): void {
    $this->userId = User::factory()->create()->id;
});

it('gives every planner table a version 7 id from the column default', function (): void {
    signalSchemaTask($this->userId);

    expect(DB::table('signal_tasks')->value('id'))->toMatch(\Tests\Support\Uuids::V7_PATTERN);
});

it('refuses a task with a blank title, an unknown colour or a done flag that disagrees with its time', function (): void {
    expect(signalSchemaState(fn () => signalSchemaTask($this->userId, ['title' => '   '])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId, ['category' => 'urgent'])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId, ['is_done' => true])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId, ['is_done' => false, 'completed_at' => now()])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId, ['is_done' => true, 'completed_at' => now()])))->toBeNull();
});

it('refuses a template with a grey colour, an empty or out-of-range weekday mask or a blank title', function (): void {
    expect(signalSchemaState(fn () => signalSchemaTemplate($this->userId, ['category' => 'extra'])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTemplate($this->userId, ['weekday_mask' => 0])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTemplate($this->userId, ['weekday_mask' => 128])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTemplate($this->userId, ['title' => ''])))->toBe('23514')
        ->and(signalSchemaState(fn () => signalSchemaTemplate($this->userId, ['weekday_mask' => 127])))->toBeNull();
});

it('lets a template yield one task a day, whoever inserts it', function (): void {
    $template = signalSchemaTemplate($this->userId);

    expect(signalSchemaState(fn () => signalSchemaTask($this->userId, ['recurring_id' => $template])))->toBeNull()
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId, ['recurring_id' => $template])))->toBe('23505')
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId, ['recurring_id' => $template, 'for_date' => '2026-10-13'])))->toBeNull()
        // Tasks without a template are not limited.
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId)))->toBeNull()
        ->and(signalSchemaState(fn () => signalSchemaTask($this->userId)))->toBeNull();
});

it('refuses a task that points to the template of another user', function (): void {
    $other = User::factory()->create()->id;
    $template = signalSchemaTemplate($other);

    expect(signalSchemaState(fn () => signalSchemaTask($this->userId, ['recurring_id' => $template])))->toBe('23503');
});

it('nulls only the template link of a task when the template is deleted', function (): void {
    $template = signalSchemaTemplate($this->userId);
    signalSchemaTask($this->userId, ['recurring_id' => $template]);

    DB::table('signal_recurring_tasks')->where('id', $template)->delete();

    $task = DB::table('signal_tasks')->first();

    expect($task->recurring_id)->toBeNull()->and($task->user_id)->toBe($this->userId);
});

it('keeps the deep-work blocks of a day within 0 and the frozen plan, one row per user and day', function (): void {
    $insert = fn (array $row) => DB::table('signal_deep_work_days')->insert(array_merge(['user_id' => $this->userId, 'for_date' => '2026-10-12', 'planned' => 3, 'completed' => 0, 'created_at' => now(), 'updated_at' => now()], $row));

    expect(signalSchemaState(fn () => $insert(['completed' => 4])))->toBe('23514')
        ->and(signalSchemaState(fn () => $insert(['completed' => -1])))->toBe('23514')
        ->and(signalSchemaState(fn () => $insert(['planned' => 13, 'completed' => 0])))->toBe('23514')
        ->and(signalSchemaState(fn () => $insert(['completed' => 3])))->toBeNull()
        ->and(signalSchemaState(fn () => $insert(['completed' => 1])))->toBe('23505');
});

it('keeps at most one settings row and one unlock row per user and day', function (): void {
    $settings = fn (array $row = []) => DB::table('signal_settings')->insert(array_merge(['user_id' => $this->userId, 'created_at' => now(), 'updated_at' => now()], $row));
    $unlock = fn () => DB::table('signal_day_overrides')->insert(['user_id' => $this->userId, 'for_date' => '2026-10-11', 'created_at' => now(), 'updated_at' => now()]);

    expect(signalSchemaState(fn () => $settings(['deep_work_weekday_blocks' => 13])))->toBe('23514')
        ->and(signalSchemaState(fn () => $settings()))->toBeNull()
        ->and(signalSchemaState(fn () => $settings()))->toBe('23505')
        ->and(signalSchemaState($unlock))->toBeNull()
        ->and(signalSchemaState($unlock))->toBe('23505');
});

it('holds goals only at positions 1 to 3 of a week that starts on a Monday', function (): void {
    $goal = fn (array $row) => DB::table('signal_weekly_goals')->insert(array_merge(['user_id' => $this->userId, 'week_start' => '2026-10-12', 'title' => 'Example goal', 'position' => 1, 'created_at' => now(), 'updated_at' => now()], $row));

    expect(signalSchemaState(fn () => $goal(['position' => 4])))->toBe('23514')
        ->and(signalSchemaState(fn () => $goal(['position' => 0])))->toBe('23514')
        ->and(signalSchemaState(fn () => $goal(['week_start' => '2026-10-14'])))->toBe('23514')
        ->and(signalSchemaState(fn () => $goal(['title' => ' '])))->toBe('23514')
        ->and(signalSchemaState(fn () => $goal([])))->toBeNull();
});

it('checks the goal position key at commit, so a reorder may pass through a duplicate', function (): void {
    $goal = fn (int $position) => DB::table('signal_weekly_goals')->insert(['user_id' => $this->userId, 'week_start' => '2026-10-12', 'title' => "Example {$position}", 'position' => $position, 'created_at' => now(), 'updated_at' => now()]);

    // Deferred: the duplicate is accepted while the transaction is open ...
    expect(signalSchemaState(function () use ($goal): void {
        $goal(1);
        $goal(1);
    }))->toBeNull();

    // ... and refused the moment the constraint is checked.
    expect(signalSchemaState(function (): void {
        DB::statement('SET CONSTRAINTS signal_weekly_goals_position_unique IMMEDIATE');
    }))->toBe('23505');
});

it('keeps one recap per user and week, and only for a Monday', function (): void {
    $recap = fn (array $row = []) => DB::table('signal_weekly_recaps')->insert(array_merge(['user_id' => $this->userId, 'week_start' => '2026-10-12', 'created_at' => now(), 'updated_at' => now()], $row));

    expect(signalSchemaState(fn () => $recap(['week_start' => '2026-10-13'])))->toBe('23514')
        ->and(signalSchemaState(fn () => $recap()))->toBeNull()
        ->and(signalSchemaState(fn () => $recap()))->toBe('23505')
        ->and(DB::table('signal_weekly_recaps')->value('what_went_well'))->toBe('');
});

it('does not let a user with planner rows be deleted', function (): void {
    signalSchemaTask($this->userId);

    expect(signalSchemaState(fn () => DB::table('users')->where('id', $this->userId)->delete()))->toBe('23503');
});
