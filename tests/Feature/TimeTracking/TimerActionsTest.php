<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * The timer start path: a client-only timer, the stop of the running one at the
 * same instant, and the Admin-only isolation of tracked time (TI-03, TI-07).
 * Every value is fictional; descriptions are assembled at runtime.
 */

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function timerSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Starts a timer for the client as the signed-in actor.
 *
 * @param  array<string, mixed>  $data
 * @return array{entry: TimeEntry, stopped: TimeEntry|null}
 */
function timerStart(User $actor, string $clientId, array $data = []): array
{
    test()->actingAs($actor);

    return app(StartTimer::class)->handle($actor, ['client_id' => $clientId, ...$data]);
}

/**
 * Runs a statement that the database must refuse, inside a savepoint so the
 * test transaction survives the error and can run the next statement.
 *
 * @param  Closure(): mixed  $statement
 */
function timerRefused(Closure $statement, string $constraint): void
{
    expect(fn () => DB::transaction($statement))->toThrow(QueryException::class, $constraint);
}

/**
 * The number of time entries of a user, read as a system run.
 */
function timerCount(User $user, bool $runningOnly = false): int
{
    return timerSystem(static function () use ($user, $runningOnly): int {
        $query = TimeEntry::query()->where('user_id', $user->id);

        return $runningOnly ? $query->whereNull('ended_at')->count() : $query->count();
    });
}

it('starts a client-only timer at the current second', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $client = Client::factory()->create();

    $result = timerStart($admin, $client->id);

    expect($result['stopped'])->toBeNull();

    $entry = $result['entry'];

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->client_id)->toBe($client->id)
        ->and($entry->project_id)->toBeNull()
        ->and($entry->task_id)->toBeNull()
        ->and($entry->ended_at)->toBeNull()
        ->and($entry->isRunning())->toBeTrue()
        ->and($entry->duration_seconds)->toBeNull()
        ->and($entry->started_at->equalTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC')))->toBeTrue()
        ->and($entry->billable)->toBeTrue()
        ->and($entry->billing_state->value)->toBe('unbilled');
});

it('stops the running timer at the instant the next one starts and keeps it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $clientA = Client::factory()->create();
    $clientB = Client::factory()->create();

    $first = timerStart($admin, $clientA->id)['entry'];

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:10:00', 'UTC'));
    $second = timerStart($admin, $clientB->id);

    $stopped = $second['stopped'];

    expect($stopped)->not->toBeNull()
        ->and($stopped?->id)->toBe($first->id)
        ->and($stopped?->ended_at?->equalTo($second['entry']->started_at))->toBeTrue()
        ->and($stopped?->duration_seconds)->toBe(600)
        ->and($second['entry']->isRunning())->toBeTrue()
        ->and($second['entry']->client_id)->toBe($clientB->id)
        ->and(timerCount($admin))->toBe(2)
        ->and(timerCount($admin, runningOnly: true))->toBe(1);
});

it('lets a Partner read no time entry at all', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    [$clientA, $clientB] = Canary::twoClients();
    $admin = Canary::admin();

    timerSystem(static function () use ($admin, $clientA, $clientB): void {
        TimeEntry::factory()->create(['user_id' => $admin->id, 'client_id' => $clientA]);
        TimeEntry::factory()->create(['user_id' => $admin->id, 'client_id' => $clientB]);
    });

    expect(timerCount($admin))->toBe(2);

    $this->actingAs(Canary::partnerFor($clientA));

    expect(TimeEntry::query()->count())->toBe(0);
});

it('refuses a Partner who starts a timer before anything is written', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    [$clientA] = Canary::twoClients();
    $partner = Canary::partnerFor($clientA);

    $this->actingAs($partner);

    expect(fn () => app(StartTimer::class)->handle($partner, ['client_id' => $clientA]))
        ->toThrow(AuthorizationException::class);

    expect(timerSystem(static fn (): int => TimeEntry::query()->count()))->toBe(0);
});

