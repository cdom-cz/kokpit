<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\InvoiceKind;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Canary;
use Tests\Support\InvoiceFixtures;
use Tests\Support\RawSql;

/*
 * The cash receipt table (PPD; Phase 15, plan 15-02; D-06, D-07, D-08, D-09, D-10, D-13), proved against raw SQL so the
 * rules hold for every writer. A receipt belongs to one cash payment of an issued proforma, is edited while open, locks
 * and unlocks only through the sanctioned setting, is cancelled once, is never deleted, and the payment of a receipt
 * that is not cancelled cannot be deleted. Names, numbers and amounts are invented.
 */

/**
 * An issued proforma of a fresh client, read back by raw SQL.
 *
 * @return array{id: string, client_id: string, number: string, currency: string}
 */
function cashReceiptsTableProforma(): array
{
    $proforma = InvoiceFixtures::issued(InvoiceFixtures::client(), [], InvoiceKind::Proforma);

    /** @var object{id: string, client_id: string, number: string, currency: string} $row */
    $row = DB::selectOne('select id, client_id, number, currency from invoices where id = ?', [$proforma->id]);

    return ['id' => $row->id, 'client_id' => $row->client_id, 'number' => $row->number, 'currency' => $row->currency];
}

/**
 * Inserts one payment of the document by raw SQL and returns its id.
 *
 * @param  array{id: string, currency: string}  $document
 */
function cashReceiptsTablePayment(array $document, string $method = 'cash', int $amountMinor = 40000): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into invoice_payments (invoice_id, currency, amount_minor, paid_on, method, created_by, created_at, updated_at) values (?, ?, ?, '2026-10-20', ?, ?, now(), now()) returning id",
        [$document['id'], $document['currency'], $amountMinor, $method, Canary::admin()->id],
    );

    return $inserted->id;
}

/**
 * The columns of a receipt row for the payment, as the issue will write them. Overrides replace single columns.
 *
 * @param  array{id: string, client_id: string, number: string, currency: string}  $document
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cashReceiptsTableRow(array $document, ?string $paymentId, array $overrides = [], int $amountMinor = 40000): array
{
    static $sequence = 0;
    $sequence++;

    return [
        'invoice_id' => $document['id'],
        'client_id' => $document['client_id'],
        'invoice_payment_id' => $paymentId,
        'number' => 'PPD'.'2026'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT),
        'document_number' => $document['number'],
        'issued_on' => '2026-10-20',
        'received_on' => '2026-10-20',
        'amount_minor' => $amountMinor,
        'currency' => $document['currency'],
        'purpose' => 'Fictional advance for example work',
        'language' => 'cs',
        'supplier_snapshot' => json_encode(['name' => 'Example Supplier s.r.o.']),
        'customer_snapshot' => json_encode(['name' => 'Example Customer a.s.']),
        'appearance_snapshot' => json_encode(['accent' => '#336699']),
        'created_at' => '2026-10-20 10:00:00+00',
        'updated_at' => '2026-10-20 10:00:00+00',
        ...$overrides,
    ];
}

/**
 * Inserts the row by raw SQL and returns the id of the receipt.
 *
 * @param  array<string, mixed>  $row
 */
