<?php

declare(strict_types=1);

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
use App\Domain\TimeTracking\Actions\CreateTimeEntry;
use App\Domain\TimeTracking\Actions\DeleteTimeEntry;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Queries\OverlapFinder;
use Carbon\CarbonImmutable;
use Database\Factories\ProjectFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * Manual time entries: creation, edit and delete through the domain Actions
 * (TI-02, TI-03, TI-07, TI-08). Every value is fictional.
 */

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function entrySystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * Creates a manual entry as the signed-in actor.
 *
 * @param  array<string, mixed>  $data
 */
function entryCreate(User $actor, array $data): TimeEntry
{
    test()->actingAs($actor);

    return app(CreateTimeEntry::class)->handle($actor, $data);
}

/**
 * The field errors of a call the Action must refuse with a ValidationException.
 *
 * @param  Closure(): mixed  $call
 * @return array<string, list<string>>
 */
function entryRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('The call was accepted although it must be refused with a field error.');
}

/**
 * The number of time entries, read as a system run.
 */
function entryCount(): int
{
    return entrySystem(static fn (): int => TimeEntry::query()->count());
}

/**
 * A project of a client with an hourly billing row, written through the domain
 * Action like the Admin form does, so the billing resolver finds its rows.
 */
function entryProject(?Client $client = null): Project
{
    $client ??= entrySystem(static fn (): Client => Client::factory()->create([
        'currency' => 'CZK',
        'hourly_rate' => Money::fromMajor('800', 'CZK'),
    ]));

    return app(CreateProject::class)->handle($client, [
        'name' => 'Example entry project',
        'key' => ProjectFactory::randomKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '900',
    ]);
}

/**
 * A task of the project as the signed-in Admin; the billing keys go through UpdateTask.
 *
 * @param  array<string, mixed>  $billing
 */
function entryTask(User $admin, Project $project, array $billing = []): Task
{
    test()->actingAs($admin);

    $task = app(CreateTask::class)->handle($admin, $project, ['title' => 'Example entry task']);

    if ($billing !== []) {
        $task = app(UpdateTask::class)->handle($admin, $task, $billing);
    }

    return $task->refresh();
}

/**
 * A stored finished entry of the user, written by the factory (a system write).
 *
 * @param  array<string, mixed>  $attributes
 */
