<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySource;
use App\Domain\Finance\Models\Transaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiFixtures;
use Tests\Support\Canary;
use Tests\Support\FinanceApiFixtures;
use Tests\Support\TransactionContract;

/*
 * The importer journey of phase 9.1 (D-01 to D-09): an external tool (the owner's n8n) discovers the categories,
 * books a statement in one batch, finds, corrects and removes transactions, all through the token-secured API.
 * The journey calls only HTTP routes; the fixtures are what a real importer finds already present: the seeded
 * categories, one client, one project and a CNB list. Real Sanctum tokens; every value is fictional.
 */

/**
 * The raw activity rows of a transaction for one event, read without the partner scope.
 *
 * @return list<object>
 */
function txFlowActivity(string $transactionId, string $event): array
{
    return DB::table('activity_log')
        ->where('subject_id', $transactionId)
        ->where('event', $event)
        ->orderBy('created_at')
        ->get()
        ->all();
}

/**
 * A new request of a real client: the guard of the test keeps the user of the previous token.
 */
function txFlowNewRequest(): void
{
    Auth::forgetGuards();
}

beforeEach(function (): void {
    // The test runner is a console process; the label of an API request comes from the execution context.
    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));

    $this->admin = ApiFixtures::admin();
    $this->client = ApiFixtures::client();
    $this->project = ApiFixtures::project($this->client, 'FLOW');
    $this->token = ApiFixtures::token($this->admin, ['finance:read', 'finance:write']);

    FinanceApiFixtures::cnbRate('EUR', '2026-10-09', '25.200');
});

it('walks the importer journey: discover, bulk book, find, correct, return to the CNB rate, delete', function (): void {
    // 1. The importer lists the expense categories and picks a child of the Software root.
    $categories = $this->withToken($this->token)
        ->getJson('/api/v1/transaction-categories?filter[type]=expense&per_page=200')
        ->assertOk()
        ->json('data');

    $root = collect($categories)->firstWhere(static fn (array $c): bool => $c['name'] === 'Software' && $c['parent_id'] === null);
    $child = collect($categories)->first(static fn (array $c): bool => $c['parent_id'] === $root['id'] && $c['archived'] === false);

    expect($root)->not->toBeNull()
        ->and($child)->not->toBeNull();

    // 2. One batch books a CZK, an own-rate EUR and a bank-figure USD expense of the same day.
    $base = [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => $child['id'],
        'client_id' => $this->client->id,
        'project_id' => 'FLOW',
    ];

    txFlowNewRequest();
    $this->withToken($this->token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            [...$base, 'amount_minor' => 123450, 'currency' => 'CZK', 'description' => 'czk row'],
            [...$base, 'amount_minor' => 10000, 'currency' => 'EUR', 'exchange_rate' => '25.125', 'description' => 'eur row'],
            [...$base, 'amount_minor' => 5000, 'currency' => 'USD', 'czk_amount_minor' => 116000, 'description' => 'usd row'],
        ],
    ])->assertCreated()->assertExactJson(['saved' => 3, 'failed' => 0, 'errors' => []]);

    expect(Transaction::query()->count())->toBe(3);

    // 3. The own-rate row is found by its CZK source and the date range, with the project resolved from the key.
    txFlowNewRequest();
    $found = $this->withToken($this->token)
        ->getJson('/api/v1/transactions?filter[czk_source]=custom&filter[date_from]=2026-10-14&filter[date_to]=2026-10-14')
        ->assertOk()
        ->json('data');

    expect($found)->toHaveCount(1);

    $eur = $found[0];
    TransactionContract::assertShape($eur);

    expect($eur['czk_minor'])->toBe(251250)
        ->and($eur['czk_source'])->toBe('custom')
        ->and($eur['project_id'])->toBe($this->project->id)
        ->and($eur['client_id'])->toBe($this->client->id)
        ->and($eur['category_id'])->toBe($child['id']);

    $usd = Transaction::query()->where('description', 'usd row')->sole();
    expect($usd->czk_minor)->toBe(116000)
        ->and($usd->czk_source->value)->toBe('manual');

    // 4. Correcting the amount keeps the own rate (sticky): 200.00 EUR at 25.125.
    txFlowNewRequest();
    $corrected = $this->withToken($this->token)
        ->patchJson('/api/v1/transactions/'.$eur['id'], ['amount_minor' => 20000])
        ->assertOk()
        ->json('data');

    expect($corrected['amount_minor'])->toBe(20000)
        ->and($corrected['czk_minor'])->toBe(502500)
        ->and($corrected['czk_source'])->toBe('custom');

    // 5. Both nulls return the transaction to the CNB rate of the stored list of 2026-10-09.
    txFlowNewRequest();
    $cnb = $this->withToken($this->token)
        ->patchJson('/api/v1/transactions/'.$eur['id'], ['exchange_rate' => null, 'czk_amount_minor' => null])
        ->assertOk()
        ->json('data');

    expect($cnb['czk_source'])->toBe('cnb')
        ->and($cnb['czk_minor'])->toBe(504000)
        ->and($cnb['exchange_rate'])->toBe('25.2000000000')
        ->and($cnb['exchange_rate_date'])->toBe('2026-10-09');

    // 6. Deleting the USD row answers 204, and the row is gone afterwards.
    txFlowNewRequest();
    $this->withToken($this->token)->deleteJson('/api/v1/transactions/'.$usd->id)->assertNoContent();

    txFlowNewRequest();
    $this->withToken($this->token)->getJson('/api/v1/transactions/'.$usd->id)->assertNotFound();

    txFlowNewRequest();
    $this->withToken($this->token)->deleteJson('/api/v1/transactions/'.$usd->id)->assertNotFound();

    expect(Transaction::query()->count())->toBe(2);

    // 7. The remaining rows are still listed, newest first, nothing more and nothing less.
    txFlowNewRequest();
    $all = $this->withToken($this->token)->getJson('/api/v1/transactions?per_page=200')->assertOk()->json('data');

    expect(array_column($all, 'id'))->toContain($eur['id'])
        ->and(array_column($all, 'id'))->not->toContain($usd->id)
        ->and($all)->toHaveCount(2);
});

