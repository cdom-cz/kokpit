<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySource;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiFixtures;

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
