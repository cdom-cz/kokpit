<?php

declare(strict_types=1);

use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\ApiDocs;
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

it('keeps the stored external when a PATCH does not name it', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['external' => (object) ['source' => 'demo-bank']]);

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->patchJson('/api/v1/transactions/'.$stored->id, ['description' => 'Changed text'])
        ->assertOk()
        ->assertJsonPath('data.description', 'Changed text')
        ->assertJsonPath('data.external', ['source' => 'demo-bank']);
});

it('clears the stored external with null on a PATCH', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['external' => (object) ['source' => 'demo-bank']]);

    Auth::forgetGuards();

    $response = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->patchJson('/api/v1/transactions/'.$stored->id, ['external' => null])
        ->assertOk();

    expect($response->json('data.external'))->toBeNull()
        ->and(DB::selectOne('select external from transactions where id = ?', [$stored->id])->external)->toBeNull();
});

it('replaces the stored external whole on a PATCH, with no merge', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['external' => (object) ['source' => 'demo-bank', 'old' => 'gone']]);

    Auth::forgetGuards();

    $response = $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->patchJson('/api/v1/transactions/'.$stored->id, ['external' => (object) ['id' => 'TX-2002']])
        ->assertOk();

    expect($response->json('data.external'))->toBe(['id' => 'TX-2002']);
});

it('sets external on a transaction that had none and refuses a non-object on a PATCH', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin);
    $token = ApiFixtures::token($this->txExternalAdmin, ['finance:write']);

    Auth::forgetGuards();

    $this->withToken($token)
        ->patchJson('/api/v1/transactions/'.$stored->id, ['external' => []])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['external']);

    Auth::forgetGuards();

    $this->withToken($token)
        ->patchJson('/api/v1/transactions/'.$stored->id, ['external' => (object) ['source' => 'demo-bank']])
        ->assertOk()
        ->assertJsonPath('data.external', ['source' => 'demo-bank']);
});

it('leaves the CZK figure and its rate snapshot as stored when only external changes', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '25.125']);
    $before = DB::selectOne('select czk_minor, czk_source, exchange_rate, exchange_unit_amount, exchange_rate_date, amount_minor from transactions where id = ?', [$stored->id]);

    expect($before->czk_source)->toBe('custom');

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->patchJson('/api/v1/transactions/'.$stored->id, ['external' => (object) ['id' => 'TX-2003']])
        ->assertOk()
        ->assertJsonPath('data.czk_source', 'custom');

    $after = DB::selectOne('select czk_minor, czk_source, exchange_rate, exchange_unit_amount, exchange_rate_date, amount_minor from transactions where id = ?', [$stored->id]);

    expect($after)->toEqual($before);
});

it('stores the external object of each batch item and fails an item with a non-object alone', function (): void {
    $token = ApiFixtures::token($this->txExternalAdmin, ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            txExternalBody(['description' => 'row a', 'external' => (object) ['id' => 'TX-3001']]),
            txExternalBody(['description' => 'row b', 'external' => [1]]),
            txExternalBody(['description' => 'row c', 'external' => new stdClass]),
        ],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(2)
        ->and($response->json('failed'))->toBe(1)
        ->and($response->json('errors.0.index'))->toBe(1)
        ->and($response->json('errors.0.errors.external'))->toBe([__('kokpit.time.errors.external_not_object')]);

    $a = DB::selectOne("select external from transactions where description = 'row a'");
    $c = DB::selectOne("select jsonb_typeof(external) as type from transactions where description = 'row c'");

    expect(json_decode((string) $a->external, true))->toBe(['id' => 'TX-3001'])
        ->and($c->type)->toBe('object')
        ->and(DB::table('transactions')->where('description', 'row b')->exists())->toBeFalse();
});

it('writes no activity row when only external changes, and none for a create that carries it', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['external' => (object) ['source' => 'demo-bank']]);
    $before = DB::table('activity_log')->where('subject_type', 'transaction')->where('subject_id', $stored->id)->count();

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($this->txExternalAdmin, ['finance:write']))
        ->patchJson('/api/v1/transactions/'.$stored->id, ['external' => (object) ['source' => 'changed']])
        ->assertOk();

    $rows = DB::table('activity_log')->where('subject_type', 'transaction')->where('subject_id', $stored->id)->get();

    expect($rows)->toHaveCount($before)
        ->and(json_encode($rows))->not->toContain('external')
        ->and(json_encode($rows))->not->toContain('demo-bank');
});

it('renders no part of external on the panel pages of a transaction, snapshot included', function (): void {
    $canary = 'canary-'.bin2hex(random_bytes(6));
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['external' => (object) ['source' => $canary, 'note' => 'x-'.$canary]]);

    Auth::forgetGuards();
    $this->actingAs($this->txExternalAdmin);

    foreach (['/admin/transactions', '/admin/transactions/'.$stored->id.'/edit'] as $path) {
        // The page state travels as an HTML-escaped Livewire snapshot, so the canary is searched in the raw markup.
        $this->get($path)->assertOk()->assertDontSee($canary, false)->assertDontSee('&quot;external&quot;', false);
    }
});

it('keeps the stored external object when the edit form of the panel is saved', function (): void {
    $stored = FinanceApiFixtures::transaction($this->txExternalAdmin, ['external' => (object) ['source' => 'demo-bank', 'id' => 'TX-6001']]);

    Auth::forgetGuards();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->txExternalAdmin);

    Livewire::test(EditTransaction::class, ['record' => $stored->getKey()])
        ->fillForm(['description' => 'Edited in the panel'])
        ->call('save')
        ->assertHasNoFormErrors();

    $row = DB::selectOne('select description, external from transactions where id = ?', [$stored->id]);

    expect($row->description)->toBe('Edited in the panel')
        ->and(json_decode((string) $row->external, true))->toEqual(['source' => 'demo-bank', 'id' => 'TX-6001']);
});

it('documents external as an optional object on create, update and every batch item', function (): void {
    foreach (['api.v1.transactions.store', 'api.v1.transactions.update'] as $name) {
        $operation = ApiDocs::operation(Route::getRoutes()->getByName($name));
        $properties = $operation['requestBody']['content']['application/json']['schema']['properties'] ?? [];

        expect(array_keys($properties))->toContain('external')
            ->and($properties['external']['description'] ?? '')->toContain('free-form JSON object');
    }

    $batch = ApiDocs::operation(Route::getRoutes()->getByName('api.v1.transactions.batch'));
    $items = $batch['requestBody']['content']['application/json']['schema']['properties']['transactions']['items'] ?? [];

    expect(array_keys($items['properties'] ?? []))->toContain('external')
        ->and($batch['description'])->toContain('`external`');
});
