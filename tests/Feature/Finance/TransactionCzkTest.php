<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Actions\DeleteTransaction;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Actions\UpdateTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;

/*
 * The write paths of transactions (FI-01, D-12, D-13): the CZK rule that fixes the CZK figure when a transaction
 * is recorded, and the record, update and delete Actions. Names, amounts and rates are invented.
 */

/**
 * The seeded root expense category "Software" of the starter set.
 */
function czkTestSoftware(): TransactionCategory
{
    return TransactionCategory::query()
        ->where('type', TransactionType::Expense->value)
        ->whereNull('parent_id')
        ->where('name', 'Software')
        ->sole();
}

/**
 * The data of a valid CZK expense; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function czkTestData(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => czkTestSoftware()->getKey(),
        'amount' => '1 234,50',
        'currency' => 'CZK',
        'description' => 'Fictional licence',
        'counterparty' => Canary::canary('counterparty'),
        ...$overrides,
    ];
}

/**
 * Stores one invented CNB rate row in the system context, as the download Action does.
 *
 * @param  array<string, mixed>  $overrides
 */
function czkTestRate(array $overrides = []): void
{
    $row = [
        'rate_date' => '2026-10-09',
        'currency' => 'EUR',
        'unit_amount' => 1,
        'rate' => '25.125',
        'fetched_at' => '2026-10-09 14:45:00+00',
        'created_at' => '2026-10-09 14:45:00+00',
        'updated_at' => '2026-10-09 14:45:00+00',
        ...$overrides,
    ];

    app(PartnerContext::class)->runAsSystem(static function () use ($row): void {
        ExchangeRate::query()->upsert([$row], ['currency', 'rate_date'], ['rate', 'unit_amount']);
    });
}

/**
 * The field errors of a ValidationException a callback raises, or null when it raises nothing.
 *
 * @return array<string, list<string>>|null
 */
function czkTestErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

describe('recording a CZK transaction', function (): void {
    it('stores the amount as the CZK figure with source identity and no rate', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        $transaction = app(RecordTransaction::class)->handle($admin, czkTestData());

        expect($transaction->type)->toBe(TransactionType::Expense)
            ->and($transaction->transaction_date->toDateString())->toBe('2026-10-14')
            ->and($transaction->amount_minor)->toBe(123450)
            ->and($transaction->amount_currency)->toBe('CZK')
            ->and($transaction->czk_minor)->toBe(123450)
            ->and($transaction->czk_source)->toBe(CzkSource::Identity)
            ->and($transaction->exchange_rate)->toBeNull()
            ->and($transaction->exchange_unit_amount)->toBeNull()
            ->and($transaction->exchange_rate_date)->toBeNull()
            ->and($transaction->czk()->equals(Money::ofMinor(123450, 'CZK')))->toBeTrue()
            ->and($transaction->description)->toBe('Fictional licence');
    });

    it('refuses a Partner and stores nothing', function (): void {
        $this->actingAs(Canary::admin());
        $data = czkTestData();
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(fn () => app(RecordTransaction::class)->handle($partner, $data))
            ->toThrow(AuthorizationException::class);

        $this->actingAs(Canary::admin());
        expect(Transaction::query()->where('counterparty', $data['counterparty'])->exists())->toBeFalse();
    });
});

describe('the CZK source labels', function (): void {
    it('shows the Czech label of every source', function (): void {
        expect(CzkSource::Identity->getLabel())->toBe('Kč')
            ->and(CzkSource::Cnb->getLabel())->toBe('Kurz ČNB')
            ->and(CzkSource::Manual->getLabel())->toBe('Zadáno ručně');
    });
});

