<?php

declare(strict_types=1);

use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Tests\Support\ApiFixtures;

/*
 * AP-02, D-14: batch create of time entries with partial success. Every item goes through the same
 * CreateTimeEntry Action as the single create, in its own transaction. Real Sanctum tokens; every value
 * is fictional.
 */

it('saves a batch of valid entries through CreateTimeEntry and answers 201 with the counts', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [
            ['task_id' => 'DEMO-1', 'started_at' => '2026-10-12T10:00:00+02:00', 'duration_seconds' => 5400],
            ['project_id' => 'DEMO', 'started_at' => '2026-10-12T13:00:00+02:00', 'ended_at' => '2026-10-12T14:00:00+02:00'],
            ['client_id' => $client->id, 'started_at' => '2026-10-12T15:00:00+02:00', 'duration_seconds' => 600],
        ],
    ])->assertCreated();

    expect($response->json())->toBe(['saved' => 3, 'failed' => 0, 'errors' => []])
        ->and(TimeEntry::query()->count())->toBe(3);

    $byStart = TimeEntry::query()->orderBy('started_at')->get();

    expect($byStart[0]->task_id)->toBe($task->id)
        ->and($byStart[0]->started_at->equalTo(CarbonImmutable::parse('2026-10-12T08:00:00+00:00')))->toBeTrue()
        ->and($byStart[0]->ended_at?->equalTo(CarbonImmutable::parse('2026-10-12T09:30:00+00:00')))->toBeTrue()
        ->and($byStart[1]->project_id)->toBe($project->id)
        ->and($byStart[1]->task_id)->toBeNull()
        ->and($byStart[1]->ended_at?->equalTo(CarbonImmutable::parse('2026-10-12T12:00:00+00:00')))->toBeTrue()
        ->and($byStart[2]->client_id)->toBe($client->id)
        ->and($byStart[2]->project_id)->toBeNull()
        ->and($byStart[2]->ended_at?->equalTo(CarbonImmutable::parse('2026-10-12T13:10:00+00:00')))->toBeTrue();
});

it('refuses a token that only reads', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [['client_id' => $client->id, 'started_at' => '2026-10-12T10:00:00+02:00', 'duration_seconds' => 600]],
    ])->assertForbidden();

    expect(TimeEntry::query()->count())->toBe(0);
});

it('refuses a request without a token', function (): void {
    $client = ApiFixtures::client();

    $this->postJson('/api/v1/time-entries/batch', [
        'entries' => [['client_id' => $client->id, 'started_at' => '2026-10-12T10:00:00+02:00', 'duration_seconds' => 600]],
    ])->assertUnauthorized();

    expect(TimeEntry::query()->count())->toBe(0);
});
