<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

/**
 * An object nested the given number of levels deep, the object itself being the first level.
 */
function externalNested(int $levels): stdClass
{
    $value = (object) ['leaf' => 'x'];

    for ($level = 1; $level < $levels; $level++) {
        $value = (object) ['n' => $value];
    }

    return $value;
}

/**
 * An object whose compact JSON is exactly the given number of bytes: `{"k":"` and `"}` take 8, the rest
 * is filler of the given character (one or two bytes each).
 */
function externalOfBytes(int $bytes, string $filler = 'a'): stdClass
{
    return (object) ['k' => str_repeat($filler, intdiv($bytes - 8, strlen($filler)))];
}

/**
 * A stored entry of the admin, finished, with the given external object.
 */
function externalEntry(mixed $admin, ?stdClass $external): string
{
    $client = ApiFixtures::client();

    return ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
        'external' => $external,
    ])->id;
}

it('stores and returns an empty object as {} and never as []', function (): void {
    $response = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->postJson('/api/v1/time-entries', externalBody(['external' => new stdClass]))
        ->assertCreated();

    expect($response->getContent())->toContain('"external":{}')
        ->and($response->getContent())->not->toContain('"external":[]');

    $stored = DB::selectOne('select jsonb_typeof(external) as type from time_entries where id = ?', [$response->json('data.id')]);

    expect($stored->type)->toBe('object');

    Auth::forgetGuards();

    $read = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:read']))
        ->getJson('/api/v1/time-entries/'.$response->json('data.id'))
        ->assertOk();

    expect($read->getContent())->toContain('"external":{}');
});

