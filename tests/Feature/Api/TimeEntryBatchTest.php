<?php

declare(strict_types=1);

use App\Domain\TimeTracking\Models\TimeEntry;
use App\Http\Controllers\Api\V1\ApiExamples;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

/**
 * A valid batch item for the client, one hour long, starting at the given hour of the fictional day.
 *
 * @return array<string, mixed>
 */
function batchItem(string $clientId, int $hour = 8, string $description = 'Fictional import'): array
{
    return [
        'client_id' => $clientId,
        'started_at' => sprintf('2026-10-12T%02d:00:00+00:00', $hour),
        'duration_seconds' => 3600,
        'description' => $description,
    ];
}

/**
 * An item the Action refuses: it ends an hour before it starts.
 *
 * @return array<string, mixed>
 */
function batchItemEndingBeforeStart(string $clientId, int $hour = 10, string $description = 'Fictional import'): array
{
    return [
        'client_id' => $clientId,
        'started_at' => sprintf('2026-10-12T%02d:00:00+00:00', $hour),
        'ended_at' => sprintf('2026-10-12T%02d:00:00+00:00', $hour - 1),
        'description' => $description,
    ];
}

it('saves the valid items, skips the one that ends before it starts and answers 207 with its index', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [
            batchItem($client->id, 8, 'first'),
            ['client_id' => $client->id, 'started_at' => '2026-10-12T10:00:00+00:00', 'ended_at' => '2026-10-12T09:00:00+00:00', 'description' => 'second'],
            batchItem($client->id, 12, 'third'),
        ],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(2)
        ->and($response->json('failed'))->toBe(1)
        ->and($response->json('errors'))->toHaveCount(1)
        ->and($response->json('errors.0.index'))->toBe(1)
        ->and($response->json('errors.0.errors'))->toHaveKey('ended_at')
        ->and($response->json('errors.0.errors.ended_at.0'))->toBe(__('kokpit.time.errors.end_before_start'))
        ->and(TimeEntry::query()->orderBy('started_at')->pluck('description')->all())->toBe(['first', 'third']);
});

it('reports a shape refusal and an Action refusal in the same index and errors format', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    $otherProject = ApiFixtures::project(ApiFixtures::client(), 'OTHER');
    $token = ApiFixtures::token($admin, ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [
            ['task_id' => 'Fictional task title', 'started_at' => '2026-10-12T08:00:00+00:00', 'duration_seconds' => 600],
            ['project_id' => 'OTHER', 'task_id' => $task->id, 'started_at' => '2026-10-12T09:00:00+00:00', 'duration_seconds' => 600],
            ['client_id' => $client->id, 'started_at' => '2026-10-12T10:00:00+00:00', 'duration_seconds' => 600],
        ],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(1)
        ->and($response->json('failed'))->toBe(2)
        ->and(array_column($response->json('errors'), 'index'))->toBe([0, 1])
        ->and($response->json('errors.0.errors.task_id.0'))->toBe(__('kokpit.time.errors.task_unavailable'))
        ->and($response->json('errors.1.errors'))->toHaveKey('task_id')
        ->and(TimeEntry::query()->count())->toBe(1)
        ->and($otherProject->key)->toBe('OTHER');
});

it('answers 422 with the counts, the errors and a message when no item can be saved', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [
            ['client_id' => $client->id, 'started_at' => 'not a date', 'duration_seconds' => 600],
            ['client_id' => $client->id, 'started_at' => '2026-10-12T10:00:00+00:00'],
        ],
    ])->assertStatus(422);

    expect($response->json())->toHaveKeys(['message', 'saved', 'failed', 'errors'])
        ->and($response->json('message'))->toBe(__('api.errors.batch_none_saved'))
        ->and($response->json('saved'))->toBe(0)
        ->and($response->json('failed'))->toBe(2)
        ->and(array_column($response->json('errors'), 'index'))->toBe([0, 1])
        ->and($response->json('errors.0.errors'))->toHaveKey('started_at')
        ->and($response->json('errors.1.errors'))->toHaveKey('ended_at')
        ->and(TimeEntry::query()->count())->toBe(0);
});

