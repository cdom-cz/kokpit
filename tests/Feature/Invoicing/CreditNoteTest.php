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
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Actions\UpdateTimeEntry;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * Credit notes (IN-10, IN-01, IN-03; D-13, D-14, D-16): the full storno issues a credit note from its own series that
 * marks the invoice cancelled and returns its time to unbilled, a partial credit note credits chosen quantities and
 * keeps the time billed until the credit notes together take back every line (Phase 17.1, D-01). Nothing is ever
 * written on the issued invoice itself. Every name and amount is fictional.
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

describe('the tracer: partial credit notes that take back everything (Phase 17.1, D-01 to D-03)', function (): void {
    it('returns the time of a sent invoice to unbilled with the credit note that takes back the last quantity, and does not cancel the invoice', function (): void {
        ['client' => $client, 'projects' => $projects, 'entries' => $entries] = creditTimeSetup();
        $invoice = InvoiceFixtures::lock(creditIssuedFromTime($client, $projects));
        [$alpha, $bravo] = creditLines($invoice);
        $rowBefore = creditRawRow($invoice);

        // The first credit note leaves the second line untouched: nothing is released.
        $first = app(IssueCreditNote::class)->partial($this->admin, $invoice, [$alpha->id => '2.5']);

        foreach ($entries as $entry) {
            $stored = creditFreshEntry($entry);

            expect($stored->billing_state)->toBe(BillingState::Billed)
                ->and($stored->invoice_id)->toBe($invoice->id)
                ->and($stored->billed_amount_minor)->not->toBeNull();
        }

        // The second one takes back the last quantity: all four entries go back to unbilled, with no link and no snapshot.
        $second = app(IssueCreditNote::class)->partial($this->admin, $invoice, [$bravo->id => '1.5']);

        foreach ($entries as $entry) {
            $stored = creditFreshEntry($entry);

            expect($stored->billing_state)->toBe(BillingState::Unbilled)
                ->and($stored->invoice_id)->toBeNull()
                ->and($stored->billed_at)->toBeNull()
                ->and($stored->billed_rate_minor)->toBeNull()
                ->and($stored->billed_rate_currency)->toBeNull()
                ->and($stored->billed_amount_minor)->toBeNull();
        }

        // The entries can be edited again.
        app(UpdateTimeEntry::class)->handle($this->admin, $entries[0], ['description' => 'Edited after the full credit']);

        expect(creditFreshEntry($entries[0])->description)->toBe('Edited after the full credit');

        // The invoice is not cancelled and its row is exactly as before the first credit note (D-03).
        expect($first->cancels_invoice)->toBeFalse()
            ->and($second->cancels_invoice)->toBeFalse()
            ->and(creditStored($invoice)->isCancelled())->toBeFalse()
            ->and(creditRawRow($invoice))->toBe($rowBefore);

        // Nothing is left to credit, so the storno is refused.
        $errors = creditRefusal(fn () => app(IssueCreditNote::class)->storno($this->admin, $invoice));

        expect($errors['lines'][0])->toBe(__('invoices.errors.credit_nothing'))
            ->and(Invoice::query()->where('kind', 'credit_note')->count())->toBe(2);
    });
});

/**
 * The errors of a refused credit note, or a failure when it was not refused.
 *
 * @param  Closure(): mixed  $attempt
 * @return array<string, list<string>>
 */
