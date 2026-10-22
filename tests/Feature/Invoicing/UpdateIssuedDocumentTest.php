<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\ConvertProforma;
use App\Domain\Invoicing\Actions\CreateDraft;
use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\IssueDocument;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * The write path of an open issued document (IN-13, IN-14; D-04, D-05, D-09, D-10): the items and the printed note are
 * corrected under the invoice row lock, every amount is computed on the server, the stored PDF is replaced and nothing
 * is sent. Every name, number and amount is fictional.
 */

/**
 * Runs the callback as a system run (all rows visible, nothing filtered).
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function issuedEditSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * The lines of the document in order, read fresh.
 *
 * @return list<InvoiceItem>
 */
function issuedEditLines(Invoice $invoice): array
{
    return array_values(issuedEditSystem(static fn (): array => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->get()->all()));
}

/**
 * The editor payload of a stored line with the given overrides.
 *
 * @param  array<string, string>  $overrides
 * @return array<string, string>
 */
function issuedEditPayload(InvoiceItem $line, array $overrides = []): array
{
    return [
        'id' => $line->id,
        'description' => $line->description,
        'quantity' => $line->quantity,
        'unit' => $line->unit,
        'unit_price' => issuedEditPrice($line),
        ...$overrides,
    ];
}

/**
 * The unit price of a line as the editor sends it.
 */
function issuedEditPrice(InvoiceItem $line): string
{
    return $line->unitPrice()->toMajor();
}

/**
 * The document row, read fresh.
 */
function issuedEditRow(Invoice $invoice): Invoice
{
    return issuedEditSystem(static fn (): Invoice => Invoice::query()->whereKey($invoice->id)->firstOrFail());
}

/**
 * The stored PDF document of the invoice, read fresh.
 */
function issuedEditPdf(Invoice $invoice): ?Document
{
    return issuedEditSystem(static function () use ($invoice): ?Document {
        $id = DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id');

        return $id === null ? null : Document::query()->whereKey($id)->first();
    });
}

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 09:30:00', 'Europe/Prague'));
});

