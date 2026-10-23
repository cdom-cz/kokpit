<?php

declare(strict_types=1);

use App\Domain\Invoicing\Actions\IssueCreditNote;
use App\Domain\Invoicing\Actions\RecordPayment;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Tests\Support\Canary;
use Tests\Support\DocumentFixtures;
use Tests\Support\InvoiceFixtures;

/*
 * Which operations lock an issued document and which leave the lock as it was (IN-11; D-01, D-02): issuing leaves an
 * invoice or a proforma open, a credit note is locked by its own issue, and a payment, a partial credit note and a
 * storno never touch the lock of the document they concern. Only a successfully sent e-mail locks (see
 * SendInvoiceEmailTest). Every name and amount is fictional.
 */

beforeEach(function (): void {
    DocumentFixtures::fakeDisk();
    InvoiceFixtures::configureSupplier();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * The lock of the document as stored, read as system.
 */
function invoiceLockOf(Invoice $invoice): InvoiceLockState
{
    return app(PartnerContext::class)->runAsSystem(static fn (): InvoiceLockState => Invoice::query()->whereKey($invoice->id)->firstOrFail()->lock_state);
}

/**
 * The id of the first line of the document.
 */
function invoiceLockFirstLine(Invoice $invoice): string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): string => InvoiceItem::query()->where('invoice_id', $invoice->id)->orderBy('position')->firstOrFail()->id);
}

describe('issuing', function (): void {
    it('leaves an issued invoice open', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        expect($invoice->lock_state)->toBe(InvoiceLockState::Open)
            ->and(invoiceLockOf($invoice))->toBe(InvoiceLockState::Open);
    });

    it('leaves an issued proforma open', function (): void {
        $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Advance', '1', '5000.00', 'pcs')], InvoiceKind::Proforma);

        expect($proforma->lock_state)->toBe(InvoiceLockState::Open)
            ->and(invoiceLockOf($proforma))->toBe(InvoiceLockState::Open);
    });

    it('locks a credit note by its own issue, a partial one and a storno', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '4', '100.00')]);

        $partial = app(IssueCreditNote::class)->partial($this->admin, $invoice, [invoiceLockFirstLine($invoice) => '1']);

        expect($partial->kind)->toBe(InvoiceKind::CreditNote)
            ->and($partial->lock_state)->toBe(InvoiceLockState::Locked)
            ->and(invoiceLockOf($partial))->toBe(InvoiceLockState::Locked);

        $other = InvoiceFixtures::issued(InvoiceFixtures::client(), [InvoiceFixtures::item('Work', '2', '100.00')]);
        $storno = app(IssueCreditNote::class)->storno($this->admin, $other);

        expect($storno->cancels_invoice)->toBeTrue()
            ->and($storno->lock_state)->toBe(InvoiceLockState::Locked)
            ->and(invoiceLockOf($storno))->toBe(InvoiceLockState::Locked);
    });
});

describe('what leaves the lock of the document as it was (D-01)', function (): void {
    it('keeps an open invoice open and a locked one locked through a payment', function (bool $locked): void {
        $client = InvoiceFixtures::client();
        $invoice = $locked ? InvoiceFixtures::lockedIssued($client) : InvoiceFixtures::issued($client);
        $expected = $locked ? InvoiceLockState::Locked : InvoiceLockState::Open;

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '300.00', 'paid_on' => '2026-10-13']);

        expect(invoiceLockOf($invoice))->toBe($expected);

        app(RecordPayment::class)->handle($this->admin, $invoice, ['amount' => '700.00', 'paid_on' => '2026-10-14']);

        expect(invoiceLockOf($invoice))->toBe($expected);
    })->with(['open' => [false], 'locked' => [true]]);

    it('keeps the lock of the invoice through a partial credit note', function (bool $locked): void {
        $client = InvoiceFixtures::client();
        $items = [InvoiceFixtures::item('Work', '4', '100.00')];
        $invoice = $locked ? InvoiceFixtures::lockedIssued($client, $items) : InvoiceFixtures::issued($client, $items);
        $expected = $locked ? InvoiceLockState::Locked : InvoiceLockState::Open;

        app(IssueCreditNote::class)->partial($this->admin, $invoice, [invoiceLockFirstLine($invoice) => '1']);

        expect(invoiceLockOf($invoice))->toBe($expected);
    })->with(['open' => [false], 'locked' => [true]]);

    it('keeps the lock of the invoice through a storno', function (bool $locked): void {
        $client = InvoiceFixtures::client();
        $items = [InvoiceFixtures::item('Work', '4', '100.00')];
        $invoice = $locked ? InvoiceFixtures::lockedIssued($client, $items) : InvoiceFixtures::issued($client, $items);
        $expected = $locked ? InvoiceLockState::Locked : InvoiceLockState::Open;

        app(IssueCreditNote::class)->storno($this->admin, $invoice);

        expect(invoiceLockOf($invoice))->toBe($expected)
            ->and(app(PartnerContext::class)->runAsSystem(static fn (): bool => Invoice::query()->whereKey($invoice->id)->firstOrFail()->isCancelled()))->toBeTrue();
    })->with(['open' => [false], 'locked' => [true]]);
});
