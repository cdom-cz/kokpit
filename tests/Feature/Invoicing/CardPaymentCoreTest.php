<?php

declare(strict_types=1);

use App\Domain\Finance\Enums\CzkSource;
use App\Domain\Finance\Enums\TransactionType;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Identity\Models\User;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\PaymentMethod;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Support\PaymentFacts;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Money\Money;
use App\Filament\Resources\InvoiceResource\Pages\ViewInvoice;
use App\Filament\Resources\InvoiceResource\RelationManagers\InvoicePaymentsRelationManager;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The system-callable write core of a payment (PA-06, PA-08; D-10, D-13, D-14): a card payment of an issued invoice
 * arrives from a queue job with no user and is booked exactly like a manual one, income included. Every name and
 * amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Records the payment the way a queue job does: no user, the system context, one transaction, the document locked.
 */
function recordAsJob(Invoice $invoice, PaymentFacts $facts, ?User $actor = null): InvoicePayment
{
    // A queue job has no session: whoever the fixtures logged in is gone.
    Auth::forgetGuards();

    return app(PartnerContext::class)->runAsSystem(static fn (): InvoicePayment => DB::transaction(static function () use ($invoice, $facts, $actor): InvoicePayment {
        $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

        return app(RecordPayment::class)->recordLocked($locked, $facts, $actor);
    }));
}

function cardFacts(string $amountMajor = '12500.00', string $currency = 'CZK', PaymentMethod $method = PaymentMethod::Card, string $day = '2026-10-14'): PaymentFacts
{
    return new PaymentFacts(Money::fromMajor($amountMajor, $currency), $day, $method, $day, null, null);
}

/**
 * The field errors a callback raises as a ValidationException, or null when it raises none.
 *
 * @return array<string, list<string>>|null
 */
