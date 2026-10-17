<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\TransactionContract;

/*
 * AP-01, D-09: the list of transactions. Newest first by transaction date with the id as tiebreaker, cursor
 * paginated, narrowed by the documented filters and sorted by the documented sorts. Every value is fictional.
 */

/**
 * The token of the Admin with the finance read ability.
 */
function txListToken(): string
{
    return ApiFixtures::token(ApiFixtures::admin(), ['finance:read']);
}

/**
 * A CZK expense of the Admin on the given day; the overrides replace single keys of the Action data.
 *
 * @param  array<string, mixed>  $overrides
 */
function txListRow(string $day, array $overrides = []): Transaction
{
    return FinanceApiFixtures::transaction(ApiFixtures::admin(), ['transaction_date' => $day, ...$overrides]);
}

/**
 * The ids a list query answers, in the order of the answer.
 *
 * @return list<string>
 */
function txListIds(mixed $test, string $query = ''): array
{
    return array_column($test->withToken(txListToken())->getJson('/api/v1/transactions'.($query === '' ? '' : '?'.$query))->assertOk()->json('data'), 'id');
}

/**
 * The cursor of the given parameters, encoded the way the paginator does it.
 *
 * @param  array<string, mixed>  $parameters
 */
function txListCursor(array $parameters, bool $pointsToNextItems = true): string
{
    return (new Cursor($parameters, $pointsToNextItems))->encode();
}

/**
 * The base64url form of a raw string, as a client could hand-edit it.
 */
function txListRawCursor(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

it('lists the transactions newest first by date with the id breaking ties, and pages them by cursor', function (): void {
    $oldest = txListRow('2026-10-12');
    $tieFirst = txListRow('2026-10-14');
    $tieSecond = txListRow('2026-10-14');
    $token = txListToken();

    $all = $this->withToken($token)->getJson('/api/v1/transactions')->assertOk();

    expect(array_column($all->json('data'), 'id'))->toBe([$tieSecond->id, $tieFirst->id, $oldest->id])
        ->and($all->json())->toHaveKeys(['data', 'links', 'meta'])
        ->and($all->json('meta.next_cursor'))->toBeNull();

    $page = $this->withToken($token)->getJson('/api/v1/transactions?per_page=2')->assertOk();
    $cursor = $page->json('meta.next_cursor');

    expect(array_column($page->json('data'), 'id'))->toBe([$tieSecond->id, $tieFirst->id])
        ->and($cursor)->toBeString()->not->toBe('');

    $next = $this->withToken($token)->getJson('/api/v1/transactions?per_page=2&cursor='.urlencode($cursor))->assertOk();

    expect(array_column($next->json('data'), 'id'))->toBe([$oldest->id])
        ->and($next->json('meta.next_cursor'))->toBeNull();
});

it('holds 50 transactions a page by default and refuses a size outside 1 to 200', function (): void {
    txListRow('2026-10-12');
    $token = txListToken();

    $this->withToken($token)->getJson('/api/v1/transactions')->assertOk()->assertJsonPath('meta.per_page', 50);
    $this->withToken($token)->getJson('/api/v1/transactions?per_page=200')->assertOk()->assertJsonPath('meta.per_page', 200);

    foreach (['0', '201', 'many'] as $size) {
        $this->withToken($token)->getJson('/api/v1/transactions?per_page='.$size)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['per_page']);
    }
});

it('refuses a token without the finance read ability', function (): void {
    $this->withToken(ApiFixtures::token(ApiFixtures::admin(), ['time:read', 'finance:write']))
        ->getJson('/api/v1/transactions')
        ->assertForbidden();
});

it('gives every listed transaction the confirmed keys', function (): void {
    txListRow('2026-10-12');
    txListRow('2026-10-13', ['description' => null, 'counterparty' => null]);

    $items = $this->withToken(txListToken())->getJson('/api/v1/transactions')->assertOk()->json('data');

    expect($items)->toHaveCount(2);

    foreach ($items as $item) {
        TransactionContract::assertShape($item);
    }
});