function cashReceiptsTableInsert(array $row): string
{
    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into cash_receipts (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * A proforma with one cash payment and its open receipt.
 *
 * @return array{document: array{id: string, client_id: string, number: string, currency: string}, payment: string, receipt: string}
 */
function cashReceiptsTableSetup(): array
{
    $document = cashReceiptsTableProforma();
    $payment = cashReceiptsTablePayment($document);

    return ['document' => $document, 'payment' => $payment, 'receipt' => cashReceiptsTableInsert(cashReceiptsTableRow($document, $payment))];
}

/**
 * A proforma with one cash payment and a locked receipt, locked the way the confirmation send does.
 *
 * @return array{document: array{id: string, client_id: string, number: string, currency: string}, payment: string, receipt: string}
 */
function cashReceiptsTableLockedSetup(): array
{
    $setup = cashReceiptsTableSetup();

    DB::update("update cash_receipts set lock_state = 'locked', updated_at = now() where id = ?", [$setup['receipt']]);

    return $setup;
}

/**
 * Runs the statement under the transaction-local unlock setting naming the given receipt, then clears the setting so
 * it cannot leak into the next statement of the test transaction.
 */
function cashReceiptsTableUnderUnlockSetting(string $settingFor, Closure $statement): void
{
    DB::select("select set_config('kokpit.cash_receipt_unlock', ?, true)", [$settingFor]);

    $statement();

    DB::select("select set_config('kokpit.cash_receipt_unlock', '', true)");
}

/**
 * Deletes the payment the way DeletePayment does: the transaction-local flag names it, and the flag is cleared again.
 */
function cashReceiptsTableDeletePayment(string $paymentId): void
{
    DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$paymentId]);
    DB::delete('delete from invoice_payments where id = ?', [$paymentId]);
    DB::select("select set_config('kokpit.payment_deletion', '', true)");
}

/**
 * Inserts one stored file by raw SQL and returns its id.
 */
function cashReceiptsTableFile(string $ownerType, string $ownerId): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into documents (name, mime_type, size_bytes, uploaded_by_id, owner_type, owner_id, created_at, updated_at) values ('Fictional.pdf', 'application/pdf', 1024, ?, ?, ?, now(), now()) returning id",
        [Canary::admin()->id, $ownerType, $ownerId],
    );

    return $inserted->id;
}

/**
 * The stored row of a receipt, read by raw SQL.
 */
function cashReceiptsTableStored(string $receiptId): object
{
    /** @var object $row */
    $row = DB::selectOne('select * from cash_receipts where id = ?', [$receiptId]);

    return $row;
}

describe('the tracer: a receipt for a cash payment', function (): void {
    it('accepts a receipt for a recorded cash payment of an issued proforma, open and not cancelled', function (): void {
        $setup = cashReceiptsTableSetup();
        $stored = cashReceiptsTableStored($setup['receipt']);

        expect($stored->invoice_payment_id)->toBe($setup['payment'])
            ->and($stored->document_number)->toBe($setup['document']['number'])
            ->and($stored->lock_state)->toBe('open')
            ->and($stored->cancelled_at)->toBeNull()
            ->and($stored->pdf_document_id)->toBeNull()
            ->and((int) $stored->amount_minor)->toBe(40000)
            ->and($stored->currency)->toBe($setup['document']['currency']);
    });

    it('refuses a second receipt for the same payment and a second receipt with the same number, both with 23505', function (): void {
        $setup = cashReceiptsTableSetup();
        $stored = cashReceiptsTableStored($setup['receipt']);

        RawSql::expectSqlState('23505', fn () => cashReceiptsTableInsert(cashReceiptsTableRow($setup['document'], $setup['payment'])));

        $second = cashReceiptsTablePayment($setup['document'], 'cash', 25000);

        RawSql::expectSqlState('23505', fn () => cashReceiptsTableInsert(cashReceiptsTableRow($setup['document'], $second, ['number' => $stored->number], 25000)));
        RawSql::expectAllowed(fn () => cashReceiptsTableInsert(cashReceiptsTableRow($setup['document'], $second, [], 25000)));
    });

    it('refuses with KP001 a receipt that does not match its cash payment, and one that is born locked, cancelled or without a payment', function (): void {
        $document = cashReceiptsTableProforma();
        $payment = cashReceiptsTablePayment($document);
        $transfer = cashReceiptsTablePayment($document, 'transfer');

        foreach ([
            'another amount' => cashReceiptsTableRow($document, $payment, ['amount_minor' => 39999]),
            'another currency' => cashReceiptsTableRow($document, $payment, ['currency' => 'EUR']),
            'another paid document number' => cashReceiptsTableRow($document, $payment, ['document_number' => 'Z'.'9'.'9'.'9']),
            'a transfer payment' => cashReceiptsTableRow($document, $transfer),
            'born locked' => cashReceiptsTableRow($document, $payment, ['lock_state' => 'locked']),
            'born cancelled' => cashReceiptsTableRow($document, $payment, ['cancelled_at' => '2026-10-21 08:00:00+00']),
            'without a payment' => cashReceiptsTableRow($document, null),
            'a payment of another document' => cashReceiptsTableRow(cashReceiptsTableProforma(), $payment),
        ] as $case => $row) {
            RawSql::expectSqlState('KP001', fn () => cashReceiptsTableInsert($row));
        }

        expect((int) DB::selectOne('select count(*) as n from cash_receipts where invoice_id = ?', [$document['id']])->n)->toBe(0);
    });

    it('refuses with 23514 a blank number, a blank paid document number, a blank purpose and a receipt date after the issue day, and with 23503 another client', function (): void {
        $document = cashReceiptsTableProforma();
        $payment = cashReceiptsTablePayment($document);

        foreach ([
            ['number' => '  '],
            ['document_number' => ''],
            ['purpose' => ' '],
            ['received_on' => '2026-10-21'],
            ['language' => 'de'],
        ] as $override) {
            RawSql::expectSqlState('23514', fn () => cashReceiptsTableInsert(cashReceiptsTableRow($document, $payment, $override)));
        }

        $other = cashReceiptsTableProforma();

        RawSql::expectSqlState('23503', fn () => cashReceiptsTableInsert(cashReceiptsTableRow($document, $payment, ['client_id' => $other['client_id']])));
        RawSql::expectAllowed(fn () => cashReceiptsTableInsert(cashReceiptsTableRow($document, $payment)));
    });
});