function cardCoreErrors(Closure $callback): ?array
{
    try {
        $callback();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return null;
}

it('lets a job without a user record a card payment of an issued invoice and books the income once', function (): void {
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $before = DB::table('invoices')->where('id', $invoice->id)->first();

    $payment = recordAsJob($invoice, cardFacts());
    $this->actingAs(Canary::admin());

    $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();
    $row = DB::table('invoice_payments')->where('id', $payment->id)->first();

    expect(InvoicePayment::query()->count())->toBe(1)
        ->and($row->method)->toBe('card')
        ->and($row->created_by)->toBeNull()
        ->and($payment->amount_minor)->toBe(1250000)
        ->and(Transaction::query()->count())->toBe(1)
        ->and($income->type)->toBe(TransactionType::Income)
        ->and($income->category_id)->toBe(TransactionCategory::invoicePayments()->id)
        ->and($income->transaction_date->toDateString())->toBe('2026-10-14')
        ->and($income->description)->toBe('Platba kartou faktury '.$invoice->number)
        ->and($income->invoice_id)->toBe($invoice->id)
        ->and($income->invoice_payment_id)->toBe($payment->id)
        ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(0)
        ->and(DB::table('invoices')->where('id', $invoice->id)->first())->toEqual($before);

    $created = Activity::query()->where('subject_type', 'invoice_payment')->where('subject_id', $payment->id)->where('event', 'created')->sole();
    expect($created->causer_id)->toBeNull();
});

it('stores a card payment of a proforma as a payment only and books no income', function (): void {
    $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')], InvoiceKind::Proforma);

    $payment = recordAsJob($proforma, cardFacts());
    $this->actingAs(Canary::admin());

    expect($payment->method)->toBe(PaymentMethod::Card)
        ->and(InvoicePayment::query()->count())->toBe(1)
        ->and(Transaction::query()->count())->toBe(0);
});

it('books the CZK figure of the CNB list for a card payment in a foreign currency', function (): void {
    InvoiceFixtures::cnbRate('EUR', '2026-10-09', '25.125');
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['currency' => 'EUR']), [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    $payment = recordAsJob($invoice, cardFacts('12500.00', 'EUR'));
    $this->actingAs(Canary::admin());
    $income = Transaction::query()->where('invoice_payment_id', $payment->id)->sole();

    expect($income->amount_currency)->toBe('EUR')
        ->and($income->czk_source)->toBe(CzkSource::Cnb)
        ->and($income->czk_minor)->toBe((int) round(1250000 * 25.125))
        ->and($income->description)->toBe('Platba kartou faktury '.$invoice->number);
});

it('fails a foreign-currency card payment under its date when no list exists and stores nothing', function (): void {
    InvoiceFixtures::cnbRate('EUR', '2026-10-09', '25.125');
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(['currency' => 'EUR']), [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    $errors = cardCoreErrors(fn () => recordAsJob($invoice, cardFacts('100.00', 'EUR', day: '2026-10-05')));
    $this->actingAs(Canary::admin());

    expect($errors)->toBe(['paid_on' => [__('invoices.errors.cnb_missing', ['currency' => 'EUR', 'date' => '5. 10. 2026'])]])
        ->and(InvoicePayment::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses a draft, a credit note and a cancelled invoice with payment_not_allowed and stores nothing', function (): void {
    $client = InvoiceFixtures::client();
    $items = [InvoiceFixtures::item('Example work', '1', '12500.00')];
    $draft = InvoiceFixtures::draft($client, $items);
    $cancelled = InvoiceFixtures::issued($client, $items);
    $creditNote = InvoiceFixtures::stornoed($cancelled);
    $expected = ['payment' => [__('invoices.errors.payment_not_allowed')]];

    $errors = [
        'draft' => cardCoreErrors(fn () => recordAsJob($draft, cardFacts())),
        'credit note' => cardCoreErrors(fn () => recordAsJob($creditNote, cardFacts())),
        'cancelled' => cardCoreErrors(fn () => recordAsJob($cancelled, cardFacts())),
    ];
    $this->actingAs(Canary::admin());

    expect($errors)->toBe(['draft' => $expected, 'credit note' => $expected, 'cancelled' => $expected])
        ->and(InvoicePayment::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('refuses a card payment of a converted proforma with payment_on_converted_proforma and stores nothing', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')], InvoiceKind::Proforma);
    app(RecordPayment::class)->handle($admin, $proforma, ['amount' => '12500.00', 'paid_on' => '2026-10-14']);
    $invoice = app(ConvertProforma::class)->handle($admin, $proforma);
    $payments = InvoicePayment::query()->count();
    $transactions = Transaction::query()->count();

    $errors = cardCoreErrors(fn () => recordAsJob($proforma, cardFacts('100.00')));
    $this->actingAs(Canary::admin());

    expect($errors)->toBe(['payment' => [__('invoices.errors.payment_on_converted_proforma', ['number' => (string) $invoice->number])]])
        ->and(InvoicePayment::query()->count())->toBe($payments)
        ->and(Transaction::query()->count())->toBe($transactions);
});

it('throws a LogicException outside a transaction', function (): void {
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    // The test wraps itself in a transaction; take it back so the core sees the state of a job that forgot its own.
    DB::rollBack(0);

    expect(DB::transactionLevel())->toBe(0)
        ->and(fn () => app(RecordPayment::class)->recordLocked($invoice, cardFacts(), null))
        ->toThrow(LogicException::class, 'inside the caller');
});

it('throws a LogicException for a null actor with any method other than card', function (): void {
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);

    foreach (PaymentMethod::manual() as $method) {
        expect(fn () => recordAsJob($invoice, cardFacts(method: $method)))
            ->toThrow(LogicException::class, 'Only a card payment');
    }

    $this->actingAs(Canary::admin());

    expect(InvoicePayment::query()->count())->toBe(0);
});

it('refuses the method card on the manual path under method and offers it nowhere by hand', function (): void {
    $admin = Canary::admin();
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $this->actingAs($admin);

    foreach (['card', PaymentMethod::Card] as $card) {
        $errors = cardCoreErrors(fn () => app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '100', 'paid_on' => '2026-10-13', 'method' => $card]));

        expect($errors)->toBe(['method' => [__('invoices.errors.payment_method')]]);
    }

    expect(InvoicePayment::query()->count())->toBe(0)
        ->and(Transaction::query()->count())->toBe(0);
});

it('keeps the Gate on the manual path: a Partner is refused', function (): void {
    $client = InvoiceFixtures::client();
    $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $partner = Canary::partnerFor($client->id);
    $this->actingAs($partner);

    expect(fn () => app(RecordPayment::class)->handle($partner, $invoice, ['amount' => '100', 'paid_on' => '2026-10-13']))
        ->toThrow(AuthorizationException::class);
});

it('holds the creator rule in the database: a transfer or cash payment needs a creator, a card payment does not', function (): void {
    $admin = Canary::admin();
    $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Example work', '1', '12500.00')]);
    $row = static fn (string $method, ?string $creator): array => [
        'invoice_id' => $invoice->id,
        'currency' => 'CZK',
        'amount_minor' => 100,
        'paid_on' => '2026-10-13',
        'method' => $method,
        'created_by' => $creator,
    ];

    foreach (['transfer', 'cash'] as $method) {
        RawSql::expectSqlState('23514', fn () => DB::table('invoice_payments')->insert($row($method, null)));
    }

    RawSql::expectSqlState('23514', fn () => DB::table('invoice_payments')->insert($row('bitcoin', $admin->id)));
    RawSql::expectAllowed(fn () => DB::table('invoice_payments')->insert($row('card', $admin->id)));
    RawSql::expectAllowed(fn () => DB::table('invoice_payments')->insert($row('card', null)));
    RawSql::expectAllowed(fn () => DB::table('invoice_payments')->insert($row('transfer', $admin->id)));

    expect(DB::table('invoice_payments')->count())->toBe(3);
});

it('books exactly one income per payment on an invoice and none on a proforma, for every payment method', function (): void {
    $admin = Canary::admin();
    $client = InvoiceFixtures::client();
    $items = [InvoiceFixtures::item('Example work', '1', '12500.00')];

    foreach ([InvoiceKind::Invoice, InvoiceKind::Proforma] as $kind) {
        foreach (PaymentMethod::cases() as $method) {
            $document = InvoiceFixtures::issued($client, $items, $kind);
            $actor = $method->isManual() ? $admin : null;

            $payment = recordAsJob($document, cardFacts(method: $method), $actor);
            $this->actingAs($admin);

            $incomes = Transaction::query()->where('invoice_id', $document->id)->get();

            expect($incomes)->toHaveCount($kind === InvoiceKind::Invoice ? 1 : 0, $kind->value.' / '.$method->value)
                ->and($payment->method)->toBe($method);

            if ($kind === InvoiceKind::Invoice) {
                expect($incomes->sole()->invoice_payment_id)->toBe($payment->id);
            }
        }
    }
});

/**
 * The Platby tab of an issued document.
 */
function cardPaymentTab(Invoice $invoice): Testable
{
    return Livewire::test(InvoicePaymentsRelationManager::class, ['ownerRecord' => $invoice, 'pageClass' => ViewInvoice::class]);
}

describe('the Platby tab and the payment modal', function (): void {
    it('offers exactly Převodem and Hotově in the payment modal', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $this->actingAs(Canary::admin());

        Livewire::test(ViewInvoice::class, ['record' => $invoice->getRouteKey()])
            ->mountAction('recordPayment')
            ->assertSchemaComponentExists('method', 'mountedActionSchema0', fn ($component): bool => $component->getOptions() === [
                'transfer' => 'Převodem',
                'cash' => 'Hotově',
            ]);
    });

    it('lists a card payment with the method Kartou and the system income category', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $payment = recordAsJob($invoice, cardFacts('1000.00'));
        $this->actingAs(Canary::admin());

        cardPaymentTab($invoice)
            ->assertCanSeeTableRecords([$payment])
            ->assertSee('Kartou')
            ->assertSee(TransactionCategory::invoicePayments()->name);
    });

    it('warns on deleting a card payment that the money is not returned, and keeps the Phase 10 body for a transfer', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $card = recordAsJob($invoice, cardFacts('400.00'));
        $admin = Canary::admin();
        $this->actingAs($admin);
        $transfer = app(RecordPayment::class)->handle($admin, $invoice, ['amount' => '100.00', 'paid_on' => '2026-10-14']);
        $amount = static fn (int $minor): string => Money::ofMinor($minor, 'CZK')->format('cs');

        $tab = cardPaymentTab($invoice)
            ->mountAction(TestAction::make('deletePayment')->table($card))
            ->assertMountedActionModalSee([
                'Smazat platbu?',
                'Platba '.$amount(40000).' ze dne 14. 10. 2026 přišla kartou přes Stripe.',
                'Smaže se z dokladu spolu s příjmem, ale peníze se tím nevrací; vrácení proveďte ve Stripe.',
                'Opakované doručení stejné události platbu nevytvoří znovu.',
                'Tento krok nelze vrátit zpět.',
                'Ponechat platbu',
            ]);

        $tab->unmountAction();
        cardPaymentTab($invoice)
            ->mountAction(TestAction::make('deletePayment')->table($transfer))
            ->assertMountedActionModalSee(['se trvale smaže spolu s příjmem, který z ní vznikl ve financích.'])
            ->assertMountedActionModalDontSee('přišla kartou přes Stripe');
    });

    it('deletes the card payment and its income through DeletePayment on confirmation', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $card = recordAsJob($invoice, cardFacts('1000.00'));
        $this->actingAs(Canary::admin());

        expect(Transaction::query()->where('invoice_payment_id', $card->id)->exists())->toBeTrue();

        cardPaymentTab($invoice)
            ->mountAction(TestAction::make('deletePayment')->table($card))
            ->callMountedAction()
            ->assertHasNoActionErrors()
            ->assertNotified('Platba byla smazána');

        expect(InvoicePayment::query()->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });
});