function creditRefusal(Closure $attempt): array
{
    try {
        $attempt();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('The credit note had to be refused.');
}

/**
 * The state of the number counters, to prove that a refused credit note consumed nothing.
 *
 * @return list<array<string, mixed>>
 */
function creditSequences(): array
{
    return DB::table('number_sequences')->orderBy('scope_key')->get()->map(static fn (object $row): array => (array) $row)->all();
}

/**
 * An issued invoice with a line of 10 h at 100,00 and a line of 2 pieces at 50,00.
 */
function creditTwoLineInvoice(?Client $client = null): Invoice
{
    return InvoiceFixtures::issued($client ?? InvoiceFixtures::client(), [
        InvoiceFixtures::item('Consulting', '10', '100.00'),
        InvoiceFixtures::item('Licence', '2', '50.00', 'pcs'),
    ]);
}

describe('a partial credit note', function (): void {
    it('credits a quantity of one line, keeps the invoice issued and the time billed, and lowers what is owed', function (): void {
        ['client' => $client, 'projects' => $projects, 'entries' => $entries] = creditTimeSetup();
        $invoice = creditIssuedFromTime($client, $projects);
        [$alpha] = creditLines($invoice);

        $credit = app(IssueCreditNote::class)->partial($this->admin, $invoice, [$alpha->id => '1.5']);

        expect($credit->kind)->toBe(InvoiceKind::CreditNote)
            ->and($credit->isIssued())->toBeTrue()
            ->and($credit->cancels_invoice)->toBeFalse()
            ->and($credit->credited_invoice_id)->toBe($invoice->id)
            ->and($credit->total_minor)->toBe(-180000)
            ->and($credit->due_date)->toBeNull()
            ->and($credit->variable_symbol)->toBeNull();

        [$line] = creditLines($credit);

        expect(creditLines($credit))->toHaveCount(1)
            ->and($line->quantity)->toBe('-1.5000')
            ->and($line->unit_price_minor)->toBe(120000)
            ->and($line->line_total_minor)->toBe(-180000)
            ->and($line->credited_item_id)->toBe($alpha->id)
            ->and($line->source?->price_manual)->toBeTrue();

        expect(creditStored($invoice)->isCancelled())->toBeFalse()
            ->and(creditStored($invoice)->isIssued())->toBeTrue()
            ->and(app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id])->toBe(['paid' => 0, 'credited' => 180000, 'remaining' => 435000 - 180000]);

        foreach ($entries as $entry) {
            $stored = creditFreshEntry($entry);

            expect($stored->billing_state)->toBe(BillingState::Billed)
                ->and($stored->invoice_id)->toBe($invoice->id)
                ->and($stored->billed_amount_minor)->not->toBeNull();
        }
    });

    it('releases the time of an open invoice only with the credit note that takes back the last quantity', function (): void {
        ['client' => $client, 'projects' => $projects, 'entries' => $entries] = creditTimeSetup();
        $invoice = creditIssuedFromTime($client, $projects);
        [$alpha, $bravo] = creditLines($invoice);

        app(IssueCreditNote::class)->partial($this->admin, $invoice, [$alpha->id => '1']);
        app(IssueCreditNote::class)->partial($this->admin, $invoice, [$alpha->id => '1.5']);
        app(IssueCreditNote::class)->partial($this->admin, $invoice, [$bravo->id => '1']);

        expect(creditStored($invoice)->isCancelled())->toBeFalse();

        foreach ($entries as $entry) {
            expect(creditFreshEntry($entry)->billing_state)->toBe(BillingState::Billed)
                ->and(creditFreshEntry($entry)->invoice_id)->toBe($invoice->id);
        }

        app(IssueCreditNote::class)->partial($this->admin, $invoice, [$bravo->id => '0.5']);

        expect(creditStored($invoice)->isCancelled())->toBeFalse();

        foreach ($entries as $entry) {
            expect(creditFreshEntry($entry)->billing_state)->toBe(BillingState::Unbilled)
                ->and(creditFreshEntry($entry)->invoice_id)->toBeNull();
        }
    });

    it('respects the earlier credits: it refuses more than is left, accepts exactly what is left, and the storno takes only the rest', function (): void {
        $invoice = creditTwoLineInvoice();
        [$consulting, $licence] = creditLines($invoice);
        $credits = app(IssueCreditNote::class);

        $credits->partial($this->admin, $invoice, [$consulting->id => '1,5']);

        $errors = creditRefusal(fn () => $credits->partial($this->admin, $invoice, [$consulting->id => '9']));

        expect($errors)->toHaveKey('lines.'.$consulting->id)
            ->and($errors['lines.'.$consulting->id][0])->toBe(__('invoices.errors.credit_quantity'))
            ->and(Invoice::query()->where('kind', 'credit_note')->count())->toBe(1);

        $credits->partial($this->admin, $invoice, [$consulting->id => '8.5']);

        expect(Invoice::query()->where('kind', 'credit_note')->count())->toBe(2);

        $storno = $credits->storno($this->admin, $invoice);
        $lines = creditLines($storno);

        expect($lines)->toHaveCount(1)
            ->and($lines[0]->credited_item_id)->toBe($licence->id)
            ->and($lines[0]->quantity)->toBe('-2.0000')
            ->and($lines[0]->line_total_minor)->toBe(-10000)
            ->and($storno->total_minor)->toBe(-10000)
            ->and($storno->cancels_invoice)->toBeTrue();

        // Together the three credit notes take back exactly the invoice.
        $credited = app(InvoiceBalances::class)->for([$invoice->id])[$invoice->id];

        expect($credited['credited'])->toBe($invoice->total_minor)
            ->and($credited['remaining'])->toBe(0)
            ->and(creditStored($invoice)->isCancelled())->toBeTrue();
    });

    it('refuses a quantity of zero, a negative or malformed one, and an item of another invoice, with nothing consumed', function (): void {
        $invoice = creditTwoLineInvoice();
        $other = creditTwoLineInvoice();
        [$consulting] = creditLines($invoice);
        [$foreign] = creditLines($other);
        $sequences = creditSequences();
        $credits = app(IssueCreditNote::class);

        foreach (['0', '0,0000', '-1', 'abc', '1.00001', ''] as $quantity) {
            $errors = creditRefusal(fn () => $credits->partial($this->admin, $invoice, [$consulting->id => $quantity]));

            expect($errors['lines.'.$consulting->id][0])->toBe(__('invoices.errors.item_quantity'));
        }

        $errors = creditRefusal(fn () => $credits->partial($this->admin, $invoice, [$foreign->id => '1']));

        expect($errors['lines.'.$foreign->id][0])->toBe(__('invoices.errors.credit_quantity'));

        $errors = creditRefusal(fn () => $credits->partial($this->admin, $invoice, []));

        expect($errors['lines'][0])->toBe(__('invoices.errors.credit_nothing'))
            ->and(Invoice::query()->where('kind', 'credit_note')->count())->toBe(0)
            ->and(creditSequences())->toBe($sequences);
    });

    it('refuses to take back more than the invoice is worth in total', function (): void {
        // 3 x 0,01 is 0,03; crediting 1,5 twice rounds to 0,02 each time, which would be 0,04 in all.
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Tiny', '3', '0.01', 'pcs')]);
        [$line] = creditLines($invoice);
        $credits = app(IssueCreditNote::class);

        $first = $credits->partial($this->admin, $invoice, [$line->id => '1.5']);

        expect($first->total_minor)->toBe(-2);

        $errors = creditRefusal(fn () => $credits->partial($this->admin, $invoice, [$line->id => '1.5']));

        expect($errors['lines'][0])->toBe(__('invoices.errors.credit_total'))
            ->and(Invoice::query()->where('kind', 'credit_note')->count())->toBe(1);

        // The storno is never refused for the sake of a rounding minor unit: it takes back the rest and cancels.
        $storno = $credits->storno($this->admin, $invoice);

        expect($storno->cancels_invoice)->toBeTrue()
            ->and(creditStored($invoice)->isCancelled())->toBeTrue();
    });
});

