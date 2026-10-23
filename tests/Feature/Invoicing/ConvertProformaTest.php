<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Finance\Models\TransactionCategory;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateDraft;
use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Invoicing\Support\InvoiceSnapshot;
use App\Domain\Payments\Enums\PaymentLinkDeactivation;
use App\Domain\Payments\Enums\PaymentLinkState;
use App\Domain\Payments\Models\PaymentLink;
use App\Domain\Payments\Support\PayToken;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\CardPaymentSetup;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The conversion of a paid proforma into an invoice (IN-06, FI-03; D-15, D-18): one transaction that numbers the
 * invoice from its own series, moves the billed time along, carries each payment over with its income at the real
 * payment date, and leaves the proforma as history. Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function convertSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client in the given currency with a project at 1 200 an hour and three finished entries of September
 * (3.25 hours in all).
 *
 * @return array{client: Client, project: Project, entries: list<TimeEntry>}
 */
function convertTimeSetup(string $currency = 'CZK'): array
{
    $client = InvoiceFixtures::client(['currency' => $currency]);
    $project = app(CreateProject::class)->handle($client, [
        'name' => 'Conversion project',
        'key' => Canary::projectKey(),
        'billing_type' => 'hourly',
        'hourly_rate' => '1200',
    ]);

    $entries = [];

    foreach ([['2026-09-10 08:00:00', '2026-09-10 09:00:00'], ['2026-09-11 08:00:00', '2026-09-11 09:30:00'], ['2026-09-12 08:00:00', '2026-09-12 08:45:00']] as [$start, $end]) {
        $factory = TimeEntry::factory()->forProject($project);
        $entries[] = convertSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => CarbonImmutable::parse($start, 'UTC'),
            'ended_at' => CarbonImmutable::parse($end, 'UTC'),
        ])->id));
    }

    return ['client' => $client, 'project' => $project, 'entries' => $entries];
}

/**
 * An issued proforma of the September time of the project.
 */
function convertIssuedProforma(Client $client, Project $project): Invoice
{
    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'proforma',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => [$project->id],
        'without_project' => false,
        'grouping' => 'project',
    ]);

    return app(IssueDocument::class)->handle(test()->admin, $draft);
}

/**
 * Records one payment of the document.
 */
function convertPay(Invoice $document, string $amount, string $paidOn): InvoicePayment
{
    return app(RecordPayment::class)->handle(test()->admin, $document, ['amount' => $amount, 'paid_on' => $paidOn]);
}

