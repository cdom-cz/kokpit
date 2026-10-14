<?php

declare(strict_types=1);

use App\Domain\Api\Idempotency\IdempotencyOutcome;
use App\Domain\Api\Idempotency\IdempotencyStore;
use App\Domain\Api\Models\IdempotencyKey;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiDocs;
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

/**
 * The id of the token row of a plain text token `<uuid>|<secret>`.
 */
function idempotencyTokenId(string $plainToken): string
{
    return explode('|', $plainToken, 2)[0];
}

/**
 * Arranges a claim row of the token for the exact request `postJson` will send: the hash is the sha256
 * of method, path and raw body, and `postJson` sends json_encode of the array.
 *
 * @param  array<string, mixed>  $body
 * @param  array<string, mixed>  $attributes
 */
function arrangeClaim(string $plainToken, string $key, array $body, array $attributes = []): IdempotencyKey
{
    $row = new IdempotencyKey;
    $row->forceFill(array_merge([
        'token_id' => idempotencyTokenId($plainToken),
        'key' => $key,
        'request_hash' => hash('sha256', "POST\napi/v1/time-entries\n".json_encode($body)),
        'locked_at' => now(),
        'expires_at' => now()->addHours(24),
    ], $attributes))->save();

    return $row;
}

it('refuses the same key with a different body with 422 idempotency_key_reused and creates nothing', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $headers = ['Idempotency-Key' => 'demo-key-0002'];

    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), $headers)->assertCreated();
    Auth::forgetGuards();

    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id, 'Another description'), $headers)
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_reused')
        ->assertJsonPath('message', __('api.errors.idempotency_key_reused'))
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(TimeEntry::query()->count())->toBe(1);
});

it('answers 409 idempotency_in_progress while another request holds a fresh claim', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $body = idempotentBody($client->id);
    arrangeClaim($token, 'demo-key-0003', $body, ['locked_at' => now()->subSeconds(10)]);

    $this->withToken($token)->postJson('/api/v1/time-entries', $body, ['Idempotency-Key' => 'demo-key-0003'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_in_progress')
        ->assertJsonPath('message', __('api.errors.idempotency_in_progress'));

    expect(TimeEntry::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(1);
});

it('takes over a claim older than 121 seconds without a response and completes it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $body = idempotentBody($client->id);
    $row = arrangeClaim($token, 'demo-key-0004', $body, ['locked_at' => now()->subSeconds(121)]);

    $this->withToken($token)->postJson('/api/v1/time-entries', $body, ['Idempotency-Key' => 'demo-key-0004'])
        ->assertCreated();

    $stored = IdempotencyKey::query()->sole();

    expect(TimeEntry::query()->count())->toBe(1)
        ->and($stored->id)->toBe($row->id)
        ->and($stored->response_status)->toBe(201)
        ->and($stored->completed_at)->not->toBeNull()
        ->and($stored->locked_at->equalTo(now()->startOfSecond()))->toBeTrue();
});

it('lets only one of two takeovers of a stale claim own it', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $row = arrangeClaim($token, 'demo-key-0005', idempotentBody($client->id), ['locked_at' => now()->subSeconds(300)]);
    $hash = $row->request_hash;
    $store = app(IdempotencyStore::class);

    $first = $store->claim(idempotencyTokenId($token), 'demo-key-0005', $hash);
    $second = $store->claim(idempotencyTokenId($token), 'demo-key-0005', $hash);

    expect($first->outcome)->toBe(IdempotencyOutcome::Claimed)
        ->and($second->outcome)->toBe(IdempotencyOutcome::InProgress);

    // The slow first owner (the row as it was read before the takeover) finishing late must change nothing.
    $store->complete($row, 201, '{"data":"late"}');
    $store->release($row);

    expect(IdempotencyKey::query()->sole()->completed_at)->toBeNull();

    $store->release($first->row);

    expect(IdempotencyKey::query()->count())->toBe(0);
});

it('treats a completed row past its expiry as absent and stores a fresh one', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $body = idempotentBody($client->id);
    arrangeClaim($token, 'demo-key-0006', $body, [
        'locked_at' => now()->subHours(25),
        'expires_at' => now()->subSecond(),
        'response_status' => 201,
        'response_body' => '{"data":"old"}',
        'completed_at' => now()->subHours(25),
    ]);
    // An expired row of another body is absent as well.
    arrangeClaim($token, 'demo-key-0007', idempotentBody($client->id, 'Other'), [
        'locked_at' => now()->subHours(25),
        'expires_at' => now()->subSecond(),
        'response_status' => 201,
        'response_body' => '{"data":"old"}',
        'completed_at' => now()->subHours(25),
    ]);

    $this->withToken($token)->postJson('/api/v1/time-entries', $body, ['Idempotency-Key' => 'demo-key-0006'])
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');
    Auth::forgetGuards();
    $this->withToken($token)->postJson('/api/v1/time-entries', $body, ['Idempotency-Key' => 'demo-key-0007'])
        ->assertCreated();

    $fresh = IdempotencyKey::query()->where('key', 'demo-key-0006')->sole();

    expect(TimeEntry::query()->count())->toBe(2)
        ->and($fresh->response_body)->not->toBe('{"data":"old"}')
        ->and($fresh->expires_at->greaterThan(now()))->toBeTrue();
});

it('leaves no row after a 422 and lets the corrected request with the same key create the entry', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    $headers = ['Idempotency-Key' => 'demo-key-0008'];
    $invalid = ['client_id' => $client->id, 'started_at' => '2026-10-12T10:00:00+02:00'];

    $this->withToken($token)->postJson('/api/v1/time-entries', $invalid, $headers)->assertStatus(422);

    expect(IdempotencyKey::query()->count())->toBe(0);

    Auth::forgetGuards();
    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), $headers)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect(TimeEntry::query()->count())->toBe(1)
        ->and(IdempotencyKey::query()->sole()->response_status)->toBe(201);
});

