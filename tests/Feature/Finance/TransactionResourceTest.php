<?php

declare(strict_types=1);

use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Finance\Actions\ArchiveTransactionCategory;
use App\Domain\Finance\Actions\RecordTransaction;
use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use App\Filament\RelationManagers\TransactionHistoryRelationManager;
use App\Filament\Resources\InvoiceResource;
use App\Filament\Resources\TransactionResource;
use App\Filament\Resources\TransactionResource\Pages\CreateTransaction;
use App\Filament\Resources\TransactionResource\Pages\EditTransaction;
use App\Filament\Resources\TransactionResource\Pages\ListTransactions;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

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

describe('the edit page follows the recompute-versus-manual rule', function (): void {
    it('stores the figure of another list when a computed transaction moves to a day with one', function (): void {
        txScreenRate();
        txScreenRate(['rate_date' => '2026-10-12', 'rate' => '24.000']);
        $transaction = txScreenRecord(['transaction_date' => '2026-10-10']);

        expect($transaction->czk_minor)->toBe(251250);

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['transaction_date' => '2026-10-13'])
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();

        expect($transaction->czk_source)->toBe(CzkSource::Cnb)
            ->and($transaction->czk_minor)->toBe(240000)
            ->and($transaction->exchange_rate_date?->toDateString())->toBe('2026-10-12');
    });

    it('shows the manual hint on a manual transaction and keeps the bank figure when the amount changes', function (): void {
        txScreenRate();
        $transaction = txScreenRecord(['czk_amount' => '2 600,00']);

        expect($transaction->czk_source)->toBe(CzkSource::Manual);

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->assertSee(__('finance.transactions.hints.czk_manual_kept'))
            ->assertFormSet(['czk_manual' => true, 'czk_amount' => '2600,00'])
            ->fillForm(['amount' => '200,00', 'transaction_date' => '2026-10-15'])
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();

        expect($transaction->czk_source)->toBe(CzkSource::Manual)
            ->and($transaction->czk_minor)->toBe(260000)
            ->and($transaction->amount->equals(Money::ofMinor(20000, 'EUR')))->toBeTrue();
    });

    it('does not show the manual hint on a computed transaction', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->assertDontSee(__('finance.transactions.hints.czk_manual_kept'));
    });

    it('computes the figure again when the manual switch is turned off on a day with a list', function (): void {
        txScreenRate();
        $transaction = txScreenRecord(['czk_amount' => '2 600,00']);

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['czk_manual' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();

        expect($transaction->czk_source)->toBe(CzkSource::Cnb)
            ->and($transaction->czk_minor)->toBe(251250);
    });

    it('turns a computed transaction into a manual one when the switch is turned on with the bank figure', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['czk_manual' => true, 'czk_amount' => '2 480,00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();

        expect($transaction->czk_source)->toBe(CzkSource::Manual)
            ->and($transaction->czk_minor)->toBe(248000);
    });

    it('asks for the CZK amount when the date moves to a day without any list, and refuses an empty value in Czech', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        $page = Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['transaction_date' => '2026-10-01'])
            ->call('save')
            ->assertHasFormErrors(['czk_amount']);

        expect(implode(' ', $page->errors()->get('data.czk_amount')))->toContain('není uložen kurz ČNB');

        $transaction->refresh();

        expect($transaction->transaction_date->toDateString())->toBe('2026-10-14')
            ->and($transaction->czk_source)->toBe(CzkSource::Cnb);
    });

    it('saves as manual once the CZK amount is typed for a day without any list', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['transaction_date' => '2026-10-01', 'czk_amount' => '2 400,00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();

        expect($transaction->czk_source)->toBe(CzkSource::Manual)
            ->and($transaction->czk_minor)->toBe(240000)
            ->and($transaction->transaction_date->toDateString())->toBe('2026-10-01');
    });

    it('opens and saves a transaction whose category was archived later, without changing the category', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();
        $category = txScreenHosting();

        app(ArchiveTransactionCategory::class)->handle($this->admin, $category, true);

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->assertFormSet(['category_id' => $category->getKey()])
            ->fillForm(['description' => 'Fictional note'])
            ->call('save')
            ->assertHasNoFormErrors();

        $transaction->refresh();

        expect($transaction->category_id)->toBe($category->getKey())
            ->and($transaction->description)->toBe('Fictional note');
    });

    it('does not offer an archived category for a new transaction', function (): void {
        $category = txScreenHosting();

        app(ArchiveTransactionCategory::class)->handle($this->admin, $category, true);

        Livewire::test(CreateTransaction::class)
            ->fillForm(txScreenForm(['currency' => 'CZK', 'category_id' => $category->getKey()]))
            ->call('create')
            ->assertHasFormErrors(['category_id']);
    });
});

