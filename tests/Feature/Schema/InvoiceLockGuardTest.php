<?php

declare(strict_types=1);

use App\Domain\Invoicing\Enums\DocumentPaymentMethod;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceLockState;
use App\Domain\Invoicing\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Canary;
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

describe('the payment method of a document (Phase 14; D-01, D-02, D-09; T-14-04)', function (): void {
    it('reads transfer on an issued invoice, and accepts a raw switch to online without the bank snapshot while the document is open', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        expect($invoice->payment_method)->toBe(DocumentPaymentMethod::Transfer)
            ->and(DB::selectOne('select payment_method from invoices where id = ?', [$invoice->id])->payment_method)->toBe('transfer');

        RawSql::expectAllowed(fn () => DB::update("update invoices set payment_method = 'online', bank_snapshot = null, updated_at = now() where id = ?", [$invoice->id]));

        expect(DB::selectOne('select payment_method, bank_snapshot from invoices where id = ?', [$invoice->id]))
            ->payment_method->toBe('online')
            ->bank_snapshot->toBeNull();
    });

    it('refuses the same switch with KP001 once the document is locked, and a method change that keeps the snapshot', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set payment_method = 'online', bank_snapshot = null, updated_at = now() where id = ?", [$invoice->id]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set payment_method = 'online' where id = ?", [$invoice->id]));

        expect(DB::selectOne('select payment_method from invoices where id = ?', [$invoice->id])->payment_method)->toBe('transfer');
    });

    it('switches back from online to transfer on an open document when the snapshot is restored', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $snapshot = json_encode($invoice->bank_snapshot);

        DB::update("update invoices set payment_method = 'online', bank_snapshot = null where id = ?", [$invoice->id]);

        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set payment_method = 'transfer' where id = ?", [$invoice->id]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set payment_method = 'transfer', bank_snapshot = ? where id = ?", [$snapshot, $invoice->id]));
    });

    it('keeps a locked online document frozen too', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        DB::update("update invoices set payment_method = 'online', bank_snapshot = null where id = ?", [$invoice->id]);
        InvoiceFixtures::lock($invoice->refresh());

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set payment_method = 'transfer', bank_snapshot = '{\"account\":\"fictional\"}' where id = ?", [$invoice->id]));
    });
});

/*
 * The state x lock matrix, written as raw SQL against documents built through the real issue pipeline.
 */

/**
 * The first row of a child table of the document, by raw SQL.
 */
function invoiceLockChildId(string $table, string $column, string $invoiceId): string
{
    /** @var object{id: string}|null $row */
    $row = DB::selectOne(sprintf('select id from %s where %s = ? limit 1', $table, $column), [$invoiceId]);

    expect($row)->not->toBeNull("The document has no row in {$table}.");

    return $row->id;
}

/**
 * Adds one more line to an open document, by raw SQL, and returns its id.
 */
function invoiceLockAddLine(string $invoiceId, int $position): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into invoice_items (invoice_id, position, description, quantity, unit, unit_price_minor, line_total_minor, created_at, updated_at) values (?, ?, 'Extra work', 1, 'h', 100, 100, now(), now()) returning id",
        [$invoiceId, $position],
    );

    return $inserted->id;
}

/**
 * Runs the statement under the transaction-local unlock setting naming the given document, then clears the setting
 * so it cannot leak into the next statement of the test transaction. A refused statement rolls its savepoint back,
 * and the setting with it, so nothing is cleared on that path.
 */
function invoiceLockUnderUnlockSetting(string $settingFor, Closure $statement): void
{
    DB::select("select set_config('kokpit.invoice_unlock', ?, true)", [$settingFor]);

    $statement();

    DB::select("select set_config('kokpit.invoice_unlock', '', true)");
}

/**
 * A draft credit note naming the invoice, by raw SQL, in the given currency.
 */
