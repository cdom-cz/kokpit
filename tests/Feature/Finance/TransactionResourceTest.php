<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\CreateTransaction;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin-only "Transakce" screen (FI-01, D-12, D-13, D-14): create with a live CNB preview, edit with the
 * manual CZK switch, delete with a confirmation, the activity trail and the list. The rule itself is tested in
 * TransactionCzkTest; here the screen follows it. Names, amounts and rates are invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
});

/**
 * The seeded child expense category "Software › Hosting".
 */
function txScreenHosting(): TransactionCategory
{
    $parent = TransactionCategory::query()->where('type', 'expense')->whereNull('parent_id')->where('name', 'Software')->sole();

    return TransactionCategory::query()->where('parent_id', $parent->getKey())->where('name', 'Hosting')->sole();
}

/**
 * Stores one invented CNB rate row in the system context, as the download Action does.
 *
 * @param  array<string, mixed>  $overrides
 */
function txScreenRate(array $overrides = []): void
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
 * The form state of a valid foreign-currency expense; the overrides replace single keys.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function txScreenForm(array $overrides = []): array
{
    return [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => txScreenHosting()->getKey(),
        'amount' => '100,00',
        'currency' => 'EUR',
        'counterparty' => Canary::canary('counterparty'),
        ...$overrides,
    ];
}

/**
 * Records a transaction through the Action, as the Admin.
 *
 * @param  array<string, mixed>  $overrides
 */
function txScreenRecord(array $overrides = []): Transaction
{
    $admin = auth()->user();
    assert($admin instanceof User);

    return app(RecordTransaction::class)->handle($admin, [
        'type' => 'expense',
        'transaction_date' => '2026-10-14',
        'category_id' => txScreenHosting()->getKey(),
        'amount' => '100,00',
        'currency' => 'EUR',
        'counterparty' => Canary::canary('counterparty'),
        ...$overrides,
    ]);
}

describe('the tracer: record a foreign-currency expense and find it in the list', function (): void {
    it('opens the list and the create page for the Admin', function (): void {
        $this->get(TransactionResource::getUrl('index'))->assertOk()->assertSee('Transakce');
        $this->get(TransactionResource::getUrl('create'))->assertOk();
    });

    it('shows the CNB preview of the chosen currency, amount and date', function (): void {
        txScreenRate();

        Livewire::test(CreateTransaction::class)
            ->fillForm(txScreenForm())
            ->assertSee('Kurz ČNB')
            ->assertSee(Money::ofMinor(251250, 'CZK')->format('cs'));
    });

    it('asks for the CZK amount when the date has no list', function (): void {
        txScreenRate();

        Livewire::test(CreateTransaction::class)
            ->fillForm(txScreenForm(['transaction_date' => '2026-10-01']))
            ->assertSee('není uložen kurz ČNB');
    });

    it('stores a CNB transaction through RecordTransaction when the form is submitted', function (): void {
        txScreenRate();
        $form = txScreenForm();

        Livewire::test(CreateTransaction::class)
            ->fillForm($form)
            ->call('create')
            ->assertHasNoFormErrors();

        $stored = Transaction::query()->where('counterparty', $form['counterparty'])->sole();

        expect($stored->czk_source)->toBe(CzkSource::Cnb)
            ->and($stored->czk_minor)->toBe(251250)
            ->and($stored->amount->equals(Money::ofMinor(10000, 'EUR')))->toBeTrue()
            ->and($stored->exchange_rate_date?->toDateString())->toBe('2026-10-09');
    });

    it('saves a foreign amount without a list once the CZK amount is typed, as a manual figure', function (): void {
        txScreenRate();
        $form = txScreenForm(['transaction_date' => '2026-10-01', 'czk_amount' => '2 500,00']);

        Livewire::test(CreateTransaction::class)
            ->fillForm($form)
            ->call('create')
            ->assertHasNoFormErrors();

        $stored = Transaction::query()->where('counterparty', $form['counterparty'])->sole();

        expect($stored->czk_source)->toBe(CzkSource::Manual)
            ->and($stored->czk_minor)->toBe(250000);
    });

    it('refuses a foreign amount without a list and without a CZK amount, next to the field', function (): void {
        txScreenRate();
        $form = txScreenForm(['transaction_date' => '2026-10-01']);

        Livewire::test(CreateTransaction::class)
            ->fillForm($form)
            ->call('create')
            ->assertHasFormErrors(['czk_amount']);

        expect(Transaction::query()->where('counterparty', $form['counterparty'])->exists())->toBeFalse();
    });

    it('saves a CZK transaction without any rate', function (): void {
        $form = txScreenForm(['currency' => 'CZK', 'amount' => '1 234,50']);

        Livewire::test(CreateTransaction::class)
            ->fillForm($form)
            ->call('create')
            ->assertHasNoFormErrors();

        $stored = Transaction::query()->where('counterparty', $form['counterparty'])->sole();

        expect($stored->czk_source)->toBe(CzkSource::Identity)
            ->and($stored->czk_minor)->toBe(123450);
    });

    it('lists the row with its original and CZK amounts and the category with its parent', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$transaction])
            ->assertSee('Software › Hosting')
            ->assertSee($transaction->amount->format('cs'))
            ->assertSee($transaction->czk()->format('cs'))
            ->assertSee($transaction->counterparty);
    });

    it('lists the newest transaction date first', function (): void {
        txScreenRate();
        $old = txScreenRecord(['transaction_date' => '2026-10-12']);
        $new = txScreenRecord(['transaction_date' => '2026-10-15']);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$new, $old], inOrder: true);
    });

    it('refuses a Partner on the list, the create page and the edit page of another client', function (): void {
        txScreenRate();
        [$clientA, $clientB] = Canary::twoClients();
        $other = txScreenRecord(['client_id' => $clientB]);

        $this->actingAs(Canary::partnerFor($clientA));

        $this->get(TransactionResource::getUrl('index'))->assertForbidden();
        $this->get(TransactionResource::getUrl('create'))->assertForbidden();
        $this->get(TransactionResource::getUrl('edit', ['record' => $other->getKey()]))->assertForbidden();
    });

    it('refuses a Partner on the Livewire components', function (): void {
        $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

        Livewire::test(ListTransactions::class)->assertForbidden();
        Livewire::test(CreateTransaction::class)->assertForbidden();
    });
});

describe('the edit page is part of the screen', function (): void {
    it('opens for the Admin with the stored values', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->assertFormSet([
                'type' => TransactionType::Expense,
                'currency' => 'EUR',
                'amount' => '100,00',
                'category_id' => $transaction->category_id,
            ]);
    });
});