function convertFreshEntry(TimeEntry $entry): TimeEntry
{
    return convertSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

describe('the tracer: a fully paid proforma built from time', function (): void {
    it('becomes a numbered, linked, paid invoice with its time moved and its income at the payment date', function (): void {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        $proformaPayment = convertPay($proforma, '3900', '2026-10-04');
        $before = array_map(convertFreshEntry(...), $entries);

        $noon = CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Prague');
        $expectedNumber = app(DocumentNumbering::class)->preview(InvoiceKind::Invoice->numberingKind(), null, $noon);

        expect(Transaction::query()->count())->toBe(0);

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        expect($invoice->kind)->toBe(InvoiceKind::Invoice)
            ->and($invoice->isIssued())->toBeTrue()
            ->and($invoice->number)->toBe($expectedNumber)
            ->and($invoice->number)->not->toBe($proforma->number)
            ->and($invoice->proforma_id)->toBe($proforma->id)
            ->and($invoice->client_id)->toBe($client->id)
            ->and($invoice->currency)->toBe('CZK')
            ->and($invoice->language)->toBe($proforma->language)
            ->and($invoice->total_minor)->toBe($proforma->total_minor)
            ->and($invoice->issue_date?->toDateString())->toBe('2026-10-14')
            ->and($invoice->due_date?->toDateString())->toBe('2026-10-28');

        $lines = InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get();
        $sourceLines = InvoiceItem::query()->where('invoice_id', $proforma->id)->orderBy('position')->get();

        expect($lines)->toHaveCount($sourceLines->count())
            ->and($lines->map(fn (InvoiceItem $line): array => [$line->description, $line->quantity, $line->unit, $line->unit_price_minor, $line->line_total_minor])->all())
            ->toBe($sourceLines->map(fn (InvoiceItem $line): array => [$line->description, $line->quantity, $line->unit, $line->unit_price_minor, $line->line_total_minor])->all());

        $stored = convertSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());

        expect($stored->admin?->pdf_document_id)->not->toBeNull()
            ->and(Document::query()->where('owner_type', 'invoice')->where('owner_id', $invoice->id)->count())->toBe(1);

        foreach ($entries as $index => $entry) {
            $moved = convertFreshEntry($entry);

            expect($moved->invoice_id)->toBe($invoice->id)
                ->and($moved->billing_state)->toBe(BillingState::Billed)
                ->and($moved->billed_at?->toIso8601String())->toBe($before[$index]->billed_at?->toIso8601String())
                ->and($moved->billed_rate_minor)->toBe($before[$index]->billed_rate_minor)
                ->and($moved->billed_amount_minor)->toBe($before[$index]->billed_amount_minor);
        }

        $carried = InvoicePayment::query()->where('invoice_id', $invoice->id)->sole();
        $income = Transaction::query()->where('invoice_payment_id', $carried->id)->sole();

        expect($carried->carried_from_payment_id)->toBe($proformaPayment->id)
            ->and($carried->paid_on->toDateString())->toBe('2026-10-04')
            ->and($carried->amount_minor)->toBe($proformaPayment->amount_minor)
            ->and($carried->currency)->toBe('CZK')
            ->and($carried->created_by)->toBe($this->admin->id)
            ->and(Transaction::query()->count())->toBe(1)
            ->and($income->transaction_date->toDateString())->toBe('2026-10-04')
            ->and($income->category_id)->toBe(TransactionCategory::invoicePayments()->id)
            ->and($income->invoice_id)->toBe($invoice->id)
            ->and($income->amount_minor)->toBe($proformaPayment->amount_minor);

        $proformaStays = InvoicePayment::query()->where('invoice_id', $proforma->id)->sole();

        expect($proformaStays->id)->toBe($proformaPayment->id)
            ->and(Transaction::query()->where('invoice_id', $proforma->id)->count())->toBe(0);

        $statuses = app(ReceivablesReader::class)->statuses(
            [Invoice::query()->findOrFail($proforma->id), Invoice::query()->findOrFail($invoice->id)],
            ReceivablesReader::today(),
        );

        expect($statuses[$proforma->id]['status'])->toBe(InvoiceStatus::Converted)
            ->and($statuses[$invoice->id]['status'])->toBe(InvoiceStatus::Paid);
    });
});

/**
 * The counters, in a stable order.
 *
 * @return list<array<string, mixed>>
 */
function convertSequences(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();
}

