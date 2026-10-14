<?php

declare(strict_types=1);

use App\Domain\Api\Jobs\PurgeExpiredIdempotencyKeys;
use App\Domain\Api\Models\IdempotencyKey;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;

/*
 * D-08: idempotency keys live 24 hours; a daily job on one server deletes the expired rows. Real Sanctum tokens;
 * every value is fictional.
 */

it('purges a key a day after use, keeps a live one, and lets the same key create a new entry again', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $body = [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T10:00:00+02:00',
        'duration_seconds' => 3600,
        'description' => 'Fictional import',
    ];
    $headers = ['Idempotency-Key' => 'purge-key-0001'];

    $first = $this->withToken($token)->postJson('/api/v1/time-entries', $body, $headers)->assertCreated();
    $expiredId = IdempotencyKey::query()->where('key', 'purge-key-0001')->value('id');

    // A second key claimed 23 hours later expires tomorrow, when the first one is already 25 hours old.
    $this->travelTo(CarbonImmutable::parse('2026-10-15 07:00:00', 'UTC'));
    Auth::forgetGuards();
    $this->withToken($token)->postJson('/api/v1/time-entries', $body, ['Idempotency-Key' => 'purge-key-0002'])->assertCreated();
    $liveId = IdempotencyKey::query()->where('key', 'purge-key-0002')->value('id');

    $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00:00', 'UTC'));
    expect(IdempotencyKey::query()->count())->toBe(2);

    (new PurgeExpiredIdempotencyKeys)->handle();

    expect(IdempotencyKey::query()->pluck('id')->all())->toBe([$liveId])
        ->and(IdempotencyKey::query()->whereKey($expiredId)->exists())->toBeFalse();

    // A second run finds nothing more to delete.
    (new PurgeExpiredIdempotencyKeys)->handle();

    expect(IdempotencyKey::query()->pluck('id')->all())->toBe([$liveId]);

    Auth::forgetGuards();
    $again = $this->withToken($token)->postJson('/api/v1/time-entries', $body, $headers)->assertCreated();

    expect($again->headers->has('Idempotent-Replayed'))->toBeFalse()
        ->and($again->json('data.id'))->not->toBe($first->json('data.id'))
        ->and(TimeEntry::query()->count())->toBe(3);
});

it('keeps a row that expires in the future, even one that was never completed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $token = ApiFixtures::token($admin, ['time:write']);
    $tokenId = explode('|', $token, 2)[0];

    foreach (['past' => now()->subSecond(), 'future' => now()->addSecond()] as $key => $expiresAt) {
        (new IdempotencyKey)->forceFill([
            'token_id' => $tokenId,
            'key' => $key,
            'request_hash' => str_repeat('a', 64),
            'locked_at' => now()->subHours(25),
            'expires_at' => $expiresAt,
        ])->save();
    }

    (new PurgeExpiredIdempotencyKeys)->handle();

    expect(IdempotencyKey::query()->pluck('key')->all())->toBe(['future']);
});

it('schedules the purge daily on one server under a stable name', function (): void {
    $events = array_values(array_filter(
        app(Schedule::class)->events(),
        static fn ($event): bool => $event->description === 'kokpit-api-idempotency-prune',
    ));

    expect($events)->toHaveCount(1)
        ->and($events[0]->onOneServer)->toBeTrue()
        ->and($events[0]->expression)->toBe('0 0 * * *');
});
