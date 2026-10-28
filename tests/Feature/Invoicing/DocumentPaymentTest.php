<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Support\IssueChecks;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The payment method and the bank account of a draft, from its creation to the issue (P14-01, P14-04, P14-09; D-01,
 * D-02, D-03, D-05, D-06, OA-1, OA-7). Every name, account and amount is fictional; the IBANs are composed at runtime.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * The Admin-only row of a draft or a document as the raw database holds it.
 */
function dpAdminRow(Invoice $invoice): stdClass
{
    return DB::table('invoice_admin')->where('invoice_id', $invoice->id)->first() ?? throw new LogicException('No Admin row.');
}

/**
 * A new empty draft of the client created as the signed-in Admin.
 *
 * @param  array<string, mixed>  $extra
 */
function dpCreate(Client $client, array $extra = []): Invoice
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    return app(CreateDraft::class)->handle($admin, ['client_id' => $client->id, 'kind' => InvoiceKind::Invoice->value, ...$extra]);
}

/**
 * Saves the draft as the signed-in Admin.
 *
 * @param  array<string, mixed>  $data
 */
function dpSave(Invoice $draft, array $data): Invoice
{
    $admin = Canary::admin();
    test()->actingAs($admin);

    return app(UpdateDraft::class)->handle($admin, $draft, $data);
}

describe('the tracer: a draft becomes an online invoice (D-01, D-02, D-03, D-05)', function (): void {
    it('is created as transfer with the first CZK account, switched to online and issued without bank details but with a pay link', function (): void {
        Queue::fake();
        CardPaymentSetup::enable();
        [$czkA] = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example CZK B', 'CZK', '22'),
            InvoiceFixtures::account('Example EUR A', 'EUR', '33'),
        );

        $client = CardPaymentSetup::client();
        $draft = dpCreate($client);
        $draft = dpSave($draft, ['items' => [InvoiceFixtures::item('Example work', '1', '12500.00')]]);

        expect($draft->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and(dpAdminRow($draft)->bank_account_id)->toBe($czkA->id);

        $draft = InvoiceFixtures::online($draft);

        expect($draft->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and(dpAdminRow($draft)->card_payment_enabled)->toBeTrue();

        $admin = Canary::admin();
        $issued = app(IssueDocument::class)->handle($admin, $draft);
        $row = dpAdminRow($issued);
        $link = app(PartnerContext::class)->runAsSystem(static fn (): PaymentLink => PaymentLink::query()->where('invoice_id', $issued->id)->firstOrFail());

        expect($issued->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and($issued->bank_snapshot)->toBeNull()
            ->and($row->card_payment_enabled)->toBeTrue()
            ->and(PayToken::isWellFormed((string) $row->pay_token))->toBeTrue()
            ->and($link->state)->toBe(PaymentLinkState::Pending)
            ->and($link->currency)->toBe('CZK')
            ->and($link->amount_minor)->toBe($issued->total_minor);

        Queue::assertPushed(CreatePaymentLink::class, 1);
        Queue::assertPushed(CreatePaymentLink::class, static fn (CreatePaymentLink $job): bool => $job->linkId === $link->id);
    });

    it('issues a transfer draft with the snapshot of its preselected account and no id in it', function (): void {
        [$czkA] = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example CZK B', 'CZK', '22'),
        );

        $draft = dpSave(dpCreate(InvoiceFixtures::client()), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]);
        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        expect($issued->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and($issued->bank_snapshot)->toMatchArray(['label' => 'Example CZK A', 'currency' => 'CZK', 'iban' => $czkA->iban])
            ->and($issued->bank_snapshot)->not->toHaveKey('id')
            ->and(dpAdminRow($issued)->bank_account_id)->toBe($czkA->id);
    });
});

describe('cash as the third method of a draft (Phase 15; D-01, D-03, D-04, D-05)', function (): void {
    beforeEach(function (): void {
        CardPaymentSetup::enable();
        InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
        );
        InvoiceFixtures::cnbRate('EUR', '2026-10-13', '25.1250000000');
    });

    it('keeps transfer as the default method of a new draft', function (): void {
        $draft = dpCreate(CardPaymentSetup::client());

        expect($draft->payment_method)->toBe(DocumentPaymentMethod::Transfer);
    });

    it('accepts cash and keeps the card switch off, even for a client with online payment on', function (): void {
        $draft = dpSave(dpCreate(CardPaymentSetup::client()), ['payment_method' => 'cash']);

        expect($draft->payment_method)->toBe(DocumentPaymentMethod::Cash)
            ->and(dpAdminRow($draft)->card_payment_enabled)->toBeFalse();
    });

    it('turns the card switch off when a transfer draft with the switch on becomes cash', function (): void {
        $draft = dpSave(dpCreate(CardPaymentSetup::client()), ['card_payment_enabled' => true]);

        expect(dpAdminRow($draft)->card_payment_enabled)->toBeTrue();

        $draft = dpSave($draft, ['payment_method' => 'cash']);

        expect(dpAdminRow($draft)->card_payment_enabled)->toBeFalse();
    });

    it('refuses an explicit request to switch card payment on for a cash draft and writes nothing', function (): void {
        $draft = dpSave(dpCreate(CardPaymentSetup::client()), ['payment_method' => 'cash']);

        $errors = dpRefused(fn () => dpSave($draft, ['card_payment_enabled' => true]));

        expect($errors)->toHaveKey('card_payment_enabled')
            ->and($errors['card_payment_enabled'][0])->toBe('Doklad placený hotově nelze platit kartou.')
            ->and(dpAdminRow($draft)->card_payment_enabled)->toBeFalse();

        $errors = dpRefused(fn () => dpSave($draft, ['payment_method' => 'cash', 'card_payment_enabled' => true]));

        expect($errors)->toHaveKey('card_payment_enabled');
    });

    it('accepts an explicit switch off for a cash draft', function (): void {
        $draft = dpSave(dpCreate(CardPaymentSetup::client()), ['payment_method' => 'cash', 'card_payment_enabled' => false]);

        expect($draft->payment_method)->toBe(DocumentPaymentMethod::Cash)
            ->and(dpAdminRow($draft)->card_payment_enabled)->toBeFalse();
    });

    it('needs no bank account for a cash draft whatever its currency', function (): void {
        $draft = dpSave(dpCreate(InvoiceFixtures::client(['currency' => 'EUR'])), [
            'items' => [InvoiceFixtures::item('Example work', '1', '100.00')],
        ]);

        expect(dpAdminRow($draft)->bank_account_id)->toBeNull()
            ->and(dpBlockerKeys($draft))->toContain('bank_missing');

        $draft = dpSave($draft, ['payment_method' => 'cash']);

        expect(dpBlockerKeys($draft))->not->toContain('bank_missing');
    });
});

