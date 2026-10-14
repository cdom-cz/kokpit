<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Actions\ArchiveTask;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Actions\UpdateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\TimerClock;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

/*
 * AP-02, D-09: writing time entries over the REST API. The API maps input and lets the domain Actions
 * decide, so every refusal carries the field key and the Czech message of the UI. Real Sanctum tokens;
 * every value is fictional.
 */

it('creates a finished entry from a task key and a duration, and answers 201 with a Location header', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries', [
        'task_id' => 'DEMO-1',
        'started_at' => '2026-10-12T10:00:00+02:00',
        'duration_seconds' => 5400,
        'description' => 'Fictional import',
    ])->assertCreated();

    $id = $response->json('data.id');

    expect($response->headers->get('Location'))->toBe(route('api.v1.time-entries.show', $id))
        ->and($response->json('data.started_at'))->toBe('2026-10-12T08:00:00+00:00')
        ->and($response->json('data.ended_at'))->toBe('2026-10-12T09:30:00+00:00')
        ->and($response->json('data.duration_seconds'))->toBe(5400)
        ->and($response->json('data.task_id'))->toBe($task->id)
        ->and($response->json('data.task_reference'))->toBe('DEMO-1')
        ->and($response->json('data.project_id'))->toBe($project->id)
        ->and($response->json('data.project_key'))->toBe('DEMO')
        ->and($response->json('data.client_id'))->toBe($client->id)
        ->and($response->json('data.description'))->toBe('Fictional import')
        ->and($response->json('data.billing_state'))->toBe('unbilled');

    // A real client is a new request: the guard of the test keeps the user of the first token.
    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($admin, ['time:read']))
        ->getJson($response->headers->get('Location'))
        ->assertOk()
        ->assertJsonPath('data.id', $id);
});

it('stores the same instants for ended_at as for the equal duration_seconds', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    ApiFixtures::task($project);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries', [
        'task_id' => 'DEMO-1',
        'started_at' => '2026-10-12T10:00:00+02:00',
        'ended_at' => '2026-10-12T11:30:00+02:00',
    ])->assertCreated();

    expect($response->json('data.started_at'))->toBe('2026-10-12T08:00:00+00:00')
        ->and($response->json('data.ended_at'))->toBe('2026-10-12T09:30:00+00:00')
        ->and($response->json('data.duration_seconds'))->toBe(5400);
});

it('refuses a token that only reads', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->postJson('/api/v1/time-entries', [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T08:00:00Z',
        'duration_seconds' => 60,
    ])->assertForbidden();

    expect(TimeEntry::query()->count())->toBe(0);
});

/**
 * A finished unbilled entry of the admin for a fresh client: 08:00 to 09:30 UTC, optionally in a project and task.
 *
 * @return array{0: TimeEntry, 1: Client, 2: Project|null, 3: Task|null}
 */
function writableEntry(User $admin, bool $withTask = false): array
{
    $client = ApiFixtures::client();
    $project = $withTask ? ApiFixtures::project($client, 'DEMO') : null;
    $task = $project === null ? null : ApiFixtures::task($project);
    $entry = ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'project_id' => $project?->id,
        'task_id' => $task?->id,
        'description' => 'Fictional work',
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    return [$entry, $client, $project, $task];
}

it('edits an unbilled entry: description and end change, absent keys keep their value', function (): void {
    $admin = ApiFixtures::admin();
    [$entry, $client] = writableEntry($admin);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, [
        'description' => 'Fictional work, corrected',
        'ended_at' => '2026-10-12T10:00:00Z',
    ])->assertOk();

    expect($response->json('data.id'))->toBe($entry->id)
        ->and($response->json('data.description'))->toBe('Fictional work, corrected')
        ->and($response->json('data.ended_at'))->toBe('2026-10-12T10:00:00+00:00')
        ->and($response->json('data.started_at'))->toBe('2026-10-12T08:00:00+00:00')
        ->and($response->json('data.client_id'))->toBe($client->id)
        ->and($response->json('data.billable'))->toBeTrue();
});