function entryStored(User $user, string $start, string $end, array $attributes = []): TimeEntry
{
    return entrySystem(static fn (): TimeEntry => TimeEntry::factory()->create([
        'user_id' => $user->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh());
}

/**
 * The stored row, read again as a system run.
 */
function entryReload(TimeEntry $entry): TimeEntry
{
    return entrySystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

/**
 * Bills the entry through raw SQL, the way the Phase 9 billing action will.
 */
function entryBill(TimeEntry $entry): void
{
    DB::table('time_entries')->where('id', $entry->id)->update(['billing_state' => 'billed', 'billed_at' => '2026-10-13 09:00:00']);
}

/*
 * Tracer: a finished client-only entry in exact seconds (TI-02, TI-03, TI-08).
 */

it('records a finished client-only entry in exact seconds', function (): void {
    $admin = Canary::admin();
    $client = Client::factory()->create();

    $entry = entryCreate($admin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    expect($entry->user_id)->toBe($admin->id)
        ->and($entry->client_id)->toBe($client->id)
        ->and($entry->project_id)->toBeNull()
        ->and($entry->task_id)->toBeNull()
        ->and($entry->duration_seconds)->toBe(5400)
        ->and($entry->billable)->toBeTrue()
        ->and($entry->billing_state->value)->toBe('unbilled')
        ->and($entry->isRunning())->toBeFalse()
        ->and($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00');
});

it('stores an ISO 8601 instant with an offset as the UTC instant', function (): void {
    $client = Client::factory()->create();

    $entry = entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T10:00:00+02:00',
        'ended_at' => '2026-10-12T08:30:00Z',
    ]);

    expect($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00')
        ->and($entry->ended_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:30:00')
        ->and($entry->duration_seconds)->toBe(1800);
});

it('truncates a fractional second instead of rounding it', function (string $start, string $end): void {
    $client = Client::factory()->create();

    $entry = entryCreate(Canary::admin(), ['client_id' => $client->id, 'started_at' => $start, 'ended_at' => $end]);

    expect($entry->started_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:00')
        ->and($entry->ended_at?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-12 08:00:59')
        ->and($entry->duration_seconds)->toBe(59);
})->with([
    'wall-clock fraction' => ['2026-10-12 08:00:00.700', '2026-10-12 08:00:59.999'],
    'ISO fraction' => ['2026-10-12T08:00:00.700+00:00', '2026-10-12T08:00:59.999999Z'],
    'fraction beyond microseconds' => ['2026-10-12 08:00:00.7000009', '2026-10-12 08:00:59.9999999'],
]);

it('refuses a missing client with the field error client_id', function (): void {
    $errors = entryRefused(fn () => entryCreate(Canary::admin(), [
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]));

    expect($errors)->toHaveKey('client_id')
        ->and($errors['client_id'][0])->toBe('Vyberte klienta.')
        ->and(entryCount())->toBe(0);
});

it('refuses a missing end with the field error ended_at', function (): void {
    $client = Client::factory()->create();

    $errors = entryRefused(fn () => entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
    ]));

    expect($errors)->toHaveKey('ended_at')
        ->and($errors['ended_at'][0])->toBe('Zadejte konec.')
        ->and(entryCount())->toBe(0);
});

/*
 * Creation rules (TI-02, TI-03, TI-08).
 */

it('refuses an end equal to or before the start with the field error ended_at', function (string $end): void {
    $client = Client::factory()->create();

    $errors = entryRefused(fn () => entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => $end,
    ]));

    expect($errors)->toHaveKey('ended_at')
        ->and($errors['ended_at'][0])->toBe('Konec musí být později než začátek.')
        ->and(entryCount())->toBe(0);
})->with([
    'equal' => ['2026-10-12 08:00:00'],
    'equal after truncation' => ['2026-10-12 08:00:00.900'],
    'one second before' => ['2026-10-12 07:59:59'],
    'a day before' => ['2026-10-11 09:00:00'],
]);

it('refuses a missing start and unparsable times as the field error of that key', function (string $key, mixed $value, string $message): void {
    $client = Client::factory()->create();
    $data = ['client_id' => $client->id, 'started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00'];
    $data[$key] = $value;

    $errors = entryRefused(fn () => entryCreate(Canary::admin(), $data));

    expect($errors)->toHaveKey($key)
        ->and($errors[$key][0])->toBe($message)
        ->and(entryCount())->toBe(0);
})->with([
    'missing start' => ['started_at', null, 'Zadejte začátek.'],
    'garbage start' => ['started_at', 'yesterday', 'Zadejte platné datum a čas.'],
    'day that does not exist' => ['ended_at', '2026-02-30 09:00:00', 'Zadejte platné datum a čas.'],
    'hour 25' => ['ended_at', '2026-10-12 25:00:00', 'Zadejte platné datum a čas.'],
    'date without a time' => ['ended_at', '2026-10-12', 'Zadejte platné datum a čas.'],
    'not a string' => ['ended_at', 12345, 'Zadejte platné datum a čas.'],
    'zone without a T' => ['ended_at', '2026-10-12 10:00:00+02:00', 'Zadejte platné datum a čas.'],
]);

it('stores an entry with a task and derives its project and client', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = entryProject();
    $task = entryTask($admin, $project);

    $entry = entryCreate($admin, [
        'task_id' => $task->id,
        'description' => '  Example work  ',
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 08:45:00',
    ]);

    expect($entry->task_id)->toBe($task->id)
        ->and($entry->project_id)->toBe($project->id)
        ->and($entry->client_id)->toBe($project->client_id)
        ->and($entry->description)->toBe('Example work')
        ->and($entry->duration_seconds)->toBe(2700);
});

it('refuses a task together with a project of another task as the field error task_id', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = entryTask($admin, entryProject());
    $otherProject = entryProject();

    $errors = entryRefused(fn () => app(CreateTimeEntry::class)->handle($admin, [
        'task_id' => $task->id,
        'project_id' => $otherProject->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]));

    expect($errors)->toHaveKey('task_id')
        ->and(entryCount())->toBe(0);
});

it('refuses a description of 1001 characters with the field error description', function (): void {
    $client = Client::factory()->create();

    $errors = entryRefused(fn () => entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'description' => str_repeat('x', 1001),
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]));

    expect($errors)->toHaveKey('description')->and(entryCount())->toBe(0);
});