function invoiceLockDraftCreditNote(string $invoiceId, string $clientId, string $currency = 'CZK'): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into invoices (client_id, kind, state, currency, language, credited_invoice_id, created_at, updated_at) values (?, 'credit_note', 'draft', ?, 'cs', ?, now(), now()) returning id",
        [$clientId, $currency, $invoiceId],
    );

    return $inserted->id;
}

/**
 * Flips the draft credit note to issued by raw SQL; the number is composed from fragments.
 */
function invoiceLockIssueCreditNote(string $creditId): void
{
    static $sequence = 0;
    $sequence++;

    DB::update(
        "update invoices set state = 'issued', number = ?, issue_date = '2026-10-17', subtotal_minor = -100, total_minor = -100, supplier_snapshot = ?, customer_snapshot = ?, appearance_snapshot = ?, issued_at = now() where id = ?",
        ['9'.'00'.$sequence, json_encode(['name' => 'Example Supplier s.r.o.']), json_encode(['name' => 'Example Customer a.s.']), json_encode(['accent' => '#336699']), $creditId],
    );
}

/**
 * The lock of a document as stored, read by raw SQL.
 */
function invoiceLockStored(string $invoiceId): string
{
    /** @var object{lock_state: string} $row */
    $row = DB::selectOne('select lock_state from invoices where id = ?', [$invoiceId]);

    return $row->lock_state;
}

describe('an open issued invoice', function (): void {
    it('accepts a write to every open column', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        foreach ([
            "currency = 'EUR'",
            "language = 'en'",
            "issue_date = '2026-10-10'",
            "taxable_supply_date = '2026-10-10'",
            "due_date = '2026-10-31'",
            'subtotal_minor = 123400, total_minor = 123400',
            "note = 'Corrected note'",
            'supplier_snapshot = \'{"name":"Corrected Supplier"}\'',
            'customer_snapshot = \'{"name":"Corrected Customer"}\'',
            'bank_snapshot = \'{"account":"corrected"}\'',
            'updated_at = now()',
        ] as $assignment) {
            RawSql::expectAllowed(fn () => DB::update("update invoices set {$assignment} where id = ?", [$invoice->id]));
        }
    });

    it('refuses a write to the number, kind and identity of the document', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $other = InvoiceFixtures::client();
        $proforma = InvoiceFixtures::issued($client, [], InvoiceKind::Proforma);

        foreach ([
            ["number = '9999'||'0001'", []],
            ["variable_symbol = '9999'||'0001'", []],
            ["kind = 'proforma'", []],
            ["state = 'draft'", []],
            ['client_id = ?', [$other->id]],
            ["vat_mode = 'payer'", []],
            ['appearance_snapshot = \'{"accent":"#000000"}\'', []],
            ['proforma_id = ?', [$proforma->id]],
            ['credited_invoice_id = ?', [$proforma->id]],
            ['cancels_invoice = true', []],
            ["issued_at = now() - interval '1 day'", []],
            ["created_at = now() - interval '1 day'", []],
        ] as [$assignment, $bindings]) {
            RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set {$assignment} where id = ?", [...$bindings, $invoice->id]));
        }
    });

    it('is never deleted, and TRUNCATE of the four tables stays refused', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoices where id = ?', [$invoice->id]));

        foreach (['invoices', 'invoice_items', 'invoice_item_sources', 'invoice_admin'] as $table) {
            RawSql::expectSqlState('KP001', fn () => DB::unprepared("TRUNCATE {$table} CASCADE"));
        }

        expect((int) DB::selectOne('select count(*) as n from invoices where id = ?', [$invoice->id])->n)->toBe(1);
    });

    it('accepts its items, their sources and the CNB figures of its Admin-only row to be written', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());
        $itemId = invoiceLockChildId('invoice_items', 'invoice_id', $invoice->id);
        $sourceId = invoiceLockChildId('invoice_item_sources', 'invoice_id', $invoice->id);
        $adminId = invoiceLockChildId('invoice_admin', 'invoice_id', $invoice->id);

        RawSql::expectAllowed(fn () => invoiceLockAddLine($invoice->id, 9));
        RawSql::expectAllowed(fn () => DB::update("update invoice_items set description = 'Corrected work', updated_at = now() where id = ?", [$itemId]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_item_sources set price_manual = true, updated_at = now() where id = ?', [$sourceId]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set exchange_rate = 24.5, exchange_unit_amount = 1, exchange_rate_date = '2026-10-17', czk_total_minor = 24500, updated_at = now() where id = ?", [$adminId]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_item_sources where id = ?', [$sourceId]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_items where id = ?', [$itemId]));
    });

    it('moves to locked freely, and a draft can never be locked', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $draft = InvoiceFixtures::draft($client);

        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set lock_state = 'locked' where id = ?", [$draft->id]));
        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set lock_state = 'sealed' where id = ?", [$invoice->id]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set lock_state = 'locked', updated_at = now() where id = ?", [$invoice->id]));

        expect(invoiceLockStored($invoice->id))->toBe('locked');
    });
});

