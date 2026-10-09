<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RawSql;

/*
 * Database-level invariants of the time_entries table (TI-05, TI-07). Every case
 * uses raw SQL, so no model, cast, Action or form rule stands between the value
 * and the constraint; each refusal is proven by its SQLSTATE. Values are fictional.
 *
 * Fixtures: two users and two clients, each client with one project and one task.
 */

/**
 * Inserts one time entry row with valid defaults (a finished hour for user one
 * and client A, without project or task) and returns its id. Overrides replace
 * single columns; the column names come from the test only.
 *
 * @param  array<string, mixed>  $overrides
 */
function timeEntriesSchemaInsert(array $overrides = []): string
{
    $row = [
        'id' => (string) Str::uuid7(),
        'user_id' => test()->timeEntriesUserOne,
        'client_id' => test()->timeEntriesClientA,
        'started_at' => '2026-10-12 10:00:00+00',
        'ended_at' => '2026-10-12 11:00:00+00',
        'created_at' => '2026-10-12 12:00:00+00',
        'updated_at' => '2026-10-12 12:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into time_entries (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * A billed finished entry: the only shape the billed checks accept.
 */
function timeEntriesSchemaBilled(): string
{
    return timeEntriesSchemaInsert(['billing_state' => 'billed', 'billed_at' => '2026-10-13 09:00:00+00']);
}

beforeEach(function (): void {
    app(PartnerContext::class)->runAsSystem(function (): void {
        $this->timeEntriesUserOne = User::factory()->create()->id;
        $this->timeEntriesUserTwo = User::factory()->create()->id;

        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $projectA = Project::factory()->create(['client_id' => $clientA->id]);
        $projectB = Project::factory()->create(['client_id' => $clientB->id]);

        $this->timeEntriesClientA = $clientA->id;
        $this->timeEntriesClientB = $clientB->id;
        $this->timeEntriesProjectA = $projectA->id;
        $this->timeEntriesProjectB = $projectB->id;
        $this->timeEntriesTaskA = Task::factory()->create(['project_id' => $projectA->id])->id;
        $this->timeEntriesTaskB = Task::factory()->create(['project_id' => $projectB->id])->id;
    });
});

describe('checks (23514)', function (): void {
    it('refuses a task without a project', function (): void {
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert(['task_id' => $this->timeEntriesTaskA]));
    });

    it('refuses an end before the start', function (): void {
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert([
            'started_at' => '2026-10-12 10:00:00+00',
            'ended_at' => '2026-10-12 09:59:59+00',
        ]));
    });

    it('accepts a zero-length finished entry with a duration of 0', function (): void {
        $id = null;

        RawSql::expectAllowed(function () use (&$id): void {
            $id = timeEntriesSchemaInsert(['started_at' => '2026-10-12 10:00:00+00', 'ended_at' => '2026-10-12 10:00:00+00']);
        });

        expect(DB::selectOne('select duration_seconds from time_entries where id = ?', [$id])->duration_seconds)->toBe(0);
    });

    it('refuses a billed entry that is still running', function (): void {
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert([
            'ended_at' => null,
            'billing_state' => 'billed',
            'billed_at' => '2026-10-13 09:00:00+00',
        ]));
    });

    it('refuses a billed entry that is not billable', function (): void {
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert([
            'billable' => 'false',
            'billing_state' => 'billed',
            'billed_at' => '2026-10-13 09:00:00+00',
        ]));
    });

    it('refuses a billed entry without billed_at and an unbilled entry with billed_at', function (): void {
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert(['billing_state' => 'billed']));
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert(['billed_at' => '2026-10-13 09:00:00+00']));
    });

    it('refuses an unknown billing state', function (): void {
        RawSql::expectSqlState('23514', fn () => timeEntriesSchemaInsert(['billing_state' => 'invoiced']));
    });
});