describe('the identity of a receipt', function (): void {
    it('refuses with KP001 a write to the number, the paid document number, the amount, the currency, the issue day, the language, the appearance and the links to the invoice and client', function (): void {
        $setup = cashReceiptsTableSetup();
        $other = cashReceiptsTableProforma();

        foreach ([
            ["number = 'PPD'||'777'", []],
            ["document_number = 'Z'||'9999'", []],
            ['amount_minor = 1', []],
            ["currency = 'EUR'", []],
            ["issued_on = '2026-10-21'", []],
            ["language = 'en'", []],
            ['appearance_snapshot = \'{"accent":"#000000"}\'', []],
            ['invoice_id = ?', [$other['id']]],
            ['client_id = ?', [$other['client_id']]],
            ["created_at = created_at - interval '1 day'", []],
        ] as [$assignment, $bindings]) {
            RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set {$assignment} where id = ?", [...$bindings, $setup['receipt']]));
        }

        $stored = cashReceiptsTableStored($setup['receipt']);
        expect((int) $stored->amount_minor)->toBe(40000)
            ->and($stored->language)->toBe('cs');
    });

    it('is never deleted and the table is never truncated', function (): void {
        $setup = cashReceiptsTableSetup();

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from cash_receipts where id = ?', [$setup['receipt']]));
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE cash_receipts'));

        expect((int) DB::selectOne('select count(*) as n from cash_receipts where id = ?', [$setup['receipt']])->n)->toBe(1);
    });
});

