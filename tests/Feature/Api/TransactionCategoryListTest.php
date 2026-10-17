<?php

declare(strict_types=1);

use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;

/*
 * AP-01, D-02, D-09: the read-only category tree. A finance:read token lists the categories and reads one by UUID
 * to learn which UUID to book a transaction into; nothing over the API changes a category. Every value is
 * fictional or the starter data of the migration.
 */

/**
 * The token of the Admin with the finance read ability.
 */
function txCategoryToken(): string
{
    return ApiFixtures::token(ApiFixtures::admin(), ['finance:read']);
}

/**
 * The listed items of a query, in the order of the answer.
 *
 * @return list<array<string, mixed>>
 */
function txCategoryItems(mixed $test, string $query = ''): array
{
    return $test->withToken(txCategoryToken())
        ->getJson('/api/v1/transaction-categories?per_page=200'.($query === '' ? '' : '&'.$query))
        ->assertOk()
        ->json('data');
}

/**
 * The ids of the listed items of a query.
 *
 * @return list<string>
 */
function txCategoryIds(mixed $test, string $query = ''): array
{
    return array_column(txCategoryItems($test, $query), 'id');
}

/**
 * Archives a category the way the Admin screen does: the archive timestamp, written as a system run.
 */
function txCategoryArchive(TransactionCategory $category): void
{
    DB::table('transaction_categories')->where('id', $category->id)->update(['archived_at' => '2026-10-10 08:00:00+00']);
}

/**
 * The category of the given name and type, found as a system run.
 */
function txCategoryNamed(string $name, string $type = 'expense'): TransactionCategory
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): TransactionCategory => TransactionCategory::query()->where('name', $name)->where('type', $type)->sole(),
    );
}

it('lists the seeded categories sorted by name with exactly the five keys', function (): void {
    $items = txCategoryItems($this);
    $expected = DB::table('transaction_categories')->orderBy('name')->orderBy('id')->pluck('id')->all();

    expect(array_column($items, 'id'))->toBe($expected)
        ->and($items)->not->toBeEmpty();

    foreach ($items as $item) {
        expect(array_keys($item))->toBe(['id', 'parent_id', 'name', 'type', 'archived'])
            ->and($item['archived'])->toBeFalse();
    }
});

it('lists a child with its parent and a root with none, and lists the system income category like any other', function (): void {
    $root = FinanceApiFixtures::softwareCategory();
    $child = FinanceApiFixtures::softwareChild();
    $system = app(PartnerContext::class)->runAsSystem(static fn (): TransactionCategory => TransactionCategory::invoicePayments());

    $byId = array_column(txCategoryItems($this), null, 'id');

    expect($byId[$root->id]['parent_id'])->toBeNull()
        ->and($byId[$root->id]['type'])->toBe('expense')
        ->and($byId[$child->id]['parent_id'])->toBe($root->id)
        ->and($byId[$system->id]['type'])->toBe('income')
        ->and($byId[$system->id]['name'])->toBe($system->name);
});

it('marks a category archived when it or its parent is archived, and filters on it', function (): void {
    $root = FinanceApiFixtures::softwareCategory();
    $child = FinanceApiFixtures::softwareChild();
    $ownArchived = txCategoryNamed('Kancelář');
    $untouched = txCategoryNamed('Ostatní výdaje');

    txCategoryArchive($root);
    txCategoryArchive($ownArchived);

    $byId = array_column(txCategoryItems($this), null, 'id');

    expect($byId[$root->id]['archived'])->toBeTrue()
        ->and($byId[$child->id]['archived'])->toBeTrue()
        ->and($byId[$ownArchived->id]['archived'])->toBeTrue()
        ->and($byId[$untouched->id]['archived'])->toBeFalse();

    $archived = txCategoryIds($this, 'filter[archived]=true');
    $usable = txCategoryIds($this, 'filter[archived]=false');

    expect($archived)->toContain($root->id, $child->id, $ownArchived->id)
        ->and($archived)->not->toContain($untouched->id)
        ->and($usable)->toContain($untouched->id)
        ->and($usable)->not->toContain($root->id)
        ->and($usable)->not->toContain($child->id)
        ->and($usable)->not->toContain($ownArchived->id)
        ->and(count($archived) + count($usable))->toBe(count($byId))
        ->and(txCategoryIds($this, 'filter[archived]=1'))->toBe($archived)
        ->and(txCategoryIds($this, 'filter[archived]=0'))->toBe($usable);
});

it('narrows the list to one type', function (): void {
    $expense = txCategoryIds($this, 'filter[type]=expense');
    $income = txCategoryIds($this, 'filter[type]=income');
    $incomeRoot = FinanceApiFixtures::incomeCategory();

    expect($income)->toContain($incomeRoot->id)
        ->and($expense)->toContain(FinanceApiFixtures::softwareCategory()->id)
        ->and(array_intersect($expense, $income))->toBe([])
        ->and(count($expense) + count($income))->toBe(DB::table('transaction_categories')->count());
});

it('answers 422 under the filter key for a malformed value or an unknown key', function (string $query, string $field): void {
    $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'a type that is neither' => ['filter[type]=transfer', 'filter.type'],
    'an archived word' => ['filter[archived]=both', 'filter.archived'],
    'an unknown filter' => ['filter[name]=Software', 'filter'],
    'a sort outside name and created_at' => ['sort=type', 'sort'],
    'a page size of zero' => ['per_page=0', 'per_page'],
    'a page size above 200' => ['per_page=201', 'per_page'],
]);

