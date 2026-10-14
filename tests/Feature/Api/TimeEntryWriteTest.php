<?php

declare(strict_types=1);

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
