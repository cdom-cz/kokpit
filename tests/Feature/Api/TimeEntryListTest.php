<?php

declare(strict_types=1);

use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\TimeEntryContract;

/*
 * AP-02, D-11: the read-back list of time entries. Newest first, cursor paginated, filtered by the
 * documented filters. Every value is fictional.
 */

it('lists the entries newest first and narrows them to one client', function (): void {
    $admin = ApiFixtures::admin();
    $first = ApiFixtures::client();
    $second = ApiFixtures::client();

    $oldest = ApiFixtures::entry($admin, ['client_id' => $first->id, 'started_at' => '2026-10-10 08:00:00', 'ended_at' => '2026-10-10 09:00:00']);
    $middle = ApiFixtures::entry($admin, ['client_id' => $second->id, 'started_at' => '2026-10-11 08:00:00', 'ended_at' => '2026-10-11 09:00:00']);
    $newest = ApiFixtures::entry($admin, ['client_id' => $first->id, 'started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00']);
    $token = ApiFixtures::token($admin, ['time:read']);

    $all = $this->withToken($token)->getJson('/api/v1/time-entries')->assertOk();

    expect(array_column($all->json('data'), 'id'))->toBe([$newest->id, $middle->id, $oldest->id])
        ->and($all->json())->toHaveKeys(['data', 'links', 'meta'])
        ->and($all->json('meta'))->toHaveKey('next_cursor')
        ->and($all->json('meta.next_cursor'))->toBeNull();

    $narrowed = $this->withToken($token)->getJson('/api/v1/time-entries?filter[client]='.$first->id)->assertOk();

    expect(array_column($narrowed->json('data'), 'id'))->toBe([$newest->id, $oldest->id]);
});

it('follows the cursor of a page to the next entry', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $older = ApiFixtures::entry($admin, ['client_id' => $client->id, 'started_at' => '2026-10-10 08:00:00', 'ended_at' => '2026-10-10 09:00:00']);
    $newer = ApiFixtures::entry($admin, ['client_id' => $client->id, 'started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00']);
    $token = ApiFixtures::token($admin, ['time:read']);

    $page = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=1')->assertOk();
    $cursor = $page->json('meta.next_cursor');

    expect(array_column($page->json('data'), 'id'))->toBe([$newer->id])
        ->and($cursor)->toBeString()->not->toBe('');

    $next = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=1&cursor='.urlencode($cursor))->assertOk();

    expect(array_column($next->json('data'), 'id'))->toBe([$older->id])
        ->and($next->json('meta.next_cursor'))->toBeNull();
});

it('refuses a token without the read ability', function (): void {
    $admin = ApiFixtures::admin();

    $this->withToken(ApiFixtures::token($admin, ['time:write']))
        ->getJson('/api/v1/time-entries')
        ->assertForbidden();
});

it('answers 422, not 400, for a filter key the API does not know', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries?filter[unknown]=1')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter']);

    $this->withToken($token)->getJson('/api/v1/time-entries?filter[client]=abc')
        ->assertStatus(422)
        ->assertJsonValidationErrors(['filter.client']);
});

/**
 * A finished entry of ten minutes that starts at the given UTC instant.
 *
 * @param  array<string, mixed>  $data
 */
function listEntryAt(string $start, array $data = []): TimeEntry
{
    $started = CarbonImmutable::parse($start);

    return ApiFixtures::entry(ApiFixtures::admin(), [
        'started_at' => $started->format('Y-m-d\TH:i:sP'),
        'ended_at' => $started->addMinutes(10)->format('Y-m-d\TH:i:sP'),
        ...$data,
    ]);
}

/**
 * The ids of the entries a list query returns.
 *
 * @return list<string>
 */
function listIds(mixed $test, string $query): array
{
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    return array_column($test->withToken($token)->getJson('/api/v1/time-entries?'.$query)->assertOk()->json('data'), 'id');
}

it('treats a date-only range as inclusive Prague days across the 25-hour day', function (): void {
    $client = ApiFixtures::client();
    $before = listEntryAt('2026-10-24T21:59:59Z', ['client_id' => $client->id]);
    $first = listEntryAt('2026-10-24T22:30:00Z', ['client_id' => $client->id]);
    $last = listEntryAt('2026-10-25T22:59:59Z', ['client_id' => $client->id]);
    $after = listEntryAt('2026-10-25T23:00:00Z', ['client_id' => $client->id]);

    $ids = listIds($this, 'filter[from]=2026-10-25&filter[to]=2026-10-25');

    expect($ids)->toContain($first->id, $last->id)
        ->and($ids)->not->toContain($before->id)
        ->and($ids)->not->toContain($after->id);

    $from = listIds($this, 'filter[from]=2026-10-25');
    expect($from)->toContain($first->id, $last->id, $after->id)->and($from)->not->toContain($before->id);

    $to = listIds($this, 'filter[to]=2026-10-25');
    expect($to)->toContain($before->id, $first->id, $last->id)->and($to)->not->toContain($after->id);
});

