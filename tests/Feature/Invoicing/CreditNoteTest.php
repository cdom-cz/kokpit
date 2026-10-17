<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Documents\Models\Document;
use App\Domain\Finance\Models\Transaction;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceStatus;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceAdmin;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Models\InvoicePayment;
use App\Domain\Invoicing\Queries\InvoiceBalances;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Numbering\DocumentNumbering;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * Credit notes (IN-10, IN-01, IN-03; D-13, D-14, D-16): the full storno issues a credit note from its own series that
 * marks the invoice cancelled and returns its time to unbilled, a partial credit note credits chosen quantities and
 * keeps the time billed. Nothing is ever written on the issued invoice itself. Every name and amount is fictional.
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
function creditSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A client in the given currency with two projects at 1 200 and 900 an hour. The first holds entries of 1 h and
 * 1,5 h, the second entries of 1 h and 0,5 h, all finished in September.
 *
 * @return array{client: Client, projects: list<Project>, entries: list<TimeEntry>}
 */
function creditTimeSetup(string $currency = 'CZK'): array
{
    $client = InvoiceFixtures::client(['currency' => $currency]);
    $projects = [];
    $entries = [];

    foreach ([['Alpha credit project', '1200', [3600, 5400]], ['Bravo credit project', '900', [3600, 1800]]] as $index => [$name, $rate, $durations]) {
        $project = app(CreateProject::class)->handle($client, [
            'name' => $name,
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => $rate,
        ]);
        $projects[] = $project;

        foreach ($durations as $position => $seconds) {
            $start = CarbonImmutable::parse('2026-09-1'.($index + $position + 1).' 08:00:00', 'UTC');
            $factory = TimeEntry::factory()->forProject($project);
            $entries[] = creditSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
                'user_id' => test()->admin->id,
                'started_at' => $start,
                'ended_at' => $start->addSeconds($seconds),
            ])->id));
        }
    }

    return ['client' => $client, 'projects' => $projects, 'entries' => $entries];
}

/**
 * An issued invoice of the September time of the projects, one line per project.
 *
 * @param  list<Project>  $projects
 */
function creditIssuedFromTime(Client $client, array $projects, string $currency = 'CZK'): Invoice
{
    $draft = app(CreateDraft::class)->handle(test()->admin, [
        'client_id' => $client->id,
        'kind' => 'invoice',
        'source' => 'time',
        'period_from' => '2026-09-01',
        'period_to' => '2026-09-30',
        'project_ids' => array_map(static fn (Project $project): string => $project->id, $projects),
        'without_project' => false,
        'grouping' => 'project',
        'currency' => $currency,
    ]);

    return app(IssueDocument::class)->handle(test()->admin, $draft);
}

function creditFreshEntry(TimeEntry $entry): TimeEntry
{
    return creditSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($entry->id));
}

function creditStored(Invoice $document): Invoice
{
    return creditSystem(static fn (): Invoice => Invoice::query()->findOrFail($document->id));
}

/**
 * Every column of the row exactly as stored, raw, for the before and after comparison.
 *
 * @return array<string, mixed>
 */
function creditRawRow(Invoice $document): array
{
    return (array) DB::table('invoices')->where('id', $document->id)->first();
}

/**
 * The lines of a document in order, with their sources.
 *
 * @return list<InvoiceItem>
 */
function creditLines(Invoice $document): array
{
    return creditSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $document->id)->with('source')->orderBy('position')->get()->all());
}

