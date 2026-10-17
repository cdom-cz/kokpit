<?php

declare(strict_types=1);

use App\Domain\Documents\Actions\AttachDocuments;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
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

    it('takes a CZK figure sent with exchange_rate null as the bank figure of a custom transaction (CR-01)', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $withList = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '26']);
        $withoutList = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'GBP', 'exchange_rate' => '29']);

        foreach ([[$withList, 'EUR'], [$withoutList, 'GBP']] as [$transaction, $currency]) {
            $data = ($this->patch)($transaction, ['exchange_rate' => null, 'czk_amount_minor' => 252000])->assertOk()->json('data');

            expect($data['czk_source'])->toBe('manual')
                ->and($data['czk_minor'])->toBe(252000)
                ->and($data['exchange_rate'])->toBeNull()
                ->and($data['exchange_unit_amount'])->toBeNull()
                ->and($data['exchange_rate_date'])->toBeNull()
                ->and($data['amount_minor'])->toBe(10000)
                ->and($data['currency'])->toBe($currency);

            $stored = $transaction->fresh();

            expect($stored->czk_source)->toBe(CzkSource::Manual)
                ->and($stored->czk_minor)->toBe(252000);
        }
    });

    it('takes the CZK figure with a changed currency that has no CNB list instead of answering currency_unknown (CR-01)', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $transaction = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR', 'exchange_rate' => '26']);

        $response = ($this->patch)($transaction, [
            'currency' => 'ISK',
            'amount_minor' => 10000,
            'exchange_rate' => null,
            'czk_amount_minor' => 1800,
        ])->assertOk();
        $data = $response->json('data');

        expect($response->json('errors'))->toBeNull()
            ->and($data['currency'])->toBe('ISK')
            ->and($data['amount_minor'])->toBe(10000)
            ->and($data['czk_source'])->toBe('manual')
            ->and($data['czk_minor'])->toBe(1800)
            ->and($data['exchange_rate'])->toBeNull();
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

/**
 * The errors of a refused write. Asserts the status and that the error keys are API field names: the Action keys
 * `amount` and `czk_amount` must never reach the client (owner decision A2).
 *
 * @return array<string, list<string>>
 */
function txWriteRefused(TestResponse $response): array
{
    $response->assertUnprocessable();

    /** @var array<string, list<string>> $errors */
    $errors = $response->json('errors');

    expect($errors)->not->toHaveKey('amount')->not->toHaveKey('czk_amount');

    return $errors;
}

describe('the CZK figure of a created transaction', function (): void {
    beforeEach(function (): void {
        $this->token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $this->post = fn (array $overrides) => $this->withToken($this->token)->postJson('/api/v1/transactions', txWriteBody($overrides));
    });

    it('books at an own rate, sent as a string or as a JSON number, with the transaction date as the rate date', function (): void {
        $string = ($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => '25.125'])->assertCreated()->json('data');
        $number = ($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => 25.125])->assertCreated()->json('data');

        foreach ([$string, $number] as $data) {
            expect($data['czk_source'])->toBe('custom')
                ->and($data['czk_minor'])->toBe(251250)
                ->and($data['exchange_rate'])->toBe('25.1250000000')
                ->and($data['exchange_unit_amount'])->toBe(1)
                ->and($data['exchange_rate_date'])->toBe('2026-10-14');
        }
    });

    it('refuses a rate in exponent form and a rate of zero under exchange_rate', function (): void {
        foreach ([0.00001, '0', '1e3', 'abc', ['25']] as $rate) {
            $errors = txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => $rate]));

            expect($errors)->toHaveKey('exchange_rate');
        }

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses a raw JSON rate written with an exponent under exchange_rate', function (): void {
        $raw = '{"type":"expense","transaction_date":"2026-10-14","category_id":"'.FinanceApiFixtures::softwareCategory()->id
            .'","amount_minor":10000,"currency":"EUR","exchange_rate":1.0E-5}';
        $response = $this->call('POST', '/api/v1/transactions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], $raw);

        expect(txWriteRefused($response))->toHaveKey('exchange_rate')
            ->and(Transaction::query()->count())->toBe(0);
    });

    it('books a rate quoted per one hundred units', function (): void {
        $data = ($this->post)(['amount_minor' => 10000, 'currency' => 'JPY', 'exchange_rate' => '16.000', 'exchange_unit_amount' => 100])->assertCreated()->json('data');

        expect($data['exchange_unit_amount'])->toBe(100)
            ->and($data['czk_source'])->toBe('custom')
            ->and($data['czk_minor'])->toBe(160000);
    });

    it('books the figure of the bank as manual, without a rate snapshot', function (): void {
        $data = ($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'czk_amount_minor' => 252000])->assertCreated()->json('data');

        expect($data['czk_source'])->toBe('manual')
            ->and($data['czk_minor'])->toBe(252000)
            ->and($data['exchange_rate'])->toBeNull()
            ->and($data['exchange_unit_amount'])->toBeNull()
            ->and($data['exchange_rate_date'])->toBeNull();

        expect(txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'czk_amount_minor' => -1])))->toHaveKey('czk_amount_minor');
    });

    it('takes the CNB rate when neither a rate nor a figure is sent, and asks for one when no rate is stored', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');

        $data = ($this->post)(['amount_minor' => 10000, 'currency' => 'EUR'])->assertCreated()->json('data');

        expect($data['czk_source'])->toBe('cnb')
            ->and($data['czk_minor'])->toBe(251250)
            ->and($data['exchange_rate_date'])->toBe('2026-10-14');

        // A rate list that starts after the day has nothing to offer.
        $errors = txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'transaction_date' => '2026-10-01']));

        expect($errors)->toHaveKey('czk_amount_minor')
            ->and($errors['czk_amount_minor'][0])->toContain('EUR');
    });

    it('refuses a rate together with a figure under exchange_rate and a unit amount alone under exchange_unit_amount', function (): void {
        $both = txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => '25.125', 'czk_amount_minor' => 252000]));
        $unit = txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'exchange_unit_amount' => 100, 'czk_amount_minor' => 252000]));

        expect($both)->toHaveKey('exchange_rate')
            ->and($both['exchange_rate'][0])->toBe(__('finance.transactions.errors.rate_with_czk_amount'))
            ->and($unit)->toHaveKey('exchange_unit_amount');
    });

    it('accepts a currency without any CNB list with a rate or a figure and refuses it without, under currency', function (): void {
        $custom = ($this->post)(['amount_minor' => 10000, 'currency' => 'ISK', 'exchange_rate' => '0.18'])->assertCreated()->json('data');
        $manual = ($this->post)(['amount_minor' => 10000, 'currency' => 'ISK', 'czk_amount_minor' => 1800])->assertCreated()->json('data');

        expect($custom['czk_source'])->toBe('custom')
            ->and($manual['czk_source'])->toBe('manual');

        foreach (['ISK', 'XYZ', 'eur', 'EURO', ''] as $currency) {
            expect(txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => $currency])))->toHaveKey('currency');
        }
    });

    it('refuses a rate, a unit amount and a CZK figure on a CZK transaction, under the field that was sent', function (): void {
        foreach ([['exchange_rate', '25'], ['exchange_unit_amount', 1], ['czk_amount_minor', 123450]] as [$field, $value]) {
            $errors = txWriteRefused(($this->post)([$field => $value]));

            expect($errors)->toHaveKey($field)
                ->and($errors[$field][0])->toBe(__('api.errors.czk_needs_no_rate'));
        }

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses an amount that is no integer, too large or overflows on conversion, under amount_minor', function (): void {
        foreach ([12.5, 'abc', '12.5', true, [1]] as $amount) {
            expect(txWriteRefused(($this->post)(['amount_minor' => $amount])))->toHaveKey('amount_minor');
        }

        // A raw JSON number above the largest integer arrives as a float and is no integer.
        $raw = '{"type":"expense","transaction_date":"2026-10-14","category_id":"'.FinanceApiFixtures::softwareCategory()->id
            .'","amount_minor":'.str_repeat('9', 25).',"currency":"CZK"}';
        $response = $this->call('POST', '/api/v1/transactions', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token,
        ], $raw);

        expect(txWriteRefused($response))->toHaveKey('amount_minor');

        // The conversion of a large amount at a large rate does not fit into an integer.
        $overflow = txWriteRefused(($this->post)(['amount_minor' => intdiv(PHP_INT_MAX, 10), 'currency' => 'EUR', 'exchange_rate' => '9999999999']));

        expect($overflow)->toHaveKey('amount_minor');
    });
});