describe('a locked issued invoice', function (): void {
    it('refuses a write to every open column, a timestamp-only write and a delete', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        // now() is the start of the test transaction, which may equal the stored value; a real change moves it.

        foreach ([
            "currency = 'EUR'",
            "language = 'en'",
            "issue_date = '2026-10-10'",
            "taxable_supply_date = '2026-10-10'",
            "due_date = '2026-10-31'",
            'subtotal_minor = 123400, total_minor = 123400',
            "note = 'Corrected note'",
            'supplier_snapshot = \'{"name":"Corrected Supplier"}\'',
            'customer_snapshot = \'{"name":"Corrected Customer"}\'',
            'bank_snapshot = \'{"account":"corrected"}\'',
            "updated_at = updated_at + interval '1 second'",
            "number = '9999'||'0001'",
            "kind = 'proforma'",
        ] as $assignment) {
            RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set {$assignment} where id = ?", [$invoice->id]));
        }

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoices where id = ?', [$invoice->id]));
    });

    it('refuses to add, change or delete an item, a timestamp-only change included, and to write its sources', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        $itemId = invoiceLockChildId('invoice_items', 'invoice_id', $invoice->id);
        $sourceId = invoiceLockChildId('invoice_item_sources', 'invoice_id', $invoice->id);

        RawSql::expectSqlState('KP001', fn () => invoiceLockAddLine($invoice->id, 9));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_items set description = 'Corrected work' where id = ?", [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_items set updated_at = updated_at + interval '1 second' where id = ?", [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_items where id = ?', [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_item_sources set price_manual = not price_manual where id = ?', [$sourceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_item_sources set updated_at = updated_at + interval '1 second' where id = ?", [$sourceId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_item_sources where id = ?', [$sourceId]));
    });

    it('freezes the CNB figures of its Admin-only row but keeps the internal note, its marker and the PDF link writable', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        $adminId = invoiceLockChildId('invoice_admin', 'invoice_id', $invoice->id);

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_admin set exchange_rate = 24.5, exchange_unit_amount = 1, exchange_rate_date = '2026-10-17', czk_total_minor = 24500 where id = ?", [$adminId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_admin where id = ?', [$adminId]));

        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set internal_note = 'Changed note', internal_note_updated_at = now(), updated_at = now() where id = ?", [$adminId]));

        /** @var object{pdf_document_id: string|null} $before */
        $before = DB::selectOne('select pdf_document_id from invoice_admin where id = ?', [$adminId]);
        expect($before->pdf_document_id)->not->toBeNull();

        // The PDF link is cleared by the foreign key when its file is deleted, and that stays possible under a lock.
        RawSql::expectAllowed(fn () => DB::delete('delete from documents where id = ?', [$before->pdf_document_id]));

        /** @var object{pdf_document_id: string|null} $after */
        $after = DB::selectOne('select pdf_document_id from invoice_admin where id = ?', [$adminId]);
        expect($after->pdf_document_id)->toBeNull();
    });

    it('keeps its own guard on payments: one is still recorded, and deleted only under its deletion flag', function (): void {
        $invoice = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());
        $adminUser = Canary::admin()->id;

        /** @var object{id: string} $payment */
        $payment = DB::selectOne(
            "insert into invoice_payments (invoice_id, currency, amount_minor, paid_on, method, created_by, created_at, updated_at) values (?, ?, 40000, '2026-10-20', 'transfer', ?, now(), now()) returning id",
            [$invoice->id, $invoice->currency, $adminUser],
        );

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_payments where id = ?', [$payment->id]));
        RawSql::expectAllowed(function () use ($payment): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$payment->id]);
            DB::delete('delete from invoice_payments where id = ?', [$payment->id]);
            DB::select("select set_config('kokpit.payment_deletion', '', true)");
        });

        expect(invoiceLockStored($invoice->id))->toBe('locked');
    });
});