it('uses an ISO 8601 instant exactly and treats an instant filter[to] as inclusive', function (): void {
    $client = ApiFixtures::client();
    $early = listEntryAt('2026-10-12T07:59:59Z', ['client_id' => $client->id]);
    $on = listEntryAt('2026-10-12T08:00:00Z', ['client_id' => $client->id]);
    $late = listEntryAt('2026-10-12T09:00:01Z', ['client_id' => $client->id]);

    $ids = listIds($this, 'filter[from]='.urlencode('2026-10-12T10:00:00+02:00'));
    expect($ids)->toContain($on->id, $late->id)->and($ids)->not->toContain($early->id);

    $ids = listIds($this, 'filter[to]='.urlencode('2026-10-12T11:00:00+02:00'));
    expect($ids)->toContain($early->id, $on->id)->and($ids)->not->toContain($late->id);
});

it('finds a project by uuid and by key alike, and an unknown key matches nothing', function (): void {
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $other = ApiFixtures::project($client, 'OTHR');
    $inProject = listEntryAt('2026-10-12T08:00:00Z', ['client_id' => $client->id, 'project_id' => $project->id]);
    $elsewhere = listEntryAt('2026-10-12T09:00:00Z', ['client_id' => $client->id, 'project_id' => $other->id]);

    expect(listIds($this, 'filter[project]='.$project->id))->toBe([$inProject->id])
        ->and(listIds($this, 'filter[project]=DEMO'))->toBe([$inProject->id])
        ->and(listIds($this, 'filter[project]=ZZZZ'))->toBe([])
        ->and($elsewhere->id)->not->toBe($inProject->id);
});

it('finds the entries of an archived project and an archived task by key', function (): void {
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    $entry = listEntryAt('2026-10-12T08:00:00Z', ['client_id' => $client->id, 'project_id' => $project->id, 'task_id' => $task->id]);

    expect(listIds($this, 'filter[task]='.$task->reference))->toBe([$entry->id])
        ->and(listIds($this, 'filter[task]='.$task->id))->toBe([$entry->id]);

    DB::table('tasks')->where('id', $task->id)->update(['deleted_at' => now()]);
    DB::table('projects')->where('id', $project->id)->update(['deleted_at' => now()]);

    expect(listIds($this, 'filter[task]='.$task->reference))->toBe([$entry->id])
        ->and(listIds($this, 'filter[project]=DEMO'))->toBe([$entry->id])
        ->and(listIds($this, 'filter[task]=DEMO-999'))->toBe([]);
});

it('filters by the billable flag and the billing state', function (): void {
    $client = ApiFixtures::client();
    $billable = listEntryAt('2026-10-12T08:00:00Z', ['client_id' => $client->id]);
    $free = listEntryAt('2026-10-12T09:00:00Z', ['client_id' => $client->id, 'billable' => false]);
    $billed = ApiFixtures::billed(listEntryAt('2026-10-12T10:00:00Z', ['client_id' => $client->id]));

    $nonBillable = listIds($this, 'filter[billable]=false&filter[client]='.$client->id);
    expect($nonBillable)->toBe([$free->id]);

    $billableIds = listIds($this, 'filter[billable]=true&filter[client]='.$client->id);
    expect($billableIds)->toContain($billable->id, $billed->id)->and($billableIds)->not->toContain($free->id);

    expect(listIds($this, 'filter[billed]=true&filter[client]='.$client->id))->toBe([$billed->id]);

    $unbilled = listIds($this, 'filter[billed]=0&filter[client]='.$client->id);
    expect($unbilled)->toContain($billable->id, $free->id)->and($unbilled)->not->toContain($billed->id);
});

it('answers 422 under the filter key for a malformed value, never 400 or 500', function (string $query, string $field): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'a non-uuid client' => ['filter[client]=abc', 'filter.client'],
    'a lower case project key' => ['filter[project]=demo', 'filter.project'],
    'a task number of zero' => ['filter[task]=DEMO-0', 'filter.task'],
    'a task without a number' => ['filter[task]=DEMO', 'filter.task'],
    'a non-calendar date' => ['filter[from]=2026-02-30', 'filter.from'],
    'a date with a time but no offset' => ['filter[to]=2026-10-12T10:00:00', 'filter.to'],
    'a billable word' => ['filter[billable]=maybe', 'filter.billable'],
    'a billed word' => ['filter[billed]=yes', 'filter.billed'],
    'an unknown filter' => ['filter[unknown]=1', 'filter'],
]);