describe('a transaction booked at an own rate', function (): void {
    /**
     * An EUR expense of 100,00 booked at the invented own rate 26.000 (2 600,00 CZK) on 2026-10-14.
     *
     * @param  array<string, mixed>  $overrides
     */
    $custom = function (array $overrides = []): Transaction {
        return txScreenRecord(['exchange_rate' => '26.000', ...$overrides]);
    };

    it('marks the CZK figure in the list with its own badge, different from the manual one', function () use ($custom): void {
        txScreenRate();
        $row = $custom();

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$row])
            ->assertSee($row->czk()->format('cs'))
            ->assertSee(__('finance.transactions.badges.custom'))
            ->assertDontSee(__('finance.transactions.badges.manual'));
    });

    it('keeps the manual badge for a manual row and shows no badge for a CNB row', function (): void {
        txScreenRate();
        $manual = txScreenRecord(['czk_amount' => '2 600,00']);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$manual])
            ->assertSee(__('finance.transactions.badges.manual'))
            ->assertDontSee(__('finance.transactions.badges.custom'));

        $manual->delete();
        $cnb = txScreenRecord();

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$cnb])
            ->assertDontSee(__('finance.transactions.badges.manual'))
            ->assertDontSee(__('finance.transactions.badges.custom'));
    });

    it('shows the stored own rate instead of the CNB preview and no CZK field on the edit page', function () use ($custom): void {
        txScreenRate();
        $row = $custom();

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->assertSee(__('finance.transactions.hints.czk_custom_kept', [
                'amount' => $row->amount->format('cs'),
                'czk' => $row->czk()->format('cs'),
                'rate' => '26,000',
                'unit' => '1',
                'currency' => 'EUR',
            ]))
            ->assertDontSee('Kurz ČNB:')
            ->assertFormFieldIsHidden('czk_amount');
    });

    it('keeps the own rate, the figure and the snapshot when only the description changes', function () use ($custom): void {
        txScreenRate();
        $row = $custom();

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->fillForm(['description' => 'Fictional note'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();

        expect($row->description)->toBe('Fictional note')
            ->and($row->czk_source)->toBe(CzkSource::Custom)
            ->and($row->czk_minor)->toBe(260000)
            ->and($row->exchange_rate)->toBe('26.0000000000')
            ->and($row->exchange_rate_date?->toDateString())->toBe('2026-10-14');
    });

    it('shows the CNB preview again and stores cnb when the amount is changed', function () use ($custom): void {
        txScreenRate();
        $row = $custom();

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->fillForm(['amount' => '200,00'])
            ->assertSee('Kurz ČNB')
            ->assertDontSee(__('finance.transactions.hints.czk_custom_kept', [
                'amount' => $row->amount->format('cs'),
                'czk' => $row->czk()->format('cs'),
                'rate' => '26,000',
                'unit' => '1',
                'currency' => 'EUR',
            ]))
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();

        expect($row->czk_source)->toBe(CzkSource::Cnb)
            ->and($row->czk_minor)->toBe(502500)
            ->and($row->exchange_rate)->toBe('25.1250000000');
    });

    it('stores cnb when the date is changed to a day with a list', function () use ($custom): void {
        txScreenRate();
        $row = $custom();

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->fillForm(['transaction_date' => '2026-10-15'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect($row->refresh()->czk_source)->toBe(CzkSource::Cnb)
            ->and($row->czk_minor)->toBe(251250);
    });

    it('opens and saves a description change of a row in a currency without any CNB list, without asking for a CZK amount', function (): void {
        $row = txScreenRecord(['currency' => 'ISK', 'amount' => '1000,00', 'exchange_rate' => '0.18']);

        expect($row->czk_source)->toBe(CzkSource::Custom);

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->assertSee('ISK')
            ->assertSee(__('finance.transactions.hints.czk_custom_kept', [
                'amount' => $row->amount->format('cs'),
                'czk' => $row->czk()->format('cs'),
                'rate' => '0,180',
                'unit' => '1',
                'currency' => 'ISK',
            ]))
            ->assertFormFieldIsHidden('czk_amount')
            ->fillForm(['description' => 'Fictional note'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();

        expect($row->description)->toBe('Fictional note')
            ->and($row->czk_source)->toBe(CzkSource::Custom)
            ->and($row->czk_minor)->toBe($row->czk()->minor)
            ->and($row->exchange_rate)->toBe('0.1800000000');
    });

    it('asks for the CZK amount when the amount of a row in a currency without a list is changed', function (): void {
        $row = txScreenRecord(['currency' => 'ISK', 'amount' => '1000,00', 'exchange_rate' => '0.18']);

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->fillForm(['amount' => '2000,00'])
            ->call('save')
            ->assertHasFormErrors(['czk_amount']);

        expect($row->refresh()->czk_source)->toBe(CzkSource::Custom);
    });

    it('starts the manual figure from the stored one when the manual switch is turned on', function () use ($custom): void {
        txScreenRate();
        $row = $custom();

        Livewire::test(EditTransaction::class, ['record' => $row->getKey()])
            ->fillForm(['czk_manual' => true])
            ->assertFormSet(['czk_amount' => '2600,00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $row->refresh();

        expect($row->czk_source)->toBe(CzkSource::Manual)
            ->and($row->czk_minor)->toBe(260000)
            ->and($row->exchange_rate)->toBeNull();
    });
});

describe('deleting a transaction', function (): void {
    it('asks for a confirmation and deletes through the Action only after it', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        $page = Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->mountAction(DeleteAction::class)
            ->assertActionMounted(DeleteAction::class);

        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeTrue();

        $page->callMountedAction()->assertRedirect(TransactionResource::getUrl('index'));

        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeFalse();
    });

    it('does not offer the delete action to a Partner, who cannot reach the page at all', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])->assertForbidden();

        $this->actingAs($this->admin);
        expect(Transaction::query()->whereKey($transaction->getKey())->exists())->toBeTrue();
    });
});

describe('the activity trail', function (): void {
    it('writes one row for the creation with the allowlisted attributes and never the description', function (): void {
        txScreenRate();
        $form = txScreenForm(['description' => 'Fictional secret note', 'document_number' => 'INV-1']);

        Livewire::test(CreateTransaction::class)->fillForm($form)->call('create')->assertHasNoFormErrors();

        $transaction = Transaction::query()->where('counterparty', $form['counterparty'])->sole();
        $rows = Activity::query()->forSubject($transaction)->forEvent('created')->get();
        $attributes = $rows->first()?->attribute_changes->get('attributes') ?? [];

        expect($rows)->toHaveCount(1)
            ->and($attributes)->toHaveKeys(['type', 'transaction_date', 'category_id', 'amount_minor', 'amount_currency', 'czk_minor', 'czk_source', 'exchange_rate', 'counterparty', 'document_number'])
            ->and($attributes)->not->toHaveKey('description')
            ->and($attributes['amount_minor'])->toBe(10000)
            ->and($attributes['czk_source'])->toBe('cnb')
            ->and(json_encode($rows->first()?->attribute_changes))->not->toContain('Fictional secret note');
    });

    it('writes one row for an edit that lists only the changed allowlisted attributes', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['counterparty' => 'Example Hosting Ltd', 'description' => 'Fictional new note'])
            ->call('save')
            ->assertHasNoFormErrors();

        $rows = Activity::query()->forSubject($transaction)->forEvent('updated')->get();

        expect($rows)->toHaveCount(1)
            ->and($rows->first()?->attribute_changes->get('attributes'))->toBe(['counterparty' => 'Example Hosting Ltd'])
            ->and(json_encode($rows->first()?->attribute_changes))->not->toContain('Fictional new note');
    });

    it('writes no row for a change of the description alone', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['description' => 'Only a note'])
            ->call('save')
            ->assertHasNoFormErrors();

        expect(Activity::query()->forSubject($transaction)->forEvent('updated')->count())->toBe(0);
    });

    it('writes one row for the deletion', function (): void {
        txScreenRate();
        $transaction = txScreenRecord(['description' => 'Fictional deleted note']);

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->callAction(DeleteAction::class);

        $rows = Activity::query()->forSubject($transaction)->forEvent('deleted')->get();

        expect($rows)->toHaveCount(1)
            ->and(json_encode($rows->first()?->attribute_changes))->not->toContain('Fictional deleted note');
    });

    it('lists the rows in the history tab with Czech attribute labels', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $transaction->getKey()])
            ->fillForm(['counterparty' => 'Example Hosting Ltd'])
            ->call('save');

        $activities = $transaction->activitiesAsSubject()->get();

        expect($activities)->toHaveCount(2)
            ->and(TransactionResource::getRelations())->toContain(TransactionHistoryRelationManager::class)
            ->and(TransactionHistoryRelationManager::canViewForRecord($transaction, EditTransaction::class))->toBeTrue();

        Livewire::test(TransactionHistoryRelationManager::class, ['ownerRecord' => $transaction, 'pageClass' => EditTransaction::class])
            ->assertSuccessful()
            ->assertCanSeeTableRecords($activities)
            ->assertSee('Protistrana')
            ->assertSee('Částka (v nejmenších jednotkách měny)');
    });

    it('hides the history tab from a Partner', function (): void {
        txScreenRate();
        $transaction = txScreenRecord();

        $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

        expect(TransactionHistoryRelationManager::canViewForRecord($transaction, EditTransaction::class))->toBeFalse();
    });
});

