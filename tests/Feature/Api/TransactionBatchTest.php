<?php

declare(strict_types=1);

use App\Domain\Api\Models\IdempotencyKey;
use App\Domain\Finance\Models\Transaction;
use App\Http\Controllers\Api\V1\TransactionExamples;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Tests\Support\ApiDocs;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;

/*
 * D-04, D-05: batch create of transactions with partial success. Every item goes through the same
 * RecordTransaction Action as the single create, in its own transaction. Real Sanctum tokens; every value is
 * fictional.
 */

/**
 * A valid CZK expense item in "Software"; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function txBatchItem(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => FinanceApiFixtures::softwareCategory()->id,
        'amount_minor' => 123450,
        'currency' => 'CZK',
        ...$overrides,
    ];
}

it('saves a batch of valid transactions through RecordTransaction and answers 201 with the counts', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            txBatchItem(['description' => 'czk row']),
            txBatchItem(['description' => 'eur row', 'amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => '25.125']),
        ],
    ])->assertCreated();

    expect($response->json())->toBe(['saved' => 2, 'failed' => 0, 'errors' => []])
        ->and(Transaction::query()->count())->toBe(2);

    $czk = Transaction::query()->where('description', 'czk row')->sole();
    $eur = Transaction::query()->where('description', 'eur row')->sole();

    expect($czk->amount_currency)->toBe('CZK')
        ->and($czk->czk_minor)->toBe(123450)
        ->and($eur->amount_currency)->toBe('EUR')
        ->and($eur->amount_minor)->toBe(10000)
        ->and($eur->czk_minor)->toBe(251250)
        ->and($eur->czk_source->value)->toBe('custom');
});

it('refuses a token that only writes time and a request without a token', function (): void {
    $body = ['transactions' => [txBatchItem()]];

    $this->withToken(ApiFixtures::token(ApiFixtures::admin(), ['time:write']))
        ->postJson('/api/v1/transactions/batch', $body)
        ->assertForbidden();

    // A real client is a new request: the guard of the test keeps the user of the first token.
    Auth::forgetGuards();
    $this->flushHeaders();

    $this->postJson('/api/v1/transactions/batch', $body)->assertUnauthorized();

    expect(Transaction::query()->count())->toBe(0);
});

it('saves the valid items, skips the one with an archived category and answers 207 with its index', function (): void {
    $archived = FinanceApiFixtures::softwareChild();
    DB::table('transaction_categories')->where('id', $archived->id)->update(['archived_at' => '2026-10-10 08:00:00+00']);
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            txBatchItem(['description' => 'first']),
            txBatchItem(['description' => 'second', 'category_id' => $archived->id]),
            txBatchItem(['description' => 'third']),
        ],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(2)
        ->and($response->json('failed'))->toBe(1)
        ->and($response->json('errors'))->toHaveCount(1)
        ->and($response->json('errors.0.index'))->toBe(1)
        ->and($response->json('errors.0.errors.category_id.0'))->toBe(__('finance.transactions.errors.category_unavailable'))
        ->and(Transaction::query()->orderBy('description')->pluck('description')->all())->toBe(['first', 'third']);
});

it('reports a shape refusal and an Action refusal in the same index and errors format under the API field names', function (): void {
    $client = ApiFixtures::client();
    $other = ApiFixtures::project(ApiFixtures::client(), 'OTHER');
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            txBatchItem(['amount_minor' => 'abc']),
            txBatchItem(['amount_minor' => 0]),
            txBatchItem(['client_id' => $client->id, 'project_id' => $other->id]),
            txBatchItem(['description' => 'good']),
        ],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(1)
        ->and($response->json('failed'))->toBe(3)
        ->and(array_column($response->json('errors'), 'index'))->toBe([0, 1, 2])
        ->and($response->json('errors.0.errors'))->toHaveKey('amount_minor')
        ->and($response->json('errors.1.errors'))->toHaveKey('amount_minor')
        ->and($response->json('errors.1.errors'))->not->toHaveKey('amount')
        ->and($response->json('errors.2.errors'))->toHaveKey('project_id')
        ->and(Transaction::query()->count())->toBe(1);
});

it('answers 422 with the counts, the errors and a message when no item can be saved', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            txBatchItem(['amount_minor' => 'abc']),
            txBatchItem(['currency' => 'eur']),
            ['type' => 'expense'],
        ],
    ])->assertStatus(422);

    expect($response->json())->toHaveKeys(['message', 'saved', 'failed', 'errors'])
        ->and($response->json('message'))->toBe(__('api.errors.batch_none_saved'))
        ->and($response->json('saved'))->toBe(0)
        ->and($response->json('failed'))->toBe(3)
        ->and(array_column($response->json('errors'), 'index'))->toBe([0, 1, 2])
        ->and($response->json('errors.0.errors'))->toHaveKey('amount_minor')
        ->and($response->json('errors.1.errors'))->toHaveKey('currency')
        ->and($response->json('errors.2.errors'))->toHaveKeys(['transaction_date', 'category_id', 'amount_minor', 'currency'])
        ->and(Transaction::query()->count())->toBe(0);
});

it('fails an item that is not a JSON object under transaction and keeps its neighbours', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [txBatchItem(['description' => 'a']), 5, 'text', [1, 2], null, txBatchItem(['description' => 'b'])],
    ])->assertStatus(207);

    expect($response->json('saved'))->toBe(2)
        ->and($response->json('failed'))->toBe(4)
        ->and(array_column($response->json('errors'), 'index'))->toBe([1, 2, 3, 4]);

    foreach ($response->json('errors') as $error) {
        expect($error['errors'])->toBe(['transaction' => [__('api.errors.batch_item_not_object')]]);
    }

    expect(Transaction::query()->count())->toBe(2);
});

it('reports a fault of the server as a failed item under transaction, answers 207 and keeps the fault text out of the body', function (): void {
    Exceptions::fake();
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
    $inserts = 0;

    // The second insert meets a fault of the server, not a refusal of the item.
    Transaction::creating(static function () use (&$inserts): void {
        if (++$inserts === 2) {
            throw new RuntimeException('fictional fault');
        }
    });

    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [txBatchItem(['description' => 'a']), txBatchItem(['description' => 'b']), txBatchItem(['description' => 'c'])],
    ], ['Idempotency-Key' => 'demo-key-0101'])->assertStatus(207);

    expect($response->json())->toBe([
        'saved' => 2,
        'failed' => 1,
        'errors' => [['index' => 1, 'errors' => ['transaction' => [__('api.errors.batch_item_failed')]]]],
    ])
        ->and($response->getContent())->not->toContain('fictional fault')
        ->and($response->getContent())->not->toContain('RuntimeException')
        ->and(Transaction::query()->orderBy('description')->pluck('description')->all())->toBe(['a', 'c'])
        ->and(IdempotencyKey::query()->count())->toBe(1);

    Exceptions::assertReported(RuntimeException::class);
});

it('refuses an empty list and more than 500 items under transactions and saves nothing', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
    $item = txBatchItem();

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => []])
        ->assertStatus(422)
        ->assertJsonValidationErrors('transactions');

    $this->withToken($token)->postJson('/api/v1/transactions/batch', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('transactions');

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => array_fill(0, 501, $item)])
        ->assertStatus(422)
        ->assertJsonValidationErrors('transactions');

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => ['a' => $item]])
        ->assertStatus(422)
        ->assertJsonValidationErrors('transactions');

    expect(Transaction::query()->count())->toBe(0);
});

it('reads the item limit from the configuration', function (): void {
    config(['kokpit.api.transaction_batch_max_items' => 3]);
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => array_fill(0, 4, txBatchItem())])
        ->assertStatus(422)
        ->assertJsonValidationErrors('transactions');

    expect(Transaction::query()->count())->toBe(0);
});

it('saves exactly 500 valid items', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
    $category = FinanceApiFixtures::softwareCategory()->id;
    $items = [];

    for ($i = 0; $i < 500; $i++) {
        $items[] = txBatchItem([
            'category_id' => $category,
            'transaction_date' => sprintf('2026-%02d-%02d', 1 + intdiv($i, 28) % 12, 1 + $i % 28),
            'amount_minor' => 1000 + $i,
            'description' => 'item '.$i,
        ]);
    }

    $started = hrtime(true);
    $response = $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => $items]);
    $seconds = (hrtime(true) - $started) / 1_000_000_000;

    // The duration is measured for the owner (idempotency_stale_lock_seconds is 120) and never asserted, so the
    // result of this test does not depend on the speed of the machine.
    fwrite(STDERR, sprintf("\ntransaction batch of 500 items: %.2f s\n", $seconds));

    $response->assertCreated()->assertExactJson(['saved' => 500, 'failed' => 0, 'errors' => []]);

    expect(Transaction::query()->count())->toBe(500);
});

it('counts a batch as one write against the per-token limit', function (): void {
    config(['kokpit.api.rate_limit.write' => 2]);
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
    $items = [txBatchItem(), txBatchItem(), txBatchItem()];

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => $items])
        ->assertCreated()
        ->assertHeader('X-RateLimit-Remaining', '1');
    Auth::forgetGuards();

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => $items])
        ->assertCreated()
        ->assertHeader('X-RateLimit-Remaining', '0');
    Auth::forgetGuards();

    $this->withToken($token)->postJson('/api/v1/transactions/batch', ['transactions' => $items])
        ->assertStatus(429);

    expect(Transaction::query()->count())->toBe(6);
});

it('documents the batch route with 201, 207, 409 and 422 and the Idempotency-Key header', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.transactions.batch');
    $operation = ApiDocs::operation($route);
    $headers = array_map(
        static fn (array $parameter): string => strtolower((string) $parameter['name']),
        array_filter($operation['parameters'] ?? [], static fn (array $parameter): bool => ($parameter['in'] ?? null) === 'header'),
    );
    $items = $operation['requestBody']['content']['application/json']['schema']['properties']['transactions']['items'] ?? [];

    expect($operation['responses'])->toHaveKeys([201, 207, 409, 422])
        ->and($headers)->toContain('idempotency-key')
        ->and($operation['description'])->toContain('`finance:write`')
        ->and(array_keys($items['properties'] ?? []))->toContain('type', 'transaction_date', 'category_id', 'amount_minor', 'currency', 'exchange_rate', 'czk_amount_minor', 'project_id')
        ->and(ApiDocs::violations([$route], ApiDocs::spec()))->toBe([]);
});

it('documents answers that have the layout of the real answers', function (): void {
    $archived = FinanceApiFixtures::softwareChild();
    DB::table('transaction_categories')->where('id', $archived->id)->update(['archived_at' => '2026-10-10 08:00:00+00']);
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $mixed = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [txBatchItem(), txBatchItem(['category_id' => $archived->id])],
    ])->assertStatus(207);

    Auth::forgetGuards();

    $none = $this->withToken($token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [txBatchItem(['amount_minor' => 0]), 'text'],
    ])->assertStatus(422);

    expect(array_keys(TransactionExamples::BATCH_ALL_SAVED))->toBe(['saved', 'failed', 'errors'])
        ->and(array_keys(TransactionExamples::BATCH_MIXED))->toBe(array_keys($mixed->json()))
        ->and(array_keys(TransactionExamples::BATCH_MIXED['errors'][0]))->toBe(array_keys($mixed->json('errors.0')))
        ->and(TransactionExamples::BATCH_MIXED['errors'][0]['errors']['category_id'])->toBe($mixed->json('errors.0.errors.category_id'))
        ->and(array_keys(TransactionExamples::BATCH_NONE_SAVED))->toBe(array_keys($none->json()))
        ->and(TransactionExamples::BATCH_NONE_SAVED['message'])->toBe($none->json('message'))
        ->and(TransactionExamples::BATCH_NONE_SAVED['errors'][0]['errors']['amount_minor'])->toBe($none->json('errors.0.errors.amount_minor'))
        ->and(TransactionExamples::BATCH_NONE_SAVED['errors'][1]['errors']['transaction'])->toBe($none->json('errors.1.errors.transaction'));
});
