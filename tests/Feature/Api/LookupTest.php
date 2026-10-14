<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;

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

/**
 * A task with the given title in the project, written through the domain Action like the fixtures do.
 */
function lookupTask(Project $project, string $title): Task
{
    $admin = ApiFixtures::admin();

    Auth::shouldUse('web');
    Auth::guard('web')->setUser($admin);

    try {
        return app(CreateTask::class)->handle($admin, $project, ['title' => $title])->refresh();
    } finally {
        Auth::forgetGuards();
    }
}

it('lists the tasks of selectable projects newest first and narrows them by project key or uuid', function (): void {
    $client = ApiFixtures::client();
    $demo = ApiFixtures::project($client, 'DEMO');
    $other = ApiFixtures::project($client, 'OTHR');
    $archivedProject = ApiFixtures::project($client, 'GONE');
    $first = lookupTask($demo, 'Example first');
    $second = lookupTask($other, 'Example second');
    $third = lookupTask($demo, 'Example third');
    $archivedTask = lookupTask($demo, 'Example archived');
    $ofArchivedProject = lookupTask($archivedProject, 'Example hidden');
    lookupArchive($archivedTask);
    lookupArchive($archivedProject);

    $all = lookupIds($this, '/api/v1/tasks');

    expect($all)->toBe([$third->id, $second->id, $first->id])
        ->and($all)->not->toContain($archivedTask->id, $ofArchivedProject->id)
        ->and(lookupIds($this, '/api/v1/tasks?filter[project]=DEMO'))->toBe([$third->id, $first->id])
        ->and(lookupIds($this, '/api/v1/tasks?filter[project]='.$other->id))->toBe([$second->id])
        ->and(lookupIds($this, '/api/v1/tasks?filter[project]=NONE'))->toBe([])
        ->and(lookupIds($this, '/api/v1/tasks?filter[project]=GONE'))->toBe([]);

    $item = $this->withToken(lookupToken())->getJson('/api/v1/tasks?filter[project]=OTHR')->json('data.0');

    expect($item)->toBe([
        'id' => $second->id,
        'project_id' => $other->id,
        'reference' => 'OTHR-1',
        'title' => 'Example second',
        'status' => $second->status->value,
        'priority' => $second->priority->value,
    ]);
});

it('matches filter[name] as a case-insensitive part of the title and a percent sign literally', function (): void {
    $project = ApiFixtures::project(ApiFixtures::client(), 'DEMO');
    $match = lookupTask($project, 'Fiktivní úkol, první');
    $percent = lookupTask($project, 'Example 100% done');
    lookupTask($project, 'Example other');

    expect(lookupIds($this, '/api/v1/tasks?filter[name]='.urlencode('ÚKOL')))->toBe([$match->id])
        ->and(lookupIds($this, '/api/v1/tasks?filter[name]='.urlencode('úkol, první')))->toBe([$match->id])
        ->and(lookupIds($this, '/api/v1/tasks?filter[name]='.urlencode('%')))->toBe([$percent->id])
        ->and(lookupIds($this, '/api/v1/tasks?filter[name]='.urlencode('xyz')))->toBe([])
        ->and(lookupIds($this, '/api/v1/tasks?filter[name]=example&filter[project]=DEMO'))->toHaveCount(2);
});

it('pages 120 tasks that share one creation time in 50, 50 and 20 without a skipped or repeated task', function (): void {
    $project = ApiFixtures::project(ApiFixtures::client(), 'DEMO');
    $admin = ApiFixtures::admin();

    app(PartnerContext::class)->runAsSystem(static function () use ($project, $admin): void {
        Task::factory()->count(120)->create(['project_id' => $project->id, 'assignee_id' => $admin->id, 'requester_id' => $admin->id]);
    });
    DB::table('tasks')->update(['created_at' => '2026-10-01 10:00:00']);

    $token = lookupToken();
    $seen = [];
    $sizes = [];
    $url = '/api/v1/tasks';

    while ($url !== null) {
        $page = $this->withToken($token)->getJson($url)->assertOk();
        $sizes[] = count($page->json('data'));
        array_push($seen, ...array_column($page->json('data'), 'id'));
        $cursor = $page->json('meta.next_cursor');
        $url = is_string($cursor) ? '/api/v1/tasks?cursor='.urlencode($cursor) : null;
    }

    expect($sizes)->toBe([50, 50, 20])
        ->and($seen)->toHaveCount(120)
        ->and(array_unique($seen))->toHaveCount(120);
});