describe('what a proforma needs to be converted', function (): void {
    it('refuses a proforma that is not paid in full, whether nothing or a part is paid', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $unpaid = convertIssuedProforma($client, $project);

        expect(fn () => app(ConvertProforma::class)->handle($this->admin, $unpaid))
            ->toThrow(ValidationException::class, __('invoices.errors.proforma_not_paid'));

        convertPay($unpaid, '1000', '2026-10-04');

        try {
            app(ConvertProforma::class)->handle($this->admin, $unpaid);
            $this->fail('A partly paid proforma had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('proforma')
                ->and($exception->errors()['proforma'])->toBe([__('invoices.errors.proforma_not_paid')]);
        }

        expect(Invoice::query()->where('kind', 'invoice')->count())->toBe(0)
            ->and(Transaction::query()->count())->toBe(0);
    });

    it('refuses a proforma that already has its invoice and creates no second one', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        convertPay($proforma, '3900', '2026-10-04');

        app(ConvertProforma::class)->handle($this->admin, $proforma);
        $sequences = convertSequences();

        expect(fn () => app(ConvertProforma::class)->handle($this->admin, $proforma))
            ->toThrow(ValidationException::class, __('invoices.errors.proforma_converted'))
            ->and(Invoice::query()->where('proforma_id', $proforma->id)->count())->toBe(1)
            ->and(Transaction::query()->count())->toBe(1)
            ->and(convertSequences())->toBe($sequences);
    });

    it('refuses an invoice, a credit note, a draft proforma and a cancelled proforma', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $credit = InvoiceFixtures::stornoed(InvoiceFixtures::issued($client));
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Advance', '1', '500.00')], InvoiceKind::Proforma);
        $cancelled = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Advance', '1', '500.00')], InvoiceKind::Proforma);
        InvoiceFixtures::stornoed($cancelled);

        $this->actingAs($this->admin);

        foreach ([$invoice, $credit, $draft, $cancelled] as $document) {
            expect(fn () => app(ConvertProforma::class)->handle($this->admin, $document))
                ->toThrow(ValidationException::class, __('invoices.errors.proforma_not_issued'));
        }

        expect(Invoice::query()->whereNotNull('proforma_id')->count())->toBe(0);
    });

    it('refuses a Partner and writes nothing', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        convertPay($proforma, '3900', '2026-10-04');
        $partner = Canary::partnerFor((string) $client->id);
        $this->actingAs($partner);

        expect(fn () => app(ConvertProforma::class)->handle($partner, $proforma))->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);

        expect(Invoice::query()->whereNotNull('proforma_id')->count())->toBe(0);
    });

    it('keeps one invoice per proforma in the database as well', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        convertPay($proforma, '3900', '2026-10-04');
        app(ConvertProforma::class)->handle($this->admin, $proforma);

        RawSql::expectSqlState('23505', static fn () => DB::table('invoices')->insert([
            'client_id' => $proforma->client_id,
            'kind' => 'invoice',
            'currency' => 'CZK',
            'language' => 'cs',
            'proforma_id' => $proforma->id,
        ]));
    });
});

