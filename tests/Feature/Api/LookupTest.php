<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;

/*
 * AP-04, D-12: read-only lookups of clients, projects and tasks, so an importer can resolve what it writes
 * against. Every value is fictional; canary strings are assembled at runtime.
 */

/**
 * A bearer token with the read ability of a fresh Admin.
 */
function lookupToken(): string
{
    return ApiFixtures::token(ApiFixtures::admin(), ['time:read']);
}

/**
 * Archives a model the way the application does (a soft delete), as a system run.
 */
function lookupArchive(object $model): void
{
    app(PartnerContext::class)->runAsSystem(static fn (): mixed => $model->delete());
}

it('answers the task lookup by its KEY-N reference and by its uuid with exactly the six keys', function (): void {
    $project = ApiFixtures::project(ApiFixtures::client(), 'DEMO');
    $task = ApiFixtures::task($project);
    $token = lookupToken();

    $expected = [
        'id' => $task->id,
        'project_id' => $project->id,
        'reference' => 'DEMO-1',
        'title' => $task->title,
        'status' => $task->status->value,
        'priority' => $task->priority->value,
    ];

    $byKey = $this->withToken($token)->getJson('/api/v1/tasks/DEMO-1')->assertOk();
    $byId = $this->withToken($token)->getJson('/api/v1/tasks/'.$task->id)->assertOk();

    expect($byKey->json('data'))->toBe($expected)
        ->and(array_keys($byKey->json('data')))->toBe(['id', 'project_id', 'reference', 'title', 'status', 'priority'])
        ->and($byId->json('data'))->toBe($expected);
});

it('answers 404 with the Czech message for an unknown, a mistyped or an archived task reference', function (): void {
    $project = ApiFixtures::project(ApiFixtures::client(), 'DEMO');
    $archived = ApiFixtures::task($project);
    ApiFixtures::task($project);
    lookupArchive($archived);
    $token = lookupToken();

    foreach (['DEMO-999', 'demo-1', 'DEMO-0', 'DEMO-', 'abc', $archived->reference, $archived->id, (string) Str::uuid()] as $reference) {
        $this->withToken($token)->getJson('/api/v1/tasks/'.$reference)
            ->assertNotFound()
            ->assertJsonPath('message', __('api.errors.not_found'));
    }
});

it('answers 404 for the task of an archived project', function (): void {
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    $token = lookupToken();

    $this->withToken($token)->getJson('/api/v1/tasks/DEMO-1')->assertOk();

    lookupArchive($project);

    $this->withToken($token)->getJson('/api/v1/tasks/DEMO-1')->assertNotFound();
    $this->withToken($token)->getJson('/api/v1/tasks/'.$task->id)->assertNotFound();
});