it('stores a description of exactly 1000 characters', function (): void {
    $client = Client::factory()->create();

    $entry = entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'description' => str_repeat('x', 1000),
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]);

    expect(mb_strlen((string) $entry->description))->toBe(1000);
});

it('stores an explicit billable value over the default and pre-sets false for a non-billable task', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = entryProject();
    $hourly = entryTask($admin, $project);
    $free = entryTask($admin, $project, ['billing_type' => 'non_billable']);
    $times = ['started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00'];

    expect(entryCreate($admin, ['task_id' => $hourly->id, 'billable' => false, ...$times])->billable)->toBeFalse()
        ->and(entryCreate($admin, ['task_id' => $hourly->id, 'billable' => null, ...$times])->billable)->toBeTrue()
        ->and(entryCreate($admin, ['task_id' => $free->id, 'billable' => null, ...$times])->billable)->toBeFalse()
        ->and(entryCreate($admin, ['task_id' => $free->id, 'billable' => true, ...$times])->billable)->toBeTrue();
});

it('stores exact seconds across a daylight saving change', function (string $start, string $end): void {
    $client = Client::factory()->create();

    $entry = entryCreate(Canary::admin(), ['client_id' => $client->id, 'started_at' => $start, 'ended_at' => $end]);

    expect($entry->duration_seconds)->toBe(7200);
})->with([
    'autumn change 2026-10-25' => ['2026-10-25 01:30:00', '2026-10-25 03:30:00'],
    'spring change 2027-03-28' => ['2027-03-28 00:30:00', '2027-03-28 02:30:00'],
]);

it('stores a Prague wall-clock offset across the autumn change as the right UTC instants', function (): void {
    $client = Client::factory()->create();

    // 02:30 CEST (+02:00) and 02:30 CET (+01:00) are one hour apart on the same wall clock.
    $entry = entryCreate(Canary::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-25T02:30:00+02:00',
        'ended_at' => '2026-10-25T02:30:00+01:00',
    ]);

    expect($entry->duration_seconds)->toBe(3600);
});

it('refuses a Partner who creates an entry before anything is written', function (): void {
    [$clientA] = Canary::twoClients();
    $partner = Canary::partnerFor($clientA);

    expect(fn () => entryCreate($partner, [
        'client_id' => $clientA,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]))->toThrow(AuthorizationException::class)
        ->and(entryCount())->toBe(0);
});

/*
 * Update (TI-02, TI-07, TI-05).
 */

it('edits the times, description and context of an unbilled finished entry', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = entryProject();
    $task = entryTask($admin, $project);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => 'Before']);

    $updated = app(UpdateTimeEntry::class)->handle($admin, $entry, [
        'task_id' => $task->id,
        'description' => 'After',
        'started_at' => '2026-10-12 07:30:00',
        'ended_at' => '2026-10-12 09:15:30',
        'billable' => false,
    ]);

    expect($updated->task_id)->toBe($task->id)
        ->and($updated->project_id)->toBe($project->id)
        ->and($updated->client_id)->toBe($project->client_id)
        ->and($updated->description)->toBe('After')
        ->and($updated->duration_seconds)->toBe(6330)
        ->and($updated->billable)->toBeFalse()
        ->and($updated->isRunning())->toBeFalse();
});

it('keeps every stored value for a key the payload does not name', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => 'Keep me', 'billable' => false]);

    $updated = app(UpdateTimeEntry::class)->handle($admin, $entry, ['ended_at' => '2026-10-12 10:00:00']);

    expect($updated->description)->toBe('Keep me')
        ->and($updated->billable)->toBeFalse()
        ->and($updated->client_id)->toBe($entry->client_id)
        ->and($updated->started_at->equalTo($entry->started_at))->toBeTrue()
        ->and($updated->duration_seconds)->toBe(7200);
});

it('refuses to move the end before the start and leaves the row unchanged', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => 'Untouched']);

    $errors = entryRefused(fn () => app(UpdateTimeEntry::class)->handle($admin, $entry, [
        'description' => 'Changed',
        'ended_at' => '2026-10-12 07:00:00',
    ]));

    $stored = entryReload($entry);

    expect($errors)->toHaveKey('ended_at')
        ->and($stored->description)->toBe('Untouched')
        ->and($stored->duration_seconds)->toBe(3600);
});