describe('recording a foreign-currency transaction', function (): void {
    beforeEach(function (): void {
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
    });

    it('converts at the list of the newest day not after a Sunday and keeps the rate as a snapshot', function (): void {
        czkTestRate(['rate_date' => '2026-10-09', 'rate' => '25.125']);

        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'transaction_date' => '2026-10-11',
            'amount' => '100,00',
            'currency' => 'EUR',
        ]));

        expect($transaction->amount_minor)->toBe(10000)
            ->and($transaction->amount_currency)->toBe('EUR')
            ->and($transaction->czk_minor)->toBe(251250)
            ->and($transaction->czk_source)->toBe(CzkSource::Cnb)
            ->and($transaction->exchange_rate)->toBe('25.1250000000')
            ->and($transaction->exchange_unit_amount)->toBe(1)
            ->and($transaction->exchange_rate_date->toDateString())->toBe('2026-10-09');
    });

    it('divides a rate quoted per 100 by its unit amount', function (): void {
        czkTestRate(['currency' => 'JPY', 'unit_amount' => 100, 'rate' => '15.0']);

        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'transaction_date' => '2026-10-09',
            'amount' => '20000',
            'currency' => 'JPY',
        ]));

        // 20 000 JPY at 15,00 CZK per 100 JPY is 3 000,00 CZK.
        expect($transaction->czk_minor)->toBe(300000)
            ->and($transaction->exchange_unit_amount)->toBe(100)
            ->and($transaction->czk_source)->toBe(CzkSource::Cnb);
    });

    it('keeps the stored figure when a corrected list is inserted afterwards', function (): void {
        czkTestRate(['rate_date' => '2026-10-09', 'rate' => '25.125']);
        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'transaction_date' => '2026-10-09',
            'amount' => '100,00',
            'currency' => 'EUR',
        ]));

        czkTestRate(['rate_date' => '2026-10-09', 'rate' => '30.000']);
        czkTestRate(['rate_date' => '2026-10-10', 'rate' => '31.000']);

        $fresh = Transaction::query()->findOrFail($transaction->getKey());

        expect($fresh->czk_minor)->toBe(251250)
            ->and($fresh->exchange_rate)->toBe('25.1250000000')
            ->and($fresh->exchange_rate_date->toDateString())->toBe('2026-10-09');
    });

    it('asks for the CZK amount when no list exists and stores the typed figure as manual', function (): void {
        $data = czkTestData(['amount' => '50,00', 'currency' => 'USD']);
        czkTestRate(['currency' => 'USD', 'rate_date' => '2026-10-20', 'rate' => '22.5']);

        $errors = czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, $data));

        expect($errors)->toHaveKey('czk_amount')
            ->and($errors['czk_amount'][0])->toContain('USD')
            ->and($errors['czk_amount'][0])->toContain('14. 10. 2026')
            ->and(Transaction::query()->where('counterparty', $data['counterparty'])->exists())->toBeFalse();

        $transaction = app(RecordTransaction::class)->handle($this->admin, [...$data, 'czk_amount' => '1 999,00']);

        expect($transaction->czk_minor)->toBe(199900)
            ->and($transaction->czk_source)->toBe(CzkSource::Manual)
            ->and($transaction->exchange_rate)->toBeNull()
            ->and($transaction->exchange_rate_date)->toBeNull();
    });

    it('stores a typed figure as manual even when a rate exists', function (): void {
        czkTestRate(['rate_date' => '2026-10-09', 'rate' => '25.125']);

        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'transaction_date' => '2026-10-09',
            'amount' => '100,00',
            'currency' => 'EUR',
            'czk_amount' => '2 500,00',
        ]));

        expect($transaction->czk_minor)->toBe(250000)
            ->and($transaction->czk_source)->toBe(CzkSource::Manual)
            ->and($transaction->exchange_rate)->toBeNull();
    });

    it('refuses an unknown currency and a known code without any CNB row', function (): void {
        czkTestRate();

        expect(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData(['currency' => 'XYZ']))))
            ->toHaveKey('currency')
            ->and(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData(['currency' => 'CHF']))))
            ->toHaveKey('currency');
    });

    it('refuses an amount that is not a positive money figure', function (): void {
        foreach (['abc', '0', '-5,00', '1,234'] as $value) {
            expect(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData(['amount' => $value]))))
                ->toHaveKey('amount');
        }
    });
});