it('sorts by name in both directions and by creation, and pages each sort with its cursor', function (string $sort): void {
    $single = txCategoryIds($this, 'sort='.$sort);
    $pages = [];
    $cursor = null;

    do {
        $url = '/api/v1/transaction-categories?per_page=4&sort='.$sort.($cursor === null ? '' : '&cursor='.urlencode($cursor));
        $response = $this->withToken(txCategoryToken())->getJson($url)->assertOk();
        $pages[] = array_column($response->json('data'), 'id');
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null && count($pages) < 20);

    expect(array_merge(...$pages))->toBe($single)
        ->and(array_unique($single))->toHaveCount(count($single));
})->with(['name', '-name', 'created_at', '-created_at']);

it('orders by name descending as the reverse of name ascending, ties by id in the same direction', function (): void {
    $ascending = DB::table('transaction_categories')->orderBy('name')->orderBy('id')->pluck('id')->all();
    $descending = DB::table('transaction_categories')->orderByDesc('name')->orderByDesc('id')->pluck('id')->all();

    expect(txCategoryIds($this, 'sort=name'))->toBe($ascending)
        ->and(txCategoryIds($this, 'sort=-name'))->toBe($descending);
});

it('answers 422 under cursor for a cursor the API did not issue, never 500', function (string $cursor): void {
    $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories?cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
})->with(function (): array {
    $id = '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9';
    $raw = static fn (string $json): string => rtrim(strtr(base64_encode($json), '+/', '-_'), '=');

    return [
        'a cursor without parameters' => [(new Cursor([], true))->encode()],
        'a cursor without the id' => [(new Cursor(['name' => 'Example'], true))->encode()],
        'a cursor of the created_at sort on the name sort' => [(new Cursor(['created_at' => '2026-10-12 08:00:00', 'transaction_categories.id' => $id], true))->encode()],
        'a transaction list cursor' => [(new Cursor(['transaction_date' => '2026-10-12', 'transactions.id' => $id], true))->encode()],
        'a non-uuid id' => [(new Cursor(['name' => 'Example', 'transaction_categories.id' => 'x'], true))->encode()],
        'a numeric name' => [(new Cursor(['name' => 5, 'transaction_categories.id' => $id], true))->encode()],
        'a text direction flag' => [$raw('{"name":"Example","transaction_categories.id":"'.$id.'","_pointsToNextItems":"yes"}')],
        'a raw string' => ['abc'],
    ];
});

it('answers 422 under cursor for a cursor issued for another sort', function (): void {
    $cursor = $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories?per_page=1')->assertOk()->json('meta.next_cursor');

    expect(Cursor::fromEncoded($cursor)?->toArray())->toHaveKey('name');

    $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories?sort=created_at&cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
});

it('returns one category by UUID, also an archived one', function (): void {
    $root = FinanceApiFixtures::softwareCategory();
    $child = FinanceApiFixtures::softwareChild();
    txCategoryArchive($root);

    $rootData = $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories/'.$root->id)->assertOk()->json('data');
    $childData = $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories/'.$child->id)->assertOk()->json('data');

    expect($rootData)->toBe(['id' => $root->id, 'parent_id' => null, 'name' => $root->name, 'type' => 'expense', 'archived' => true])
        ->and($childData)->toBe(['id' => $child->id, 'parent_id' => $root->id, 'name' => $child->name, 'type' => 'expense', 'archived' => true]);
});

it('answers an unknown UUID with the Czech 404 of the API and a path that is no UUID with a router 404', function (): void {
    $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories/'.Str::uuid())
        ->assertNotFound()
        ->assertJsonPath('message', __('api.errors.not_found'));

    $route = Route::getRoutes()->getByName('api.v1.transaction-categories.show');

    expect($route->wheres)->toHaveKey('category');

    $response = $this->withToken(txCategoryToken())->getJson('/api/v1/transaction-categories/not-a-uuid')->assertNotFound();

    expect($response->json('message'))->not->toBe(__('api.errors.not_found'));
});

it('offers no route that writes a category over the API', function (): void {
    $seen = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/v1/transaction-categories')) {
            continue;
        }

        $seen[] = $route->uri();

        expect(array_values(array_diff($route->methods(), ['HEAD'])))->toBe(['GET']);
    }

    expect($seen)->toBe(['api/v1/transaction-categories', 'api/v1/transaction-categories/{category}']);

    foreach (['post', 'patch', 'put', 'delete'] as $method) {
        $this->withToken(ApiFixtures::token(ApiFixtures::admin(), ['finance:read', 'finance:write', 'time:read', 'time:write']))
            ->json($method, '/api/v1/transaction-categories/'.FinanceApiFixtures::softwareCategory()->id, ['name' => 'Example'])
            ->assertStatus(405);
    }

    $this->withToken(ApiFixtures::token(ApiFixtures::admin(), ['finance:read', 'finance:write']))
        ->postJson('/api/v1/transaction-categories', ['name' => 'Example', 'type' => 'expense'])
        ->assertStatus(405);
});

it('refuses a token without the finance read ability on both routes', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['time:read', 'finance:write']);

    $this->withToken($token)->getJson('/api/v1/transaction-categories')->assertForbidden();
    $this->withToken($token)->getJson('/api/v1/transaction-categories/'.FinanceApiFixtures::softwareCategory()->id)->assertForbidden();
});