describe('what the conversion carries over', function (): void {
    it('converts an overpaid proforma and leaves the invoice with nothing to pay', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        convertPay($proforma, '4000', '2026-10-04');

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        $balance = app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id];

        expect($balance['paid'])->toBe(400000)
            ->and($balance['remaining'])->toBe(0);
    });

    it('carries two payments as two rows and two incomes, each with its own date', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        $first = convertPay($proforma, '1000', '2026-10-02');
        $second = app(RecordPayment::class)->handle($this->admin, $proforma, ['amount' => '2900', 'paid_on' => '2026-10-09', 'method' => 'cash', 'note' => 'Second part']);

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        $carried = InvoicePayment::query()->where('invoice_id', $invoice->id)->orderBy('paid_on')->get();

        expect($carried)->toHaveCount(2)
            ->and($carried->pluck('carried_from_payment_id')->all())->toBe([$first->id, $second->id])
            ->and($carried->pluck('amount_minor')->all())->toBe([100000, 290000])
            ->and($carried->pluck('paid_on')->map(fn ($day): string => $day->toDateString())->all())->toBe(['2026-10-02', '2026-10-09'])
            ->and($carried[1]->method->value)->toBe('cash')
            ->and($carried[1]->note)->toBe('Second part');

        $incomes = Transaction::query()->orderBy('transaction_date')->get();

        expect($incomes)->toHaveCount(2)
            ->and($incomes->pluck('transaction_date')->map(fn ($day): string => $day->toDateString())->all())->toBe(['2026-10-02', '2026-10-09'])
            ->and($incomes->pluck('invoice_payment_id')->all())->toBe($carried->pluck('id')->all())
            ->and(InvoicePayment::query()->where('invoice_id', $proforma->id)->count())->toBe(2);
    });

    it('copies the printed note of the proforma and not the internal one', function (): void {
        ['client' => $client, 'project' => $project] = convertTimeSetup();
        $draft = app(CreateDraft::class)->handle($this->admin, [
            'client_id' => $client->id,
            'kind' => 'proforma',
            'source' => 'time',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'project_ids' => [$project->id],
            'without_project' => false,
            'grouping' => 'project',
        ]);
        app(UpdateDraft::class)->handle($this->admin, $draft, ['note' => 'Advance for September', 'internal_note' => 'Internal remark']);
        $proforma = app(IssueDocument::class)->handle($this->admin, $draft->refresh());
        convertPay($proforma, '3900', '2026-10-04');

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $proformaNote = convertSystem(static fn (): ?string => InvoiceAdmin::query()->where('invoice_id', $proforma->id)->value('internal_note'));
        $invoiceNote = convertSystem(static fn (): ?string => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->value('internal_note'));

        expect($invoice->note)->toBe('Advance for September')
            ->and($proformaNote)->toBe('Internal remark')
            ->and($invoiceNote)->toBeNull();
    });

    it('prices a EUR proforma with the CNB list of the conversion day while the amounts stay those of the proforma', function (): void {
        $client = InvoiceFixtures::client(['currency' => 'EUR']);

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-05', '25.000');
        $proforma = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Advance', '1', '200.00', 'pcs')], InvoiceKind::Proforma);

        $this->travelTo(CarbonImmutable::parse('2026-10-07 09:30:00', 'Europe/Prague'));
        $this->actingAs($this->admin);
        convertPay($proforma, '200', '2026-10-06');

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-06', '25.500');
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '24.000');

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        $proformaAdmin = convertSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $proforma->id)->firstOrFail());
        $invoiceAdmin = convertSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());
        $line = InvoiceItem::query()->where('invoice_id', $invoice->id)->sole();
        $income = Transaction::query()->sole();

        expect($invoice->currency)->toBe('EUR')
            ->and($invoice->total_minor)->toBe(20000)
            ->and($line->unit_price_minor)->toBe(20000)
            ->and($line->line_total_minor)->toBe(20000)
            ->and((float) $proformaAdmin->exchange_rate)->toBe(25.0)
            ->and($proformaAdmin->czk_total_minor)->toBe(500000)
            ->and((float) $invoiceAdmin->exchange_rate)->toBe(24.0)
            ->and($invoiceAdmin->exchange_rate_date?->toDateString())->toBe('2026-10-14')
            ->and($invoiceAdmin->czk_total_minor)->toBe(480000)
            ->and($income->amount_currency)->toBe('EUR')
            ->and($income->transaction_date->toDateString())->toBe('2026-10-06')
            ->and($income->czk_minor)->toBe(510000);
    });
});

/**
 * An issued proforma of one 3 900 line of the client with the given payment method and, for a transfer proforma, the
 * given account id (null keeps the preselected one). The caller enables card payment first for an online one.
 */
function convertPaymentProforma(Client $client, DocumentPaymentMethod $method, ?string $accountId = null): Invoice
{
    $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Example advance', '1', '3900.00')], InvoiceKind::Proforma, test()->admin);
    $data = ['payment_method' => $method->value];

    if ($accountId !== null) {
        $data['bank_account_id'] = $accountId;
    }

    app(UpdateDraft::class)->handle(test()->admin, $draft, $data);

    return app(IssueDocument::class)->handle(test()->admin, $draft->refresh());
}

/**
 * The Admin-only row of a document as the raw database holds it.
 */
function convertAdminRow(Invoice $document): stdClass
{
    return DB::table('invoice_admin')->where('invoice_id', $document->id)->first() ?? throw new LogicException('No Admin row.');
}

