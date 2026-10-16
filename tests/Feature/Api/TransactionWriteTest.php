<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\ApiFixtures;
use Tests\Support\DocumentFixtures;
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

/**
 * Attaches one fictional receipt to the transaction through the domain Action, with the Admin signed in on the web
 * guard only while it runs, so the fixture never masks a token check.
 */
function txWriteReceipt(User $admin, Transaction $transaction): void
{
    Auth::shouldUse('web');
    Auth::guard('web')->setUser($admin);

    try {
        app(AttachDocuments::class)->handle($admin, $transaction, [DocumentFixtures::upload('receipt-example-1.txt')]);
    } finally {
        Auth::forgetGuards();
    }
}

describe('editing a transaction', function (): void {
    beforeEach(function (): void {
        $this->admin = ApiFixtures::admin();
        $this->token = ApiFixtures::token($this->admin, ['finance:write']);
        $this->patch = fn (Transaction $transaction, array $body) => $this->withToken($this->token)
            ->patchJson('/api/v1/transactions/'.$transaction->id, $body);
    });

    it('changes only the description and keeps every other value and the CZK figure', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $transaction = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR']);

        $response = ($this->patch)($transaction, ['description' => 'Example corrected note'])->assertOk();
        $data = $response->json('data');

        TransactionContract::assertShape($data);
        expect($data['description'])->toBe('Example corrected note')
            ->and($data['counterparty'])->toBe('Demo Vendor')
            ->and($data['amount_minor'])->toBe(10000)
            ->and($data['currency'])->toBe('EUR')
            ->and($data['czk_minor'])->toBe(251250)
            ->and($data['czk_source'])->toBe('cnb')
            ->and($data['exchange_rate'])->toBe('25.1250000000');
    });

    it('clears the text fields, the client and the project with null', function (): void {
        $client = ApiFixtures::client();
        $project = ApiFixtures::project($client, 'DEMO');
        $transaction = FinanceApiFixtures::transaction($this->admin, [
            'document_number' => 'DEMO-2026-001',
            'client_id' => $client->id,
            'project_id' => $project->id,
        ]);

        $data = ($this->patch)($transaction, [
            'description' => null,
            'counterparty' => null,
            'document_number' => null,
            'client_id' => null,
            'project_id' => null,
        ])->assertOk()->json('data');

        expect($data['description'])->toBeNull()
            ->and($data['counterparty'])->toBeNull()
            ->and($data['document_number'])->toBeNull()
            ->and($data['client_id'])->toBeNull()
            ->and($data['project_id'])->toBeNull();
    });

    it('refuses to clear the client of a transaction that keeps its project, under project_id', function (): void {
        $client = ApiFixtures::client();
        $project = ApiFixtures::project($client, 'DEMO');
        $transaction = FinanceApiFixtures::transaction($this->admin, ['client_id' => $client->id, 'project_id' => $project->id]);

        ($this->patch)($transaction, ['client_id' => null])
            ->assertUnprocessable()
            ->assertJsonPath('errors.project_id.0', __('finance.transactions.errors.project_not_of_client'));

        expect($transaction->fresh()->client_id)->toBe($client->id);
    });

    it('refuses a currency without an amount, under amount_minor', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $transaction = FinanceApiFixtures::transaction($this->admin);

        ($this->patch)($transaction, ['currency' => 'EUR'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount_minor']);

        expect($transaction->fresh()->amount_currency)->toBe('CZK');
    });

    it('recomputes a changed amount of a custom transaction at its own stored rate', function (): void {
        $transaction = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '25.125']);

        $data = ($this->patch)($transaction, ['amount_minor' => 20000])->assertOk()->json('data');

        expect($data['amount_minor'])->toBe(20000)
            ->and($data['czk_minor'])->toBe(502500)
            ->and($data['czk_source'])->toBe('custom')
            ->and($data['exchange_rate'])->toBe('25.1250000000');
    });

    it('sets a new own rate and a bank figure on an existing transaction', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $transaction = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR']);

        $custom = ($this->patch)($transaction, ['exchange_rate' => '26'])->assertOk()->json('data');
        $manual = ($this->patch)($transaction, ['czk_amount_minor' => 252000])->assertOk()->json('data');

        expect($custom['czk_source'])->toBe('custom')
            ->and($custom['czk_minor'])->toBe(260000)
            ->and($manual['czk_source'])->toBe('manual')
            ->and($manual['czk_minor'])->toBe(252000)
            ->and($manual['exchange_rate'])->toBeNull();
    });

    it('returns a custom or a manual transaction to the CNB rate with both null and leaves a CNB transaction as it is', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $custom = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '26']);
        $manual = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'czk_amount' => '2520,00']);
        $cnb = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR']);
        $body = ['exchange_rate' => null, 'czk_amount_minor' => null];

        foreach ([$custom, $manual, $cnb] as $transaction) {
            $data = ($this->patch)($transaction, $body)->assertOk()->json('data');

            expect($data['czk_source'])->toBe('cnb')
                ->and($data['czk_minor'])->toBe(251250)
                ->and($data['exchange_rate'])->toBe('25.1250000000');
        }
    });

    it('drops only what each null names: the rate of a custom row, the figure of a manual row, nothing else', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $custom = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '26']);
        $manual = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'czk_amount' => '2520,00']);

        // exchange_rate null alone: a custom row falls back to CNB, a manual row keeps its bank figure.
        expect(($this->patch)($manual, ['exchange_rate' => null])->assertOk()->json('data.czk_source'))->toBe('manual');
        expect(($this->patch)($custom, ['exchange_rate' => null])->assertOk()->json('data.czk_source'))->toBe('cnb');

        // czk_amount_minor null alone: a manual row falls back to CNB, a custom row keeps its own rate.
        $custom = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '26']);
        expect(($this->patch)($custom, ['czk_amount_minor' => null])->assertOk()->json('data.czk_source'))->toBe('custom');
        expect(($this->patch)($manual, ['czk_amount_minor' => null])->assertOk()->json('data.czk_source'))->toBe('cnb');
    });

    it('answers 404 with the Czech message for an unknown transaction', function (): void {
        $this->withToken($this->token)
            ->patchJson('/api/v1/transactions/'.Str::uuid()->toString(), ['description' => 'Example'])
            ->assertNotFound()
            ->assertJsonPath('message', __('api.errors.not_found'));
    });
});