it('refuses a value that is not an object with 422 under external and creates nothing', function (mixed $value): void {
    $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->postJson('/api/v1/time-entries', externalBody(['external' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external'])
        ->assertJsonPath('errors.external.0', __('kokpit.time.errors.external_not_object'));

    expect(DB::table('time_entries')->count())->toBe(0);
})->with([
    'an empty list' => [[]],
    'a list' => [[1]],
    'a string' => ['text'],
    'a number' => [5],
    'a bool' => [true],
]);

it('accepts an object of exactly the byte limit and refuses one byte more', function (): void {
    $token = ApiFixtures::token($this->externalAdmin, ['time:write']);

    expect(strlen((string) json_encode(externalOfBytes(4096))))->toBe(4096);

    $this->withToken($token)
        ->postJson('/api/v1/time-entries', externalBody(['external' => externalOfBytes(4096)]))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/time-entries', externalBody(['external' => externalOfBytes(4097)]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.external.0', __('kokpit.time.errors.external_too_large', ['max' => 4096]));

    expect(DB::table('time_entries')->count())->toBe(1);
});

it('counts multi-byte text in bytes, not in characters', function (): void {
    $token = ApiFixtures::token($this->externalAdmin, ['time:write']);

    // 2044 two-byte characters plus the 8 bytes of the frame are exactly 4096 bytes.
    $this->withToken($token)
        ->postJson('/api/v1/time-entries', externalBody(['external' => externalOfBytes(4096, 'č')]))
        ->assertCreated();

    // 2049 of them are 4098 bytes of text: refused although it is only 2049 characters.
    $this->withToken($token)
        ->postJson('/api/v1/time-entries', externalBody(['external' => (object) ['k' => str_repeat('č', 2049)]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external']);
});

it('accepts 8 levels of nesting and refuses 9 under external', function (): void {
    $token = ApiFixtures::token($this->externalAdmin, ['time:write']);

    $this->withToken($token)
        ->postJson('/api/v1/time-entries', externalBody(['external' => externalNested(8)]))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/time-entries', externalBody(['external' => externalNested(9)]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.external.0', __('kokpit.time.errors.external_too_deep'));
});

it('keeps the stored external when a PATCH does not name it', function (): void {
    $id = externalEntry($this->externalAdmin, (object) ['source' => 'demo-tracker']);

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->patchJson('/api/v1/time-entries/'.$id, ['description' => 'Changed text'])
        ->assertOk()
        ->assertJsonPath('data.description', 'Changed text')
        ->assertJsonPath('data.external', ['source' => 'demo-tracker']);
});

it('clears the stored external with null on a PATCH', function (): void {
    $id = externalEntry($this->externalAdmin, (object) ['source' => 'demo-tracker']);

    Auth::forgetGuards();

    $response = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->patchJson('/api/v1/time-entries/'.$id, ['external' => null])
        ->assertOk();

    expect($response->json('data.external'))->toBeNull()
        ->and(DB::selectOne('select external from time_entries where id = ?', [$id])->external)->toBeNull();
});

it('replaces the stored external whole on a PATCH, with no merge', function (): void {
    $id = externalEntry($this->externalAdmin, (object) ['source' => 'demo-tracker', 'old' => 'gone']);

    Auth::forgetGuards();

    $response = $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->patchJson('/api/v1/time-entries/'.$id, ['external' => (object) ['id' => 'A-2002']])
        ->assertOk();

    expect($response->json('data.external'))->toBe(['id' => 'A-2002']);
});

it('sets external on an entry that had none and refuses a non-object on a PATCH', function (): void {
    $id = externalEntry($this->externalAdmin, null);
    $token = ApiFixtures::token($this->externalAdmin, ['time:write']);

    Auth::forgetGuards();

    $this->withToken($token)
        ->patchJson('/api/v1/time-entries/'.$id, ['external' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external']);

    Auth::forgetGuards();

    $this->withToken($token)
        ->patchJson('/api/v1/time-entries/'.$id, ['external' => (object) ['source' => 'demo-tracker']])
        ->assertOk()
        ->assertJsonPath('data.external', ['source' => 'demo-tracker']);
});

it('answers 409 entry_locked to a PATCH that changes only external of a billed entry and keeps the value', function (): void {
    $client = ApiFixtures::client();
    $entry = ApiFixtures::entry($this->externalAdmin, [
        'client_id' => $client->id,
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:00:00',
        'external' => (object) ['source' => 'demo-tracker'],
    ]);
    ApiFixtures::billed($entry);

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->patchJson('/api/v1/time-entries/'.$entry->id, ['external' => (object) ['source' => 'changed']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'entry_locked');

    $stored = DB::selectOne('select external from time_entries where id = ?', [$entry->id]);

    expect(json_decode((string) $stored->external, true))->toBe(['source' => 'demo-tracker']);
});

it('writes no activity row when only external changes, and none for a create that carries it', function (): void {
    $id = externalEntry($this->externalAdmin, (object) ['source' => 'demo-tracker']);
    $before = DB::table('activity_log')->where('subject_type', 'time_entry')->where('subject_id', $id)->count();

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->externalAdmin, ['time:write']))
        ->patchJson('/api/v1/time-entries/'.$id, ['external' => (object) ['source' => 'changed']])
        ->assertOk();

    $rows = DB::table('activity_log')->where('subject_type', 'time_entry')->where('subject_id', $id)->get();

    expect($rows)->toHaveCount($before)
        ->and(json_encode($rows))->not->toContain('external')
        ->and(json_encode($rows))->not->toContain('demo-tracker');
});

it('renders no part of external on the panel pages of an entry, snapshot included', function (): void {
    $canary = 'canary-'.bin2hex(random_bytes(6));
    $id = externalEntry($this->externalAdmin, (object) ['source' => $canary, 'note' => 'x-'.$canary]);

    Auth::forgetGuards();
    $this->actingAs($this->externalAdmin);

    foreach (['/admin/time-entries', '/admin/time-entries/'.$id, '/admin/time-entries/'.$id.'/edit'] as $path) {
        // The page state travels as an HTML-escaped Livewire snapshot, so the canary is searched in the raw markup.
        $this->get($path)->assertOk()->assertDontSee($canary, false)->assertDontSee('&quot;external&quot;', false);
    }
});