describe('an open receipt', function (): void {
    it('accepts a change of the receipt date, the purpose and the supplier and customer snapshots, and refuses a receipt date after the issue day', function (): void {
        $setup = cashReceiptsTableSetup();

        foreach ([
            "received_on = '2026-10-19'",
            "purpose = 'Corrected purpose'",
            'customer_snapshot = \'{"name":"Corrected Customer"}\'',
            'supplier_snapshot = \'{"name":"Corrected Supplier"}\'',
            'updated_at = now()',
        ] as $assignment) {
            RawSql::expectAllowed(fn () => DB::update("update cash_receipts set {$assignment} where id = ?", [$setup['receipt']]));
        }

        RawSql::expectSqlState('23514', fn () => DB::update("update cash_receipts set received_on = '2026-10-21' where id = ?", [$setup['receipt']]));
        RawSql::expectSqlState('23514', fn () => DB::update("update cash_receipts set purpose = '' where id = ?", [$setup['receipt']]));
    });

    it('moves to locked freely, and to no other state', function (): void {
        $setup = cashReceiptsTableSetup();

        RawSql::expectSqlState('23514', fn () => DB::update("update cash_receipts set lock_state = 'sealed' where id = ?", [$setup['receipt']]));
        RawSql::expectAllowed(fn () => DB::update("update cash_receipts set lock_state = 'locked', updated_at = now() where id = ?", [$setup['receipt']]));

        expect(cashReceiptsTableStored($setup['receipt'])->lock_state)->toBe('locked');
    });
});

describe('a locked receipt and the unlock', function (): void {
    it('refuses every edit with KP001, and accepts only a timestamp or PDF link write', function (): void {
        $setup = cashReceiptsTableLockedSetup();

        foreach ([
            "purpose = 'Corrected purpose'",
            "received_on = '2026-10-19'",
            'customer_snapshot = \'{"name":"Corrected Customer"}\'',
            'supplier_snapshot = \'{"name":"Corrected Supplier"}\'',
            "number = 'PPD'||'777'",
        ] as $assignment) {
            RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set {$assignment} where id = ?", [$setup['receipt']]));
        }

        RawSql::expectAllowed(fn () => DB::update("update cash_receipts set updated_at = updated_at + interval '1 second' where id = ?", [$setup['receipt']]));
    });

    it('refuses to open without the setting, with the setting of another receipt, or with another column changed', function (): void {
        $setup = cashReceiptsTableLockedSetup();
        $other = cashReceiptsTableLockedSetup();

        RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set lock_state = 'open' where id = ?", [$setup['receipt']]));
        RawSql::expectSqlState('KP001', fn () => cashReceiptsTableUnderUnlockSetting($other['receipt'], fn () => DB::update("update cash_receipts set lock_state = 'open' where id = ?", [$setup['receipt']])));
        RawSql::expectSqlState('KP001', fn () => cashReceiptsTableUnderUnlockSetting($setup['receipt'], fn () => DB::update("update cash_receipts set lock_state = 'open', purpose = 'Sneaked in' where id = ?", [$setup['receipt']])));

        expect(cashReceiptsTableStored($setup['receipt'])->lock_state)->toBe('locked');
    });

    it('opens under the setting that names it, and the receipt is editable afterwards', function (): void {
        $setup = cashReceiptsTableLockedSetup();

        RawSql::expectAllowed(fn () => cashReceiptsTableUnderUnlockSetting($setup['receipt'], fn () => DB::update("update cash_receipts set lock_state = 'open', updated_at = now() where id = ?", [$setup['receipt']])));

        expect(cashReceiptsTableStored($setup['receipt'])->lock_state)->toBe('open');

        RawSql::expectAllowed(fn () => DB::update("update cash_receipts set purpose = 'Corrected after the unlock', updated_at = now() where id = ?", [$setup['receipt']]));
    });
});