it('reveals no rate, price, billing, contact or tax data of a client, project or task', function (): void {
    $canaries = [
        'street' => Canary::canary('street'),
        'city' => Canary::canary('city'),
        'mail' => Canary::canary('mail'),
        'tax' => Canary::canary('tax'),
        'projectDescription' => Canary::canary('projectdescription'),
        'taskDescription' => Canary::canary('taskdescription'),
        'taskNote' => Canary::canary('tasknote'),
    ];

    $client = app(PartnerContext::class)->runAsSystem(static fn (): Client => Client::factory()->create([
        'name' => 'Fiktivní klient',
        'street' => $canaries['street'],
        'city' => $canaries['city'],
        'invoice_email' => strtolower($canaries['mail']).'@'.implode('.', ['example', 'com']),
        'tax_number' => $canaries['tax'],
        'hourly_rate' => Money::ofMinor(123456, 'CZK'),
        'payment_terms_days' => 37,
    ]));
    $project = ApiFixtures::project($client, 'DEMO');
    $task = lookupTask($project, 'Fiktivní úkol');

    app(PartnerContext::class)->runAsSystem(static function () use ($project, $task, $canaries): void {
        $project->forceFill(['description' => $canaries['projectDescription']])->save();
        $task->forceFill(['description' => $canaries['taskDescription']])->save();
        $task->billing()->create([
            'billing_type' => 'hourly',
            'hourly_rate' => Money::ofMinor(98765, 'CZK'),
            'internal_note' => $canaries['taskNote'],
        ]);
    });

    $urls = [
        '/api/v1/clients' => ['id', 'name'],
        '/api/v1/clients/'.$client->id => ['id', 'name'],
        '/api/v1/projects' => ['id', 'client_id', 'key', 'name', 'status', 'priority'],
        '/api/v1/projects/DEMO' => ['id', 'client_id', 'key', 'name', 'status', 'priority'],
        '/api/v1/projects/'.$project->id => ['id', 'client_id', 'key', 'name', 'status', 'priority'],
        '/api/v1/tasks' => ['id', 'project_id', 'reference', 'title', 'status', 'priority'],
        '/api/v1/tasks/DEMO-1' => ['id', 'project_id', 'reference', 'title', 'status', 'priority'],
        '/api/v1/tasks/'.$task->id => ['id', 'project_id', 'reference', 'title', 'status', 'priority'],
    ];
    $token = lookupToken();

    foreach ($urls as $url => $keys) {
        $response = $this->withToken($token)->getJson($url)->assertOk();
        $body = (string) $response->getContent();
        $data = $response->json('data');
        $item = array_is_list($data) ? $data[0] : $data;

        expect(array_keys($item))->toBe($keys, $url);

        foreach ([...array_values($canaries), 'hourly_rate', 'price', 'billing', '123456', '98765', 'payment_terms', 'tax_number', 'invoice_email', 'description'] as $needle) {
            expect($body)->not->toContain($needle, $url);
        }
    }
});

it('has no write route for clients, projects or tasks', function (): void {
    $token = lookupToken();
    $paths = ['/api/v1/clients', '/api/v1/projects', '/api/v1/tasks'];

    foreach ($paths as $path) {
        foreach (['POST', 'PUT', 'PATCH', 'DELETE'] as $method) {
            $this->withToken($token)->json($method, $path, ['name' => 'Example'])->assertStatus(405);
            $this->withToken($token)->json($method, $path.'/'.Str::uuid(), ['name' => 'Example'])->assertStatus(405);
        }
    }

    $this->withToken($token)->json('PATCH', '/api/v1/tasks/DEMO-1', ['title' => 'Example'])->assertStatus(405);
});

it('answers 422 for a task list filter or sort it does not know and for a malformed project key', function (): void {
    $token = lookupToken();

    $cases = [
        '/api/v1/tasks?filter[client]='.Str::uuid() => 'filter',
        '/api/v1/tasks?filter[unknown]=1' => 'filter',
        '/api/v1/tasks?filter[project]=demo' => 'filter.project',
        '/api/v1/tasks?filter[project]=DEMO-1' => 'filter.project',
        '/api/v1/tasks?per_page=201' => 'per_page',
        '/api/v1/tasks?sort=description' => 'sort',
    ];

    foreach ($cases as $url => $key) {
        $this->withToken($token)->getJson($url)->assertStatus(422)->assertJsonValidationErrors([$key]);
    }
});

it('refuses the lookups to a token without the read ability', function (): void {
    $writeOnly = ApiFixtures::token(ApiFixtures::admin(), ['time:write']);

    foreach (['/api/v1/clients', '/api/v1/projects', '/api/v1/tasks', '/api/v1/tasks/DEMO-1'] as $url) {
        $this->withToken($writeOnly)->getJson($url)->assertForbidden();
    }
});

it('refuses the lookups to a guest', function (): void {
    foreach (['/api/v1/clients', '/api/v1/projects', '/api/v1/tasks', '/api/v1/tasks/DEMO-1'] as $url) {
        $this->getJson($url)->assertUnauthorized();
    }
});