describe('what can be credited', function (): void {
    it('refuses a storno of a cancelled invoice and a second storno, in the model and in the database', function (): void {
        $invoice = creditTwoLineInvoice();
        app(IssueCreditNote::class)->storno($this->admin, $invoice);
        $sequences = creditSequences();

        foreach ([fn () => app(IssueCreditNote::class)->storno($this->admin, $invoice), fn () => app(IssueCreditNote::class)->partial($this->admin, $invoice, [creditLines($invoice)[0]->id => '1'])] as $attempt) {
            expect(creditRefusal($attempt)['invoice'][0])->toBe(__('invoices.errors.credit_not_allowed'));
        }

        expect(Invoice::query()->where('kind', 'credit_note')->count())->toBe(1)
            ->and(creditSequences())->toBe($sequences);

        RawSql::expectSqlState('23505', static fn () => DB::table('invoices')->insert([
            'client_id' => $invoice->client_id,
            'kind' => 'credit_note',
            'currency' => 'CZK',
            'language' => 'cs',
            'credited_invoice_id' => $invoice->id,
            'cancels_invoice' => true,
        ]));
    });

    it('refuses a proforma, a credit note and a draft', function (): void {
        $client = InvoiceFixtures::client();
        $proforma = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Advance', '1', '500.00')], InvoiceKind::Proforma);
        $note = InvoiceFixtures::stornoed(InvoiceFixtures::issued($client));
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Draft work', '1', '500.00')]);
        $this->actingAs($this->admin);

        foreach ([$proforma, $note, $draft] as $document) {
            expect(creditRefusal(fn () => app(IssueCreditNote::class)->storno($this->admin, $document))['invoice'][0])->toBe(__('invoices.errors.credit_not_allowed'));
            expect(creditRefusal(fn () => app(IssueCreditNote::class)->partial($this->admin, $document, ['x' => '1']))['invoice'][0])->toBe(__('invoices.errors.credit_not_allowed'));
        }
    });

    it('refuses a Partner and writes nothing', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $partner = Canary::partnerFor((string) $client->id);
        $this->actingAs($partner);

        expect(fn () => app(IssueCreditNote::class)->storno($partner, $invoice))->toThrow(AuthorizationException::class)
            ->and(fn () => app(IssueCreditNote::class)->partial($partner, $invoice, ['x' => '1']))->toThrow(AuthorizationException::class);

        $this->actingAs($this->admin);

        expect(Invoice::query()->where('kind', 'credit_note')->count())->toBe(0);
    });
});