describe('the cancel', function (): void {
    it('cancels a locked receipt once, never restores it, and refuses every edit of a cancelled receipt', function (): void {
        $setup = cashReceiptsTableLockedSetup();

        RawSql::expectAllowed(fn () => DB::update("update cash_receipts set cancelled_at = '2026-10-21 08:00:00+00', updated_at = now() where id = ?", [$setup['receipt']]));

        RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set cancelled_at = '2026-10-22 08:00:00+00' where id = ?", [$setup['receipt']]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update cash_receipts set cancelled_at = null where id = ?', [$setup['receipt']]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set purpose = 'Late edit' where id = ?", [$setup['receipt']]));
        RawSql::expectSqlState('KP001', fn () => cashReceiptsTableUnderUnlockSetting($setup['receipt'], fn () => DB::update("update cash_receipts set lock_state = 'open' where id = ?", [$setup['receipt']])));

        expect(cashReceiptsTableStored($setup['receipt'])->cancelled_at)->not->toBeNull();
    });

    it('cancels an open receipt too, and only with nothing else changing', function (): void {
        $setup = cashReceiptsTableSetup();

        RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set cancelled_at = '2026-10-21 08:00:00+00', purpose = 'Sneaked in' where id = ?", [$setup['receipt']]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update cash_receipts set cancelled_at = '2026-10-21 08:00:00+00', lock_state = 'locked' where id = ?", [$setup['receipt']]));
        RawSql::expectAllowed(fn () => DB::update("update cash_receipts set cancelled_at = '2026-10-21 08:00:00+00', updated_at = now() where id = ?", [$setup['receipt']]));

        expect(cashReceiptsTableStored($setup['receipt'])->purpose)->toBe('Fictional advance for example work');
    });
});

describe('the payment of a receipt (D-08)', function (): void {
    it('cannot be deleted while the receipt is not cancelled, in either lock state, and nothing changes', function (): void {
        foreach (['open' => cashReceiptsTableSetup(), 'locked' => cashReceiptsTableLockedSetup()] as $setup) {
            RawSql::expectSqlState('23514', fn () => cashReceiptsTableDeletePayment($setup['payment']));

            $stored = cashReceiptsTableStored($setup['receipt']);

            expect($stored->invoice_payment_id)->toBe($setup['payment'])
                ->and((int) DB::selectOne('select count(*) as n from invoice_payments where id = ?', [$setup['payment']])->n)->toBe(1);
        }
    });

    it('can be deleted after the cancel, and the cancelled receipt stays with its number and an empty payment link', function (): void {
        foreach (['open' => cashReceiptsTableSetup(), 'locked' => cashReceiptsTableLockedSetup()] as $setup) {
            $number = cashReceiptsTableStored($setup['receipt'])->number;

            DB::update("update cash_receipts set cancelled_at = '2026-10-21 08:00:00+00', updated_at = now() where id = ?", [$setup['receipt']]);

            RawSql::expectAllowed(fn () => cashReceiptsTableDeletePayment($setup['payment']));

            $stored = cashReceiptsTableStored($setup['receipt']);

            expect($stored->invoice_payment_id)->toBeNull()
                ->and($stored->number)->toBe($number)
                ->and($stored->cancelled_at)->not->toBeNull()
                ->and((int) DB::selectOne('select count(*) as n from invoice_payments where id = ?', [$setup['payment']])->n)->toBe(0);
        }
    });

    it('is not deletable without the sanctioned deletion flag either, and the link never moves to another payment', function (): void {
        $setup = cashReceiptsTableSetup();
        $other = cashReceiptsTablePayment($setup['document'], 'cash', 25000);

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_payments where id = ?', [$setup['payment']]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update cash_receipts set invoice_payment_id = ? where id = ?', [$other, $setup['receipt']]));
        RawSql::expectSqlState('23514', fn () => DB::update('update cash_receipts set invoice_payment_id = null where id = ?', [$setup['receipt']]));
    });
});

