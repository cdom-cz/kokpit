<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Support\Facades\DB;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The send-driven lock of an issued document (IN-03 amended, IN-11; D-01, D-12, D-08), proved against raw SQL so the
 * rule holds for every writer. An issued invoice or proforma is open until a successfully sent e-mail locks it; a
 * locked document and its children refuse every write with KP001. Names, numbers and amounts are invented.
 */

/**
 * The id of the first item of the document, read by raw SQL.
 */
function invoiceLockFirstItem(string $invoiceId): string
{
    /** @var object{id: string} $item */
    $item = DB::selectOne('select id from invoice_items where invoice_id = ? order by position limit 1', [$invoiceId]);

    return $item->id;
}

describe('the tracer: issue, open, lock', function (): void {
    it('leaves a freshly issued invoice open, and accepts a raw correction of the document and of its line', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $itemId = invoiceLockFirstItem($invoice->id);

        expect($invoice->lock_state)->toBe(InvoiceLockState::Open)
            ->and($invoice->isLocked())->toBeFalse();

        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'Corrected note', updated_at = now() where id = ?", [$invoice->id]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_items set description = 'Corrected work', updated_at = now() where id = ?", [$itemId]));
    });

    it('refuses the same two statements with KP001 once the document is locked', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        $itemId = invoiceLockFirstItem($invoice->id);

        expect($invoice->lock_state)->toBe(InvoiceLockState::Locked)
            ->and($invoice->isLocked())->toBeTrue();

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set note = 'Corrected note', updated_at = now() where id = ?", [$invoice->id]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_items set description = 'Corrected work', updated_at = now() where id = ?", [$itemId]));
    });

    it('locks a document through the fixture without any other change to the row', function (): void {
        $open = InvoiceFixtures::issued(InvoiceFixtures::client());
        $locked = InvoiceFixtures::lock($open);

        expect($locked->lock_state)->toBe(InvoiceLockState::Locked)
            ->and($locked->number)->toBe($open->number)
            ->and($locked->total_minor)->toBe($open->total_minor)
            ->and(Invoice::query()->whereKey($open->id)->value('lock_state'))->toBe(InvoiceLockState::Locked);
    });
});