describe('a foreign currency invoice', function (): void {
    it('copies the CNB rate of the invoice and does not look the rate up again', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $client = InvoiceFixtures::client(['currency' => 'EUR']);
        $invoice = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Consulting', '3', '100.00')]);
        $original = creditSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $invoice->id)->firstOrFail());

        // Another day, and no CNB list of the currency for it: a fresh lookup would fail.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00', 'Europe/Prague'));

        $credit = app(IssueCreditNote::class)->storno($this->admin, $invoice);
        $admin = creditSystem(static fn (): InvoiceAdmin => InvoiceAdmin::query()->where('invoice_id', $credit->id)->firstOrFail());

        expect($original->czk_total_minor)->toBe(753750)
            ->and($admin->exchange_rate)->toBe($original->exchange_rate)
            ->and($admin->exchange_unit_amount)->toBe($original->exchange_unit_amount)
            ->and($admin->exchange_rate_date?->format('Y-m-d'))->toBe('2026-10-14')
            ->and($admin->czk_total_minor)->toBe(-753750)
            ->and($credit->issue_date?->format('Y-m-d'))->toBe('2026-10-20')
            ->and($credit->bank_snapshot)->toBeNull();
    });

    it('credits a converted line with its amount rounded once, not the printed unit price times the quantity', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $client = InvoiceFixtures::client();
        $project = app(CreateProject::class)->handle($client, [
            'name' => 'Converted credit project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '1500',
        ]);
        $factory = TimeEntry::factory()->forProject($project);
        $start = CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC');
        $entry = creditSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => $start,
            'ended_at' => $start->addHours(10),
        ])->id));

        $invoice = creditIssuedFromTime($client, [$project], 'EUR');
        [$converted] = creditLines($invoice);

        expect($converted->unit_price_minor)->toBe(5970)
            ->and($converted->line_total_minor)->toBe(59701)
            ->and($converted->price_converted)->toBeTrue();

        // A partial credit of 7,3333 h: 1 500 CZK x 7,3333 / 25,125 is 437,8090 EUR, rounded once to 437,81;
        // the printed price 59,70 x 7,3333 would give 437,80.
        $partial = app(IssueCreditNote::class)->partial($this->admin, $invoice, [$converted->id => '7.3333']);
        [$line] = creditLines($partial);
        $expected = Money::ofMinor(150000, 'CZK')->timesThroughCzk('-7.3333', '1', 1, 'EUR', '25.125', 1);

        expect($expected->minor)->toBe(-43781)
            ->and($line->line_total_minor)->toBe(-43781)
            ->and($line->unit_price_minor)->toBe(5970)
            ->and($line->quantity)->toBe('-7.3333')
            ->and($line->price_converted)->toBeTrue()
            ->and($line->source?->conversion_snapshot)->toBe($converted->source?->conversion_snapshot)
            ->and($partial->total_minor)->toBe(-43781)
            ->and(creditFreshEntry($entry)->billing_state)->toBe(BillingState::Billed);

        // The storno takes back the rest of the quantity, and together the two cancel the invoice to the minor unit.
        $storno = app(IssueCreditNote::class)->storno($this->admin, $invoice);
        [$rest] = creditLines($storno);

        expect($rest->quantity)->toBe('-2.6667')
            ->and($rest->price_converted)->toBeTrue()
            ->and(creditFreshEntry($entry)->billing_state)->toBe(BillingState::Unbilled);
    });

    it('credits the whole converted line to exactly minus the original amount', function (): void {
        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $client = InvoiceFixtures::client();
        $project = app(CreateProject::class)->handle($client, [
            'name' => 'Whole credit project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '1500',
        ]);
        $factory = TimeEntry::factory()->forProject($project);
        $start = CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC');
        creditSystem(static fn (): TimeEntry => TimeEntry::query()->findOrFail($factory->create([
            'user_id' => test()->admin->id,
            'started_at' => $start,
            'ended_at' => $start->addHours(10),
        ])->id));

        $invoice = creditIssuedFromTime($client, [$project], 'EUR');
        $storno = app(IssueCreditNote::class)->storno($this->admin, $invoice);
        [$line] = creditLines($storno);

        expect($line->quantity)->toBe('-10.0000')
            ->and($line->unit_price_minor)->toBe(5970)
            ->and($line->line_total_minor)->toBe(-59701)
            ->and($line->price_converted)->toBeTrue()
            ->and($storno->total_minor)->toBe(-$invoice->total_minor)
            ->and($storno->total_minor)->toBe(-59701);
    });
});