describe('the unlock path', function (): void {
    it('refuses to open a locked document without the setting, with the setting of another document, or with another column changed', function (): void {
        $client = InvoiceFixtures::client();
        $locked = InvoiceFixtures::lockedIssued($client);
        $other = InvoiceFixtures::lockedIssued($client);

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set lock_state = 'open' where id = ?", [$locked->id]));
        RawSql::expectSqlState('KP001', fn () => invoiceLockUnderUnlockSetting($other->id, fn () => DB::update("update invoices set lock_state = 'open' where id = ?", [$locked->id])));
        RawSql::expectSqlState('KP001', fn () => invoiceLockUnderUnlockSetting($locked->id, fn () => DB::update("update invoices set lock_state = 'open', note = 'sneaked in' where id = ?", [$locked->id])));
        RawSql::expectSqlState('KP001', fn () => invoiceLockUnderUnlockSetting($locked->id, fn () => DB::update("update invoices set lock_state = 'open', total_minor = 5, subtotal_minor = 5 where id = ?", [$locked->id])));

        expect(invoiceLockStored($locked->id))->toBe('locked');
    });

    it('opens a locked document under the setting that names it, and the document is writable afterwards', function (): void {
        $locked = InvoiceFixtures::lockedIssued(InvoiceFixtures::client());

        RawSql::expectAllowed(fn () => invoiceLockUnderUnlockSetting($locked->id, fn () => DB::update("update invoices set lock_state = 'open', updated_at = now() where id = ?", [$locked->id])));

        expect(invoiceLockStored($locked->id))->toBe('open');

        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'Corrected after the unlock', updated_at = now() where id = ?", [$locked->id]));
        RawSql::expectAllowed(fn () => invoiceLockAddLine($locked->id, 9));
    });
});

