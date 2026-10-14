<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiFixtures;

/*
 * Rate limits of the REST API, per token (AP-05, D-16). Real Sanctum tokens;
 * every value is fictional.
 */

it('limits reads to 120 and writes to 60 a minute by default and carries the other API settings', function (): void {
    expect(config('kokpit.api.rate_limit.read'))->toBe(120)
        ->and(config('kokpit.api.rate_limit.write'))->toBe(60)
        ->and(config('kokpit.api.docs_rate_limit'))->toBe(60)
        ->and(config('kokpit.api.page_size.default'))->toBe(50)
        ->and(config('kokpit.api.page_size.max'))->toBe(200)
        ->and(config('kokpit.api.batch_max_items'))->toBe(100)
        ->and(config('kokpit.api.external_max_bytes'))->toBe(4096)
        ->and(config('kokpit.api.idempotency_ttl_hours'))->toBe(24)
        ->and(config('kokpit.api.idempotency_stale_lock_seconds'))->toBe(120);
});

it('answers 429 with Retry-After and the rate limit headers over the read limit, one bucket per token', function (): void {
    config(['kokpit.api.rate_limit.read' => 3]);
    $admin = ApiFixtures::admin();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $path = '/api/v1/time-entries/'.$entry->id;
    $first = ApiFixtures::token($admin, ['time:read']);
    $second = ApiFixtures::token($admin, ['time:read']);

    foreach ([2, 1, 0] as $remaining) {
        $this->withToken($first)->getJson($path)
            ->assertOk()
            ->assertHeader('X-RateLimit-Limit', '3')
            ->assertHeader('X-RateLimit-Remaining', (string) $remaining);
    }

    $this->withToken($first)->getJson($path)
        ->assertStatus(429)
        ->assertHeader('X-RateLimit-Limit', '3')
        ->assertHeader('X-RateLimit-Remaining', '0')
        ->assertHeader('Retry-After');

    // A real client is a new request: the guard of the test keeps the user of the first token.
    Auth::forgetGuards();

    $this->withToken($second)->getJson($path)->assertOk();
});

it('keeps the write bucket apart from the read bucket of the same token', function (): void {
    config(['kokpit.api.rate_limit.write' => 2]);
    Route::middleware(['auth:sanctum', 'throttle:api-write'])
        ->post('api/v1/probe-write', static fn () => response()->json(['ok' => true]));

    $admin = ApiFixtures::admin();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => ApiFixtures::client()->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:read', 'time:write']);

    $this->withToken($token)->postJson('/api/v1/probe-write')->assertOk();
    $this->withToken($token)->postJson('/api/v1/probe-write')->assertOk();
    $this->withToken($token)->postJson('/api/v1/probe-write')->assertStatus(429)->assertHeader('Retry-After');

    $this->withToken($token)->getJson('/api/v1/time-entries/'.$entry->id)->assertOk();
});

it('puts the token guard, the ability check and the read limiter on the read route', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.time-entries.show');

    expect($route)->not->toBeNull()
        ->and($route->gatherMiddleware())->toContain('auth:sanctum', 'abilities:time:read', 'throttle:api-read');
});
