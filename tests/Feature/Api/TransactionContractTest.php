<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\CzkSource;
use App\Http\Controllers\Api\V1\TransactionExamples;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\ApiDocs;
use Tests\Support\ApiFixtures;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\TransactionContract;

/*
 * D-07: the transaction wire contract. The keys, their order and their JSON types are pinned against the real
 * resource, the documented example and the generated spec, so a rename, a type change or a column that leaks
 * fails here before any n8n flow breaks. `id` is the first key (the clarification under D-07 in 09.1-CONTEXT.md).
 */

it('returns exactly the confirmed keys, order and types for a foreign currency transaction with client and project', function (): void {
    $admin = ApiFixtures::admin();
    $client = ApiFixtures::client();
    $project = ApiFixtures::project($client, 'DEMO');
    FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
    $transaction = FinanceApiFixtures::transaction($admin, [
        'amount' => '100,00',
        'currency' => 'EUR',
        'transaction_date' => '2026-10-14',
        'category_id' => FinanceApiFixtures::softwareChild()->id,
        'document_number' => 'DEMO-2026-001',
        'client_id' => $client->id,
        'project_id' => $project->id,
    ]);

    $data = $this->withToken(ApiFixtures::token($admin, ['finance:read']))
        ->getJson('/api/v1/transactions/'.$transaction->id)
        ->assertOk()
        ->json('data');

    TransactionContract::assertShape($data);
    expect($data['id'])->toBe($transaction->id)
        ->and($data['type'])->toBe('expense')
        ->and($data['transaction_date'])->toBe('2026-10-14')
        ->and($data['category_id'])->toBe(FinanceApiFixtures::softwareChild()->id)
        ->and($data['amount_minor'])->toBe(10000)
        ->and($data['currency'])->toBe('EUR')
        ->and($data['czk_minor'])->toBe(251250)
        ->and($data['czk_source'])->toBe(CzkSource::Cnb->value)
        ->and($data['exchange_rate'])->toBe('25.1250000000')
        ->and($data['exchange_unit_amount'])->toBe(1)
        ->and($data['exchange_rate_date'])->toBe('2026-10-14')
        ->and($data['document_number'])->toBe('DEMO-2026-001')
        ->and($data['client_id'])->toBe($client->id)
        ->and($data['project_id'])->toBe($project->id)
        ->and($data['created_at'])->toEndWith('+00:00');
});

it('returns the same keys with null for every optional value on a minimal CZK transaction', function (): void {
    $admin = ApiFixtures::admin();
    $transaction = FinanceApiFixtures::transaction($admin, ['description' => null, 'counterparty' => null]);

    $data = $this->withToken(ApiFixtures::token($admin, ['finance:read']))
        ->getJson('/api/v1/transactions/'.$transaction->id)
        ->assertOk()
        ->json('data');

    TransactionContract::assertShape($data);
    expect($data['amount_minor'])->toBe(123450)
        ->and($data['czk_minor'])->toBe(123450)
        ->and($data['czk_source'])->toBe(CzkSource::Identity->value)
        ->and($data['exchange_rate'])->toBeNull()
        ->and($data['exchange_unit_amount'])->toBeNull()
        ->and($data['exchange_rate_date'])->toBeNull()
        ->and($data['description'])->toBeNull()
        ->and($data['counterparty'])->toBeNull()
        ->and($data['document_number'])->toBeNull()
        ->and($data['client_id'])->toBeNull()
        ->and($data['project_id'])->toBeNull();
});

it('carries no receipt, document or file key', function (): void {
    $admin = ApiFixtures::admin();
    $transaction = FinanceApiFixtures::transaction($admin);

    $data = $this->withToken(ApiFixtures::token($admin, ['finance:read']))
        ->getJson('/api/v1/transactions/'.$transaction->id)
        ->assertOk()
        ->json('data');

    foreach (array_keys($data) as $key) {
        expect($key)->not->toContain('receipt')->not->toContain('file')->not->toContain('storage');
    }
});

it('documents an example with the same keys and types as the resource', function (): void {
    TransactionContract::assertShape(TransactionExamples::TRANSACTION['data']);

    expect(TransactionExamples::TRANSACTION['data']['czk_source'])->toBe(CzkSource::Custom->value);
});

it('marks exactly the optional keys of the response as nullable in the generated spec', function (): void {
    $operation = ApiDocs::operation(Route::getRoutes()->getByName('api.v1.transactions.show'));

    expect($operation)->not->toBeNull();

    $schema = $operation['responses'][200]['content']['application/json']['schema'];
    $properties = $schema['properties']['data']['properties'];

    expect(array_keys($properties))->toBe(array_keys(TransactionContract::KEYS))
        ->and($schema['properties']['data']['required'])->toBe(array_keys(TransactionContract::KEYS));

    foreach (TransactionContract::KEYS as $key => $type) {
        expect(in_array('null', (array) $properties[$key]['type'], true))
            ->toBe(str_starts_with($type, '?'), "nullable flag of $key in the spec");
    }
});

it('answers an unknown UUID with the Czech 404 of the API', function (): void {
    $this->withToken(ApiFixtures::token(ApiFixtures::admin(), ['finance:read']))
        ->getJson('/api/v1/transactions/'.Str::uuid())
        ->assertNotFound()
        ->assertJsonPath('message', __('api.errors.not_found'));
});

it('answers a path segment that is no UUID with 404 before the controller is reached', function (): void {
    $route = Route::getRoutes()->getByName('api.v1.transactions.show');

    expect($route->wheres)->toHaveKey('transaction');

    $response = $this->withToken(ApiFixtures::token(ApiFixtures::admin(), ['finance:read']))
        ->getJson('/api/v1/transactions/not-a-uuid')
        ->assertNotFound();

    expect($response->json('message'))->not->toBe(__('api.errors.not_found'));
});