describe('correcting an open issued invoice', function (): void {
    it('recomputes the totals on the server, replaces the PDF, keeps the document open and sends nothing', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [
            InvoiceFixtures::item('Design', '2', '500.00'),
            InvoiceFixtures::item('Review', '1', '250.00'),
        ]);
        [$first, $second] = issuedEditLines($issued);
        $oldPdf = issuedEditPdf($issued);
        $number = $issued->number;

        Queue::fake();

        $updated = app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, [
            'items' => [
                issuedEditPayload($first, ['quantity' => '3']),
                issuedEditPayload($second),
            ],
            'note' => 'Corrected quantity of the design work.',
        ]);

        $lines = issuedEditLines($issued);
        $row = issuedEditRow($issued);
        $newPdf = issuedEditPdf($issued);
        $documents = issuedEditSystem(static fn (): int => Document::query()->where('owner_id', $issued->id)->count());

        expect($lines[0]->id)->toBe($first->id)
            ->and($lines[0]->line_total_minor)->toBe(150000)
            ->and($lines[1]->line_total_minor)->toBe(25000)
            ->and($row->subtotal_minor)->toBe(175000)
            ->and($row->total_minor)->toBe(175000)
            ->and($updated->total_minor)->toBe(175000)
            ->and($row->note)->toBe('Corrected quantity of the design work.')
            ->and($row->state)->toBe(InvoiceState::Issued)
            ->and($row->lock_state)->toBe(InvoiceLockState::Open)
            ->and($row->number)->toBe($number)
            ->and($newPdf)->toBeInstanceOf(Document::class)
            ->and($newPdf?->id)->not->toBe($oldPdf?->id)
            ->and($newPdf?->owner_id)->toBe($issued->id)
            ->and($documents)->toBe(1)
            ->and(issuedEditSystem(static fn (): bool => Document::query()->whereKey($oldPdf?->id)->exists()))->toBeFalse()
            ->and(issuedEditSystem(static fn (): int => Media::query()->count()))->toBe(1)
            ->and(DB::table('invoice_emails')->where('invoice_id', $issued->id)->count())->toBe(0);

        Queue::assertNothingPushed();
        Queue::assertNotPushed(SendInvoiceEmail::class);
    });

    it('adds a manual line and deletes another in the same save', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [
            InvoiceFixtures::item('Design', '2', '500.00'),
            InvoiceFixtures::item('Review', '1', '250.00'),
        ]);
        [, $second] = issuedEditLines($issued);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, [
            'items' => [
                InvoiceFixtures::item('Hosting', '4', '100.00', 'month'),
                issuedEditPayload($second, ['description' => 'Review of the draft']),
            ],
        ]);

        $lines = issuedEditLines($issued);

        expect($lines)->toHaveCount(2)
            ->and($lines[0]->description)->toBe('Hosting')
            ->and($lines[0]->position)->toBe(1)
            ->and($lines[0]->line_total_minor)->toBe(40000)
            ->and($lines[1]->id)->toBe($second->id)
            ->and($lines[1]->description)->toBe('Review of the draft')
            ->and($lines[1]->position)->toBe(2)
            ->and(issuedEditRow($issued)->total_minor)->toBe(65000);
    });

    it('rounds a fractional quantity once, half up', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Consulting', '1', '100.00')]);
        [$line] = issuedEditLines($issued);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, [
            'items' => [issuedEditPayload($line, ['quantity' => '1.0050', 'unit_price' => '33.33'])],
        ]);

        expect(issuedEditLines($issued)[0]->line_total_minor)->toBe(3350)
            ->and(issuedEditRow($issued)->total_minor)->toBe(3350);
    });

    it('edits only the note when no items are sent and still replaces the PDF', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        $oldPdf = issuedEditPdf($issued);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, ['note' => 'Thank you.']);

        expect(issuedEditRow($issued)->note)->toBe('Thank you.')
            ->and(issuedEditRow($issued)->total_minor)->toBe(100000)
            ->and(issuedEditPdf($issued)?->id)->not->toBe($oldPdf?->id)
            ->and(Storage::disk('local')->allFiles())->toHaveCount(1);
    });

    it('saves the internal note through its own action', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, ['internal_note' => 'Agreed by phone.']);

        expect(DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('internal_note'))->toBe('Agreed by phone.');
    });

    it('never writes the number, the kind, the state or the lock', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        [$line] = issuedEditLines($issued);
        $before = issuedEditRow($issued);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, [
            'items' => [issuedEditPayload($line, ['quantity' => '5'])],
            'number' => '2099-9999',
            'kind' => 'credit_note',
            'state' => 'draft',
            'lock_state' => 'locked',
            'total_minor' => 1,
        ]);

        $after = issuedEditRow($issued);

        expect($after->number)->toBe($before->number)
            ->and($after->kind)->toBe($before->kind)
            ->and($after->state)->toBe($before->state)
            ->and($after->lock_state)->toBe(InvoiceLockState::Open)
            ->and($after->variable_symbol)->toBe($before->variable_symbol)
            ->and($after->total_minor)->toBe(250000);
    });
});

/**
 * Everything an edit could have touched, read fresh: the row, the lines and the stored PDF link.
 *
 * @return array<string, mixed>
 */
function issuedEditState(Invoice $invoice): array
{
    return issuedEditSystem(static fn (): array => [
        'row' => (array) DB::table('invoices')->where('id', $invoice->id)->first(),
        'lines' => DB::table('invoice_items')->where('invoice_id', $invoice->id)->orderBy('position')->get()->map(static fn (object $row): array => (array) $row)->all(),
        'pdf' => DB::table('invoice_admin')->where('invoice_id', $invoice->id)->value('pdf_document_id'),
        'documents' => Document::query()->where('owner_id', $invoice->id)->count(),
    ]);
}

