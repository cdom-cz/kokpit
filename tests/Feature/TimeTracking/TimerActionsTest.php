<?php

declare(strict_types=1);

use App\Domain\Clients\Actions\ArchiveClient;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\Actions\StopTimer;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
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

/**
 * A project of a client with an hourly billing row, written through the domain
 * Action like the Admin form does, so the billing resolver finds its rows.
 *
 * @param  array<string, mixed>  $projectData
 */
function timerProject(?Client $client = null, array $projectData = []): Project
{
    $client ??= timerSystem(static fn (): Client => Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor('800', 'CZK'),
    ]));

    return app(CreateProject::class)->handle($client, [
        'name' => 'Example timer project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
        ...$projectData,
    ]);
}

/**
 * A task or subtask of the project as the signed-in Admin; the billing keys go
 * through UpdateTask like on the edit page.
 *
 * @param  array<string, mixed>  $billing
 */
function timerTask(User $admin, Project $project, array $billing = [], ?Task $parent = null): Task
{
    test()->actingAs($admin);

    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example timer task'], $parent);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle($admin, $task, $billing);
    }

    return $task->refresh();
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
 * Exact seconds at the edges (TI-08, research A1, Pitfalls 2 and 8).
 */

it('keeps a zero-length entry when a start comes in the same second as the running one', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00.400', 'UTC'));
    $admin = Canary::admin();
    $client = Client::factory()->create();

    $first = timerStart($admin, $client->id)['entry'];
    $second = timerStart($admin, $client->id);

    $stopped = $second['stopped'];

    expect($stopped?->id)->toBe($first->id)
        ->and($stopped?->ended_at?->equalTo($stopped->started_at))->toBeTrue()
        ->and($stopped?->duration_seconds)->toBe(0)
        ->and($second['entry']->isRunning())->toBeTrue()
        ->and(timerCount($admin))->toBe(2)
        ->and(timerCount($admin, runningOnly: true))->toBe(1);
});

it('stops a running entry that lies ahead of the clock at its own start, never before it (clock skew)', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $client = Client::factory()->create();
    $skewedStart = CarbonImmutable::parse('2026-10-12 08:00:05', 'UTC');

    timerSystem(static fn () => TimeEntry::factory()->running()->create([
        'user_id' => $admin->id,
        'client_id' => $client->id,
        'started_at' => $skewedStart,
    ]));

    $result = timerStart($admin, $client->id);

    expect($result['stopped']?->ended_at?->equalTo($skewedStart))->toBeTrue()
        ->and($result['stopped']?->duration_seconds)->toBe(0)
        ->and($result['entry']->started_at->equalTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC')))->toBeTrue()
        ->and(timerCount($admin, runningOnly: true))->toBe(1);
});

it('truncates a fractional start and stop instead of rounding them up', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00:00.700', 'UTC'));
    $admin = Canary::admin();
    $client = Client::factory()->create();

    $first = timerStart($admin, $client->id)['entry'];

    expect($first->started_at->format('Y-m-d H:i:s'))->toBe('2026-10-12 10:00:00');

    $this->travelTo(CarbonImmutable::parse('2026-10-12 11:25:30.200', 'UTC'));
    $stopped = timerStart($admin, $client->id)['stopped'];

    expect($stopped?->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-12 11:25:30')
        ->and($stopped?->duration_seconds)->toBe(5130);
});

it('returns the stopped entry with its duration loaded from the database', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $client = Client::factory()->create();

    timerStart($admin, $client->id);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:42', 'UTC'));
    $stopped = timerStart($admin, $client->id)['stopped'];

    expect($stopped?->duration_seconds)->toBe(42)
        ->and($stopped?->isRunning())->toBeFalse();
});

it('stores a description of exactly 1000 characters and refuses 1001', function (): void {
    $admin = Canary::admin();
    $client = Client::factory()->create();

    $stored = timerStart($admin, $client->id, ['description' => str_repeat('a', 1000)])['entry'];

    expect(mb_strlen((string) $stored->description))->toBe(1000);

    $count = timerCount($admin);

    try {
        app(StartTimer::class)->handle($admin, ['client_id' => $client->id, 'description' => str_repeat('a', 1001)]);
        $this->fail('A description of 1001 characters was accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('description');
    }

    expect(timerCount($admin))->toBe($count);
});

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

/*
 * Start from a task, stop (TI-01, TI-04, D-02, D-03).
 */

it('starts a timer from only a task id and derives its project and client', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();
    $task = timerTask($admin, $project);

    $entry = app(StartTimer::class)->handle($admin, ['task_id' => $task->id])['entry'];

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($project->id)
        ->and($entry->client_id)->toBe($project->client_id)
        ->and($entry->billable)->toBeTrue()
        ->and($entry->isRunning())->toBeTrue();
});

