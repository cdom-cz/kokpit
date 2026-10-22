<?php

declare(strict_types=1);

use App\Domain\Documents\Models\Document;
use App\Domain\Invoicing\Actions\UpdateIssuedDocument;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Jobs\SendInvoiceEmail;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Media;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