/**
 * An issued CZK invoice of a new client paid in full through RecordPayment, which books the income (FI-03).
 *
 * @return array{0: Invoice, 1: Transaction}
 */
function txScreenPaymentIncome(string $currency = 'CZK'): array
{
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();

    $admin = auth()->user();
    assert($admin instanceof User);

    $foreign = $currency !== 'CZK';

    if ($foreign) {
        // The CNB lists the income is booked and re-dated against (the invoice is issued on the travelled-to today).
        InvoiceFixtures::cnbRate($currency, '2026-10-14', '25.000');
        InvoiceFixtures::cnbRate($currency, '2026-10-10', '24.500');
        InvoiceFixtures::cnbRate($currency, '2026-10-12', '24.000');
    }

    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['currency' => $currency]), [InvoiceFixtures::item('Example work', '1', $foreign ? '100.00' : '12500.00')]);
    $payment = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => $foreign ? '100,00' : '12 500,00', 'paid_on' => '2026-10-10']);

    return [$invoice, Transaction::query()->where('invoice_payment_id', $payment->id)->sole()];
}

describe('the invoice reference on the list (FI-03, Surface M)', function (): void {
    it('shows the invoice number as a link to the invoice view for a payment income and a dash for the others', function (): void {
        [$invoice, $income] = txScreenPaymentIncome();
        $ordinary = txScreenRecord(['currency' => 'CZK', 'amount' => '250,00']);

        Livewire::test(ListTransactions::class)
            ->assertCanSeeTableRecords([$income, $ordinary])
            ->assertTableColumnExists('invoice.number')
            ->assertTableColumnStateSet('invoice.number', (string) $invoice->number, $income)
            ->assertTableColumnStateSet('invoice.number', null, $ordinary)
            ->assertSee(InvoiceResource::getUrl('view', ['record' => $invoice->getKey()]));

        expect($income->description)->toBe('Platba faktury '.$invoice->number);
    });

    it('narrows the rows with the Faktura filter in both directions', function (): void {
        [, $income] = txScreenPaymentIncome();
        $ordinary = txScreenRecord(['currency' => 'CZK', 'amount' => '250,00']);

        Livewire::test(ListTransactions::class)
            ->filterTable('with_invoice', true)
            ->assertCanSeeTableRecords([$income])
            ->assertCanNotSeeTableRecords([$ordinary])
            ->filterTable('with_invoice', false)
            ->assertCanSeeTableRecords([$ordinary])
            ->assertCanNotSeeTableRecords([$income]);
    });
});