it('pre-sets billable to false when the task is non-billable', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = timerTask($admin, timerProject(), ['billing_type' => 'non_billable']);

    $entry = app(StartTimer::class)->handle($admin, ['task_id' => $task->id])['entry'];

    expect($entry->billable)->toBeFalse();
});

it('stops the running timer at the current second and is a no-op the next time', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = timerTask($admin, timerProject());
    $started = app(StartTimer::class)->handle($admin, ['task_id' => $task->id])['entry'];

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:25:30', 'UTC'));
    $stopped = app(StopTimer::class)->handle($admin);

    expect($stopped)->not->toBeNull()
        ->and($stopped?->id)->toBe($started->id)
        ->and($stopped?->isRunning())->toBeFalse()
        ->and($stopped?->ended_at?->equalTo(CarbonImmutable::parse('2026-10-12 08:25:30', 'UTC')))->toBeTrue()
        ->and($stopped?->duration_seconds)->toBe(1530);

    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));

    expect(app(StopTimer::class)->handle($admin))->toBeNull()
        ->and(timerSystem(static fn (): ?CarbonImmutable => TimeEntry::query()->findOrFail($started->id)->ended_at)?->equalTo(CarbonImmutable::parse('2026-10-12 08:25:30', 'UTC')))->toBeTrue();
});

/*
 * Context guards: forged, archived and inconsistent context (TI-07, T-06-04).
 */

/**
 * Starts a timer that must be refused with a field error and returns the messages.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, array<int, string>>
 */
function timerRefusedStart(User $actor, array $data): array
{
    test()->actingAs($actor);

    try {
        app(StartTimer::class)->handle($actor, $data);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('The start was accepted although the context is not valid.');
}

it('refuses an archived task, a task of an archived project and a task of an archived client on task_id and leaves the running timer alone', function (string $archived): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();
    $task = timerTask($admin, $project);
    $running = timerStart($admin, timerProject()->client_id)['entry'];

    match ($archived) {
        'task' => app(ArchiveTask::class)->handle($admin, $task),
        'project' => timerSystem(static fn () => $project->delete()),
        'client' => app(ArchiveClient::class)->handle($project->client()->withTrashed()->firstOrFail()),
    };

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:30:00', 'UTC'));
    $errors = timerRefusedStart($admin, ['task_id' => $task->id]);

    expect($errors)->toHaveKey('task_id')
        ->and($errors['task_id'][0])->toBe('Úkol je archivovaný. Vyberte jiný úkol.')
        ->and(timerCount($admin))->toBe(1)
        ->and(timerCount($admin, runningOnly: true))->toBe(1)
        ->and(timerSystem(static fn (): bool => TimeEntry::query()->findOrFail($running->id)->isRunning()))->toBeTrue();
})->with(['task', 'project', 'client']);

it('refuses an archived project and a project of an archived client on project_id', function (string $archived): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();

    if ($archived === 'project') {
        timerSystem(static fn () => $project->delete());
    } else {
        app(ArchiveClient::class)->handle($project->client()->firstOrFail());
    }

    $errors = timerRefusedStart($admin, ['project_id' => $project->id]);

    expect($errors)->toHaveKey('project_id')
        ->and($errors['project_id'][0])->toBe('Projekt je archivovaný. Vyberte jiný projekt.')
        ->and(timerCount($admin))->toBe(0);
})->with(['project', 'client']);

it('starts a timer from a project and derives the client', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();

    $entry = app(StartTimer::class)->handle($admin, ['project_id' => $project->id])['entry'];

    expect($entry->project_id)->toBe($project->id)
        ->and($entry->client_id)->toBe($project->client_id)
        ->and($entry->task_id)->toBeNull();
});

it('refuses a task together with a project of another task as inconsistent_context on task_id', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = timerTask($admin, timerProject());
    $other = timerProject();

    $errors = timerRefusedStart($admin, ['task_id' => $task->id, 'project_id' => $other->id]);

    expect($errors)->toHaveKey('task_id')
        ->and($errors['task_id'][0])->toBe('Klient, projekt a úkol k sobě nepatří. Vyberte je znovu.')
        ->and(timerCount($admin))->toBe(0);
});

it('refuses a project together with a client of another project as inconsistent_context on project_id', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();
    $otherClient = timerSystem(static fn (): Client => Client::factory()->create());

    $errors = timerRefusedStart($admin, ['project_id' => $project->id, 'client_id' => $otherClient->id]);

    expect($errors)->toHaveKey('project_id')
        ->and($errors['project_id'][0])->toBe('Klient, projekt a úkol k sobě nepatří. Vyberte je znovu.')
        ->and(timerCount($admin))->toBe(0);
});