describe('the storno is atomic', function (): void {
    it('leaves nothing when the PDF cannot be rendered: no credit note, no number, still billed, not cancelled', function (): void {
        ['client' => $client, 'projects' => $projects, 'entries' => $entries] = creditTimeSetup();
        $invoice = creditIssuedFromTime($client, $projects);
        $sequences = creditSequences();
        $files = Storage::disk('local')->allFiles();
        $rowBefore = creditRawRow($invoice);

        View::composer('pdf.invoice', static function (): void {
            throw new RuntimeException('The rendering failed.');
        });

        expect(fn () => app(IssueCreditNote::class)->storno($this->admin, $invoice))->toThrow(RuntimeException::class, 'The rendering failed.');

        expect(Invoice::query()->where('kind', 'credit_note')->count())->toBe(0)
            ->and(creditSequences())->toBe($sequences)
            ->and(Storage::disk('local')->allFiles())->toBe($files)
            ->and(creditStored($invoice)->isCancelled())->toBeFalse()
            ->and(creditRawRow($invoice))->toBe($rowBefore);

        foreach ($entries as $entry) {
            expect(creditFreshEntry($entry)->billing_state)->toBe(BillingState::Billed)
                ->and(creditFreshEntry($entry)->invoice_id)->toBe($invoice->id);
        }
    });
});