/**
 * The keys of the blockers of the draft today.
 *
 * @return list<string>
 */
function dpBlockerKeys(Invoice $draft): array
{
    return array_column(app(IssueChecks::class)->blockers($draft->fresh(), '2026-10-14'), 'key');
}

/**
 * The refusal keys and messages of a call that must fail validation.
 *
 * @return array<string, list<string>>
 */
function dpRefused(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $e) {
        return $e->errors();
    }

    test()->fail('The call was accepted although it must be refused with a field error.');
}

describe('the chosen account (D-05, D-06)', function (): void {
    beforeEach(function (): void {
        $this->accounts = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example CZK B', 'CZK', '22'),
            InvoiceFixtures::account('Example EUR A', 'EUR', '33'),
        );
        InvoiceFixtures::cnbRate('EUR', '2026-10-13', '25.1250000000');
    });

    it('preselects the first account of the currency in list order and issues the account the Admin picked instead', function (): void {
        $draft = dpSave(dpCreate(InvoiceFixtures::client()), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]);

        expect(dpAdminRow($draft)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example CZK A'));

        $draft = dpSave($draft, ['bank_account_id' => InvoiceFixtures::accountId('Example CZK B')]);
        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        expect($issued->bank_snapshot)->toEqual($this->accounts[1]->toArray())
            ->and($issued->bank_snapshot['label'])->toBe('Example CZK B')
            ->and($issued->bank_snapshot)->not->toHaveKey('id')
            ->and(dpAdminRow($issued)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example CZK B'));
    });

    it('preselects again on a change of currency, and keeps an explicit account sent with it', function (): void {
        $draft = dpCreate(InvoiceFixtures::client());

        $draft = dpSave($draft, ['currency' => 'EUR']);
        expect(dpAdminRow($draft)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example EUR A'));

        $draft = dpSave($draft, ['currency' => 'CZK']);
        expect(dpAdminRow($draft)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example CZK A'));

        $draft = dpSave($draft, ['currency' => 'EUR', 'bank_account_id' => InvoiceFixtures::accountId('Example CZK B')]);
        expect(dpAdminRow($draft)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example CZK B'));
    });

    it('keeps the stored account on a save that changes neither the currency nor the account', function (): void {
        $draft = dpSave(dpCreate(InvoiceFixtures::client()), ['bank_account_id' => InvoiceFixtures::accountId('Example CZK B')]);
        $draft = dpSave($draft, ['note' => 'Example note']);

        expect(dpAdminRow($draft)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example CZK B'));
    });

    it('issues a EUR draft with an account in another currency and no blocker or warning', function (): void {
        $client = InvoiceFixtures::client(['currency' => 'EUR']);
        $draft = dpSave(dpCreate($client), ['items' => [InvoiceFixtures::item('Example work', '1', '100.00')]]);

        expect(dpAdminRow($draft)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example EUR A'));

        $draft = dpSave($draft, ['bank_account_id' => InvoiceFixtures::accountId('Example CZK A')]);

        expect(dpBlockerKeys($draft))->toBe([]);

        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        expect($issued->currency)->toBe('EUR')
            ->and($issued->bank_snapshot)->toMatchArray(['label' => 'Example CZK A', 'currency' => 'CZK']);
    });

    it('stores no account for a currency without one, blocks the transfer, and lets the online document through without a snapshot', function (): void {
        CardPaymentSetup::enable();
        InvoiceFixtures::cnbRate('USD', '2026-10-13', '23.0000000000');
        $client = CardPaymentSetup::client(['currency' => 'USD']);
        $draft = dpSave(dpCreate($client), ['items' => [InvoiceFixtures::item('Example work', '1', '100.00')]]);

        expect(dpAdminRow($draft)->bank_account_id)->toBeNull()
            ->and(array_column(app(IssueChecks::class)->blockers($draft->fresh(), '2026-10-14'), 'message', 'key')['bank_missing'])
            ->toBe('Doklad s úhradou převodem nemá vybraný bankovní účet. Vyberte ho na dokladu, nebo ho přidejte v Nastavení.');

        $draft = InvoiceFixtures::online($draft);

        expect(dpBlockerKeys($draft))->not->toContain('bank_missing');

        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        expect($issued->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and($issued->bank_snapshot)->toBeNull();
    });

    it('blocks a draft whose chosen account was removed from the settings, although another account of the currency exists', function (): void {
        $draft = dpSave(dpCreate(InvoiceFixtures::client()), [
            'items' => [InvoiceFixtures::item('Example work', '1', '800.00')],
            'bank_account_id' => InvoiceFixtures::accountId('Example CZK B'),
        ]);

        InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example EUR A', 'EUR', '33'),
        );

        expect(dpBlockerKeys($draft))->toBe(['bank_missing']);
        expect(fn () => app(IssueDocument::class)->handle(Canary::admin(), $draft))->toThrow(ValidationException::class);
        expect($draft->fresh()->isDraft())->toBeTrue();
    });

    it('issues a legacy draft with no stored account using the first account of its currency, and records it', function (): void {
        $draft = dpSave(dpCreate(InvoiceFixtures::client()), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]);
        DB::table('invoice_admin')->where('invoice_id', $draft->id)->update(['bank_account_id' => null]);

        expect(dpBlockerKeys($draft))->toBe([]);

        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        expect($issued->bank_snapshot)->toMatchArray(['label' => 'Example CZK A'])
            ->and(dpAdminRow($issued)->bank_account_id)->toBe(InvoiceFixtures::accountId('Example CZK A'));
    });

    it('refuses an account id that is no configured account and writes nothing', function (mixed $value): void {
        $draft = dpSave(dpCreate(InvoiceFixtures::client()), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]);
        $before = [dpAdminRow($draft), DB::table('invoices')->where('id', $draft->id)->first()];

        $errors = dpRefused(fn () => dpSave($draft, ['bank_account_id' => $value, 'note' => 'Example note']));

        expect($errors)->toHaveKey('bank_account_id')
            ->and($errors['bank_account_id'][0])->toBe('Vybraný bankovní účet v Nastavení neexistuje.')
            ->and([dpAdminRow($draft), DB::table('invoices')->where('id', $draft->id)->first()])->toEqual($before);
    })->with([
        'a random uuid' => fn () => (string) Str::uuid(),
        'not a uuid' => 'not-a-uuid',
        'a number' => 5,
        'a list' => [['id' => 'x']],
    ]);

    it('takes a null or blank account id as no account', function (): void {
        $draft = dpCreate(InvoiceFixtures::client());

        expect(dpAdminRow($draft)->bank_account_id)->not->toBeNull();

        $draft = dpSave($draft, ['bank_account_id' => null]);
        expect(dpAdminRow($draft)->bank_account_id)->toBeNull();

        $draft = dpSave($draft, ['bank_account_id' => InvoiceFixtures::accountId('Example CZK B')]);
        $draft = dpSave($draft, ['bank_account_id' => '  ']);
        expect(dpAdminRow($draft)->bank_account_id)->toBeNull();
    });
});

/**
 * Switches the online-payment flag of a client, as system.
 */
function dpClientFlag(Client $client, bool $on): void
{
    app(PartnerContext::class)->runAsSystem(static fn (): int => Client::query()->whereKey($client->id)->update(['online_payment_enabled' => $on]));
}

/**
 * A credit-note draft of the client, built as system with the only writes the schema allows: no method, no Admin row.
 */
function dpCreditNoteDraft(Client $client): Invoice
{
    return app(PartnerContext::class)->runAsSystem(static function () use ($client): Invoice {
        $source = InvoiceFixtures::issued($client);
        $credit = new Invoice;
        $credit->forceFill([
            'client_id' => $client->id,
            'kind' => InvoiceKind::CreditNote,
            'currency' => $source->currency,
            'language' => $source->language,
            'vat_mode' => $source->vat_mode,
            'credited_invoice_id' => $source->id,
            'cancels_invoice' => false,
            'subtotal_minor' => -100,
            'vat_minor' => 0,
            'total_minor' => -100,
        ])->save();

        return $credit;
    });
}

describe('online needs card payment (D-03, P14-09)', function (): void {
    beforeEach(function (): void {
        CardPaymentSetup::enable();
        InvoiceFixtures::configureAccounts(InvoiceFixtures::account('Example CZK account', 'CZK', '11'));
    });

    it('refuses the change to online while the global setting is off, a Stripe secret is missing or the client flag is off', function (string $cause): void {
        $client = CardPaymentSetup::client(['online_payment_enabled' => $cause !== 'client_off']);
        $draft = dpSave(dpCreate($client), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]);

        match ($cause) {
            'settings_off' => CardPaymentSetup::onlinePayments(false),
            'stripe_missing' => config(['services.stripe.secret' => null]),
            default => null,
        };

        $errors = dpRefused(fn () => InvoiceFixtures::online($draft));

        expect($errors)->toHaveKey('payment_method')
            ->and($errors['payment_method'][0])->toBe('Úhradu online teď nelze použít: platba kartou musí být zapnutá v Nastavení, Stripe musí být nastavený a klient musí mít online platbu zapnutou.')
            ->and($draft->fresh()->payment_method)->toBe(DocumentPaymentMethod::Transfer);
    })->with(['settings_off', 'stripe_missing', 'client_off']);

    it('keeps the card switch on for an online draft whatever the data says', function (): void {
        $draft = InvoiceFixtures::online(dpCreate(CardPaymentSetup::client()));

        $draft = dpSave($draft, ['card_payment_enabled' => false]);

        expect(dpAdminRow($draft)->card_payment_enabled)->toBeTrue()
            ->and($draft->payment_method)->toBe(DocumentPaymentMethod::Online);

        $draft = dpSave($draft, ['payment_method' => 'online', 'card_payment_enabled' => false]);

        expect(dpAdminRow($draft)->card_payment_enabled)->toBeTrue();
    });

    it('resets the switch to the client flag on online to transfer, unless the Admin sends a value (OA-1)', function (bool $clientOn, ?bool $sent, bool $expected): void {
        $client = CardPaymentSetup::client();
        $draft = InvoiceFixtures::online(dpCreate($client));

        dpClientFlag($client, $clientOn);

        $data = ['payment_method' => 'transfer'];

        if ($sent !== null) {
            $data['card_payment_enabled'] = $sent;
        }

        $draft = dpSave($draft, $data);

        expect($draft->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and(dpAdminRow($draft)->card_payment_enabled)->toBe($expected);
    })->with([
        'client flag on, nothing sent' => [true, null, true],
        'client flag off, nothing sent' => [false, null, false],
        'client flag on, false sent' => [true, false, false],
        'client flag off, true sent' => [false, true, true],
    ]);

    it('lets the Admin turn the card switch on for a transfer draft of a client with the flag off, and the issue freezes it through the settings', function (): void {
        $client = CardPaymentSetup::client(['online_payment_enabled' => false]);
        $draft = dpSave(dpCreate($client), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]);

        expect(dpAdminRow($draft)->card_payment_enabled)->toBeFalse();

        $draft = dpSave($draft, ['card_payment_enabled' => true]);

        expect(dpAdminRow($draft)->card_payment_enabled)->toBeTrue()
            ->and($draft->payment_method)->toBe(DocumentPaymentMethod::Transfer);

        $issued = app(IssueDocument::class)->handle(Canary::admin(), $draft);

        expect(dpAdminRow($issued)->card_payment_enabled)->toBeTrue()
            ->and($issued->bank_snapshot)->not->toBeNull();
    });

    it('refuses a payment method that is neither transfer nor online', function (mixed $value): void {
        $draft = dpCreate(CardPaymentSetup::client());

        $errors = dpRefused(fn () => dpSave($draft, ['payment_method' => $value]));

        expect($errors)->toHaveKey('payment_method')
            ->and($errors['payment_method'][0])->toBe('Zvolte úhradu převodem, online nebo hotově.')
            ->and($draft->fresh()->payment_method)->toBe(DocumentPaymentMethod::Transfer);
    })->with(['card', '', 5, null]);

    it('refuses the method and the account on a credit-note draft, keeps it without a method, and refuses its card key as before', function (): void {
        $credit = dpCreditNoteDraft(CardPaymentSetup::client());

        foreach (['payment_method' => 'transfer', 'bank_account_id' => InvoiceFixtures::accountId('Example CZK account')] as $key => $value) {
            $errors = dpRefused(fn () => dpSave($credit, [$key => $value]));

            expect($errors)->toHaveKey($key)
                ->and($errors[$key][0])->toBe('Dobropis nemá způsob úhrady ani bankovní účet.');
        }

        $errors = dpRefused(fn () => dpSave($credit, ['card_payment_enabled' => true]));

        expect($errors['card_payment_enabled'][0])->toBe(__('invoices.errors.card_payment_invalid'))
            ->and(DB::table('invoices')->where('id', $credit->id)->value('payment_method'))->toBeNull();
    });

    it('does not block an online draft converted from a proforma, and blocks any other once card payment is unavailable', function (): void {
        $client = CardPaymentSetup::client();
        $draft = InvoiceFixtures::online(dpSave(dpCreate($client), ['items' => [InvoiceFixtures::item('Example work', '1', '800.00')]]));

        CardPaymentSetup::onlinePayments(false);

        expect(dpBlockerKeys($draft))->toContain('online_unavailable');

        $converted = $draft->fresh();
        $converted->forceFill(['proforma_id' => (string) Str::uuid()]);

        expect(array_column(app(IssueChecks::class)->blockers($converted, '2026-10-14'), 'key'))->not->toContain('online_unavailable');
    });
});
