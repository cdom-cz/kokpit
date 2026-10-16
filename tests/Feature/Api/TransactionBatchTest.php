<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use Illuminate\Support\Facades\Auth;
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
