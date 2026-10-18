<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\TransactionContract;

/*
 * Quick task 261018-mic: the optional `external` JSON object of a transaction for import tools, the same object
 * a time entry carries (D-15). Real Sanctum tokens; every value is fictional.
 */

/**
 * The body of a create request for a CZK expense, plus the given extra keys.
 *
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function txExternalBody(array $extra = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => FinanceApiFixtures::softwareCategory()->id,
        'amount_minor' => 123450,
        'currency' => 'CZK',
        ...$extra,
    ];
}

/**
 * An object nested the given number of levels deep, the object itself being the first level.
 */
function txExternalNested(int $levels): stdClass
{
    $value = (object) ['leaf' => 'x'];

    for ($level = 1; $level < $levels; $level++) {
        $value = (object) ['n' => $value];
    }

    return $value;
}

/**
 * An object whose compact JSON is exactly the given number of bytes: `{"k":"` and `"}` take 8, the rest
 * is filler of the given character (one or two bytes each).
 */
function txExternalOfBytes(int $bytes, string $filler = 'a'): stdClass
{
    return (object) ['k' => str_repeat($filler, intdiv($bytes - 8, strlen($filler)))];
}

beforeEach(function (): void {
    $this->txExternalAdmin = ApiFixtures::admin();
});

it('stores an external object on create and returns the same object on detail and list', function (): void {
    $external = ['source' => 'demo-bank', 'id' => 'TX-1001', 'tags' => ['x']];

    $response = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->postJson('/api/v1/transactions', txExternalBody(['external' => $external]))
        ->assertCreated();

    // jsonb does not keep the order of the keys, which JSON does not define either: compare the content.
    expect($response->json('data.external'))->toEqual($external);
    TransactionContract::assertShape($response->json('data'));

    Auth::forgetGuards();

    $read = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:read']))
        ->getJson('/api/v1/transactions/'.$response->json('data.id'))
        ->assertOk();

    expect($read->json('data.external'))->toEqual($external);

    Auth::forgetGuards();

    $list = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:read']))
        ->getJson('/api/v1/transactions')
        ->assertOk();

    expect($list->json('data.0.external'))->toEqual($external);
});

it('returns external as null for a transaction created without it', function (): void {
    $response = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->postJson('/api/v1/transactions', txExternalBody())
        ->assertCreated();

    expect($response->json())->toHaveKey('data.external')
        ->and($response->json('data.external'))->toBeNull();
});

it('stores and returns an empty object as {} and never as []', function (): void {
    $response = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->postJson('/api/v1/transactions', txExternalBody(['external' => new stdClass]))
        ->assertCreated();

    expect($response->getContent())->toContain('"external":{}')
        ->and($response->getContent())->not->toContain('"external":[]');

    $stored = DB::selectOne('select jsonb_typeof(external) as type from transactions where id = ?', [$response->json('data.id')]);

    expect($stored->type)->toBe('object');

    Auth::forgetGuards();

    $read = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:read']))
        ->getJson('/api/v1/transactions/'.$response->json('data.id'))
        ->assertOk();

    expect($read->getContent())->toContain('"external":{}');
});

it('refuses a value that is not an object with 422 under external and creates nothing', function (mixed $value): void {
    $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->postJson('/api/v1/transactions', txExternalBody(['external' => $value]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external'])
        ->assertJsonPath('errors.external.0', __('kokpit.time.errors.external_not_object'));

    expect(DB::table('transactions')->count())->toBe(0);
})->with([
    'an empty list' => [[]],
    'a list' => [[1]],
    'a string' => ['text'],
    'a number' => [5],
    'a bool' => [true],
]);

it('accepts an object of exactly the byte limit and refuses one byte more', function (): void {
    $token = ApiFixtures::token($this->txExternalAdmin, ['finance:write']);

    expect(strlen((string) json_encode(txExternalOfBytes(4096))))->toBe(4096);

    $this->withToken($token)
        ->postJson('/api/v1/transactions', txExternalBody(['external' => txExternalOfBytes(4096)]))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/transactions', txExternalBody(['external' => txExternalOfBytes(4097)]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.external.0', __('kokpit.time.errors.external_too_large', ['max' => 4096]));

    expect(DB::table('transactions')->count())->toBe(1);
});

it('accepts 8 levels of nesting and refuses 9 under external', function (): void {
    $token = ApiFixtures::token($this->txExternalAdmin, ['finance:write']);

    $this->withToken($token)
        ->postJson('/api/v1/transactions', txExternalBody(['external' => txExternalNested(8)]))
        ->assertCreated();

    $this->withToken($token)
        ->postJson('/api/v1/transactions', txExternalBody(['external' => txExternalNested(9)]))
        ->assertUnprocessable()
        ->assertJsonPath('errors.external.0', __('kokpit.time.errors.external_too_deep'));
});