describe('deleting a transaction', function (): void {
    beforeEach(function (): void {
        DocumentFixtures::fakeDisk();
        $this->admin = ApiFixtures::admin();
        $this->token = ApiFixtures::token($this->admin, ['finance:write']);
    });

    it('deletes the transaction together with its receipt and answers 204 without a body', function (): void {
        $transaction = FinanceApiFixtures::transaction($this->admin);
        txWriteReceipt($this->admin, $transaction);
        $before = count(Storage::disk('local')->allFiles());

        expect(DB::table('documents')->count())->toBe(1)
            ->and($before)->toBeGreaterThan(0);

        $response = $this->withToken($this->token)->deleteJson('/api/v1/transactions/'.$transaction->id)->assertNoContent();

        expect($response->getContent())->toBe('')
            ->and(DB::table('transactions')->where('id', $transaction->id)->exists())->toBeFalse()
            ->and(DB::table('documents')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('answers 409 transaction_in_use when another row refers to the transaction and deletes nothing', function (): void {
        $transaction = FinanceApiFixtures::transaction($this->admin);
        txWriteReceipt($this->admin, $transaction);
        $files = Storage::disk('local')->allFiles();
        // A table of the test refers to the transaction, the way a later payment record will.
        DB::statement('CREATE TABLE api_probe_transaction_refs (transaction_id uuid NOT NULL REFERENCES transactions (id))');
        DB::table('api_probe_transaction_refs')->insert(['transaction_id' => $transaction->id]);

        $response = $this->withToken($this->token)->deleteJson('/api/v1/transactions/'.$transaction->id)->assertStatus(409);

        // Only the fixed Czech message and the code: no SQL state, no table name.
        expect($response->json())->toBe(['message' => __('api.errors.transaction_in_use'), 'code' => 'transaction_in_use'])
            ->and(DB::table('transactions')->where('id', $transaction->id)->exists())->toBeTrue()
            ->and(DB::table('documents')->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toBe($files);
    });

    it('answers 404 with the Czech message for an unknown transaction', function (): void {
        $this->withToken($this->token)
            ->deleteJson('/api/v1/transactions/'.Str::uuid()->toString())
            ->assertNotFound()
            ->assertJsonPath('message', __('api.errors.not_found'));
    });
});