it('refuses an empty start or end of a finished entry and an unparsable time', function (string $key, mixed $value, string $message): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');

    $errors = entryRefused(fn () => app(UpdateTimeEntry::class)->handle($admin, $entry, [$key => $value]));

    expect($errors)->toHaveKey($key)
        ->and($errors[$key][0])->toBe($message)
        ->and(entryReload($entry)->duration_seconds)->toBe(3600);
})->with([
    'empty start' => ['started_at', '', 'Zadejte začátek.'],
    'empty end' => ['ended_at', null, 'Zadejte konec.'],
    'garbage end' => ['ended_at', 'soon', 'Zadejte platné datum a čas.'],
]);

it('edits a running entry and keeps it running even when the payload names an end', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'));
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = entryTask($admin, entryProject());
    $running = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->running()->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 07:30:00', 'UTC'),
    ]));

    $updated = app(UpdateTimeEntry::class)->handle($admin, $running, [
        'task_id' => $task->id,
        'description' => 'Still going',
        'ended_at' => '2026-10-12 07:45:00',
    ]);

    expect($updated->isRunning())->toBeTrue()
        ->and($updated->ended_at)->toBeNull()
        ->and($updated->duration_seconds)->toBeNull()
        ->and($updated->task_id)->toBe($task->id)
        ->and($updated->description)->toBe('Still going');
});

it('does not re-check an unchanged context, so a description edit survives an archived task', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = entryTask($admin, entryProject());
    $entry = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->forTask($task)->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
    ]));

    app(ArchiveTask::class)->handle($admin, $task);

    $updated = app(UpdateTimeEntry::class)->handle($admin, $entry, [
        'task_id' => $task->id,
        'project_id' => $task->project_id,
        'client_id' => $entry->client_id,
        'description' => 'Corrected after the archive',
    ]);

    expect($updated->description)->toBe('Corrected after the archive')
        ->and($updated->task_id)->toBe($task->id);
});

it('checks a changed context: another archived task is the field error task_id', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = entryProject();
    $task = entryTask($admin, $project);
    $archived = entryTask($admin, $project);
    $entry = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->forTask($task)->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
    ]));

    app(ArchiveTask::class)->handle($admin, $archived);

    $errors = entryRefused(fn () => app(UpdateTimeEntry::class)->handle($admin, $entry, ['task_id' => $archived->id]));

    expect($errors)->toHaveKey('task_id')
        ->and(entryReload($entry)->task_id)->toBe($task->id);
});

it('derives project and client when only the task changes, and clears them with a client-only context', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $first = entryProject();
    $second = entryProject();
    $taskA = entryTask($admin, $first);
    $taskB = entryTask($admin, $second);
    $entry = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->forTask($taskA)->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
    ]));

    $moved = app(UpdateTimeEntry::class)->handle($admin, $entry, ['task_id' => $taskB->id]);

    expect($moved->task_id)->toBe($taskB->id)
        ->and($moved->project_id)->toBe($second->id)
        ->and($moved->client_id)->toBe($second->client_id);

    $cleared = app(UpdateTimeEntry::class)->handle($admin, $moved, [
        'client_id' => $second->client_id,
        'project_id' => null,
        'task_id' => null,
    ]);

    expect($cleared->task_id)->toBeNull()
        ->and($cleared->project_id)->toBeNull()
        ->and($cleared->client_id)->toBe($second->client_id);
});

it('refuses a client change that leaves the project of the old client in place', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $project = entryProject();
    $other = Client::factory()->create();
    $entry = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->forProject($project)->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 08:00:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
    ]));

    $errors = entryRefused(fn () => app(UpdateTimeEntry::class)->handle($admin, $entry, ['client_id' => $other->id]));

    expect($errors)->toHaveKey('project_id')
        ->and(entryReload($entry)->client_id)->toBe($project->client_id);
});

/*
 * The billed lock (TI-05, D-06).
 */