it('accepts a task together with its own project and client', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();
    $task = timerTask($admin, $project);

    $entry = app(StartTimer::class)->handle($admin, [
        'client_id' => $project->client_id,
        'project_id' => $project->id,
        'task_id' => $task->id,
    ])['entry'];

    expect($entry->task_id)->toBe($task->id);
});

it('answers a malformed uuid in any of the three keys with the field error of that key', function (string $key, string $message): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $client = timerSystem(static fn (): Client => Client::factory()->create());

    $data = $key === 'client_id' ? [] : ['client_id' => $client->id];
    $errors = timerRefusedStart($admin, [...$data, $key => 'not-a-uuid']);

    expect($errors)->toHaveKey($key)
        ->and($errors[$key][0])->toBe($message)
        ->and(timerCount($admin))->toBe(0);
})->with([
    'task_id' => ['task_id', 'Úkol je archivovaný. Vyberte jiný úkol.'],
    'project_id' => ['project_id', 'Projekt je archivovaný. Vyberte jiný projekt.'],
    'client_id' => ['client_id', 'Vyberte klienta.'],
]);

it('keeps the running timer running after the task, the project client and the task are archived and stops it normally', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = timerProject();
    $task = timerTask($admin, $project);
    $entry = app(StartTimer::class)->handle($admin, ['task_id' => $task->id])['entry'];

    app(ArchiveTask::class)->handle($admin, $task);
    app(ArchiveClient::class)->handle($project->client()->firstOrFail());

    $loaded = TimeEntry::query()->findOrFail($entry->id);

    expect($loaded->isRunning())->toBeTrue()
        ->and($loaded->task?->trashed())->toBeTrue()
        ->and($loaded->task?->id)->toBe($task->id)
        ->and($loaded->project?->id)->toBe($project->id)
        ->and($loaded->client?->trashed())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:45:00', 'UTC'));
    $stopped = app(StopTimer::class)->handle($admin);

    expect($stopped?->id)->toBe($entry->id)
        ->and($stopped?->duration_seconds)->toBe(2700);
});

it('stores an explicit billable value over the default of the task', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $nonBillable = timerTask($admin, timerProject(), ['billing_type' => 'non_billable']);

    $forced = app(StartTimer::class)->handle($admin, ['task_id' => $nonBillable->id, 'billable' => true])['entry'];

    expect($forced->billable)->toBeTrue();

    $hourly = timerTask($admin, timerProject());
    $off = app(StartTimer::class)->handle($admin, ['task_id' => $hourly->id, 'billable' => false])['entry'];

    expect($off->billable)->toBeFalse();
});

it('does nothing when the expected entry id is not the running entry', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $client = timerSystem(static fn (): Client => Client::factory()->create());
    $first = timerStart($admin, $client->id)['entry'];

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:10:00', 'UTC'));
    $second = timerStart($admin, $client->id)['entry'];

    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:20:00', 'UTC'));

    expect(app(StopTimer::class)->handle($admin, $first->id))->toBeNull()
        ->and(timerCount($admin, runningOnly: true))->toBe(1)
        ->and(timerSystem(static fn (): bool => TimeEntry::query()->findOrFail($second->id)->isRunning()))->toBeTrue();

    expect(app(StopTimer::class)->handle($admin, $second->id)?->id)->toBe($second->id);
});

it('stops the running timer at its own start when the clock lags behind it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $client = timerSystem(static fn (): Client => Client::factory()->create());
    $entry = timerStart($admin, $client->id)['entry'];

    $this->travelTo(CarbonImmutable::parse('2026-10-12 07:59:50', 'UTC'));
    $stopped = app(StopTimer::class)->handle($admin);

    expect($stopped?->id)->toBe($entry->id)
        ->and($stopped?->duration_seconds)->toBe(0);
});

it('refuses a Partner who stops a timer and leaves the timer of the Admin running', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    [$clientA] = Canary::twoClients();
    $running = timerStart($admin, $clientA)['entry'];
    $partner = Canary::partnerFor($clientA);

    $this->actingAs($partner);

    expect(fn () => app(StopTimer::class)->handle($partner))->toThrow(AuthorizationException::class)
        ->and(fn () => app(StopTimer::class)->handle($partner, $running->id))->toThrow(AuthorizationException::class)
        ->and(timerSystem(static fn (): bool => TimeEntry::query()->findOrFail($running->id)->isRunning()))->toBeTrue();
});