it('recomputes the end from duration_seconds and the stored start', function (): void {
    $admin = ApiFixtures::admin();
    [$entry] = writableEntry($admin);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, ['duration_seconds' => 600])->assertOk();

    expect($response->json('data.ended_at'))->toBe('2026-10-12T08:10:00+00:00')
        ->and($response->json('data.duration_seconds'))->toBe(600);

    $moved = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, [
        'started_at' => '2026-10-12T12:00:00+02:00',
        'duration_seconds' => 1200,
    ])->assertOk();

    expect($moved->json('data.started_at'))->toBe('2026-10-12T10:00:00+00:00')
        ->and($moved->json('data.ended_at'))->toBe('2026-10-12T10:20:00+00:00');
});

it('clears the task with null and keeps the project', function (): void {
    $admin = ApiFixtures::admin();
    [$entry, , $project] = writableEntry($admin, withTask: true);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, ['task_id' => null])->assertOk();

    expect($response->json('data.task_id'))->toBeNull()
        ->and($response->json('data.task_reference'))->toBeNull()
        ->and($response->json('data.project_id'))->toBe($project->id)
        ->and($response->json('data.description'))->toBe('Fictional work');
});

it('answers 409 entry_locked for a billed entry on PATCH and DELETE and leaves the row unchanged', function (): void {
    $admin = ApiFixtures::admin();
    [$entry] = writableEntry($admin);
    $entry = ApiFixtures::billed($entry);
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, ['description' => 'Fictional change'])
        ->assertStatus(409)
        ->assertExactJson(['message' => __('kokpit.time.errors.locked'), 'code' => 'entry_locked']);

    $this->withToken($token)->deleteJson('/api/v1/time-entries/'.$entry->id)
        ->assertStatus(409)
        ->assertExactJson(['message' => __('kokpit.time.errors.locked'), 'code' => 'entry_locked']);

    $stored = TimeEntry::query()->findOrFail($entry->id);

    expect($stored->description)->toBe('Fictional work')
        ->and($stored->billing_state->value)->toBe('billed');
});

it('refuses to end a running entry from outside, with 422 under ended_at', function (): void {
    $admin = ApiFixtures::admin();
    $running = ApiFixtures::running($admin, ApiFixtures::client());
    $token = ApiFixtures::token($admin, ['time:write']);

    foreach ([['ended_at' => '2026-10-12T10:00:00Z'], ['duration_seconds' => 600]] as $body) {
        $this->withToken($token)->patchJson('/api/v1/time-entries/'.$running->id, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['ended_at'])
            ->assertJsonPath('errors.ended_at.0', __('api.errors.running_entry_end'));
    }

    expect(TimeEntry::query()->findOrFail($running->id)->ended_at)->toBeNull();
});

it('lets a running entry change its description, billable flag and start', function (): void {
    $admin = ApiFixtures::admin();
    $running = ApiFixtures::running($admin, ApiFixtures::client());
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$running->id, [
        'description' => 'Fictional timer note',
        'billable' => false,
        'started_at' => '2026-10-12T07:00:00Z',
    ])->assertOk();

    expect($response->json('data.description'))->toBe('Fictional timer note')
        ->and($response->json('data.billable'))->toBeFalse()
        ->and($response->json('data.started_at'))->toBe('2026-10-12T07:00:00+00:00')
        ->and($response->json('data.ended_at'))->toBeNull()
        ->and($response->json('data.duration_seconds'))->toBeNull();
});

it('refuses to delete a running entry with 409 entry_running and deletes a finished one with 204', function (): void {
    $admin = ApiFixtures::admin();
    $running = ApiFixtures::running($admin, ApiFixtures::client());
    [$finished] = writableEntry($admin);
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->deleteJson('/api/v1/time-entries/'.$running->id)
        ->assertStatus(409)
        ->assertExactJson(['message' => __('api.errors.running_entry_delete'), 'code' => 'entry_running']);

    expect(TimeEntry::query()->whereKey($running->id)->exists())->toBeTrue();

    $this->withToken($token)->deleteJson('/api/v1/time-entries/'.$finished->id)
        ->assertNoContent();

    expect(TimeEntry::query()->whereKey($finished->id)->exists())->toBeFalse();
});