it('refuses to update or delete a billed entry and leaves it unchanged', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => 'Billed work']);
    entryBill($entry);
    $message = 'Záznam je vyfakturovaný a nelze ho upravit. Nejdřív zrušte fakturaci.';

    // The model the caller holds is stale: it still says unbilled.
    expect($entry->billing_state->value)->toBe('unbilled')
        ->and(fn () => app(UpdateTimeEntry::class)->handle($admin, $entry, ['description' => 'Changed']))
        ->toThrow(DomainException::class, $message)
        ->and(fn () => app(DeleteTimeEntry::class)->handle($admin, $entry))
        ->toThrow(DomainException::class, $message);

    $stored = entryReload($entry);

    expect($stored->description)->toBe('Billed work')
        ->and($stored->billing_state->value)->toBe('billed');
});

it('translates a guard trigger refusal that slips past the lock into the same message', function (string $event, Closure $call): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => 'Raced work']);

    // Bills the row between the locked read and the write, as a writer that ignores the lock would.
    TimeEntry::{$event}(static function (TimeEntry $row): void {
        DB::table('time_entries')->where('id', $row->id)->update(['billing_state' => 'billed', 'billed_at' => '2026-10-13 09:00:00']);
    });

    expect(fn () => $call($admin, $entry))
        ->toThrow(DomainException::class, 'Záznam je vyfakturovaný a nelze ho upravit. Nejdřív zrušte fakturaci.');
})->with([
    'update' => ['updating', fn (User $admin, TimeEntry $entry) => app(UpdateTimeEntry::class)->handle($admin, $entry, ['description' => 'Changed'])],
    'delete' => ['deleting', fn (User $admin, TimeEntry $entry) => app(DeleteTimeEntry::class)->handle($admin, $entry)],
]);

it('updates and deletes an entry again once its billing was cancelled', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    entryBill($entry);
    DB::table('time_entries')->where('id', $entry->id)->update(['billing_state' => 'unbilled', 'billed_at' => null]);

    $updated = app(UpdateTimeEntry::class)->handle($admin, $entry, ['description' => 'Free again']);

    expect($updated->description)->toBe('Free again');

    app(DeleteTimeEntry::class)->handle($admin, $updated);

    expect(entryCount())->toBe(0);
});

/*
 * Delete.
 */

it('deletes an unbilled finished entry and an unbilled running entry', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $finished = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00');
    $running = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->running()->create(['user_id' => $admin->id]));

    app(DeleteTimeEntry::class)->handle($admin, $finished);

    expect(entryCount())->toBe(1);

    app(DeleteTimeEntry::class)->handle($admin, $running);

    expect(entryCount())->toBe(0);
});

it('refuses a Partner on update and delete and leaves the entry untouched', function (): void {
    $admin = Canary::admin();
    [$clientA] = Canary::twoClients();
    $entry = entryStored($admin, '2026-10-12 08:00:00', '2026-10-12 09:00:00', ['description' => 'Admin only']);
    $partner = Canary::partnerFor($clientA);

    $this->actingAs($partner);

    expect(fn () => app(UpdateTimeEntry::class)->handle($partner, $entry, ['description' => 'Changed']))
        ->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteTimeEntry::class)->handle($partner, $entry))
        ->toThrow(AuthorizationException::class)
        ->and(entryReload($entry)->description)->toBe('Admin only');
});

/*
 * Overlaps are allowed and only looked up (D-04).
 */

/**
 * The id of the first overlapping entry of the user, or null.
 */
function entryOverlap(string $userId, string $from, ?string $to, ?string $exceptId = null): ?string
{
    return entrySystem(static fn (): ?string => (new OverlapFinder)->first(
        $userId,
        CarbonImmutable::parse($from, 'UTC'),
        $to === null ? null : CarbonImmutable::parse($to, 'UTC'),
        $exceptId,
    )?->id);
}

it('stores overlapping entries of one user without blocking', function (): void {
    $admin = Canary::admin();
    $client = Client::factory()->create();
    $times = ['client_id' => $client->id, 'started_at' => '2026-10-12 09:00:00', 'ended_at' => '2026-10-12 10:00:00'];

    entryCreate($admin, $times);
    entryCreate($admin, $times);

    expect(entryCount())->toBe(2);
});