describe('the references of a transaction', function (): void {
    beforeEach(function (): void {
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
    });

    it('refuses a category of the other type, an archived category and an unknown one', function (): void {
        $income = TransactionCategory::query()->where('type', 'income')->whereNull('parent_id')->firstOrFail();
        $archived = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('id', '!=', czkTestSoftware()->getKey())->firstOrFail();
        $archived->forceFill(['archived_at' => now()])->save();

        foreach ([$income->getKey(), $archived->getKey(), '018f0000-0000-7000-8000-000000000000'] as $id) {
            expect(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData(['category_id' => $id]))))
                ->toHaveKey('category_id');
        }
    });

    it('accepts a client and a project of that client and refuses the forged combinations', function (): void {
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->getKey()]);
        $archivedClient = Client::factory()->create();
        $archivedClient->delete();

        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'client_id' => $client->getKey(),
            'project_id' => $project->getKey(),
        ]));

        expect($transaction->client_id)->toBe($client->getKey())
            ->and($transaction->project_id)->toBe($project->getKey())
            ->and(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData([
                'client_id' => $other->getKey(),
                'project_id' => $project->getKey(),
            ]))))->toHaveKey('project_id')
            ->and(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData([
                'project_id' => $project->getKey(),
            ]))))->toHaveKey('project_id')
            ->and(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData([
                'client_id' => $archivedClient->getKey(),
            ]))))->toHaveKey('client_id')
            ->and(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData([
                'client_id' => '018f0000-0000-7000-8000-000000000000',
            ]))))->toHaveKey('client_id');
    });

    it('refuses an archived project', function (): void {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->getKey()]);
        $project->delete();

        expect(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'client_id' => $client->getKey(),
            'project_id' => $project->getKey(),
        ]))))->toHaveKey('project_id');
    });

    it('refuses a text that is too long', function (): void {
        expect(czkTestErrors(fn () => app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'document_number' => str_repeat('a', 65),
        ]))))->toHaveKey('document_number');
    });
});

