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
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

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
