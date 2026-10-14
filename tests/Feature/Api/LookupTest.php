<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
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

/**
 * A client with the given name, created as a system run like the fixtures do.
 */
function lookupClient(string $name): Client
{
    return app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create(['name' => $name]));
}

/**
 * The ids of a lookup list answer, in order.
 *
 * @return list<string>
 */
function lookupIds(mixed $test, string $url): array
{
    return array_column($test->withToken(lookupToken())->getJson($url)->assertOk()->json('data'), 'id');
}

it('lists the non-archived clients newest first with exactly the keys id and name', function (): void {
    $oldest = lookupClient('Example client one');
    $newest = lookupClient('Example client two');
    $archived = lookupClient('Example client archived');
    lookupArchive($archived);

    $response = $this->withToken(lookupToken())->getJson('/api/v1/clients')->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$newest->id, $oldest->id])
        ->and($response->json('data.0'))->toBe(['id' => $newest->id, 'name' => 'Example client two'])
        ->and($response->json())->toHaveKeys(['data', 'links', 'meta'])
        ->and($response->json('meta'))->toHaveKey('next_cursor');
});

it('finds a client by a case-insensitive part of its name', function (): void {
    $match = lookupClient('Fiktivní klient');
    lookupClient('Example other');

    expect(lookupIds($this, '/api/v1/clients?filter[name]=fikt'))->toBe([$match->id])
        ->and(lookupIds($this, '/api/v1/clients?filter[name]=FIKTIVNÍ+KL'))->toBe([$match->id])
        ->and(lookupIds($this, '/api/v1/clients?filter[name]=nothing-like-this'))->toBe([]);
});

it('matches a comma, a percent sign and an underscore in filter[name] literally', function (): void {
    $comma = lookupClient('Fiktivní, s.r.o.');
    lookupClient('Fiktivní klient');
    $percent = lookupClient('Example 50% off');
    $underscore = lookupClient('Example a_b');
    lookupClient('Example axb');

    expect(lookupIds($this, '/api/v1/clients?filter[name]='.urlencode('Fiktivní, s.r.o.')))->toBe([$comma->id])
        ->and(lookupIds($this, '/api/v1/clients?filter[name]='.urlencode(',')))->toBe([$comma->id])
        ->and(lookupIds($this, '/api/v1/clients?filter[name]='.urlencode('%')))->toBe([$percent->id])
        ->and(lookupIds($this, '/api/v1/clients?filter[name]='.urlencode('_')))->toBe([$underscore->id])
        ->and(lookupIds($this, '/api/v1/clients?filter[name]='.urlencode('a_b')))->toBe([$underscore->id]);
});

it('answers a client by its uuid and 404 for an archived, unknown or malformed reference', function (): void {
    $client = lookupClient('Fiktivní klient');
    $archived = lookupClient('Example archived');
    lookupArchive($archived);
    $token = lookupToken();

    $this->withToken($token)->getJson('/api/v1/clients/'.$client->id)
        ->assertOk()
        ->assertExactJson(['data' => ['id' => $client->id, 'name' => 'Fiktivní klient']]);

    foreach ([$archived->id, (string) Str::uuid(), 'abc', 'DEMO', '1'] as $reference) {
        $this->withToken($token)->getJson('/api/v1/clients/'.$reference)
            ->assertNotFound()
            ->assertJsonPath('message', __('api.errors.not_found'));
    }
});

it('lists only selectable projects and narrows them by client', function (): void {
    $client = ApiFixtures::client();
    $other = ApiFixtures::client();
    $archivedClient = ApiFixtures::client();
    $first = ApiFixtures::project($client, 'DEMO');
    $second = ApiFixtures::project($other, 'OTHR');
    $ofArchivedClient = ApiFixtures::project($archivedClient, 'GONE');
    $archivedProject = ApiFixtures::project($client, 'OLDP');
    lookupArchive($archivedClient);
    lookupArchive($archivedProject);

    $all = lookupIds($this, '/api/v1/projects');

    expect($all)->toBe([$second->id, $first->id])
        ->and($all)->not->toContain($ofArchivedClient->id, $archivedProject->id)
        ->and(lookupIds($this, '/api/v1/projects?filter[client]='.$client->id))->toBe([$first->id])
        ->and(lookupIds($this, '/api/v1/projects?filter[client]='.$archivedClient->id))->toBe([])
        ->and(lookupIds($this, '/api/v1/projects?filter[name]='.urlencode($second->name)))->toBe([$second->id]);

    $item = $this->withToken(lookupToken())->getJson('/api/v1/projects?filter[client]='.$client->id)->json('data.0');

    expect($item)->toBe([
        'id' => $first->id,
        'client_id' => $client->id,
        'key' => 'DEMO',
        'name' => $first->name,
        'status' => $first->status->value,
        'priority' => $first->priority->value,
    ]);
});

it('answers a project by its key and by its uuid and 404 for an archived, unknown or mistyped one', function (): void {
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $archived = ApiFixtures::project($client, 'OLDP');
    lookupArchive($archived);
    $token = lookupToken();

    $byKey = $this->withToken($token)->getJson('/api/v1/projects/DEMO')->assertOk();
    $byId = $this->withToken($token)->getJson('/api/v1/projects/'.$project->id)->assertOk();

    expect($byKey->json('data.id'))->toBe($project->id)
        ->and($byId->json('data'))->toBe($byKey->json('data'));

    foreach (['NONE', 'demo', 'D', 'TOOLONGKEY', 'OLDP', $archived->id, (string) Str::uuid(), 'DEMO-1'] as $reference) {
        $this->withToken($token)->getJson('/api/v1/projects/'.$reference)
            ->assertNotFound()
            ->assertJsonPath('message', __('api.errors.not_found'));
    }
});

it('answers 422 under the offending key for an unknown filter, a bad uuid, a page size or a sort the list does not know', function (): void {
    $token = lookupToken();

    $cases = [
        '/api/v1/clients?filter[unknown]=1' => 'filter',
        '/api/v1/clients?filter[project]=DEMO' => 'filter',
        '/api/v1/clients?filter[client]='.Str::uuid() => 'filter',
        '/api/v1/projects?filter[client]=abc' => 'filter.client',
        '/api/v1/projects?filter[project]=DEMO' => 'filter',
        '/api/v1/clients?per_page=201' => 'per_page',
        '/api/v1/clients?per_page=0' => 'per_page',
        '/api/v1/projects?per_page=abc' => 'per_page',
        '/api/v1/clients?sort=hourly_rate' => 'sort',
        '/api/v1/projects?sort=-priority' => 'sort',
        '/api/v1/clients?filter[name][]=a' => 'filter.name',
    ];

    foreach ($cases as $url => $key) {
        $this->withToken($token)->getJson($url)->assertStatus(422)->assertJsonValidationErrors([$key]);
    }
});

it('sorts the lists by name when asked and keeps the default newest first', function (): void {
    $b = lookupClient('Example b');
    $a = lookupClient('Example a');

    expect(lookupIds($this, '/api/v1/clients?sort=name'))->toBe([$a->id, $b->id])
        ->and(lookupIds($this, '/api/v1/clients?sort=-name'))->toBe([$b->id, $a->id])
        ->and(lookupIds($this, '/api/v1/clients'))->toBe([$a->id, $b->id]);
});
