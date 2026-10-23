<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Jobs\CreatePaymentLink;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
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