/**
 * Inserts the given number of finished entries that all share one start (and one creation time),
 * so only the id can order them.
 *
 * @return list<string> the ids of the entries
 */
function listTiedEntries(int $count): array
{
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $rows = [];

    for ($i = 0; $i < $count; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'user_id' => $admin->id,
            'client_id' => $client->id,
            'description' => 'Example work '.$i,
            'started_at' => '2026-10-12 08:00:00+00',
            'ended_at' => '2026-10-12 08:10:00+00',
            'billable' => true,
            'billing_state' => 'unbilled',
            'created_at' => '2026-10-12 08:11:00+00',
            'updated_at' => '2026-10-12 08:11:00+00',
        ];
    }

    DB::table('time_entries')->insert($rows);

    return array_column($rows, 'id');
}

/**
 * Follows the cursor from the first page to the last and returns the ids of every page.
 *
 * @return list<list<string>>
 */
function listPages(mixed $test, string $query): array
{
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);
    $pages = [];
    $cursor = null;

    do {
        $url = '/api/v1/time-entries?'.$query.($cursor === null ? '' : '&cursor='.urlencode($cursor));
        $response = $test->withToken($token)->getJson($url)->assertOk();
        $pages[] = array_column($response->json('data'), 'id');
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null && count($pages) < 10);

    return $pages;
}

it('pages 120 entries with the same start in 50, 50 and 20 without a skipped or repeated row', function (string $sort): void {
    $ids = listTiedEntries(120);

    $pages = listPages($this, 'per_page=50'.($sort === '' ? '' : '&sort='.$sort));

    expect(array_map('count', $pages))->toBe([50, 50, 20])
        ->and(array_merge(...$pages))->toHaveCount(120)
        ->and(array_unique(array_merge(...$pages)))->toHaveCount(120)
        ->and(array_diff($ids, array_merge(...$pages)))->toBe([]);
})->with([
    'the default sort' => [''],
    'started_at ascending' => ['started_at'],
    'created_at descending' => ['-created_at'],
    'created_at ascending' => ['created_at'],
    'started_at descending' => ['-started_at'],
]);

it('breaks a tie by id in the direction of the sort', function (): void {
    listTiedEntries(5);

    $newestFirst = array_merge(...listPages($this, 'per_page=2'));
    $oldestFirst = array_merge(...listPages($this, 'per_page=2&sort=started_at'));

    $sorted = $newestFirst;
    rsort($sorted);

    expect($newestFirst)->toBe($sorted)
        ->and($oldestFirst)->toBe(array_reverse($newestFirst));
});

it('holds 50 entries a page by default, accepts 200 and refuses a size outside 1 to 200', function (): void {
    listTiedEntries(120);
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    expect($this->withToken($token)->getJson('/api/v1/time-entries')->assertOk()->json('data'))->toHaveCount(50);

    $all = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=200')->assertOk();
    expect($all->json('data'))->toHaveCount(120)
        ->and($all->json('meta.next_cursor'))->toBeNull();

    $this->withToken($token)->getJson('/api/v1/time-entries?per_page=0')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/time-entries?per_page=201')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
    $this->withToken($token)->getJson('/api/v1/time-entries?per_page=many')->assertStatus(422)->assertJsonValidationErrors(['per_page']);
});

it('refuses a sort outside started_at and created_at with 422 under sort', function (string $sort): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries?sort='.urlencode($sort))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['sort']);
})->with(['description', '-description', 'started_at,created_at', '']);

it('lists a running entry with a null end and a null duration', function (): void {
    $admin = ApiFixtures::admin();
    $entry = ApiFixtures::running($admin, ApiFixtures::client());
    $token = ApiFixtures::token($admin, ['time:read']);

    $item = $this->withToken($token)->getJson('/api/v1/time-entries')->assertOk()->json('data.0');

    expect($item['id'])->toBe($entry->id)
        ->and($item['ended_at'])->toBeNull()
        ->and($item['duration_seconds'])->toBeNull();
});

it('gives every listed entry exactly the confirmed keys and no rate, price or amount', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $task = ApiFixtures::task($project);
    listEntryAt('2026-10-12T08:00:00Z', ['client_id' => $client->id, 'project_id' => $project->id, 'task_id' => $task->id]);
    ApiFixtures::running($admin, $client);
    $token = ApiFixtures::token($admin, ['time:read']);

    $items = $this->withToken($token)->getJson('/api/v1/time-entries')->assertOk()->json('data');

    expect($items)->toHaveCount(2);

    foreach ($items as $item) {
        TimeEntryContract::assertShape($item);

        foreach (array_keys($item) as $key) {
            expect($key)->not->toMatch('/rate|price|amount|cost|total|money/i');
        }
    }
});