/**
 * Runs the edit and returns the errors of the refusal, failing when it is not a validation refusal.
 *
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function issuedEditRefusal(Invoice $invoice, array $data): array
{
    try {
        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $invoice, $data);
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    test()->fail('The edit had to be refused.');
}

describe('what the edit refuses', function (): void {
    it('refuses a draft and writes nothing', function (): void {
        $draft = InvoiceFixtures::draft(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '1', '100.00')]);
        $before = issuedEditState($draft);

        expect(issuedEditRefusal($draft, ['note' => 'Changed.']))->toBe(['document' => [__('invoices.errors.not_issued')]])
            ->and(issuedEditState($draft))->toBe($before);
    });

    it('refuses a credit note', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        $credit = InvoiceFixtures::stornoed($issued);
        $before = issuedEditState($credit);

        expect(issuedEditRefusal($credit, ['note' => 'Changed.']))->toBe(['document' => [__('invoices.errors.credit_note_locked')]])
            ->and(issuedEditState($credit))->toBe($before);
    });

    it('refuses a locked invoice', function (): void {
        $locked = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        $before = issuedEditState($locked);

        expect(issuedEditRefusal($locked, ['note' => 'Changed.']))->toBe(['document' => [__('invoices.errors.document_locked')]])
            ->and(issuedEditState($locked))->toBe($before);
    });

    it('refuses a cancelled invoice', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client());
        InvoiceFixtures::stornoed($issued);
        $before = issuedEditState($issued);

        expect(issuedEditRefusal($issued, ['note' => 'Changed.']))->toBe(['document' => [__('invoices.errors.cancelled_not_editable')]])
            ->and(issuedEditState($issued))->toBe($before);
    });

    it('refuses a proforma that has its invoice', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);
        app(RecordPayment::class)->handle(Canary::admin(), $proforma, ['amount' => '1000.00', 'paid_on' => '2026-10-13']);
        app(ConvertProforma::class)->handle(Canary::admin(), $proforma);
        $before = issuedEditState($proforma);

        expect(issuedEditRefusal($proforma, ['note' => 'Changed.']))->toBe(['document' => [__('invoices.errors.converted_not_editable')]])
            ->and(issuedEditState($proforma))->toBe($before);
    });

    it('edits an open proforma that has no invoice yet', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '800.00')], InvoiceKind::Proforma);
        [$line] = issuedEditLines($proforma);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $proforma, ['items' => [issuedEditPayload($line, ['unit_price' => '900.00'])]]);

        expect(issuedEditRow($proforma)->total_minor)->toBe(90000);
    });

    it('answers isEditable with the same rule', function (): void {
        $client = InvoiceFixtures::client();
        $open = InvoiceFixtures::issued($client);
        $locked = InvoiceFixtures::lockedIssued($client);
        $draft = InvoiceFixtures::draft($client, [InvoiceFixtures::item('Design', '1', '100.00')]);

        expect(issuedEditRow($open)->isEditable())->toBeTrue()
            ->and(issuedEditRow($locked)->isEditable())->toBeFalse()
            ->and(issuedEditRow($draft)->isEditable())->toBeFalse();
    });

    it('lets a partial payment and a partial credit note through, no hard block', function (): void {
        $client = InvoiceFixtures::client();
        $paid = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Design', '2', '500.00')]);
        app(RecordPayment::class)->handle(Canary::admin(), $paid, ['amount' => '400.00', 'paid_on' => '2026-10-13']);
        [$paidLine] = issuedEditLines($paid);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $paid, ['items' => [issuedEditPayload($paidLine, ['quantity' => '3'])]]);

        expect(issuedEditRow($paid)->total_minor)->toBe(150000);

        $credited = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Design', '2', '500.00'), InvoiceFixtures::item('Review', '1', '250.00')]);
        [$designLine, $reviewLine] = issuedEditLines($credited);
        app(IssueCreditNote::class)->partial(Canary::admin(), $credited, [$designLine->id => '1']);

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $credited, ['items' => [
            issuedEditPayload($designLine),
            issuedEditPayload($reviewLine, ['quantity' => '2']),
        ]]);

        expect(issuedEditRow($credited)->total_minor)->toBe(150000);
    });

    it('refuses a foreign line id and a duplicate id as a field error and writes nothing', function (): void {
        $client = InvoiceFixtures::client();
        $issued = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Design', '2', '500.00')]);
        $other = InvoiceFixtures::issued($client, [InvoiceFixtures::item('Other work', '1', '300.00')]);
        [$line] = issuedEditLines($issued);
        [$foreign] = issuedEditLines($other);
        $before = issuedEditState($issued);
        $otherBefore = issuedEditState($other);

        expect(issuedEditRefusal($issued, ['items' => [issuedEditPayload($foreign)]]))->toHaveKey('items.0.id')
            ->and(issuedEditRefusal($issued, ['items' => [issuedEditPayload($line), issuedEditPayload($line)]]))->toHaveKey('items.1.id')
            ->and(issuedEditState($issued))->toBe($before)
            ->and(issuedEditState($other))->toBe($otherBefore);
    });

    it('refuses a total that is not positive', function (): void {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00')]);
        [$line] = issuedEditLines($issued);
        $before = issuedEditState($issued);

        expect(issuedEditRefusal($issued, ['items' => [issuedEditPayload($line, ['unit_price' => '0', 'confirm_zero_price' => true])]]))
            ->toBe(['total_not_positive' => [__('invoices.errors.total_not_positive')]])
            ->and(issuedEditState($issued))->toBe($before);
    });
});

describe('a line that a credit note credits', function (): void {
    /**
     * An issued invoice of two lines, the first credited by one unit.
     *
     * @return array{0: Invoice, 1: InvoiceItem, 2: InvoiceItem}
     */
    function issuedEditCredited(): array
    {
        $issued = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Design', '2', '500.00'), InvoiceFixtures::item('Review', '1', '250.00')]);
        [$design, $review] = issuedEditLines($issued);
        app(IssueCreditNote::class)->partial(Canary::admin(), $issued, [$design->id => '1']);

        return [$issued, $design, $review];
    }

    it('keeps its quantity, unit and unit price', function (string $field, string $value): void {
        [$issued, $design, $review] = issuedEditCredited();
        $before = issuedEditState($issued);

        expect(issuedEditRefusal($issued, ['items' => [issuedEditPayload($design, [$field => $value]), issuedEditPayload($review)]]))
            ->toBe(['items.0.quantity' => [__('invoices.errors.credited_line_frozen')]])
            ->and(issuedEditState($issued))->toBe($before);
    })->with([
        'quantity' => ['quantity', '3'],
        'unit' => ['unit', 'pcs'],
        'unit price' => ['unit_price', '450.00'],
    ]);

    it('cannot be deleted', function (): void {
        [$issued, , $review] = issuedEditCredited();
        $before = issuedEditState($issued);

        expect(issuedEditRefusal($issued, ['items' => [issuedEditPayload($review)]]))
            ->toBe(['items' => [__('invoices.errors.credited_line_delete')]])
            ->and(issuedEditState($issued))->toBe($before);
    });

    it('keeps its text editable and the other lines free', function (): void {
        [$issued, $design, $review] = issuedEditCredited();

        app(UpdateIssuedDocument::class)->handle(Canary::admin(), $issued, ['items' => [
            issuedEditPayload($design, ['description' => 'Design of the landing page']),
            issuedEditPayload($review, ['quantity' => '4']),
        ]]);

        $lines = issuedEditLines($issued);

        expect($lines[0]->description)->toBe('Design of the landing page')
            ->and($lines[0]->line_total_minor)->toBe(100000)
            ->and($lines[1]->line_total_minor)->toBe(100000)
            ->and(issuedEditRow($issued)->total_minor)->toBe(200000);
    });
});