describe('a credit note', function (): void {
    it('is locked by its own raw flip to issued, and by a raw insert as issued', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $credit = invoiceLockDraftCreditNote($invoice->id, $client->id);

        expect(invoiceLockStored($credit))->toBe('open');

        invoiceLockIssueCreditNote($credit);

        expect(invoiceLockStored($credit))->toBe('locked');

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set note = 'later' where id = ?", [$credit]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set updated_at = updated_at + interval '1 second' where id = ?", [$credit]));

        // A credit note inserted already issued is locked by the same guard, whatever lock the insert names.
        /** @var object{id: string} $inserted */
        $inserted = DB::selectOne(
            "insert into invoices (client_id, kind, state, currency, language, credited_invoice_id, number, issue_date, subtotal_minor, total_minor, supplier_snapshot, customer_snapshot, appearance_snapshot, issued_at, lock_state) values (?, 'credit_note', 'issued', 'CZK', 'cs', ?, ?, '2026-10-17', -100, -100, '{\"name\":\"s\"}', '{\"name\":\"c\"}', '{\"accent\":\"a\"}', now(), 'open') returning id",
            [$client->id, $invoice->id, '9'.'09'.'0002'],
        );

        expect(invoiceLockStored($inserted->id))->toBe('locked');
    });

    it('is never opened again, not even under the setting that names it', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $credit = invoiceLockDraftCreditNote($invoice->id, $client->id);
        invoiceLockIssueCreditNote($credit);

        RawSql::expectSqlState('KP001', fn () => invoiceLockUnderUnlockSetting($credit, fn () => DB::update("update invoices set lock_state = 'open' where id = ?", [$credit])));

        expect(invoiceLockStored($credit))->toBe('locked');
    });

    it('is locked by its own issue even when the statement names the open lock', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $credit = invoiceLockDraftCreditNote($invoice->id, $client->id);

        // The flip sets the lock itself, so an explicit open value in the same statement is overridden, not stored.
        RawSql::expectAllowed(fn () => DB::update(
            "update invoices set state = 'issued', number = ?, issue_date = '2026-10-17', subtotal_minor = -100, total_minor = -100, supplier_snapshot = '{\"name\":\"s\"}', customer_snapshot = '{\"name\":\"c\"}', appearance_snapshot = '{\"accent\":\"a\"}', issued_at = now(), lock_state = 'open' where id = ?",
            ['9'.'09'.'0001', $credit],
        ));

        expect(invoiceLockStored($credit))->toBe('locked');
    });

    it('is refused as a draft that is locked', function (): void {
        $client = InvoiceFixtures::client();
        $invoice = InvoiceFixtures::issued($client);
        $credit = invoiceLockDraftCreditNote($invoice->id, $client->id);

        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set lock_state = 'locked' where id = ?", [$credit]));
    });
});

