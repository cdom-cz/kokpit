<?php

declare(strict_types=1);

use Tests\Support\ApiFixtures;

/*
 * Token authentication of the REST API (AP-01, AP-02 read part). Real Sanctum
 * tokens through the guard path; every value is fictional.
 */

it('lets an Admin token with time:read read one time entry', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:read']);

    $response = $this->withToken($token)->getJson('/api/v1/time-entries/'.$entry->id);

    $response->assertOk()
        ->assertJsonPath('data.id', $entry->id)
        ->assertJsonPath('data.client_id', $client->id)
        ->assertJsonPath('data.duration_seconds', 5400)
        ->assertJsonPath('data.started_at', '2026-10-12T08:00:00+00:00')
        ->assertJsonPath('data.ended_at', '2026-10-12T09:30:00+00:00')
        ->assertJsonPath('data.billing_state', 'unbilled')
        ->assertJsonPath('data.billable', true);

    expect(array_keys($response->json('data')))->toBe([
        'id', 'client_id', 'project_id', 'project_key', 'task_id', 'task_reference', 'description',
        'started_at', 'ended_at', 'duration_seconds', 'billable', 'billing_state', 'billed_at',
        'created_at', 'updated_at',
    ]);
});

it('refuses a request without a token with 401 JSON', function (): void {
    $entry = ApiFixtures::entry(ApiFixtures::admin(), [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);

    $this->getJson('/api/v1/time-entries/'.$entry->id)
        ->assertUnauthorized()
        ->assertJsonStructure(['message']);
});