describe('the PDF link', function (): void {
    it('names only a file owned by its own receipt, is never repointed and is cleared only with its file', function (): void {
        $setup = cashReceiptsTableSetup();
        $receipt = $setup['receipt'];

        $invoiceFile = cashReceiptsTableFile('invoice', $setup['document']['id']);
        $foreignFile = cashReceiptsTableFile('cash_receipt', $setup['payment']);
        $ownFile = cashReceiptsTableFile('cash_receipt', $receipt);
        $secondOwnFile = cashReceiptsTableFile('cash_receipt', $receipt);

        RawSql::expectSqlState('KP001', fn () => DB::update('update cash_receipts set pdf_document_id = ? where id = ?', [$invoiceFile, $receipt]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update cash_receipts set pdf_document_id = ? where id = ?', [$foreignFile, $receipt]));
        RawSql::expectAllowed(fn () => DB::update('update cash_receipts set pdf_document_id = ?, updated_at = now() where id = ?', [$ownFile, $receipt]));

        RawSql::expectSqlState('KP001', fn () => DB::update('update cash_receipts set pdf_document_id = ? where id = ?', [$secondOwnFile, $receipt]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update cash_receipts set pdf_document_id = null where id = ?', [$receipt]));

        RawSql::expectAllowed(fn () => DB::delete('delete from documents where id = ?', [$ownFile]));

        expect(cashReceiptsTableStored($receipt)->pdf_document_id)->toBeNull();
    });

    it('stays relinkable and clearable under a lock and after the cancel', function (): void {
        $setup = cashReceiptsTableLockedSetup();
        $receipt = $setup['receipt'];

        $file = cashReceiptsTableFile('cash_receipt', $receipt);
        RawSql::expectAllowed(fn () => DB::update('update cash_receipts set pdf_document_id = ?, updated_at = now() where id = ?', [$file, $receipt]));
        RawSql::expectAllowed(fn () => DB::delete('delete from documents where id = ?', [$file]));

        DB::update("update cash_receipts set cancelled_at = '2026-10-21 08:00:00+00', updated_at = now() where id = ?", [$receipt]);

        $second = cashReceiptsTableFile('cash_receipt', $receipt);
        RawSql::expectAllowed(fn () => DB::update('update cash_receipts set pdf_document_id = ?, updated_at = now() where id = ?', [$second, $receipt]));
        RawSql::expectAllowed(fn () => DB::delete('delete from documents where id = ?', [$second]));

        expect(cashReceiptsTableStored($receipt)->pdf_document_id)->toBeNull();
    });
});

describe('the pinned design', function (): void {
    it('holds no reason, causer or note column, so no Admin free text sits on the Partner-readable row', function (): void {
        $columns = Schema::getColumnListing('cash_receipts');

        foreach (['reason', 'cancel_reason', 'unlock_reason', 'created_by', 'cancelled_by', 'internal_note', 'note'] as $forbidden) {
            expect($columns)->not->toContain($forbidden);
        }

        expect($columns)->toHaveCount(20);
    });

    it('ties the payment link to its D-08 rule in the catalogue', function (): void {
        /** @var object{definition: string} $check */
        $check = DB::selectOne("select pg_get_constraintdef(oid) as definition from pg_constraint where conname = 'cash_receipts_payment_link_check'");
        /** @var object{definition: string} $foreignKey */
        $foreignKey = DB::selectOne("select pg_get_constraintdef(oid) as definition from pg_constraint where conname = 'cash_receipts_payment_fk'");

        expect(str_replace(['(', ')'], '', $check->definition))->toContain('invoice_payment_id IS NOT NULL OR cancelled_at IS NOT NULL')
            ->and(preg_match('/ON DELETE SET NULL\s*\(?invoice_payment_id\)?/', $foreignKey->definition))->toBe(1);
    });

    it('names the unlock setting inside the row guard and both guards on the table', function (): void {
        /** @var object{definition: string} $function */
        $function = DB::selectOne("select pg_get_functiondef('kokpit_guard_cash_receipt_row'::regproc) as definition");

        expect($function->definition)->toContain('kokpit.cash_receipt_unlock');

        foreach (['cash_receipts_row_guard', 'cash_receipts_pdf_link_guard', 'cash_receipts_truncate_guard'] as $trigger) {
            expect((int) DB::selectOne(
                'select count(*) as n from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = ? and t.tgname = ? and not t.tgisinternal',
                ['cash_receipts', $trigger],
            )->n)->toBe(1, "The trigger {$trigger} is missing.");
        }
    });
});
