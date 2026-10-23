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