describe('the payment method of the converted invoice (Phase 14)', function (): void {
    it('turns an online proforma paid in full into an online invoice with no bank snapshot and no live link', function (): void {
        Queue::fake();
        CardPaymentSetup::enable();
        $client = CardPaymentSetup::client();

        $proforma = convertPaymentProforma($client, DocumentPaymentMethod::Online);
        $proformaLinksOf = static fn (): array => convertSystem(static fn (): array => PaymentLink::query()->where('invoice_id', $proforma->id)->orderBy('id')->get()->map(static fn (PaymentLink $link): array => [$link->id, $link->state->value, $link->deactivation_reason?->value])->all());
        convertPay($proforma, '3900', '2026-10-04');
        $proformaLinksBefore = $proformaLinksOf();

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $row = convertAdminRow($invoice);
        $links = convertSystem(static fn (): Collection => PaymentLink::query()->where('invoice_id', $invoice->id)->get());

        expect($proformaLinksBefore)->toHaveCount(1)
            ->and($invoice->kind)->toBe(InvoiceKind::Invoice)
            ->and($invoice->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and($invoice->bank_snapshot)->toBeNull()
            ->and($row->card_payment_enabled)->toBeTrue()
            ->and(PayToken::isWellFormed((string) $row->pay_token))->toBeTrue()
            ->and($links)->toHaveCount(1)
            ->and($links->first()->state)->toBe(PaymentLinkState::Deactivated)
            ->and($links->first()->deactivation_reason)->toBe(PaymentLinkDeactivation::Paid)
            ->and(convertSystem(static fn (): int => PaymentLink::query()->where('invoice_id', $invoice->id)->live()->count()))->toBe(0)
            ->and($proformaLinksOf())->toBe($proformaLinksBefore);
    });
});

describe('the payment method of the converted invoice (Phase 14): edges', function (): void {
    it('turns a transfer proforma with the second CZK account into a transfer invoice with that account and no card switch', function (): void {
        Queue::fake();
        CardPaymentSetup::enable();
        [, $second] = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example CZK B', 'CZK', '22'),
            InvoiceFixtures::account('Example EUR A', 'EUR', '33'),
        );
        $client = CardPaymentSetup::client();

        $proforma = convertPaymentProforma($client, DocumentPaymentMethod::Transfer, $second->id);
        $proformaRow = convertAdminRow($proforma);
        convertPay($proforma, '3900', '2026-10-04');

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $row = convertAdminRow($invoice);
        $expected = app(InvoiceSnapshot::class)->bankAccount($second);

        // The proforma itself carried the card switch (the client flag is on), the converted invoice must not.
        expect($proformaRow->card_payment_enabled)->toBeTrue()
            ->and($invoice->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and($invoice->bank_snapshot)->toEqual($expected)
            ->and($row->bank_account_id)->toBe($second->id)
            ->and($row->card_payment_enabled)->toBeFalse()
            ->and($row->pay_token)->toBeNull()
            ->and(convertSystem(static fn (): int => PaymentLink::query()->where('invoice_id', $invoice->id)->count()))->toBe(0);
    });

    it('converts with the first account of the currency when the account the proforma chose was removed from the settings', function (): void {
        Queue::fake();
        [$first, $second] = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example CZK B', 'CZK', '22'),
        );
        $client = InvoiceFixtures::client();

        $proforma = convertPaymentProforma($client, DocumentPaymentMethod::Transfer, $second->id);
        convertPay($proforma, '3900', '2026-10-04');
        InvoiceFixtures::configureAccounts($first);

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);

        expect($invoice->bank_snapshot)->toEqual(app(InvoiceSnapshot::class)->bankAccount($first))
            ->and(convertAdminRow($invoice)->bank_account_id)->toBe($first->id);
    });

    it('refuses the conversion with bank_missing and writes nothing when no account of the currency is left', function (): void {
        Queue::fake();
        [, , $eur] = InvoiceFixtures::configureAccounts(
            InvoiceFixtures::account('Example CZK A', 'CZK', '11'),
            InvoiceFixtures::account('Example CZK B', 'CZK', '22'),
            InvoiceFixtures::account('Example EUR A', 'EUR', '33'),
        );
        $client = InvoiceFixtures::client();

        $proforma = convertPaymentProforma($client, DocumentPaymentMethod::Transfer);
        convertPay($proforma, '3900', '2026-10-04');
        InvoiceFixtures::configureAccounts($eur);
        $sequences = convertSequences();

        try {
            app(ConvertProforma::class)->handle($this->admin, $proforma);
            $this->fail('The conversion had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('bank_missing');
        }

        expect(Invoice::query()->whereNotNull('proforma_id')->count())->toBe(0)
            ->and(convertSequences())->toBe($sequences)
            ->and(InvoicePayment::query()->count())->toBe(1)
            ->and(Transaction::query()->count())->toBe(0);
    });

    it('converts an online proforma while online payments are off in the settings: online, switch off, no token, no link', function (): void {
        Queue::fake();
        CardPaymentSetup::enable();
        $client = CardPaymentSetup::client();

        $proforma = convertPaymentProforma($client, DocumentPaymentMethod::Online);
        convertPay($proforma, '3900', '2026-10-04');
        CardPaymentSetup::onlinePayments(false);

        $invoice = app(ConvertProforma::class)->handle($this->admin, $proforma);
        $row = convertAdminRow($invoice);

        expect($invoice->payment_method)->toBe(DocumentPaymentMethod::Online)
            ->and($invoice->bank_snapshot)->toBeNull()
            ->and($row->card_payment_enabled)->toBeFalse()
            ->and($row->pay_token)->toBeNull()
            ->and(convertSystem(static fn (): int => PaymentLink::query()->where('invoice_id', $invoice->id)->count()))->toBe(0);
    });
});