it('answers 404 for an unknown entry on PATCH and DELETE and 403 for a token that only reads', function (): void {
    $admin = ApiFixtures::admin();
    [$entry] = writableEntry($admin);
    $token = ApiFixtures::token($admin, ['time:write']);
    $unknown = '0199e3ff-0000-7000-8000-000000000000';

    $this->withToken($token)->patchJson('/api/v1/time-entries/'.$unknown, ['description' => 'Fictional'])->assertNotFound();
    $this->withToken($token)->deleteJson('/api/v1/time-entries/'.$unknown)->assertNotFound();

    Auth::forgetGuards();
    $reader = ApiFixtures::token($admin, ['time:read']);

    $this->withToken($reader)->patchJson('/api/v1/time-entries/'.$entry->id, ['description' => 'Fictional'])->assertForbidden();
    $this->withToken($reader)->deleteJson('/api/v1/time-entries/'.$entry->id)->assertForbidden();

    expect(TimeEntry::query()->whereKey($entry->id)->exists())->toBeTrue();
});

/**
 * The refusal matrix of the UI through the API (D-09). The expected messages are the Action messages, read
 * through __(), so the tests prove that the panel and the API answer alike.
 */

/**
 * A client, a project DEMO with its task DEMO-1 and a token that may write.
 *
 * @return array{0: User, 1: Client, 2: Project, 3: Task, 4: string}
 */
function matrixContext(): array
{
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);

    return [$admin, $client, $project, $task, ApiFixtures::token($admin, ['time:write'])];
}

