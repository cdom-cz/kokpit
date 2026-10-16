<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use Illuminate\Pagination\Cursor;
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
