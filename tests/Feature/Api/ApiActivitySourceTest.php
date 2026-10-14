<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySource;
use App\Domain\Audit\ActivitySourceLabel;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\Probes\ActivityProbe;

/*
 * OD-4, Phase 3 D-08: a change made through /api/v1 is logged with the source 'api'. The label comes
 * from the execution context (a middleware of the api group), never from the request. The web
 * context is simulated the way ActivitySourceTest does it, because the test runner is a console
 * process. Real Sanctum tokens; every value is fictional.
 */

/**
 * The raw activity rows of a time entry, oldest first, read without the partner scope.
 *
 * @return list<object>
 */
function apiActivityRows(string $entryId, string $event): array
{
    return DB::table('activity_log')
        ->where('subject_id', $entryId)
        ->where('event', $event)
        ->orderBy('created_at')
        ->get()
        ->all();
}

it('logs an entry created through the API with the source api and the Admin as causer', function (): void {
    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $id = $this->withToken($token)->postJson('/api/v1/time-entries', [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T08:00:00Z',
        'duration_seconds' => 1800,
        'description' => 'Fictional import',
    ])->assertCreated()->json('data.id');

    $rows = apiActivityRows($id, 'created');

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->source)->toBe('api')
        ->and($rows[0]->causer_id)->toBe($admin->getKey());
});

it('logs an update and a delete through the API with the source api', function (): void {
    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $entry = ApiFixtures::entry($admin, [
        'client_id' => $client->id,
        'description' => 'Fictional work',
        'started_at' => '2026-10-12 08:00:00',
        'ended_at' => '2026-10-12 09:30:00',
    ]);
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->patchJson('/api/v1/time-entries/'.$entry->id, ['ended_at' => '2026-10-12T10:00:00Z'])->assertOk();
    $this->withToken($token)->deleteJson('/api/v1/time-entries/'.$entry->id)->assertNoContent();

    $updated = apiActivityRows($entry->id, 'updated');
    $deleted = apiActivityRows($entry->id, 'deleted');

    expect($updated)->not->toBeEmpty()
        ->and(array_unique(array_column($updated, 'source')))->toBe(['api'])
        ->and($deleted)->not->toBeEmpty()
        ->and(array_unique(array_column($deleted, 'source')))->toBe(['api']);
});

it('leaves the label after the request: a panel write in a web request stays web and a console write stays console', function (): void {
    ActivityProbe::provision();
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $this->withToken($token)->postJson('/api/v1/time-entries', [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T08:00:00Z',
        'duration_seconds' => 60,
    ])->assertCreated();

    expect(app(ActivitySource::class)->current())->toBe(ActivitySourceLabel::Console);

    ActivityProbe::query()->create(['title' => 'console after api']);

    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));
    $this->actingAs(Canary::admin());
    ActivityProbe::query()->create(['title' => 'web after api']);

    $sources = DB::table('activity_log')
        ->where('subject_type', ActivityProbe::ALIAS)
        ->where('event', 'created')
        ->pluck('source', 'subject_id');

    $console = ActivityProbe::query()->where('title', 'console after api')->firstOrFail()->getKey();
    $web = ActivityProbe::query()->where('title', 'web after api')->firstOrFail()->getKey();

    expect($sources[$console])->toBe('console')
        ->and($sources[$web])->toBe('web');

    ActivityProbe::restoreMorphMap();
});

it('does not let a request choose its own source label', function (): void {
    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $token = ApiFixtures::token($admin, ['time:write']);

    $id = $this->withToken($token)->postJson('/api/v1/time-entries?source=web', [
        'client_id' => $client->id,
        'started_at' => '2026-10-12T08:00:00Z',
        'duration_seconds' => 60,
        'source' => 'console',
    ], ['X-Activity-Source' => 'webhook'])->assertCreated()->json('data.id');

    expect(array_column(apiActivityRows($id, 'created'), 'source'))->toBe(['api']);
});