it('logs every write of the journey with the source api', function (): void {
    txFlowNewRequest();
    $batch = $this->withToken($this->token)->postJson('/api/v1/transactions/batch', [
        'transactions' => [
            ['type' => 'expense', 'transaction_date' => '2026-10-14', 'category_id' => FinanceApiFixtures::softwareChild()->id, 'amount_minor' => 5000, 'currency' => 'CZK', 'description' => 'first'],
            ['type' => 'expense', 'transaction_date' => '2026-10-14', 'category_id' => FinanceApiFixtures::softwareChild()->id, 'amount_minor' => 7000, 'currency' => 'CZK', 'description' => 'second'],
        ],
    ])->assertCreated();

    expect($batch->json('saved'))->toBe(2);

    $first = Transaction::query()->where('description', 'first')->sole();
    $second = Transaction::query()->where('description', 'second')->sole();

    txFlowNewRequest();
    $this->withToken($this->token)->patchJson('/api/v1/transactions/'.$first->id, ['counterparty' => 'Demo Vendor'])->assertOk();

    txFlowNewRequest();
    $this->withToken($this->token)->deleteJson('/api/v1/transactions/'.$second->id)->assertNoContent();

    $created = [...txFlowActivity($first->id, 'created'), ...txFlowActivity($second->id, 'created')];
    $updated = txFlowActivity($first->id, 'updated');
    $deleted = txFlowActivity($second->id, 'deleted');

    expect($created)->toHaveCount(2)
        ->and($updated)->not->toBeEmpty()
        ->and($deleted)->not->toBeEmpty()
        ->and(array_unique(array_column([...$created, ...$updated, ...$deleted], 'source')))->toBe(['api'])
        ->and(array_unique(array_column([...$created, ...$updated, ...$deleted], 'causer_id')))->toBe([$this->admin->getKey()]);
});

it('refuses the finance routes to a time-only token with 403 and to a Partner token with 401', function (): void {
    $timeOnly = ApiFixtures::token($this->admin, ['time:read', 'time:write']);
    $partner = ApiFixtures::token(Canary::partnerFor($this->client->id), ['finance:read', 'finance:write']);
    $transaction = FinanceApiFixtures::transaction($this->admin);

    $routes = [
        ['getJson', '/api/v1/transaction-categories', []],
        ['getJson', '/api/v1/transactions', []],
        ['getJson', '/api/v1/transactions/'.$transaction->id, []],
        ['postJson', '/api/v1/transactions', ['type' => 'expense']],
        ['postJson', '/api/v1/transactions/batch', ['transactions' => []]],
        ['patchJson', '/api/v1/transactions/'.$transaction->id, ['counterparty' => 'Demo Other Vendor']],
        ['deleteJson', '/api/v1/transactions/'.$transaction->id, []],
    ];

    foreach ($routes as [$method, $uri, $body]) {
        txFlowNewRequest();
        $this->flushHeaders();
        $this->withToken($timeOnly)->{$method}($uri, $body)->assertForbidden();

        txFlowNewRequest();
        $this->flushHeaders();
        $this->withToken($partner)->{$method}($uri, $body)->assertUnauthorized();

        txFlowNewRequest();
        $this->flushHeaders();
        $this->{$method}($uri, $body)->assertUnauthorized();
    }

    // No refused request wrote or deleted anything (read raw: the model scope needs a signed-in user).
    expect(DB::table('transactions')->where('id', $transaction->id)->value('counterparty'))->toBe('Demo Vendor');
});