describe('the conversion is atomic', function (): void {
    /**
     * A paid proforma of the September time and what exists before its conversion.
     *
     * @return array{proforma: Invoice, entries: list<TimeEntry>, sequences: list<array<string, mixed>>, files: list<string>}
     */
    function convertPaidProforma(): array
    {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = convertTimeSetup();
        $proforma = convertIssuedProforma($client, $project);
        convertPay($proforma, '3900', '2026-10-04');

        return ['proforma' => $proforma, 'entries' => $entries, 'sequences' => convertSequences(), 'files' => Storage::disk('local')->allFiles()];
    }

    /**
     * Asserts that a refused conversion left everything where it was.
     *
     * @param  list<TimeEntry>  $entries
     * @param  list<array<string, mixed>>  $sequences
     * @param  list<string>  $files
     */
    function convertAssertNothingChanged(Invoice $proforma, array $entries, array $sequences, array $files): void
    {
        expect(Invoice::query()->whereNotNull('proforma_id')->count())->toBe(0)
            ->and(Invoice::query()->where('kind', 'invoice')->count())->toBe(0)
            ->and(convertSequences())->toBe($sequences)
            ->and(InvoicePayment::query()->count())->toBe(1)
            ->and(Transaction::query()->count())->toBe(0)
            ->and(Document::query()->count())->toBe(1)
            ->and(Storage::disk('local')->allFiles())->toBe($files);

        foreach ($entries as $entry) {
            $stored = convertFreshEntry($entry);

            expect($stored->invoice_id)->toBe($proforma->id)
                ->and($stored->billing_state)->toBe(BillingState::Billed);
        }
    }

    it('leaves nothing when the PDF cannot be rendered', function (): void {
        ['proforma' => $proforma, 'entries' => $entries, 'sequences' => $sequences, 'files' => $files] = convertPaidProforma();

        View::composer('pdf.invoice', static function (): void {
            throw new RuntimeException('The rendering failed.');
        });

        expect(fn () => app(ConvertProforma::class)->handle($this->admin, $proforma))->toThrow(RuntimeException::class, 'The rendering failed.');

        convertAssertNothingChanged($proforma, $entries, $sequences, $files);
    });

    it('leaves nothing, not even the stored PDF or the moved time, when a payment cannot be carried over', function (): void {
        ['client' => $client, 'project' => $project, 'entries' => $entries] = convertTimeSetup('EUR');

        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-05', '25.000');
        $proforma = convertIssuedProforma($client, $project);
        // Paid before the first CNB list of the currency exists: the income of that day cannot be priced.
        convertPay($proforma, $proforma->total()->toMajor(), '2026-10-01');

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '24.000');
        $sequences = convertSequences();
        $files = Storage::disk('local')->allFiles();

        try {
            app(ConvertProforma::class)->handle($this->admin, $proforma);
            $this->fail('The conversion had to be refused.');
        } catch (ValidationException $exception) {
            expect($exception->errors())->toHaveKey('cnb_missing');
        }

        convertAssertNothingChanged($proforma, $entries, $sequences, $files);
    });
});