describe('the tracer: the storno of an invoice built from time', function (): void {
    it('issues a numbered credit note, cancels the invoice and returns its time to unbilled', function (): void {
        ['client' => $client, 'projects' => $projects, 'entries' => $entries] = creditTimeSetup();
        $invoice = creditIssuedFromTime($client, $projects);
        $payment = app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '1000', 'paid_on' => '2026-10-05']);

        expect($invoice->total_minor)->toBe(300000 + 135000)
            ->and(count(creditLines($invoice)))->toBe(2)
            ->and(Transaction::query()->count())->toBe(1);

        $rowBefore = creditRawRow($invoice);
        $transactionsBefore = Transaction::query()->get()->map(static fn (Transaction $transaction): array => $transaction->getAttributes())->all();
        $paymentsBefore = creditSystem(static fn (): array => InvoicePayment::query()->get()->map(static fn (InvoicePayment $row): array => $row->getAttributes())->all());

        $expectedNumber = app(DocumentNumbering::class)->preview(InvoiceKind::CreditNote->numberingKind(), null, CarbonImmutable::parse('2026-10-14 12:00:00', 'Europe/Prague'));

        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);

        // The credit note: first number of its own series, naming the invoice, negative, nothing a credit note must not carry.
        expect($credit->kind)->toBe(InvoiceKind::CreditNote)
            ->and($credit->isIssued())->toBeTrue()
            ->and($credit->number)->toBe($expectedNumber)
            ->and($credit->credited_invoice_id)->toBe($invoice->id)
            ->and($credit->cancels_invoice)->toBeTrue()
            ->and($credit->total_minor)->toBe(-$invoice->total_minor)
            ->and($credit->subtotal_minor)->toBe(-$invoice->subtotal_minor)
            ->and($credit->vat_minor)->toBe(0)
            ->and($credit->due_date)->toBeNull()
            ->and($credit->variable_symbol)->toBeNull()
            ->and($credit->bank_snapshot)->toBeNull()
            ->and($credit->issue_date?->format('Y-m-d'))->toBe('2026-10-14')
            ->and($credit->supplier_snapshot)->not->toBeNull()
            ->and($credit->customer_snapshot)->not->toBeNull();

        expect(Invoice::query()->where('kind', 'credit_note')->count())->toBe(1);

        // Two lines, each taking one invoice line back at the original unit price.
        $originalLines = creditLines($invoice);
        $creditLines = creditLines($credit);

        expect($creditLines)->toHaveCount(2);

        foreach ($creditLines as $index => $line) {
            $original = $originalLines[$index];

            expect($line->credited_item_id)->toBe($original->id)
                ->and($line->quantity)->toBe('-'.$original->quantity)
                ->and($line->unit_price_minor)->toBe($original->unit_price_minor)
                ->and($line->line_total_minor)->toBe(-$original->line_total_minor)
                ->and($line->description)->toBe($original->description)
                ->and($line->unit)->toBe($original->unit)
                ->and($line->price_converted)->toBeFalse();
        }

        // Its PDF is stored like every issued document.
        $admin = creditSystem(static fn (): ?InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $credit->id)->first());

        expect($admin?->pdf_document_id)->not->toBeNull()
            ->and(creditSystem(static fn (): int => Document::query()->whereKey($admin?->pdf_document_id)->count()))->toBe(1);

        // The invoice reads as cancelled and owes nothing; its own row is exactly as issued.
        expect(creditStored($invoice)->isCancelled())->toBeTrue()
            ->and(creditRawRow($invoice))->toBe($rowBefore)
            ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id]['remaining'])->toBe(0)
            ->and(app(ReceivablesReader::class)->statuses([creditStored($invoice)], ReceivablesReader::today())[$invoice->id]['status'])->toBe(InvoiceStatus::Cancelled);

        // The four entries are unbilled again, with no link and no snapshot, and editable.
        foreach ($entries as $entry) {
            $stored = creditFreshEntry($entry);

            expect($stored->billing_state)->toBe(BillingState::Unbilled)
                ->and($stored->invoice_id)->toBeNull()
                ->and($stored->billed_at)->toBeNull()
                ->and($stored->billed_rate_minor)->toBeNull()
                ->and($stored->billed_rate_currency)->toBeNull()
                ->and($stored->billed_amount_minor)->toBeNull();
        }

        app(UpdateTimeEntry::class)->handle($this->admin, $entries[0], ['description' => 'Edited after the storno']);

        expect(creditFreshEntry($entries[0])->description)->toBe('Edited after the storno');

        // The payment and its income are untouched (D-16).
        expect(creditSystem(static fn (): array => InvoicePayment::query()->get()->map(static fn (InvoicePayment $row): array => $row->getAttributes())->all()))->toBe($paymentsBefore)
            ->and(Transaction::query()->get()->map(static fn (Transaction $transaction): array => $transaction->getAttributes())->all())->toBe($transactionsBefore)
            ->and($payment->exists)->toBeTrue();

        expect(Storage::disk('local')->allFiles())->toHaveCount(2);
    });
});
