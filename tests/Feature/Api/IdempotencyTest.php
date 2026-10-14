<?php

declare(strict_types=1);

use App\Domain\Api\Models\IdempotencyKey;
use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;

/*
 * AP-05, D-06 to D-08, OD-3: an optional Idempotency-Key makes a retried create safe. The claim lives in
 * PostgreSQL; a repeat of the same key and body replays the stored answer. Real Sanctum tokens; every value
 * is fictional.
 */

/**
 * A valid create body for the given client.
 *
 * @return array<string, mixed>
 */
function idempotentBody(string $clientId, string $description = 'Fictional import'): array
{
    return [
        'client_id' => $clientId,
        'started_at' => '2026-10-12T10:00:00+02:00',
        'duration_seconds' => 3600,
        'description' => $description,
    ];
}

it('creates one entry for the same key and body sent twice, and replays the stored 201 byte for byte', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $headers = ['Idempotency-Key' => 'demo-key-0001'];

    $first = $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), $headers)
        ->assertCreated();

    // A real client is a new request: the guard of the test keeps the user of the first request.
    Auth::forgetGuards();

    $second = $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), $headers)
        ->assertCreated()
        ->assertHeader('Idempotent-Replayed', 'true');

    expect($second->getContent())->toBe($first->getContent())
        ->and($first->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and(TimeEntry::query()->count())->toBe(1);
});

it('creates an entry on every request that carries no key', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $first = $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id))->assertCreated();
    Auth::forgetGuards();
    $second = $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id))->assertCreated();

    expect($second->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($second->json('data.id'))->not->toBe($first->json('data.id'))
        ->and(TimeEntry::query()->count())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(0);
});