describe('the references of a created transaction', function (): void {
    beforeEach(function (): void {
        $this->token = ApiFixtures::token(ApiFixtures::admin(), ['finance:write']);
        $this->post = fn (array $overrides) => $this->withToken($this->token)->postJson('/api/v1/transactions', txWriteBody($overrides));
    });

    it('accepts a transaction without a client and a project', function (): void {
        $data = ($this->post)([])->assertCreated()->json('data');

        expect($data['client_id'])->toBeNull()
            ->and($data['project_id'])->toBeNull();
    });

    it('refuses a project without a client and a project of another client, under project_id', function (): void {
        $client = ApiFixtures::client();
        $other = ApiFixtures::client();
        $project = ApiFixtures::project($client, 'DEMO');

        $without = txWriteRefused(($this->post)(['project_id' => $project->id]));
        $foreign = txWriteRefused(($this->post)(['client_id' => $other->id, 'project_id' => 'DEMO']));

        expect($without)->toHaveKey('project_id')
            ->and($without['project_id'][0])->toBe(__('finance.transactions.errors.project_not_of_client'))
            ->and($foreign)->toHaveKey('project_id')
            ->and($foreign['project_id'][0])->toBe(__('finance.transactions.errors.project_not_of_client'));
    });

    it('resolves a project key to the project UUID of the client', function (): void {
        $client = ApiFixtures::client();
        $project = ApiFixtures::project($client, 'DEMO');

        $data = ($this->post)(['client_id' => $client->id, 'project_id' => 'DEMO'])->assertCreated()->json('data');

        expect($data['client_id'])->toBe($client->id)
            ->and($data['project_id'])->toBe($project->id);
    });

    it('refuses a client, a category and a project given by name, under their own field', function (): void {
        $client = ApiFixtures::client();
        ApiFixtures::project($client, 'DEMO');

        foreach ([
            ['client_id', $client->name],
            ['category_id', 'Software'],
            ['project_id', 'Example project'],
        ] as [$field, $value]) {
            $errors = txWriteRefused(($this->post)([$field => $value]));

            expect($errors)->toHaveKey($field);
        }
    });

    it('refuses an archived category, a category of the other type and an archived project, with the messages of the panel', function (): void {
        $archived = FinanceApiFixtures::softwareChild();
        DB::table('transaction_categories')->where('id', $archived->id)->update(['archived_at' => '2026-10-10 08:00:00+00']);

        $category = txWriteRefused(($this->post)(['category_id' => $archived->id]));
        $mismatch = txWriteRefused(($this->post)(['category_id' => FinanceApiFixtures::incomeCategory()->id]));

        $client = ApiFixtures::client();
        $project = ApiFixtures::project($client, 'DEMO');
        DB::table('projects')->where('id', $project->id)->update(['deleted_at' => '2026-10-10 08:00:00+00']);
        $gone = txWriteRefused(($this->post)(['client_id' => $client->id, 'project_id' => $project->id]));

        expect($category['category_id'][0])->toBe(__('finance.transactions.errors.category_unavailable'))
            ->and($mismatch['category_id'][0])->toBe(__('finance.transactions.errors.category_type_mismatch'))
            ->and($gone['project_id'][0])->toBe(__('finance.transactions.errors.project_unavailable'));
    });
});