describe('foreign keys (23503)', function (): void {
    it('refuses a project of another client', function (): void {
        RawSql::expectSqlState('23503', fn () => timeEntriesSchemaInsert([
            'client_id' => $this->timeEntriesClientB,
            'project_id' => $this->timeEntriesProjectA,
        ]));
    });

    it('refuses a task of another project', function (): void {
        RawSql::expectSqlState('23503', fn () => timeEntriesSchemaInsert([
            'client_id' => $this->timeEntriesClientA,
            'project_id' => $this->timeEntriesProjectA,
            'task_id' => $this->timeEntriesTaskB,
        ]));
    });

    it('accepts a client-only row, a project row and a task row that agree', function (): void {
        RawSql::expectAllowed(fn () => timeEntriesSchemaInsert());
        RawSql::expectAllowed(fn () => timeEntriesSchemaInsert([
            'project_id' => $this->timeEntriesProjectA,
        ]));
        RawSql::expectAllowed(fn () => timeEntriesSchemaInsert([
            'project_id' => $this->timeEntriesProjectA,
            'task_id' => $this->timeEntriesTaskA,
        ]));
    });
});

describe('one running entry per user (23505)', function (): void {
    it('refuses a second running row of the same user', function (): void {
        timeEntriesSchemaInsert(['ended_at' => null]);

        RawSql::expectSqlState('23505', fn () => timeEntriesSchemaInsert(['ended_at' => null]));
    });

    it('accepts a running row of another user and any number of finished rows', function (): void {
        timeEntriesSchemaInsert(['ended_at' => null]);

        RawSql::expectAllowed(fn () => timeEntriesSchemaInsert(['ended_at' => null, 'user_id' => $this->timeEntriesUserTwo]));
        RawSql::expectAllowed(fn () => timeEntriesSchemaInsert());
    });
});

describe('the billed freeze (KP001)', function (): void {
    it('refuses to edit the content of a billed row', function (string $column): void {
        $id = timeEntriesSchemaBilled();

        $change = match ($column) {
            'started_at' => ["started_at = '2026-10-12 09:00:00+00'", []],
            'ended_at' => ["ended_at = '2026-10-12 12:00:00+00'", []],
            'client_id' => ['client_id = ?', [$this->timeEntriesClientB]],
            'description' => ["description = 'Example changed'", []],
        };

        RawSql::expectSqlState('KP001', fn () => DB::update(
            'update time_entries set '.$change[0].' where id = ?',
            [...$change[1], $id],
        ));
    })->with(['started_at', 'ended_at', 'client_id', 'description']);

    it('refuses to delete a billed row', function (): void {
        $id = timeEntriesSchemaBilled();

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from time_entries where id = ?', [$id]));
    });

    it('refuses TRUNCATE', function (): void {
        timeEntriesSchemaBilled();

        RawSql::expectSqlState('KP001', fn () => DB::statement('TRUNCATE time_entries'));
    });

    it('allows the unlock of a billed row and recomputes the duration of an edit after it', function (): void {
        $id = timeEntriesSchemaBilled();

        RawSql::expectAllowed(fn () => DB::update(
            "update time_entries set billing_state = 'unbilled', billed_at = null where id = ?",
            [$id],
        ));

        expect(DB::selectOne('select duration_seconds from time_entries where id = ?', [$id])->duration_seconds)->toBe(3600);

        RawSql::expectAllowed(fn () => DB::update(
            "update time_entries set ended_at = ended_at + interval '1 hour' where id = ?",
            [$id],
        ));

        expect(DB::selectOne('select duration_seconds from time_entries where id = ?', [$id])->duration_seconds)->toBe(7200);
    });
});

it('computes the duration in exact seconds', function (): void {
    $id = timeEntriesSchemaInsert(['started_at' => '2026-10-12 10:00:00+00', 'ended_at' => '2026-10-12 11:25:30+00']);

    expect(DB::selectOne('select duration_seconds from time_entries where id = ?', [$id])->duration_seconds)->toBe(5130);
});

it('has no duration while the entry is running', function (): void {
    $id = timeEntriesSchemaInsert(['ended_at' => null]);

    expect(DB::selectOne('select duration_seconds from time_entries where id = ?', [$id])->duration_seconds)->toBeNull();
});