describe('a frozen conversion', function (): void {
    it('prices a changed quantity of a converted time line with the quotes of its snapshot, not the lists of today', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);
        $client = InvoiceFixtures::client();
        $project = app(CreateProject::class)->handle($client, [
            'name' => 'Alpha conversion project',
            'key' => Canary::projectKey(),
            'billing_type' => 'hourly',
            'hourly_rate' => '1500',
        ]);
        $start = CarbonImmutable::parse('2026-09-10 08:00:00', 'UTC');
        $factory = TimeEntry::factory()->forProject($project);
        issuedEditSystem(static fn (): TimeEntry => $factory->create(['user_id' => $admin->id, 'started_at' => $start, 'ended_at' => $start->addHours(10)]));

        InvoiceFixtures::cnbRate('EUR', '2026-10-14', '25.125');
        $draft = app(CreateDraft::class)->handle($admin, [
            'client_id' => $client->id,
            'kind' => 'invoice',
            'source' => 'time',
            'period_from' => '2026-09-01',
            'period_to' => '2026-09-30',
            'project_ids' => [$project->id],
            'without_project' => false,
            'grouping' => 'project',
            'currency' => 'EUR',
        ]);
        $issued = app(IssueDocument::class)->handle($admin, $draft);
        [$line] = issuedEditLines($issued);

        // A new list for a later day: a re-price with today's lists would give another amount.
        $this->travelTo(CarbonImmutable::parse('2026-10-20 09:30:00', 'Europe/Prague'));
        InvoiceFixtures::cnbRate('EUR', '2026-10-20', '27.000');

        app(UpdateIssuedDocument::class)->handle($admin, $issued, ['items' => [issuedEditPayload($line, ['quantity' => '3'])]]);

        $expected = Money::ofMinor(150000, 'CZK')->timesThroughCzk('3.0000', '1', 1, 'EUR', '25.125', 1);
        $total = Money::ofMinor($expected->minor, 'EUR')->convert('25.125', 'CZK', 1);
        [$edited] = issuedEditLines($issued);

        expect($edited->line_total_minor)->toBe($expected->minor)
            ->and($edited->price_converted)->toBeTrue()
            ->and($edited->unit_price_minor)->toBe(5970)
            ->and(issuedEditRow($issued)->total_minor)->toBe($expected->minor)
            ->and(DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('czk_total_minor'))->toBe($total->minor)
            ->and((string) DB::table('invoice_admin')->where('invoice_id', $issued->id)->value('exchange_rate'))->toStartWith('25.125');
    });
});