describe('editing a transaction', function (): void {
    beforeEach(function (): void {
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
        czkTestRate(['rate_date' => '2026-10-09', 'rate' => '25.125']);
        czkTestRate(['rate_date' => '2026-10-13', 'rate' => '26.000']);
    });

    /**
     * A recorded EUR expense of 100,00 on 2026-10-09 (CNB, 2 512,50 CZK), with overrides.
     *
     * @param  array<string, mixed>  $overrides
     */
    $record = function (array $overrides = []): Transaction {
        return app(RecordTransaction::class)->handle(test()->admin, czkTestData([
            'transaction_date' => '2026-10-09',
            'amount' => '100,00',
            'currency' => 'EUR',
            ...$overrides,
        ]));
    };

    it('recomputes the figure and the snapshot when the date changes on a CNB transaction', function () use ($record): void {
        $transaction = $record();

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['transaction_date' => '2026-10-14']);

        expect($updated->czk_minor)->toBe(260000)
            ->and($updated->czk_source)->toBe(CzkSource::Cnb)
            ->and($updated->exchange_rate)->toBe('26.0000000000')
            ->and($updated->exchange_rate_date->toDateString())->toBe('2026-10-13');
    });

    it('leaves the figure and the snapshot alone when only the description changes', function () use ($record): void {
        $transaction = $record();
        czkTestRate(['rate_date' => '2026-10-09', 'rate' => '30.000']);

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['description' => 'Changed text']);

        expect($updated->description)->toBe('Changed text')
            ->and($updated->czk_minor)->toBe(251250)
            ->and($updated->exchange_rate)->toBe('25.1250000000');
    });

    it('keeps a manual figure when the amount, date or currency changes', function () use ($record): void {
        $transaction = $record(['czk_amount' => '2 400,00']);

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, [
            'amount' => '200,00',
            'transaction_date' => '2026-10-14',
        ]);

        expect($updated->amount_minor)->toBe(20000)
            ->and($updated->czk_minor)->toBe(240000)
            ->and($updated->czk_source)->toBe(CzkSource::Manual)
            ->and($updated->exchange_rate)->toBeNull();
    });

    it('recomputes a manual transaction when the manual flag is switched off', function () use ($record): void {
        $transaction = $record(['czk_amount' => '2 400,00']);

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['czk_manual' => false]);

        expect($updated->czk_minor)->toBe(251250)
            ->and($updated->czk_source)->toBe(CzkSource::Cnb)
            ->and($updated->exchange_rate_date->toDateString())->toBe('2026-10-09');
    });

    it('stores a new manual figure and keeps the source manual', function () use ($record): void {
        $transaction = $record(['czk_amount' => '2 400,00']);

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['czk_amount' => '2 450,50']);

        expect($updated->czk_minor)->toBe(245050)
            ->and($updated->czk_source)->toBe(CzkSource::Manual);
    });

    it('turns a computed transaction into a manual one with the typed figure', function () use ($record): void {
        $transaction = $record();

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['czk_amount' => '2 000,00']);

        expect($updated->czk_minor)->toBe(200000)
            ->and($updated->czk_source)->toBe(CzkSource::Manual)
            ->and($updated->exchange_rate)->toBeNull()
            ->and($updated->exchange_rate_date)->toBeNull();
    });

    it('changes a foreign transaction to CZK as identity and clears the snapshot', function () use ($record): void {
        $transaction = $record();

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['currency' => 'CZK', 'amount' => '2 500,00']);

        expect($updated->amount_currency)->toBe('CZK')
            ->and($updated->czk_minor)->toBe(250000)
            ->and($updated->czk_source)->toBe(CzkSource::Identity)
            ->and($updated->exchange_rate)->toBeNull()
            ->and($updated->exchange_unit_amount)->toBeNull()
            ->and($updated->exchange_rate_date)->toBeNull();
    });

    it('refuses a date without any list unless a figure is typed', function () use ($record): void {
        $transaction = $record();

        $errors = czkTestErrors(fn () => app(UpdateTransaction::class)->handle($this->admin, $transaction, ['transaction_date' => '2026-10-01']));

        expect($errors)->toHaveKey('czk_amount')
            ->and(Transaction::query()->findOrFail($transaction->getKey())->czk_minor)->toBe(251250);

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, [
            'transaction_date' => '2026-10-01',
            'czk_amount' => '2 111,00',
        ]);

        expect($updated->czk_minor)->toBe(211100)
            ->and($updated->czk_source)->toBe(CzkSource::Manual);
    });

    it('keeps an archived category that is unchanged and refuses a change to another archived one', function () use ($record): void {
        $transaction = $record();
        $other = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('id', '!=', czkTestSoftware()->getKey())->firstOrFail();
        $other->forceFill(['archived_at' => now()])->save();
        czkTestSoftware()->forceFill(['archived_at' => now()])->save();

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, [
            'category_id' => $transaction->category_id,
            'description' => 'Still editable',
        ]);

        expect($updated->description)->toBe('Still editable')
            ->and($updated->category_id)->toBe($transaction->category_id)
            ->and(czkTestErrors(fn () => app(UpdateTransaction::class)->handle($this->admin, $transaction, ['category_id' => $other->getKey()])))
            ->toHaveKey('category_id');
    });

    it('keeps an archived client and project that are unchanged', function (): void {
        $client = Client::factory()->create();
        $project = Project::factory()->create(['client_id' => $client->getKey()]);
        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData([
            'client_id' => $client->getKey(),
            'project_id' => $project->getKey(),
        ]));
        $project->delete();
        $client->delete();

        $updated = app(UpdateTransaction::class)->handle($this->admin, $transaction, ['description' => 'After the archive']);

        expect($updated->description)->toBe('After the archive')
            ->and($updated->client_id)->toBe($client->getKey())
            ->and($updated->project_id)->toBe($project->getKey());
    });

    it('refuses a type change that leaves the category of the other type', function () use ($record): void {
        $transaction = $record();

        expect(czkTestErrors(fn () => app(UpdateTransaction::class)->handle($this->admin, $transaction, ['type' => 'income'])))
            ->toHaveKey('category_id');
    });

    it('refuses a Partner and changes nothing', function () use ($record): void {
        $transaction = $record();
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(fn () => app(UpdateTransaction::class)->handle($partner, $transaction, ['description' => 'Hijacked']))
            ->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);
        expect(Transaction::query()->findOrFail($transaction->getKey())->description)->toBe('Fictional licence');
    });
});

describe('deleting a transaction', function (): void {
    beforeEach(function (): void {
        $this->admin = Canary::admin();
        $this->actingAs($this->admin);
    });

    it('removes the row for the Admin', function (): void {
        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData());

        app(DeleteTransaction::class)->handle($this->admin, $transaction);

        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeFalse();
    });

    it('refuses a Partner and keeps the row', function (): void {
        $transaction = app(RecordTransaction::class)->handle($this->admin, czkTestData());
        [$clientA] = Canary::twoClients();
        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(fn () => app(DeleteTransaction::class)->handle($partner, $transaction))
            ->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);
        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeTrue();
    });
});
