<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;

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