it('finds an entry that overlaps and ignores one that only touches', function (): void {
    $admin = Canary::admin();
    $other = entryStored($admin, '2026-10-12 09:30:00', '2026-10-12 10:30:00');
    entryStored($admin, '2026-10-12 10:00:00', '2026-10-12 11:00:00');

    expect(entryOverlap($admin->id, '2026-10-12 09:00:00', '2026-10-12 10:00:00'))->toBe($other->id)
        ->and(entryOverlap($admin->id, '2026-10-12 11:00:00', '2026-10-12 12:00:00'))->toBeNull()
        ->and(entryOverlap($admin->id, '2026-10-12 08:00:00', '2026-10-12 09:30:00'))->toBeNull();
});

it('lets a zero-length entry overlap nothing, on either side', function (): void {
    $admin = Canary::admin();
    entryStored($admin, '2026-10-12 09:30:00', '2026-10-12 09:30:00');
    $wide = entryStored($admin, '2026-10-12 12:00:00', '2026-10-12 13:00:00');

    expect(entryOverlap($admin->id, '2026-10-12 09:00:00', '2026-10-12 10:00:00'))->toBeNull()
        ->and(entryOverlap($admin->id, '2026-10-12 12:30:00', '2026-10-12 12:30:00'))->toBeNull()
        ->and(entryOverlap($admin->id, '2026-10-12 12:00:00', '2026-10-12 13:00:00'))->toBe($wide->id);
});

it('counts a running entry as open-ended', function (): void {
    $admin = Canary::admin();
    $running = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->running()->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
    ]));

    expect(entryOverlap($admin->id, '2026-10-12 12:00:00', '2026-10-12 13:00:00'))->toBe($running->id)
        ->and(entryOverlap($admin->id, '2026-10-12 07:00:00', '2026-10-12 09:00:00'))->toBeNull()
        ->and(entryOverlap($admin->id, '2026-10-12 12:00:00', null))->toBe($running->id);
});

it('excludes the entry itself and never counts the entries of another user', function (): void {
    $admin = Canary::admin();
    $stranger = Canary::admin();
    $own = entryStored($admin, '2026-10-12 09:00:00', '2026-10-12 10:00:00');
    entryStored($stranger, '2026-10-12 09:00:00', '2026-10-12 10:00:00');

    expect(entryOverlap($admin->id, '2026-10-12 09:00:00', '2026-10-12 10:00:00', $own->id))->toBeNull()
        ->and(entryOverlap($admin->id, '2026-10-12 09:00:00', '2026-10-12 10:00:00'))->toBe($own->id);
});

it('lets each of three mutually overlapping entries find one of the other two', function (): void {
    $admin = Canary::admin();
    $a = entryStored($admin, '2026-10-12 09:00:00', '2026-10-12 10:00:00');
    $b = entryStored($admin, '2026-10-12 09:30:00', '2026-10-12 10:30:00');
    $c = entryStored($admin, '2026-10-12 09:45:00', '2026-10-12 10:15:00');

    foreach ([$a, $b, $c] as $entry) {
        $found = entryOverlap($admin->id, $entry->started_at->toDateTimeString(), $entry->ended_at?->toDateTimeString(), $entry->id);
        $others = array_values(array_diff([$a->id, $b->id, $c->id], [$entry->id]));

        expect($found)->not->toBeNull()->and($others)->toContain($found);
    }
});

it('names the earliest overlapping entry first and loads archived context', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $task = entryTask($admin, entryProject());
    $late = entryStored($admin, '2026-10-12 09:40:00', '2026-10-12 10:00:00');
    $early = entrySystem(static fn (): TimeEntry => TimeEntry::factory()->forTask($task)->create([
        'user_id' => $admin->id,
        'started_at' => CarbonImmutable::parse('2026-10-12 09:10:00', 'UTC'),
        'ended_at' => CarbonImmutable::parse('2026-10-12 09:20:00', 'UTC'),
    ]));
    app(ArchiveTask::class)->handle($admin, $task);

    $found = entrySystem(static fn (): ?TimeEntry => (new OverlapFinder)->first(
        $admin->id,
        CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-12 10:00:00', 'UTC'),
    ));

    expect($late->id)->not->toBe($early->id)
        ->and($found?->id)->toBe($early->id)
        ->and($found?->relationLoaded('task'))->toBeTrue()
        ->and($found?->task?->trashed())->toBeTrue();
});