it('issues a cursor that holds exactly the sort column, the id and the direction flag', function (): void {
    txListRow('2026-10-12');
    txListRow('2026-10-14');

    $issued = $this->withToken(txListToken())->getJson('/api/v1/transactions?per_page=1')->assertOk()->json('meta.next_cursor');
    $decoded = Cursor::fromEncoded($issued)?->toArray();

    expect($decoded)->toBeArray()
        ->and(array_keys($decoded))->toEqualCanonicalizing(['transaction_date', 'transactions.id', '_pointsToNextItems'])
        ->and($decoded['transaction_date'])->toMatch('/^\d{4}-\d{2}-\d{2}( 00:00:00)?$/')
        ->and(Str::isUuid($decoded['transactions.id']))->toBeTrue()
        ->and($decoded['_pointsToNextItems'])->toBeTrue();
});

it('answers 422 under cursor for a cursor the API did not issue, never 500', function (string $cursor): void {
    $this->withToken(txListToken())->getJson('/api/v1/transactions?cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
})->with(function (): array {
    $id = '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9';
    $day = '2026-10-12';

    return [
        'a cursor without parameters' => [txListCursor([])],
        'a cursor without the id' => [txListCursor(['transaction_date' => $day])],
        'a cursor without the sort column' => [txListCursor(['transactions.id' => $id])],
        'a wrong key' => [txListCursor(['created_at' => '2026-10-12 08:00:00', 'transactions.id' => $id])],
        'an extra key' => [txListCursor(['transaction_date' => $day, 'transactions.id' => $id, 'extra' => 1])],
        'a time entry list cursor' => [txListCursor(['started_at' => '2026-10-12 08:00:00', 'time_entries.id' => $id])],
        'an impossible date' => [txListCursor(['transaction_date' => '2026-02-30', 'transactions.id' => $id])],
        'an impossible date with a time' => [txListCursor(['transaction_date' => '2026-02-30 00:00:00', 'transactions.id' => $id])],
        'a date with a time of day' => [txListCursor(['transaction_date' => '2026-10-12 08:00:00', 'transactions.id' => $id])],
        'a year 0000 date' => [txListCursor(['transaction_date' => '0000-01-01', 'transactions.id' => $id])],
        'a text date' => [txListCursor(['transaction_date' => 'x', 'transactions.id' => $id])],
        'a numeric date' => [txListCursor(['transaction_date' => 5, 'transactions.id' => $id])],
        'a non-uuid id' => [txListCursor(['transaction_date' => $day, 'transactions.id' => 'x'])],
        'a numeric id' => [txListCursor(['transaction_date' => $day, 'transactions.id' => 5])],
        'a text direction flag' => [txListRawCursor('{"transaction_date":"'.$day.'","transactions.id":"'.$id.'","_pointsToNextItems":"yes"}')],
        'a raw string' => ['abc'],
        'a json list' => [txListRawCursor('[1,2]')],
        'json that is not an object' => [txListRawCursor('5')],
    ];
});

it('accepts the cursor of a day written with a zero time, as the paginator writes it', function (): void {
    $id = '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9';

    $this->withToken(txListToken())
        ->getJson('/api/v1/transactions?cursor='.urlencode(txListCursor(['transaction_date' => '2026-10-12 00:00:00', 'transactions.id' => $id])))
        ->assertOk();

    $this->withToken(txListToken())
        ->getJson('/api/v1/transactions?cursor='.urlencode(txListCursor(['transaction_date' => '2026-10-12', 'transactions.id' => $id])))
        ->assertOk();
});

it('narrows the list to income or to expense', function (): void {
    $expense = txListRow('2026-10-12');
    $income = txListRow('2026-10-13', ['type' => 'income', 'category_id' => FinanceApiFixtures::incomeCategory()->id]);

    expect(txListIds($this, 'filter[type]=income'))->toBe([$income->id])
        ->and(txListIds($this, 'filter[type]=expense'))->toBe([$expense->id]);
});

it('includes the children of a parent category and narrows a child to itself', function (): void {
    $root = FinanceApiFixtures::softwareCategory();
    $child = FinanceApiFixtures::softwareChild();
    $inRoot = txListRow('2026-10-12', ['category_id' => $root->id]);
    $inChild = txListRow('2026-10-13', ['category_id' => $child->id]);
    $elsewhere = txListRow('2026-10-14', ['type' => 'income', 'category_id' => FinanceApiFixtures::incomeCategory()->id]);

    expect(txListIds($this, 'filter[category]='.$root->id))->toBe([$inChild->id, $inRoot->id])
        ->and(txListIds($this, 'filter[category]='.$child->id))->toBe([$inChild->id])
        ->and(txListIds($this, 'filter[category]='.Str::uuid()))->toBe([])
        ->and($elsewhere->id)->not->toBe($inRoot->id);
});

it('narrows the list to one client by uuid', function (): void {
    $client = ApiFixtures::client();
    $other = ApiFixtures::client();
    $mine = txListRow('2026-10-12', ['client_id' => $client->id]);
    txListRow('2026-10-13', ['client_id' => $other->id]);
    txListRow('2026-10-14');

    expect(txListIds($this, 'filter[client]='.$client->id))->toBe([$mine->id])
        ->and(txListIds($this, 'filter[client]='.Str::uuid()))->toBe([]);
});

it('finds a project by uuid and by key alike, and an unknown key matches nothing', function (): void {
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    $other = ApiFixtures::project($client, 'OTHR');
    $inProject = txListRow('2026-10-12', ['client_id' => $client->id, 'project_id' => $project->id]);
    txListRow('2026-10-13', ['client_id' => $client->id, 'project_id' => $other->id]);
    txListRow('2026-10-14');

    expect(txListIds($this, 'filter[project]='.$project->id))->toBe([$inProject->id])
        ->and(txListIds($this, 'filter[project]=DEMO'))->toBe([$inProject->id])
        ->and(txListIds($this, 'filter[project]=ZZZZ'))->toBe([]);
});

it('includes both given days in a date range', function (): void {
    txListRow('2026-10-12');
    $first = txListRow('2026-10-13');
    $last = txListRow('2026-10-14');
    $after = txListRow('2026-10-15');

    expect(txListIds($this, 'filter[date_from]=2026-10-13&filter[date_to]=2026-10-14'))->toBe([$last->id, $first->id])
        ->and(txListIds($this, 'filter[date_from]=2026-10-15'))->toBe([$after->id])
        ->and(txListIds($this, 'filter[date_to]=2026-10-12'))->toHaveCount(1)
        ->and(txListIds($this, 'filter[date_from]=2026-10-16'))->toBe([]);
});

it('narrows the list by currency and by the way the CZK figure was booked', function (): void {
    FinanceApiFixtures::cnbRate('EUR', '2026-10-13', '25.000');
    $czk = txListRow('2026-10-12');
    $converted = txListRow('2026-10-13', ['currency' => 'EUR', 'amount' => '10,00']);
    $own = txListRow('2026-10-14', ['currency' => 'EUR', 'amount' => '10,00', 'exchange_rate' => '24,5']);
    $typed = txListRow('2026-10-15', ['currency' => 'EUR', 'amount' => '10,00', 'czk_amount' => '250,00']);

    expect(txListIds($this, 'filter[currency]=EUR'))->toBe([$typed->id, $own->id, $converted->id])
        ->and(txListIds($this, 'filter[currency]=CZK'))->toBe([$czk->id])
        ->and(txListIds($this, 'filter[czk_source]=identity'))->toBe([$czk->id])
        ->and(txListIds($this, 'filter[czk_source]=cnb'))->toBe([$converted->id])
        ->and(txListIds($this, 'filter[czk_source]=custom'))->toBe([$own->id])
        ->and(txListIds($this, 'filter[czk_source]=manual'))->toBe([$typed->id]);
});

it('combines the filters with AND', function (): void {
    $client = ApiFixtures::client();
    $match = txListRow('2026-10-13', ['client_id' => $client->id]);
    txListRow('2026-10-13');
    txListRow('2026-10-20', ['client_id' => $client->id]);

    expect(txListIds($this, 'filter[client]='.$client->id.'&filter[date_to]=2026-10-14&filter[type]=expense'))->toBe([$match->id]);
});

it('answers 422 under the filter key for a malformed value or an unknown key, never 400 or 500', function (string $query, string $field): void {
    $this->withToken(txListToken())->getJson('/api/v1/transactions?'.$query)
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field]);
})->with([
    'a type that is neither' => ['filter[type]=transfer', 'filter.type'],
    'a non-uuid category' => ['filter[category]=abc', 'filter.category'],
    'a non-uuid client' => ['filter[client]=abc', 'filter.client'],
    'a project key as client' => ['filter[client]=DEMO', 'filter.client'],
    'a lower case project key' => ['filter[project]=demo', 'filter.project'],
    'a project name with spaces' => ['filter[project]=my%20project', 'filter.project'],
    'a non-calendar date_from' => ['filter[date_from]=2026-02-30', 'filter.date_from'],
    'a non-calendar date_to' => ['filter[date_to]=2026-13-01', 'filter.date_to'],
    'an instant with a time as date_from' => ['filter[date_from]=2026-10-12T10:00:00Z', 'filter.date_from'],
    'an instant with a time as date_to' => ['filter[date_to]=2026-10-12T10:00:00Z', 'filter.date_to'],
    'a lower case currency' => ['filter[currency]=eur', 'filter.currency'],
    'a currency of four letters' => ['filter[currency]=EURO', 'filter.currency'],
    'a czk source that does not exist' => ['filter[czk_source]=guess', 'filter.czk_source'],
    'an unknown filter' => ['filter[unknown]=1', 'filter'],
    'a sort outside the three' => ['sort=description', 'sort'],
    'a sort of the internal column' => ['sort=czk_minor', 'sort'],
]);