describe('smuggled keys', function (): void {
    beforeEach(function (): void {
        $this->admin = ApiFixtures::admin();
        $this->token = ApiFixtures::token($this->admin, ['finance:write']);
        $this->smuggled = [
            'id' => Str::uuid()->toString(),
            'czk_source' => 'manual',
            'czk_minor' => 1,
            'czk_manual' => true,
            'created_at' => '2000-01-01T00:00:00+00:00',
            'updated_at' => '2000-01-01T00:00:00+00:00',
            'amount' => '1.00',
            'czk_amount' => '1.00',
            'exchange_rate_date' => '2000-01-01',
            'unknown_key' => 'x',
        ];
    });

    it('never reach the Action on create: the row keeps its own values', function (): void {
        $data = $this->withToken($this->token)->postJson('/api/v1/transactions', txWriteBody($this->smuggled))->assertCreated()->json('data');

        expect($data['id'])->not->toBe($this->smuggled['id'])
            ->and($data['czk_source'])->toBe('identity')
            ->and($data['czk_minor'])->toBe(123450)
            ->and($data['amount_minor'])->toBe(123450)
            ->and($data['created_at'])->not->toStartWith('2000')
            ->and(DB::table('transactions')->where('id', $this->smuggled['id'])->exists())->toBeFalse();
    });

    it('never reach the Action on update: the figure and the timestamps are untouched', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $transaction = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR']);

        $data = $this->withToken($this->token)
            ->patchJson('/api/v1/transactions/'.$transaction->id, [...$this->smuggled, 'description' => 'Example smuggle'])
            ->assertOk()
            ->json('data');

        expect($data['id'])->toBe($transaction->id)
            ->and($data['description'])->toBe('Example smuggle')
            ->and($data['czk_source'])->toBe('cnb')
            ->and($data['czk_minor'])->toBe(251250)
            ->and($data['amount_minor'])->toBe(10000)
            ->and($data['created_at'])->not->toStartWith('2000');
    });
});