/**
 * The cursor of the given parameters, encoded the way the paginator does it.
 *
 * @param  array<string, mixed>  $parameters
 */
function listCursor(array $parameters, bool $pointsToNextItems = true): string
{
    return (new Cursor($parameters, $pointsToNextItems))->encode();
}

/**
 * The base64url form of a raw string, as a client could hand-edit it.
 */
function listRawCursor(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

it('issues a cursor that holds exactly the sort column, the id and the direction flag', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    ApiFixtures::entry($admin, ['client_id' => $client->id, 'started_at' => '2026-10-10 08:00:00', 'ended_at' => '2026-10-10 09:00:00']);
    ApiFixtures::entry($admin, ['client_id' => $client->id, 'started_at' => '2026-10-12 08:00:00', 'ended_at' => '2026-10-12 09:00:00']);
    $token = ApiFixtures::token($admin, ['time:read']);

    $issued = $this->withToken($token)->getJson('/api/v1/time-entries?per_page=1')->assertOk()->json('meta.next_cursor');
    $decoded = Cursor::fromEncoded($issued)?->toArray();

    expect($decoded)->toBeArray()
        ->and(array_keys($decoded))->toEqualCanonicalizing(['started_at', 'time_entries.id', '_pointsToNextItems'])
        ->and($decoded['started_at'])->toMatch('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/')
        ->and(Str::isUuid($decoded['time_entries.id']))->toBeTrue()
        ->and($decoded['_pointsToNextItems'])->toBeTrue();
});

it('answers 422 under cursor for a cursor without parameters, never 500', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries?cursor='.urlencode(listCursor([])))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
});

it('answers 422 under cursor for a cursor of another list or of another sort', function (string $sort, array $parameters): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);
    $query = $sort === '' ? '' : 'sort='.$sort.'&';

    $this->withToken($token)->getJson('/api/v1/time-entries?'.$query.'cursor='.urlencode(listCursor($parameters)))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
})->with([
    'a client list cursor' => ['', ['created_at' => '2026-10-12 08:00:00', 'clients.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9']],
    'a project list cursor' => ['', ['name' => 'Example', 'projects.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9']],
    'a created_at cursor on the started_at sort' => ['', ['created_at' => '2026-10-12 08:00:00', 'time_entries.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9']],
    'a started_at cursor on the created_at sort' => ['-created_at', ['started_at' => '2026-10-12 08:00:00', 'time_entries.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9']],
    'a cursor without the id' => ['', ['started_at' => '2026-10-12 08:00:00']],
    'a cursor without the sort column' => ['', ['time_entries.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9']],
    'an extra key next to the right ones' => ['', ['started_at' => '2026-10-12 08:00:00', 'time_entries.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9', 'extra' => 1]],
]);

it('answers 422 under cursor for a wrong-typed value or a cursor that is not an object', function (string $cursor): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read']);

    $this->withToken($token)->getJson('/api/v1/time-entries?cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
})->with(function (): array {
    $id = '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9';
    $at = '2026-10-12 08:00:00';

    return [
        'a non-uuid id' => [listCursor(['started_at' => $at, 'time_entries.id' => 'x'])],
        'an unparseable instant' => [listCursor(['started_at' => 'x', 'time_entries.id' => $id])],
        'a year 0000 instant' => [listCursor(['started_at' => '0000-01-01 00:00:00', 'time_entries.id' => $id])],
        'an impossible date' => [listCursor(['started_at' => '2026-02-30 10:00:00', 'time_entries.id' => $id])],
        'an instant with an offset' => [listCursor(['started_at' => '2026-10-12 08:00:00+02', 'time_entries.id' => $id])],
        'a numeric instant' => [listCursor(['started_at' => 5, 'time_entries.id' => $id])],
        'a numeric id' => [listCursor(['started_at' => $at, 'time_entries.id' => 5])],
        'a text direction flag' => [listRawCursor('{"started_at":"'.$at.'","time_entries.id":"'.$id.'","_pointsToNextItems":"yes"}')],
        'a raw string' => ['abc'],
        'a json list' => [listRawCursor('[1,2]')],
        'json that is not an object' => [listRawCursor('5')],
        'an object without a direction flag' => [listRawCursor('{"started_at":"'.$at.'","time_entries.id":"'.$id.'"}')],
    ];
});