it('sorts by date, by the CZK figure across currencies and by creation', function (): void {
    $small = txListRow('2026-10-13', ['amount' => '500,00']);
    $large = txListRow('2026-10-12', ['currency' => 'EUR', 'amount' => '100,00', 'exchange_rate' => '25']);
    $medium = txListRow('2026-10-14', ['amount' => '1 000,00']);

    // Created last to first, so the creation order differs from the date order.
    DB::table('transactions')->where('id', $small->id)->update(['created_at' => '2026-10-10 08:00:00+00']);
    DB::table('transactions')->where('id', $large->id)->update(['created_at' => '2026-10-11 08:00:00+00']);
    DB::table('transactions')->where('id', $medium->id)->update(['created_at' => '2026-10-12 08:00:00+00']);

    expect(txListIds($this, 'sort=transaction_date'))->toBe([$large->id, $small->id, $medium->id])
        ->and(txListIds($this, 'sort=-transaction_date'))->toBe([$medium->id, $small->id, $large->id])
        ->and(txListIds($this, 'sort=-amount'))->toBe([$large->id, $medium->id, $small->id])
        ->and(txListIds($this, 'sort=amount'))->toBe([$small->id, $medium->id, $large->id])
        ->and(txListIds($this, 'sort=created_at'))->toBe([$small->id, $large->id, $medium->id])
        ->and(txListIds($this, 'sort=-created_at'))->toBe([$medium->id, $large->id, $small->id]);
});