it('refuses an archived client with the field error client_id', function (): void {
    $admin = Canary::admin();
    $client = Client::factory()->create();
    $client->delete();

    $this->actingAs($admin);

    try {
        app(StartTimer::class)->handle($admin, ['client_id' => $client->id]);
        $this->fail('The start for an archived client was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('client_id')
            ->and($exception->errors()['client_id'][0])->toBe('Vyberte klienta.');
    }

    expect(timerCount($admin))->toBe(0);
});

it('refuses a malformed or missing client id with the field error client_id', function (mixed $clientId): void {
    $admin = Canary::admin();

    $this->actingAs($admin);

    expect(fn () => app(StartTimer::class)->handle($admin, ['client_id' => $clientId]))
        ->toThrow(ValidationException::class);

    expect(timerCount($admin))->toBe(0);
})->with([
    'malformed' => 'not-a-uuid',
    'unknown' => '019a0000-0000-7000-8000-000000000000',
    'missing' => null,
]);

/*
 * What the database itself refuses, whatever code writes the row (TI-07).
 */

it('refuses in the database a second running entry of the same user', function (): void {
    $user = User::factory()->create();

    TimeEntry::factory()->running()->create(['user_id' => $user->id]);

    timerRefused(
        static fn () => TimeEntry::factory()->running()->create(['user_id' => $user->id]),
        'time_entries_one_running_per_user',
    );
});

it('refuses in the database an end before the start but accepts a zero-length entry', function (): void {
    $start = CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC');

    $zero = TimeEntry::factory()->create(['started_at' => $start, 'ended_at' => $start]);

    expect($zero->refresh()->duration_seconds)->toBe(0);

    timerRefused(
        static fn () => TimeEntry::factory()->create(['started_at' => $start, 'ended_at' => $start->subSecond()]),
        'time_entries_end_check',
    );
});

it('refuses in the database a project of another client and a task of another project', function (): void {
    $project = timerSystem(static fn () => Project::factory()->create());
    $other = timerSystem(static fn () => Project::factory()->create());
    $task = timerSystem(static fn (): Task => Task::factory()->create(['project_id' => $other->id]));

    timerRefused(
        static fn () => TimeEntry::factory()->create(['client_id' => Client::factory(), 'project_id' => $project->id]),
        'time_entries_project_client_fk',
    );

    timerRefused(
        static fn () => TimeEntry::factory()->create([
            'client_id' => $project->client_id,
            'project_id' => $project->id,
            'task_id' => $task->id,
        ]),
        'time_entries_task_project_fk',
    );
});

it('refuses in the database a task without a project', function (): void {
    $project = timerSystem(static fn () => Project::factory()->create());
    $task = timerSystem(static fn (): Task => Task::factory()->create(['project_id' => $project->id]));

    timerRefused(
        static fn () => TimeEntry::factory()->create(['client_id' => $project->client_id, 'task_id' => $task->id]),
        'time_entries_task_needs_project_check',
    );
});

it('accepts an entry that agrees on client, project and task', function (): void {
    $task = timerSystem(static fn (): Task => Task::factory()->create());

    $entry = TimeEntry::factory()->forTask($task)->create();

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($task->project_id);
});

it('freezes a billed entry but lets its billing state flip and its duration recompute', function (): void {
    $entry = TimeEntry::factory()->billed()->create();

    timerRefused(
        static fn () => DB::table('time_entries')->where('id', $entry->id)->update(['started_at' => $entry->started_at->subHour()]),
        'immutable',
    );

    DB::table('time_entries')->where('id', $entry->id)->update(['billing_state' => 'unbilled', 'billed_at' => null]);
    DB::table('time_entries')->where('id', $entry->id)->update(['ended_at' => $entry->ended_at?->addHour()]);

    expect($entry->refresh()->duration_seconds)->toBe(7200);
});
