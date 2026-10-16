<?php

declare(strict_types=1);

use App\Domain\Finance\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\TransactionContract;

/*
 * D-02, D-03, D-06, D-08 and owner decisions A1, A2: writing single transactions over the REST API. The API maps
 * the request and lets the finance Actions decide, so every refusal carries the Czech message of the Admin UI under
 * the API field name. Real Sanctum tokens; every amount, rate and name is fictional.
 */

/**
 * The minimal body of a CZK expense in "Software"; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function txWriteBody(array $overrides = []): array
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

it('records a CZK expense and answers 201 with the transaction and a Location header', function (): void {
    $admin = ApiFixtures::admin();
    $token = ApiFixtures::token($admin, ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions', txWriteBody([
        'description' => 'Example licence',
        'counterparty' => 'Demo Vendor',
    ]))->assertCreated();

    $data = $response->json('data');

    TransactionContract::assertShape($data);
    expect($response->headers->get('Location'))->toBe(route('api.v1.transactions.show', $data['id']))
        ->and($data['type'])->toBe('expense')
        ->and($data['transaction_date'])->toBe('2026-10-14')
        ->and($data['amount_minor'])->toBe(123450)
        ->and($data['currency'])->toBe('CZK')
        ->and($data['czk_minor'])->toBe(123450)
        ->and($data['czk_source'])->toBe('identity')
        ->and($data['exchange_rate'])->toBeNull()
        ->and($data['description'])->toBe('Example licence')
        ->and($data['counterparty'])->toBe('Demo Vendor')
        ->and(Transaction::query()->whereKey($data['id'])->exists())->toBeTrue();

    // A real client is a new request: the guard of the test keeps the user of the first token.
    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($admin, ['finance:read']))
        ->getJson($response->headers->get('Location'))
        ->assertOk()
        ->assertJsonPath('data.id', $data['id']);
});

it('refuses a write with a token that lacks finance:write and a request without a token', function (): void {
    $admin = ApiFixtures::admin();

    $this->withToken(ApiFixtures::token($admin, ['time:write']))
        ->postJson('/api/v1/transactions', txWriteBody())
        ->assertForbidden();

    Auth::forgetGuards();

    $this->withToken(ApiFixtures::token($admin, ['finance:read']))
        ->postJson('/api/v1/transactions', txWriteBody())
        ->assertForbidden();

    Auth::forgetGuards();
    $this->flushHeaders();

    $this->postJson('/api/v1/transactions', txWriteBody())->assertUnauthorized();

    expect(Transaction::query()->count())->toBe(0);
});

it('lists every missing required field of a create', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $this->withToken($token)->postJson('/api/v1/transactions', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'transaction_date', 'category_id', 'amount_minor', 'currency']);
});

it('keys an invalid amount under amount_minor and never under amount', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);

    $response = $this->withToken($token)->postJson('/api/v1/transactions', txWriteBody(['amount_minor' => 0]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount_minor']);

    expect($response->json('errors'))->not->toHaveKey('amount')
        ->and($response->json('errors.amount_minor.0'))->toBe(__('finance.transactions.errors.amount_not_positive'));
});

it('answers the same create with the stored result when the Idempotency-Key is repeated', function (): void {
    $token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
    $body = txWriteBody(['description' => 'Example retry']);

    $first = $this->withToken($token)->withHeader('Idempotency-Key', 'demo-key-0003')
        ->postJson('/api/v1/transactions', $body)->assertCreated();
    $second = $this->withToken($token)->withHeader('Idempotency-Key', 'demo-key-0003')
        ->postJson('/api/v1/transactions', $body)->assertCreated();

    expect($second->headers->get('Idempotent-Replayed'))->toBe('true')
        ->and($second->json('data.id'))->toBe($first->json('data.id'))
        ->and(Transaction::query()->count())->toBe(1);
});