/**
 * Five transactions that share the date, the CZK figure and the creation time, so only the id can order them.
 *
 * @return list<string> the ids
 */
function txListTiedRows(): array
{
    $ids = [];

    for ($i = 0; $i < 5; $i++) {
        $ids[] = txListRow('2026-10-12')->id;
    }

    DB::table('transactions')->update(['created_at' => '2026-10-12 08:00:00+00']);

    return $ids;
}

/**
 * Follows the cursor from the first page to the last and returns the ids of every page.
 *
 * @return list<list<string>>
 */
function txListPages(mixed $test, string $query): array
{
    $pages = [];
    $cursor = null;

    do {
        $url = '/api/v1/transactions?'.$query.($cursor === null ? '' : '&cursor='.urlencode($cursor));
        $response = $test->withToken(txListToken())->getJson($url)->assertOk();
        $pages[] = array_column($response->json('data'), 'id');
        $cursor = $response->json('meta.next_cursor');
    } while ($cursor !== null && count($pages) < 10);

    return $pages;
}

it('pages rows that tie on the sorted value without a skipped or repeated row, under every sort', function (string $sort): void {
    $ids = txListTiedRows();

    $pages = txListPages($this, 'per_page=2&sort='.$sort);
    $all = array_merge(...$pages);
    $expected = $ids;
    sort($expected);

    if (str_starts_with($sort, '-')) {
        $expected = array_reverse($expected);
    }

    expect(array_map('count', $pages))->toBe([2, 2, 1])
        ->and(array_unique($all))->toHaveCount(5)
        ->and($all)->toBe($expected);
})->with([
    'transaction_date ascending' => ['transaction_date'],
    'transaction_date descending' => ['-transaction_date'],
    'amount ascending' => ['amount'],
    'amount descending' => ['-amount'],
    'created_at ascending' => ['created_at'],
    'created_at descending' => ['-created_at'],
]);