/**
 * The body of a valid create to override per case.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function matrixBody(Client $client, array $override = []): array
{
    return [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T08:00:00Z',
        'ended_at' => '2026-10-12T09:00:00Z',
        ...$override,
    ];
}

it('refuses a task together with the project of another task, and a project of another client, as the panel does', function (): void {
    [$admin, $client, $project, $task, $token] = matrixContext();
    $otherProject = ApiFixtures::project($client, 'OTHR');
    $otherClient = ApiFixtures::client();

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => $task->id, 'project_id' => $otherProject->id]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_id'])
        ->assertJsonPath('errors.task_id.0', __('kokpit.time.errors.inconsistent_context'));

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($otherClient, ['project_id' => 'DEMO']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['project_id'])
        ->assertJsonPath('errors.project_id.0', __('kokpit.time.errors.inconsistent_context'));

    expect(TimeEntry::query()->count())->toBe(0);
});

it('refuses an end equal to or before the start under ended_at', function (string $end): void {
    [, $client, , , $token] = matrixContext();

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['ended_at' => $end]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ended_at'])
        ->assertJsonPath('errors.ended_at.0', __('kokpit.time.errors.end_before_start'));
})->with([
    'equal' => '2026-10-12T08:00:00Z',
    'equal after truncation' => '2026-10-12T08:00:00.900Z',
    'before' => '2026-10-12T07:59:59Z',
]);

it('refuses an archived task by key and by uuid, a name and a lowercase key under their own keys, and no context under client_id', function (): void {
    [$admin, $client, , $task, $token] = matrixContext();

    Auth::shouldUse('web');
    Auth::guard('web')->setUser($admin);
    app(ArchiveTask::class)->handle($admin, $task);
    Auth::forgetGuards();

    foreach (['DEMO-1', $task->id] as $reference) {
        $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => $reference]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['task_id'])
            ->assertJsonPath('errors.task_id.0', __('kokpit.time.errors.task_unavailable'));
    }

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => 'Fictional task title']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_id']);

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['project_id' => 'demo']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['project_id']);

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => 'NOPE-9']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['task_id'])
        ->assertJsonPath('errors.task_id.0', __('kokpit.time.errors.task_unavailable'));

    $this->withToken($token)->postJson('/api/v1/time-entries', ['started_at' => '2026-10-12T08:00:00Z', 'duration_seconds' => 60])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['client_id'])
        ->assertJsonPath('errors.client_id.0', __('kokpit.time.errors.client_required'));

    expect(TimeEntry::query()->count())->toBe(0);
});

it('needs an offset on every instant, takes Z and cuts a fraction to the whole second', function (): void {
    [, $client, , , $token] = matrixContext();

    foreach (['started_at', 'ended_at'] as $field) {
        $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, [$field => '2026-10-12 09:30:00']))
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    $response = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, [
        'started_at' => '2026-10-12T08:00:00.700Z',
        'ended_at' => '2026-10-12T08:30:00.999Z',
    ]))->assertCreated();

    expect($response->json('data.started_at'))->toBe('2026-10-12T08:00:00+00:00')
        ->and($response->json('data.ended_at'))->toBe('2026-10-12T08:30:00+00:00')
        ->and($response->json('data.duration_seconds'))->toBe(1800);
});

it('wants exactly one of ended_at and duration_seconds and a duration of 1 to 2147483647', function (): void {
    [, $client, , , $token] = matrixContext();
    $withoutEnd = static fn (array $extra): array => ['client_id' => $client->id, 'started_at' => '2026-10-12T08:00:00Z', ...$extra];

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['duration_seconds' => 60]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ended_at']);

    $this->withToken($token)->postJson('/api/v1/time-entries', $withoutEnd([]))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ended_at']);

    foreach ([0, -5, 1.5, 2147483648, 'abc'] as $duration) {
        $this->withToken($token)->postJson('/api/v1/time-entries', $withoutEnd(['duration_seconds' => $duration]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['duration_seconds']);
    }

    $largest = $this->withToken($token)->postJson('/api/v1/time-entries', $withoutEnd(['duration_seconds' => 2147483647]))
        ->assertCreated();

    expect($largest->json('data.duration_seconds'))->toBe(2147483647);
});

it('refuses an interval beyond the column limit under ended_at, never a database error', function (): void {
    [, $client, , , $token] = matrixContext();

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['ended_at' => '9999-12-31T23:59:59Z']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ended_at'])
        ->assertJsonPath('errors.ended_at.0', __('kokpit.time.errors.duration_too_long'))
        ->assertJsonPath('errors.ended_at.0', 'Záznam je příliš dlouhý.');

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, [
        'started_at' => '0000-01-01T00:00:00Z',
        'ended_at' => '0000-01-01T00:00:01Z',
    ]))->assertStatus(422)->assertJsonValidationErrors(['started_at']);

    $edit = ApiFixtures::entry(ApiFixtures::admin(), [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]);

    $this->withToken($token)->patchJson('/api/v1/time-entries/'.$edit->id, ['ended_at' => '9999-12-31T23:59:59Z'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['ended_at'])
        ->assertJsonPath('errors.ended_at.0', __('kokpit.time.errors.duration_too_long'));

    expect(TimeEntry::query()->count())->toBe(1);
});

it('refuses a start far in the past on a finished entry with 422 under started_at, never a database error', function (): void {
    $admin = ApiFixtures::admin();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, ['started_at' => '1900-01-01T00:00:00Z'])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['started_at'])
        ->assertJsonPath('errors.started_at.0', __('kokpit.time.errors.duration_too_long'));

    $stored = TimeEntry::query()->findOrFail($entry->id);

    expect($stored->started_at->toIso8601String())->toBe('2026-10-12T08:00:00+00:00')
        ->and($stored->duration_seconds)->toBe(3600);
});

it('still accepts an old start whose interval fits the column', function (): void {
    $admin = ApiFixtures::admin();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, ['started_at' => '1960-01-01T00:00:00Z'])
        ->assertOk();

    expect($response->json('data.duration_seconds'))->toBe(2107414800);
});

it('refuses a start far in the past or far ahead on a running entry and keeps the entry list readable', function (): void {
    $admin = ApiFixtures::admin();
    $running = ApiFixtures::running($admin, ApiFixtures::client());
    $token = ApiFixtures::token($admin, ['time:write']);
    $before = $running->started_at->toIso8601String();

    foreach (['1900-01-01T00:00:00Z', '2200-01-01T00:00:00Z'] as $start) {
        $this->withToken($token)->patchJson('/api/v1/time-entries/'.$running->id, ['started_at' => $start])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['started_at'])
            ->assertJsonPath('errors.started_at.0', __('kokpit.time.errors.duration_too_long'));
    }

    expect(TimeEntry::query()->findOrFail($running->id)->started_at->toIso8601String())->toBe($before)
        ->and(TimeEntry::query()->withElapsedSeconds(TimerClock::now())->get())->toHaveCount(1);
});

it('ignores smuggled keys: the entry is the token owner\'s, unbilled and has a new uuid', function (): void {
    [$admin, $client, , , $token] = matrixContext();
    $other = Canary::partnerFor($client->id);
    $smuggledId = '0199e3ff-1111-7000-8000-000000000001';

    $response = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, [
        'id' => $smuggledId,
        'user_id' => $other->id,
        'billing_state' => 'billed',
        'billed_at' => '2026-10-13T09:00:00Z',
        'created_at' => '2020-01-01T00:00:00Z',
    ]))->assertCreated();

    $stored = TimeEntry::query()->findOrFail($response->json('data.id'));

    expect($stored->id)->not->toBe($smuggledId)
        ->and($stored->user_id)->toBe($admin->id)
        ->and($stored->billing_state->value)->toBe('unbilled')
        ->and($stored->billed_at)->toBeNull()
        ->and($response->json('data.billing_state'))->toBe('unbilled')
        ->and($response->json('data.billed_at'))->toBeNull()
        ->and($stored->created_at->year)->not->toBe(2020);

    $patched = $this->withToken($token)->patchJson('/api/v1/time-entries/'.$stored->id, [
        'user_id' => $other->id,
        'billing_state' => 'billed',
        'billed_at' => '2026-10-13T09:00:00Z',
        'description' => 'Fictional note',
    ])->assertOk();

    $reloaded = TimeEntry::query()->findOrFail($stored->id);

    expect($patched->json('data.billing_state'))->toBe('unbilled')
        ->and($reloaded->user_id)->toBe($admin->id)
        ->and($reloaded->billing_state->value)->toBe('unbilled');
});

it('accepts an overlapping entry and takes the billable default of the task when billable is absent', function (): void {
    [$admin, $client, $project, , $token] = matrixContext();
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($admin);
    $freeTask = app(UpdateTask::class)->handle(
        $admin,
        app(CreateTask::class)->handle($admin, $project, ['title' => 'Example free task']),
        ['billing_type' => 'non_billable'],
    );
    Auth::forgetGuards();

    $first = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client))->assertCreated();
    $overlap = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, [
        'started_at' => '2026-10-12T08:30:00Z',
        'ended_at' => '2026-10-12T09:30:00Z',
    ]))->assertCreated();

    expect($overlap->json('data.id'))->not->toBe($first->json('data.id'))
        ->and($first->json('data.billable'))->toBeTrue();

    $absent = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => $freeTask->reference]))->assertCreated();
    $explicit = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => $freeTask->reference, 'billable' => true]))->assertCreated();
    $nullDefault = $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['task_id' => $freeTask->reference, 'billable' => null]))->assertCreated();

    expect($absent->json('data.billable'))->toBeFalse()
        ->and($explicit->json('data.billable'))->toBeTrue()
        ->and($nullDefault->json('data.billable'))->toBeFalse();
});

it('limits the writes of one token on the real create route: the 3rd of 2 a minute answers 429', function (): void {
    config(['kokpit.api.rate_limit.write' => 2]);
    [, $client, , , $token] = matrixContext();

    foreach ([1, 2] as $attempt) {
        $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client, ['started_at' => '2026-10-1'.$attempt.'T08:00:00Z', 'ended_at' => '2026-10-1'.$attempt.'T09:00:00Z']))
            ->assertCreated();
    }

    $this->withToken($token)->postJson('/api/v1/time-entries', matrixBody($client))
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    expect(TimeEntry::query()->count())->toBe(2);
});