describe('the edit page locks the income of an invoice payment (FI-03, D-19 as amended, R-19-1)', function (): void {
    beforeEach(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    });

    it('disables every field but the date and the category and shows the helper with the invoice number', function (): void {
        [$invoice, $income] = txScreenPaymentIncome('EUR');

        $page = Livewire::test(EditTransaction::class, ['record' => $income->getKey()]);

        foreach (['type', 'amount', 'currency', 'client_id', 'project_id', 'description', 'counterparty', 'document_number'] as $field) {
            $page->assertFormFieldIsDisabled($field);
        }

        $page->assertFormFieldIsEnabled('transaction_date')
            ->assertFormFieldIsEnabled('category_id')
            ->assertFormFieldIsHidden('czk_manual')
            ->assertSee(__('invoices.transactions.helper', ['number' => $invoice->number]))
            ->assertSee('Změnit lze jen datum a kategorii; chybnou platbu smažte v detailu faktury.');
    });

    it('leaves an ordinary transaction fully editable and without the helper', function (): void {
        txScreenRate();
        $ordinary = txScreenRecord();

        Livewire::test(EditTransaction::class, ['record' => $ordinary->getKey()])
            ->assertFormFieldIsEnabled('amount')
            ->assertFormFieldIsEnabled('description')
            ->assertDontSee('Transakce vznikla z platby faktury');
    });

    it('saves a changed date with the CZK figure following the CNB list of the new day, and another income category', function (): void {
        [, $income] = txScreenPaymentIncome('EUR');
        $other = TransactionCategory::query()->where('type', 'income')->whereKeyNot($income->category_id)->whereNull('parent_id')->orderBy('id')->firstOrFail();

        expect($income->czk_minor)->toBe(245000);

        Livewire::test(EditTransaction::class, ['record' => $income->getKey()])
            ->fillForm(['transaction_date' => '2026-10-12', 'category_id' => $other->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $income->refresh();

        expect($income->transaction_date->toDateString())->toBe('2026-10-12')
            ->and($income->czk_minor)->toBe(240000)
            ->and($income->category_id)->toBe($other->getKey())
            ->and($income->amount_minor)->toBe(10000)
            ->and($income->invoice_payment_id)->not->toBeNull();
    });

    it('refuses a forged amount or description in the form state with the field error and changes nothing', function (): void {
        [$invoice, $income] = txScreenPaymentIncome('EUR');
        $before = (array) DB::table('transactions')->where('id', $income->getKey())->first();
        $message = __('invoices.errors.payment_transaction_locked', ['number' => $invoice->number]);

        Livewire::test(EditTransaction::class, ['record' => $income->getKey()])
            ->set('data.amount', '999,00')
            ->set('data.description', 'Example edited text')
            ->call('save')
            ->assertHasFormErrors(['amount' => $message, 'description' => $message]);

        expect((array) DB::table('transactions')->where('id', $income->getKey())->first())->toBe($before);
    });
});

describe('deleting a payment income on the edit page', function (): void {
    beforeEach(function (): void {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
    });

    it('shows the refusal of the Action as a danger notification with the action to the invoice and keeps the row', function (): void {
        [$invoice, $income] = txScreenPaymentIncome();
        $message = __('invoices.errors.payment_transaction_delete', ['number' => $invoice->number]);

        Livewire::test(EditTransaction::class, ['record' => $income->getKey()])
            ->mountAction(DeleteAction::class)
            ->callMountedAction()
            ->assertNoRedirect()
            ->assertNotified(
                Notification::make()
                    ->danger()
                    ->title($message)
                    ->actions([
                        Action::make('open_invoice')
                            ->label('Otevřít fakturu')
                            ->url(InvoiceResource::getUrl('view', ['record' => $invoice->getKey()]))
                            ->button(),
                    ]),
            );

        expect(Transaction::query()->whereKey($income->getKey())->exists())->toBeTrue();
    });
});