it('pages rows of different values in order under every sort', function (string $sort): void {
    foreach (['2026-10-11', '2026-10-13', '2026-10-12', '2026-10-15', '2026-10-14'] as $i => $day) {
        $row = txListRow($day, ['amount' => (100 + $i * 10).',00']);
        DB::table('transactions')->where('id', $row->id)->update(['created_at' => '2026-10-1'.$i.' 08:00:00+00']);
    }

    $single = array_column(
        $this->withToken(txListToken())->getJson('/api/v1/transactions?per_page=200&sort='.$sort)->assertOk()->json('data'),
        'id',
    );
    $paged = array_merge(...txListPages($this, 'per_page=2&sort='.$sort));

    expect($single)->toHaveCount(5)
        ->and($paged)->toBe($single);
})->with(['transaction_date', '-transaction_date', 'amount', '-amount', 'created_at', '-created_at']);

it('answers 422 under cursor for a cursor issued for another sort', function (): void {
    txListRow('2026-10-12');
    txListRow('2026-10-14');

    $dateCursor = $this->withToken(txListToken())->getJson('/api/v1/transactions?per_page=1')->assertOk()->json('meta.next_cursor');
    $amountCursor = $this->withToken(txListToken())->getJson('/api/v1/transactions?per_page=1&sort=amount')->assertOk()->json('meta.next_cursor');

    expect(Cursor::fromEncoded($amountCursor)?->toArray())->toHaveKey('czk_minor');

    foreach (['amount', '-amount', 'created_at'] as $sort) {
        $this->withToken(txListToken())->getJson('/api/v1/transactions?sort='.$sort.'&cursor='.urlencode($dateCursor))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cursor']);
    }

    foreach (['', 'sort=created_at'] as $query) {
        $this->withToken(txListToken())->getJson('/api/v1/transactions?'.$query.'&cursor='.urlencode($amountCursor))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['cursor']);
    }
});

it('answers 422 under cursor for a forged cursor of the amount sort, never 500', function (string $cursor): void {
    $this->withToken(txListToken())->getJson('/api/v1/transactions?sort=amount&cursor='.urlencode($cursor))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['cursor']);
})->with(function (): array {
    $id = '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9';

    return [
        'a numeric string figure' => [txListCursor(['czk_minor' => '5', 'transactions.id' => $id])],
        'a float figure' => [txListCursor(['czk_minor' => 5.5, 'transactions.id' => $id])],
        'a text figure' => [txListCursor(['czk_minor' => 'x', 'transactions.id' => $id])],
        'a date instead of the figure' => [txListCursor(['transaction_date' => '2026-10-12', 'transactions.id' => $id])],
        'a null figure' => [txListCursor(['czk_minor' => null, 'transactions.id' => $id])],
    ];
});

it('accepts a cursor of the amount sort with an integer figure', function (): void {
    $this->withToken(txListToken())
        ->getJson('/api/v1/transactions?sort=amount&cursor='.urlencode(txListCursor(['czk_minor' => 5, 'transactions.id' => '0199e39f-c2d4-7a1b-9e0c-d4e5f6a7b8c9'])))
        ->assertOk();
});