it('leaves no row when the controller throws, and the exception surfaces as 500', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);
    TimeEntry::creating(static function (): void {
        throw new RuntimeException('forced failure');
    });

    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), ['Idempotency-Key' => 'demo-key-0009'])
        ->assertStatus(500);

    expect(IdempotencyKey::query()->count())->toBe(0)
        ->and(TimeEntry::query()->count())->toBe(0);
});

it('stores status 201, the completion time and an expiry 24 hours after the claim', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 12:00:00', 'UTC'));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), ['Idempotency-Key' => 'demo-key-0010'])
        ->assertCreated();

    $row = IdempotencyKey::query()->sole();

    expect($row->response_status)->toBe(201)
        ->and($row->response_body)->toBe($response->getContent())
        ->and($row->completed_at)->not->toBeNull()
        ->and($row->token_id)->toBe(idempotencyTokenId($token))
        ->and($row->expires_at->equalTo($row->locked_at->addHours(24)))->toBeTrue();
});

it('refuses an empty, an over-long and a non-printable key with 422 idempotency_key_invalid and creates nothing', function (string $key): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), ['Idempotency-Key' => $key])
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_invalid')
        ->assertJsonPath('message', __('api.errors.idempotency_key_invalid'));

    expect(TimeEntry::query()->count())->toBe(0)
        ->and(IdempotencyKey::query()->count())->toBe(0);
})->with([
    'empty' => [''],
    '256 characters' => [str_repeat('k', 256)],
    'a space' => ['demo key'],
    'a control character' => ["demo\x01key"],
    'a non-ASCII letter' => ["demo-kl\u{00ED}\u{010D}"],
]);

it('accepts a key of exactly 255 printable characters', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), ['Idempotency-Key' => str_repeat('k', 255)])
        ->assertCreated();

    expect(IdempotencyKey::query()->sole()->key)->toBe(str_repeat('k', 255));
});

it('keeps the keys of two tokens of the same Admin apart, so neither replays the other', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $first = ApiFixtures::token($admin, ['time:write']);
    $second = ApiFixtures::token($admin, ['time:write']);
    $headers = ['Idempotency-Key' => 'demo-key-0011'];

    $one = $this->withToken($first)->postJson('/api/v1/time-entries', idempotentBody($client->id), $headers)->assertCreated();
    Auth::forgetGuards();
    $two = $this->withToken($second)->postJson('/api/v1/time-entries', idempotentBody($client->id), $headers)
        ->assertCreated()
        ->assertHeaderMissing('Idempotent-Replayed');

    expect($two->json('data.id'))->not->toBe($one->json('data.id'))
        ->and(TimeEntry::query()->count())->toBe(2)
        ->and(IdempotencyKey::query()->count())->toBe(2);
});

it('ignores the header on PATCH, DELETE and GET and stores nothing for them', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:read', 'time:write']);
    $headers = ['Idempotency-Key' => 'demo-key-0012'];
    $path = '/api/v1/time-entries/'.$entry->id;

    $this->withToken($token)->getJson($path, $headers)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    Auth::forgetGuards();
    $this->withToken($token)->patchJson($path, ['description' => 'Changed'], $headers)->assertOk();
    Auth::forgetGuards();
    // The same PATCH again is applied again: no replay and no 422 for a repeated key.
    $this->withToken($token)->patchJson($path, ['description' => 'Changed again'], $headers)
        ->assertOk()
        ->assertJsonPath('data.description', 'Changed again')
        ->assertHeaderMissing('Idempotent-Replayed');
    Auth::forgetGuards();
    $this->withToken($token)->deleteJson($path, [], $headers)->assertNoContent();

    expect(IdempotencyKey::query()->count())->toBe(0)
        ->and(TimeEntry::query()->count())->toBe(0);
});

it('deletes the keys of a token with the token', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries', idempotentBody($client->id), ['Idempotency-Key' => 'demo-key-0013'])
        ->assertCreated();

    expect(IdempotencyKey::query()->count())->toBe(1);

    $admin->tokens()->delete();

    expect(IdempotencyKey::query()->count())->toBe(0);
});

it('documents the Idempotency-Key header, the 409 and 422 answers and the replay header on POST time-entries', function (): void {
    ApiDocs::ensureGenerated();
    $route = Route::getRoutes()->getByName('api.v1.time-entries.store');
    $operation = ApiDocs::operation($route);
    $headers = array_map(
        static fn (array $parameter): string => strtolower((string) $parameter['name']),
        array_filter($operation['parameters'] ?? [], static fn (array $parameter): bool => ($parameter['in'] ?? null) === 'header'),
    );

    expect($headers)->toContain('idempotency-key')
        ->and($operation['responses'])->toHaveKeys([201, 409, 422])
        ->and($operation['description'])->toContain('Idempotent-Replayed')
        ->toContain('idempotency_key_reused')
        ->toContain('idempotency_in_progress')
        ->toContain('24 hours');

    // The documentation rule is not vacuous: the route carries the middleware, so it asks for the header.
    expect(ApiDocs::violations([$route], ApiDocs::spec()))->toBe([]);

    $spec = ApiDocs::spec();
    unset($spec['paths']['/'.ApiDocs::documentedUri($route)]['post']['responses'][409]);

    expect(ApiDocs::violations([$route], $spec))->toContain('POST /api/v1/time-entries documents no 409 response');
});