it('refuses an empty list and more than 100 items under entries and saves nothing', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', ['entries' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entries');

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entries');

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', ['entries' => array_fill(0, 101, batchItem($client->id))])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entries');

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', ['entries' => ['a' => batchItem($client->id)]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entries');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('saves exactly 100 items', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);
    $entries = [];

    for ($i = 0; $i < 100; $i++) {
        $entries[] = batchItem($client->id, $i % 24, 'item '.$i);
    }

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', ['entries' => $entries])
        ->assertCreated()
        ->assertExactJson(['saved' => 100, 'failed' => 0, 'errors' => []]);

    expect(TimeEntry::query()->count())->toBe(100);
});

it('reads the item limit from the configuration', function (): void {
    config(['kokpit.api.batch_max_items' => 2]);
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', ['entries' => array_fill(0, 3, batchItem($client->id))])
        ->assertStatus(422)
        ->assertJsonValidationErrors('entries');

    expect(TimeEntry::query()->count())->toBe(0);
});

it('fails an item that is not a JSON object under entry and keeps its neighbours', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [batchItem($client->id, 8), 5, 'text', [1, 2], null, batchItem($client->id, 9)],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(2)
        ->and($response->json('failed'))->toBe(4)
        ->and(array_column($response->json('errors'), 'index'))->toBe([1, 2, 3, 4]);

    foreach ($response->json('errors') as $error) {
        expect($error['errors'])->toBe(['entry' => [__('api.errors.batch_item_not_object')]]);
    }

    expect(TimeEntry::query()->count())->toBe(2);
});

it('keeps an external object that is empty and fails an item whose external is a list', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $response = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [
            [...batchItem($client->id, 8), 'external' => ['source' => 'demo-tracker']],
            [...batchItem($client->id, 9), 'external' => []],
            batchItem($client->id, 10),
        ],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(2)
        ->and($response->json('errors.0.index'))->toBe(1)
        ->and($response->json('errors.0.errors'))->toHaveKey('external');

    Auth::forgetGuards();

    // A PHP array cannot be a JSON object when empty, so the empty object is a stdClass.
    $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [[...batchItem($client->id, 11), 'external' => new stdClass]],
    ])->assertCreated();

    $stored = DB::selectOne("select jsonb_typeof(external) as type, external::text as text from time_entries where description = 'Fictional import' and started_at = ?", ['2026-10-12 11:00:00+00']);

    expect($stored->type)->toBe('object')
        ->and($stored->text)->toBe('{}');
});

it('leaves the database with exactly the good rows when an item in the middle fails', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [
            batchItem($client->id, 8, 'before'),
            batchItemEndingBeforeStart($client->id, 9, 'broken'),
            batchItem($client->id, 10, 'after'),
        ],
    ])->assertStatus(207);

    expect(TimeEntry::query()->orderBy('started_at')->pluck('description')->all())->toBe(['before', 'after'])
        ->and(TimeEntry::query()->count())->toBe(2);
});

it('documents answers that have the layout of the real answers', function (): void {
    $client = ApiFixtures::client();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    $mixed = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [batchItem($client->id, 8), batchItemEndingBeforeStart($client->id, 9)],
    ])->assertStatus(207);

    Auth::forgetGuards();

    $none = $this->withToken($token)->postJson('/api/v1/time-entries/batch', [
        'entries' => [batchItemEndingBeforeStart($client->id, 9)],
    ])->assertStatus(422);

    expect(array_keys(ApiExamples::BATCH_ALL_SAVED))->toBe(['saved', 'failed', 'errors'])
        ->and(array_keys(ApiExamples::BATCH_MIXED))->toBe(array_keys($mixed->json()))
        ->and(array_keys(ApiExamples::BATCH_MIXED['errors'][0]))->toBe(array_keys($mixed->json('errors.0')))
        ->and(array_keys(ApiExamples::BATCH_NONE_SAVED))->toBe(array_keys($none->json()))
        ->and(ApiExamples::BATCH_MIXED['errors'][0]['errors']['ended_at'])->toBe($mixed->json('errors.0.errors.ended_at'));
});