describe('JSON booleans in the integer fields (WR-01)', function (): void {
    beforeEach(function (): void {
        $this->admin = ApiFixtures::admin();
        $this->token = ApiFixtures::token($this->admin, ['finance:write']);
        $this->post = fn (array $overrides) => $this->withToken($this->token)->postJson('/api/v1/transactions', txWriteBody($overrides));
        $this->patch = fn (Transaction $transaction, array $body) => $this->withToken($this->token)
            ->patchJson('/api/v1/transactions/'.$transaction->id, $body);
    });

    it('refuses a boolean amount on create with the panel message and still accepts an integer string (WR-01)', function (): void {
        foreach ([true, false] as $amount) {
            $errors = txWriteRefused(($this->post)(['amount_minor' => $amount]));

            expect($errors['amount_minor'])->toBe([__('finance.transactions.errors.amount_invalid')]);
        }

        expect(Transaction::query()->count())->toBe(0);

        $data = ($this->post)(['amount_minor' => '10000'])->assertCreated()->json('data');

        expect($data['amount_minor'])->toBe(10000)
            ->and($data['currency'])->toBe('CZK');
    });

    it('refuses a boolean CZK figure and a boolean unit amount on create (WR-01)', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');

        foreach ([true, false] as $flag) {
            $figure = txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'czk_amount_minor' => $flag]));
            $unit = txWriteRefused(($this->post)(['amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => '25.125', 'exchange_unit_amount' => $flag]));

            expect($figure['czk_amount_minor'])->toBe([__('finance.transactions.errors.czk_invalid')])
                ->and($unit['exchange_unit_amount'])->toBe([__('finance.transactions.errors.unit_invalid')]);
        }

        expect(Transaction::query()->count())->toBe(0);
    });

    it('refuses booleans on update and leaves the stored row as it was (WR-01)', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $czk = FinanceApiFixtures::transaction($this->admin);
        $eur = FinanceApiFixtures::transaction($this->admin, ['amount' => '100,00', 'currency' => 'EUR']);

        foreach ([true, false] as $flag) {
            $amount = txWriteRefused(($this->patch)($czk, ['currency' => 'EUR', 'amount_minor' => $flag]));
            $figure = txWriteRefused(($this->patch)($eur, ['czk_amount_minor' => $flag]));
            $unit = txWriteRefused(($this->patch)($eur, ['exchange_rate' => '26', 'exchange_unit_amount' => $flag]));

            expect($amount['amount_minor'])->toBe([__('finance.transactions.errors.amount_invalid')])
                ->and($figure['czk_amount_minor'])->toBe([__('finance.transactions.errors.czk_invalid')])
                ->and($unit['exchange_unit_amount'])->toBe([__('finance.transactions.errors.unit_invalid')]);
        }

        $czk = $czk->fresh();
        $eur = $eur->fresh();

        expect($czk->amount_currency)->toBe('CZK')
            ->and($czk->amount_minor)->toBe(123450)
            ->and($czk->czk_minor)->toBe(123450)
            ->and($eur->czk_source)->toBe(CzkSource::Cnb)
            ->and($eur->czk_minor)->toBe(251250);
    });

    it('reports a batch item with a boolean as failed with the same panel message as a single create (WR-01)', function (): void {
        FinanceApiFixtures::cnbRate('EUR', '2026-10-14', '25.125');

        $response = $this->withToken($this->token)->postJson('/api/v1/transactions/batch', [
            'transactions' => [
                txWriteBody(['amount_minor' => true]),
                txWriteBody(['amount_minor' => 10000, 'currency' => 'EUR', 'czk_amount_minor' => true]),
                txWriteBody(['description' => 'good']),
            ],
        ])->assertStatus(207);

        expect($response->json('saved'))->toBe(1)
            ->and($response->json('failed'))->toBe(2)
            ->and(array_column($response->json('errors'), 'index'))->toBe([0, 1])
            ->and($response->json('errors.0.errors.amount_minor'))->toBe([__('finance.transactions.errors.amount_invalid')])
            ->and($response->json('errors.1.errors.czk_amount_minor'))->toBe([__('finance.transactions.errors.czk_invalid')])
            ->and(Transaction::query()->count())->toBe(1)
            ->and(Transaction::query()->sole()->description)->toBe('good');
    });
});