describe('the currency of a credited document (owner ruling of D-06)', function (): void {
    it('refuses a currency change of a document named by a credit note', function (): void {
        $client = InvoiceFixtures::client();
        $change = "update invoices set currency = 'EUR', bank_snapshot = '{\"account\":\"eur\"}' where id = ?";

        // No credit note and no payment: the change is accepted, and so is the change back.
        $free = InvoiceFixtures::issued($client);
        RawSql::expectAllowed(fn () => DB::update($change, [$free->id]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set currency = 'CZK' where id = ?", [$free->id]));

        // A draft credit note names it: the same change is refused, every other open column stays writable (D-04).
        $draftCredit = invoiceLockDraftCreditNote($free->id, $client->id);
        RawSql::expectSqlState('KP001', fn () => DB::update($change, [$free->id]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'Corrected', due_date = '2026-11-15', updated_at = now() where id = ?", [$free->id]));

        // An issued partial credit note names another document: the same.
        $credited = InvoiceFixtures::issued($client);
        $issuedCredit = invoiceLockDraftCreditNote($credited->id, $client->id);
        invoiceLockIssueCreditNote($issuedCredit);
        RawSql::expectSqlState('KP001', fn () => DB::update($change, [$credited->id]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'Corrected', due_date = '2026-11-15', updated_at = now() where id = ?", [$credited->id]));

        // The rule reads the credit notes, not the issue: once the draft credit note is gone, the change is allowed again.
        DB::delete('delete from invoices where id = ?', [$draftCredit]);
        RawSql::expectAllowed(fn () => DB::update($change, [$free->id]));

        $stored = DB::selectOne('select currency from invoices where id = ?', [$credited->id]);
        expect($stored->currency)->toBe('CZK');
    });

    it('keeps the payment half with the key of the payment, not with the guard', function (): void {
        $invoice = InvoiceFixtures::issued(InvoiceFixtures::client());

        DB::insert(
            "insert into invoice_payments (invoice_id, currency, amount_minor, paid_on, method, created_by, created_at, updated_at) values (?, ?, 40000, '2026-10-20', 'transfer', ?, now(), now())",
            [$invoice->id, $invoice->currency, Canary::admin()->id],
        );

        RawSql::expectSqlState('23503', fn () => DB::update("update invoices set currency = 'EUR' where id = ?", [$invoice->id]));
    });
});

describe('the pinned design', function (): void {
    it('pins the open column list inside the invoice row guard exactly', function (): void {
        /** @var object{definition: string} $function */
        $function = DB::selectOne("select pg_get_functiondef('kokpit_guard_invoice_row'::regproc) as definition");

        expect(preg_match('/open_columns text\[\] := ARRAY\[(.*?)\];/s', $function->definition, $match))->toBe(1);

        preg_match_all("/'([a-z_]+)'/", $match[1], $columns);

        expect($columns[1])->toBe([
            'currency', 'language', 'issue_date', 'taxable_supply_date', 'due_date',
            'subtotal_minor', 'vat_minor', 'total_minor', 'note',
            'supplier_snapshot', 'customer_snapshot', 'bank_snapshot', 'payment_method', 'lock_state', 'updated_at',
        ], 'Widening the open list makes a column of an issued document writable; that needs an owner decision (D-08, D-12; D-09 of Phase 14 opened payment_method on purpose).');
    });

    it('names the three child guards with the lock as the lifecycle column', function (): void {
        foreach (['invoice_items', 'invoice_item_sources', 'invoice_admin'] as $table) {
            /** @var object{hex: string} $trigger */
            $trigger = DB::selectOne(
                "select encode(t.tgargs, 'hex') as hex from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = ? and t.tgname = ? and not t.tgisinternal",
                [$table, $table.'_parent_guard'],
            );
            $arguments = explode("\0", (string) hex2bin($trigger->hex));

            expect(array_slice($arguments, 0, 4))->toBe(['invoices', 'invoice_id', 'lock_state', 'open'], "The child guard of {$table} must follow the lock.");
        }
    });
});

describe('the migration', function (): void {
    /**
     * Writes one document in the model of Phase 10 (no lock column, a frozen issued row) and returns its id.
     */
    $oldModelDocument = function (string $clientId, string $kind, bool $issue, ?string $credited = null): string {
        static $sequence = 0;
        $sequence++;

        /** @var object{id: string} $row */
        $row = DB::selectOne(
            "insert into invoices (client_id, kind, state, currency, language, credited_invoice_id, created_at, updated_at) values (?, ?, 'draft', 'CZK', 'cs', ?, '2026-10-17 10:00:00+00', '2026-10-17 10:00:00+00') returning id",
            [$clientId, $kind, $credited],
        );

        if ($issue) {
            $creditNote = $kind === 'credit_note';
            $number = '8'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);

            DB::update(
                "update invoices set state = 'issued', number = ?, variable_symbol = ?, issue_date = '2026-10-17', due_date = ?, subtotal_minor = ?, total_minor = ?, supplier_snapshot = ?, customer_snapshot = ?, bank_snapshot = ?, appearance_snapshot = ?, issued_at = '2026-10-17 10:30:00+00' where id = ?",
                [
                    $number,
                    $creditNote ? null : $number,
                    $creditNote ? null : '2026-10-31',
                    $creditNote ? -100 : 100,
                    $creditNote ? -100 : 100,
                    json_encode(['name' => 'Example Supplier s.r.o.']),
                    json_encode(['name' => 'Example Customer a.s.']),
                    $creditNote ? null : json_encode(['account' => 'fictional']),
                    json_encode(['accent' => '#336699']),
                    $row->id,
                ],
            );
        }

        return $row->id;
    };

    $email = function (string $invoiceId, string $state, string $userId): void {
        DB::insert(
            'insert into invoice_emails (invoice_id, recipient, subject, body, state, sent_at, failure_code, created_by, created_at, updated_at) values (?, ?, ?, ?, ?, ?, ?, ?, now(), now())',
            [$invoiceId, 'jane@example.com', 'Fictional invoice', 'Fictional body', $state, $state === 'sent' ? '2026-10-17 11:00:00+00' : null, $state === 'failed' ? 'transport' : null, $userId],
        );
    };

    it('backfills credit notes and sent documents as locked and every other document as open, without touching updated_at', function () use ($oldModelDocument, $email): void {
        $migration = require base_path('database/migrations/2026_10_22_000100_add_lock_state_to_invoices.php');
        $paymentMethod = require base_path('database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php');
        $clientId = InvoiceFixtures::client()->id;
        $userId = Canary::admin()->id;

        // Phase 14 sits on top of the lock: its method column is rolled back first and put back last.
        $paymentMethod->down();
        $migration->down();

        $sent = $oldModelDocument($clientId, 'invoice', true);
        $failedOnly = $oldModelDocument($clientId, 'invoice', true);
        $proforma = $oldModelDocument($clientId, 'proforma', true);
        $credit = $oldModelDocument($clientId, 'credit_note', true, $sent);
        $draft = $oldModelDocument($clientId, 'invoice', false);

        $email($sent, 'failed', $userId);
        $email($sent, 'sent', $userId);
        $email($failedOnly, 'failed', $userId);
        $email($failedOnly, 'queued', $userId);

        $migration->up();
        $paymentMethod->up();

        $locks = collect(DB::select('select id, lock_state, updated_at from invoices where id in (?, ?, ?, ?, ?)', [$sent, $failedOnly, $proforma, $credit, $draft]))->keyBy('id');

        expect($locks[$sent]->lock_state)->toBe('locked')
            ->and($locks[$failedOnly]->lock_state)->toBe('open')
            ->and($locks[$proforma]->lock_state)->toBe('open')
            ->and($locks[$credit]->lock_state)->toBe('locked')
            ->and($locks[$draft]->lock_state)->toBe('open');

        foreach ($locks as $lock) {
            expect(CarbonImmutable::parse($lock->updated_at)->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-17 10:00:00');
        }

        // The new guards are in force on the backfilled rows.
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set note = 'later' where id = ?", [$sent]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'later' where id = ?", [$failedOnly]));
    });

    it('restores the frozen-at-issue model on down() and the lock on up()', function () use ($oldModelDocument): void {
        $migration = require base_path('database/migrations/2026_10_22_000100_add_lock_state_to_invoices.php');
        $paymentMethod = require base_path('database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php');
        $clientId = InvoiceFixtures::client()->id;

        $paymentMethod->down();
        $migration->down();

        $hasTrigger = static fn (string $table, string $trigger): bool => (int) DB::selectOne(
            'select count(*) as n from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = ? and t.tgname = ? and not t.tgisinternal',
            [$table, $trigger],
        )->n === 1;

        expect(Schema::hasColumn('invoices', 'lock_state'))->toBeFalse()
            ->and($hasTrigger('invoices', 'invoices_frozen_guard'))->toBeTrue()
            ->and($hasTrigger('invoices', 'invoices_row_guard'))->toBeFalse()
            ->and($hasTrigger('invoice_admin', 'invoice_admin_issued_guard'))->toBeFalse();

        $issued = $oldModelDocument($clientId, 'invoice', true);
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set note = 'later' where id = ?", [$issued]));

        $migration->up();
        $paymentMethod->up();

        expect(Schema::hasColumn('invoices', 'lock_state'))->toBeTrue()
            ->and($hasTrigger('invoices', 'invoices_frozen_guard'))->toBeFalse()
            ->and($hasTrigger('invoices', 'invoices_row_guard'))->toBeTrue()
            ->and($hasTrigger('invoice_admin', 'invoice_admin_issued_guard'))->toBeTrue()
            ->and(invoiceLockStored($issued))->toBe('open');

        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'later' where id = ?", [$issued]));
    });
});
