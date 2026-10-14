<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;

/*
 * D-15: the optional `external` JSON object of a time entry for import helpers. Real Sanctum tokens;
 * every value is fictional.
 */

/**
 * The body of a create request with a task key and a duration, plus the given extra keys.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function externalBody(array $extra = []): array
{
    return [
        'task_id' => 'DEMO-1',
        'started_at' => '2026-10-12T10:00:00+02:00',
        'duration_seconds' => 3600,
        ...$extra,
    ];
}

beforeEach(function (): void {
    $this->externalAdmin = ApiFixtures::admin();
    $this->externalClient = ApiFixtures::client();
    $project = ApiFixtures::project($this->externalClient, 'DEMO');
    ApiFixtures::task($project);
});

it('stores an external object on create and returns the same object on read', function (): void {
    $external = ['source' => 'demo-tracker', 'id' => 'A-1001', 'tags' => ['x']];

    $response = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->postJson('/api/v1/time-entries', externalBody(['external' => $external]))
        ->assertCreated();

    // jsonb does not keep the order of the keys, which JSON does not define either: compare the content.
    expect($response->json('data.external'))->toEqual($external);

    Auth::forgetGuards();

    $read = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:read']))
        ->getJson('/api/v1/time-entries/'.$response->json('data.id'))
        ->assertOk();

    expect($read->json('data.external'))->toEqual($external);
});

it('returns external as null for an entry created without it', function (): void {
    $response = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->postJson('/api/v1/time-entries', externalBody())
        ->assertCreated();

    expect($response->json())->toHaveKey('data.external')
        ->and($response->json('data.external'))->toBeNull();
});

it('lists the external object of every entry', function (): void {
    $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->postJson('/api/v1/time-entries', externalBody(['external' => ['source' => 'demo-tracker']]))
        ->assertCreated();

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:read']))
        ->getJson('/api/v1/time-entries')
        ->assertOk()
        ->assertJsonPath('data.0.external', ['source' => 'demo-tracker']);
});
