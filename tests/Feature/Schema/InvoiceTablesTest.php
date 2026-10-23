<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\Immutability;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RawSql;

/*
 * The storage end of the billing documents (IN-01, IN-03, IN-08, IN-09, D-13): the invoices and invoice_items
 * tables are written with raw SQL here, so the proof holds for every writer. Names, numbers and amounts are
 * invented; no real business data. Issued numbers are composed from fragments. Since phase 10.1 an issued document
 * is open until it is locked (IN-11): the frozen-state proofs run on a locked document, the open state has its own
 * cases, and the full state x lock matrix lives in InvoiceLockGuardTest.
 */

/**
 * A fresh fictional client and its id.
 */
function invoiceTablesClient(): string
{
    return app(PartnerContext::class)->runAsSystem(static fn (): string => Client::factory()->create()->id);
}

/**
 * Inserts one draft invoice by raw SQL and returns its id. Overrides replace single columns; the column names come
 * from the test only.
 *
 * @param  array<string, mixed>  $overrides
 */
function invoiceTablesInsert(array $overrides = []): string
{
    $row = [
        'client_id' => invoiceTablesClient(),
        'kind' => 'invoice',
        'state' => 'draft',
        'currency' => 'CZK',
        'language' => 'cs',
        'created_at' => '2026-10-17 10:00:00+00',
        'updated_at' => '2026-10-17 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into invoices (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * Inserts one item of the invoice by raw SQL and returns its id. The defaults are a consistent ordinary line of
 * two pieces at 500.00.
 *
 * @param  array<string, mixed>  $overrides
 */
function invoiceTablesInsertItem(string $invoiceId, array $overrides = []): string
{
    $row = [
        'invoice_id' => $invoiceId,
        'position' => 1,
        'description' => 'Fictional consulting',
        'quantity' => '2.0000',
        'unit' => 'h',
        'unit_price_minor' => 50000,
        'line_total_minor' => 100000,
        'created_at' => '2026-10-17 10:00:00+00',
        'updated_at' => '2026-10-17 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into invoice_items (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * The fields an issued invoice needs, as an update list. Overrides replace single entries.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoiceTablesIssuedFields(array $overrides = []): array
{
    static $sequence = 0;
    $sequence++;
    $number = '2026'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

    return [
        'state' => 'issued',
        'number' => $number,
        'variable_symbol' => $number,
        'issue_date' => '2026-10-17',
        'due_date' => '2026-10-31',
        'subtotal_minor' => 100000,
        'total_minor' => 100000,
        'supplier_snapshot' => json_encode(['name' => 'Example Supplier s.r.o.']),
        'customer_snapshot' => json_encode(['name' => 'Example Customer a.s.']),
        'bank_snapshot' => json_encode(['account' => 'fictional']),
        'appearance_snapshot' => json_encode(['accent' => '#336699']),
        'issued_at' => '2026-10-17 10:30:00+00',
        ...$overrides,
    ];
}

/**
 * Issues the draft by raw SQL.
 *
 * @param  array<string, mixed>  $overrides
 */
function invoiceTablesIssue(string $invoiceId, array $overrides = []): void
{
    $fields = invoiceTablesIssuedFields($overrides);
    $assignments = implode(', ', array_map(static fn (string $column): string => $column.' = ?', array_keys($fields)));

    DB::update(sprintf('update invoices set %s where id = ?', $assignments), [...array_values($fields), $invoiceId]);
}

/**
 * Issues the draft by raw SQL and locks it in the same statement, the state of a document that has been sent (IN-11).
 * An issued document is open until then; the frozen-state proofs below run on a locked one.
 *
 * @param  array<string, mixed>  $overrides
 */
function invoiceTablesIssueLocked(string $invoiceId, array $overrides = []): void
{
    invoiceTablesIssue($invoiceId, ['lock_state' => 'locked', ...$overrides]);
}

/**
 * A draft with two items.
 *
 * @return array{0: string, 1: string, 2: string} the invoice id and the two item ids
 */
function invoiceTablesDraftWithItems(): array
{
    $invoiceId = invoiceTablesInsert();

    return [
        $invoiceId,
        invoiceTablesInsertItem($invoiceId),
        invoiceTablesInsertItem($invoiceId, ['position' => 2, 'description' => 'Fictional workshop']),
    ];
}

describe('a draft and its items', function (): void {
    it('stays freely editable and deletable', function (): void {
        [$invoiceId, $firstItem, $secondItem] = invoiceTablesDraftWithItems();

        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'Fictional note', subtotal_minor = 5, total_minor = 5 where id = ?", [$invoiceId]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_items set description = 'Renamed', updated_at = now() where id = ?", [$firstItem]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_items where id = ?', [$secondItem]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => 3]));

        DB::delete('delete from invoice_items where invoice_id = ?', [$invoiceId]);

        RawSql::expectAllowed(fn () => DB::delete('delete from invoices where id = ?', [$invoiceId]));
    });

    it('gives a draft a version 7 id from the column default', function (): void {
        expect(invoiceTablesInsert())->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/');
    });
});

describe('a locked issued invoice', function (): void {
    it('accepts no write at all, a timestamp-only one included', function (): void {
        [$invoiceId] = invoiceTablesDraftWithItems();
        invoiceTablesIssueLocked($invoiceId);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set total_minor = 7 where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set note = 'later' where id = ?", [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set updated_at = now() where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set cancels_invoice = true where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set state = 'draft' where id = ?", [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoices where id = ?', [$invoiceId]));
    });

    it('refuses TRUNCATE of both tables', function (): void {
        [$invoiceId] = invoiceTablesDraftWithItems();
        invoiceTablesIssueLocked($invoiceId);

        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoices CASCADE'));
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoice_items CASCADE'));

        expect((int) DB::selectOne('select count(*) as n from invoices where id = ?', [$invoiceId])->n)->toBe(1);
    });

    it('refuses to add, change or delete an item, a timestamp-only change included', function (): void {
        [$invoiceId, $itemId] = invoiceTablesDraftWithItems();
        invoiceTablesIssueLocked($invoiceId);

        RawSql::expectSqlState('KP001', fn () => invoiceTablesInsertItem($invoiceId, ['position' => 3]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_items set description = 'Renamed' where id = ?", [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set updated_at = now() where id = ?', [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_items where id = ?', [$itemId]));
    });

    it('refuses to move an item of a draft under a locked document and the other way round', function (): void {
        [$draftId, $draftItem] = invoiceTablesDraftWithItems();
        [$issuedId, $issuedItem] = invoiceTablesDraftWithItems();
        invoiceTablesIssueLocked($issuedId);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set invoice_id = ?, position = 9 where id = ?', [$issuedId, $draftItem]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set invoice_id = ?, position = 9 where id = ?', [$draftId, $issuedItem]));
    });

    it('proves each guard by a mutation: without the item guard or the row guard the same statement is accepted', function (): void {
        [$invoiceId, $itemId] = invoiceTablesDraftWithItems();
        invoiceTablesIssueLocked($invoiceId);

        $insertItem = fn () => RawSql::expectSqlState('KP001', fn () => invoiceTablesInsertItem($invoiceId, ['position' => 3]));
        $touchInvoice = fn () => RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set updated_at = now() where id = ?', [$invoiceId]));
        $touchItem = fn () => RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set updated_at = now() where id = ?', [$itemId]));

        $insertItem();
        $touchInvoice();
        $touchItem();

        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_items'));
        expect($insertItem)->toThrow(AssertionFailedError::class, 'was accepted');
        expect($touchItem)->toThrow(AssertionFailedError::class, 'was accepted');

        DB::unprepared('DROP TRIGGER invoices_row_guard ON invoices');
        expect($touchInvoice)->toThrow(AssertionFailedError::class, 'was accepted');
    });
});

describe('an open issued invoice', function (): void {
    it('takes corrections of its open columns and keeps its identity', function (): void {
        [$invoiceId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        RawSql::expectAllowed(fn () => DB::update("update invoices set subtotal_minor = 7, total_minor = 7, note = 'later', updated_at = now() where id = ?", [$invoiceId]));
        RawSql::expectAllowed(fn () => DB::update('update invoices set updated_at = now() where id = ?', [$invoiceId]));

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set cancels_invoice = true where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set state = 'draft' where id = ?", [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoices where id = ?', [$invoiceId]));
    });

    it('accepts items to be added, changed and deleted', function (): void {
        [$invoiceId, $itemId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => 3]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_items set description = 'Renamed', updated_at = now() where id = ?", [$itemId]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_items where id = ?', [$itemId]));
    });

    it('proves the guard by a mutation: without the row guard its identity can be rewritten', function (): void {
        [$invoiceId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        $renumber = fn () => RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set number = '9999'||'0001' where id = ?", [$invoiceId]));

        $renumber();

        DB::unprepared('DROP TRIGGER invoices_row_guard ON invoices');

        expect($renumber)->toThrow(AssertionFailedError::class, 'was accepted');
    });
});

/**
 * A draft credit note of the invoice, by raw SQL. Overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function invoiceTablesInsertCreditNote(string $invoiceId, string $clientId, array $overrides = []): string
{
    return invoiceTablesInsert([
        'client_id' => $clientId,
        'kind' => 'credit_note',
        'credited_invoice_id' => $invoiceId,
        ...$overrides,
    ]);
}

/**
 * The fields an issued credit note needs: no due date, no bank snapshot and no variable symbol.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function invoiceTablesCreditFields(array $overrides = []): array
{
    return [
        'variable_symbol' => null,
        'due_date' => null,
        'bank_snapshot' => null,
        'subtotal_minor' => -100000,
        'total_minor' => -100000,
        ...$overrides,
    ];
}

/**
 * A position that no other item of the test uses, so one invoice can hold many probes.
 */
function invoiceTablesPosition(): int
{
    static $position = 0;

    return ++$position;
}

describe('number, state and series', function (): void {
    it('keeps a draft without a number and gives an issued row one', function (): void {
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['number' => '2026'.'0001']));

        $id = invoiceTablesInsert();
        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($id, ['number' => null]));
        RawSql::expectAllowed(fn () => invoiceTablesIssue($id));
    });

    it('refuses a kind, a state, a language, a VAT mode or a currency outside the lists', function (string $column, string $value): void {
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert([$column => $value]));
    })->with([
        'kind' => ['kind', 'receipt'],
        'state' => ['state', 'cancelled'],
        'language' => ['language', 'de'],
        'VAT mode' => ['vat_mode', 'exempt'],
        'lower-case currency' => ['currency', 'czk'],
        'two-letter currency' => ['currency', 'EU'],
    ]);

    it('refuses a duplicate number within one kind and keeps the series of the kinds apart', function (): void {
        $clientId = invoiceTablesClient();
        $number = '2026'.'0042';
        $first = invoiceTablesInsert(['client_id' => $clientId]);
        invoiceTablesIssue($first, ['number' => $number]);

        $second = invoiceTablesInsert(['client_id' => $clientId]);
        RawSql::expectSqlState('23505', fn () => invoiceTablesIssue($second, ['number' => $number]));

        $proforma = invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma']);
        RawSql::expectAllowed(fn () => invoiceTablesIssue($proforma, ['number' => $number]));

        $credit = invoiceTablesInsertCreditNote($first, $clientId);
        RawSql::expectAllowed(fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields(['number' => $number])));
    });

    it('allows any number of drafts, all without a number', function (): void {
        $clientId = invoiceTablesClient();

        RawSql::expectAllowed(function () use ($clientId): void {
            invoiceTablesInsert(['client_id' => $clientId]);
            invoiceTablesInsert(['client_id' => $clientId]);
        });
    });

    it('refuses a note over 500 characters', function (): void {
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['note' => str_repeat('a', 500)]));
        RawSql::expectSqlState('22001', fn () => invoiceTablesInsert(['note' => str_repeat('a', 501)]));
    });
});

describe('the lines', function (): void {
    it('pins an ordinary line to the rounded product of quantity and unit price', function (): void {
        $invoiceId = invoiceTablesInsert();

        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '1.3333', 'unit_price_minor' => 100000, 'line_total_minor' => 133330]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '1.3333', 'unit_price_minor' => 100000, 'line_total_minor' => 133331]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '1.3333', 'unit_price_minor' => 100000, 'line_total_minor' => 133329]));
    });

    it('rounds half away from zero, a credit line included', function (): void {
        $invoiceId = invoiceTablesInsert();

        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '-2.5000', 'unit_price_minor' => 3, 'line_total_minor' => -8]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '-2.5000', 'unit_price_minor' => 3, 'line_total_minor' => -7]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '2.5000', 'unit_price_minor' => 3, 'line_total_minor' => 8]));
    });

    it('refuses a zero quantity, a negative unit price, a bad position and a blank text', function (array $overrides): void {
        $invoiceId = invoiceTablesInsert();

        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), ...$overrides]));
    })->with([
        'zero quantity' => [['quantity' => '0.0000', 'line_total_minor' => 0]],
        'negative unit price' => [['unit_price_minor' => -1, 'quantity' => '1.0000', 'line_total_minor' => -1]],
        'position zero' => [['position' => 0]],
        'blank description' => [['description' => '   ']],
        'blank unit' => [['unit' => '']],
    ]);

    it('refuses a second line on the same position of one document', function (): void {
        $invoiceId = invoiceTablesInsert();
        invoiceTablesInsertItem($invoiceId, ['position' => 1]);

        RawSql::expectSqlState('23505', fn () => invoiceTablesInsertItem($invoiceId, ['position' => 1]));
    });

    it('bounds a converted line to the rounding of its printed unit price', function (): void {
        $invoiceId = invoiceTablesInsert(['currency' => 'EUR']);
        $converted = ['quantity' => '10.0000', 'unit_price_minor' => 5970, 'price_converted' => true];

        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), ...$converted, 'line_total_minor' => 59701]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), ...$converted, 'line_total_minor' => 59700]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), ...$converted, 'line_total_minor' => 59710]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '-10.0000', 'unit_price_minor' => 5970, 'price_converted' => true, 'line_total_minor' => -59701]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '-10.0000', 'unit_price_minor' => 5970, 'price_converted' => true, 'line_total_minor' => -59710]));
    });

    it('does not let an ordinary line take the converted tolerance', function (): void {
        $invoiceId = invoiceTablesInsert(['currency' => 'EUR']);

        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '10.0000', 'unit_price_minor' => 5970, 'price_converted' => false, 'line_total_minor' => 59701]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'quantity' => '10.0000', 'unit_price_minor' => 5970, 'price_converted' => false, 'line_total_minor' => 59700]));
    });

    it('gives a line without a VAT rate a VAT amount of zero', function (): void {
        $invoiceId = invoiceTablesInsert();

        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'vat_minor' => 5]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'vat_rate' => '21.00', 'vat_minor' => 5]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertItem($invoiceId, ['position' => invoiceTablesPosition(), 'vat_rate' => '121.00']));
    });

    it('lets a credit line point at a line of the document it credits and nothing else', function (): void {
        $clientId = invoiceTablesClient();
        $invoiceId = invoiceTablesInsert(['client_id' => $clientId]);
        $itemId = invoiceTablesInsertItem($invoiceId);
        $creditId = invoiceTablesInsertCreditNote($invoiceId, $clientId);

        RawSql::expectAllowed(fn () => invoiceTablesInsertItem($creditId, ['credited_item_id' => $itemId, 'quantity' => '-1.0000', 'line_total_minor' => -50000]));
        RawSql::expectSqlState('23503', fn () => invoiceTablesInsertItem($creditId, ['position' => 2, 'credited_item_id' => '01900000-0000-7000-8000-000000000000']));
    });
});

describe('links between documents', function (): void {
    it('refuses a source proforma or a credited invoice of another client', function (): void {
        $clientA = invoiceTablesClient();
        $clientB = invoiceTablesClient();
        $proformaOfA = invoiceTablesInsert(['client_id' => $clientA, 'kind' => 'proforma']);
        $invoiceOfA = invoiceTablesInsert(['client_id' => $clientA]);

        RawSql::expectSqlState('23503', fn () => invoiceTablesInsert(['client_id' => $clientB, 'proforma_id' => $proformaOfA]));
        RawSql::expectSqlState('23503', fn () => invoiceTablesInsertCreditNote($invoiceOfA, $clientB));
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['client_id' => $clientA, 'proforma_id' => $proformaOfA]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertCreditNote($invoiceOfA, $clientA));
    });

    it('allows one invoice per proforma', function (): void {
        $clientId = invoiceTablesClient();
        $proforma = invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma']);
        invoiceTablesInsert(['client_id' => $clientId, 'proforma_id' => $proforma]);

        RawSql::expectSqlState('23505', fn () => invoiceTablesInsert(['client_id' => $clientId, 'proforma_id' => $proforma]));
    });

    it('lets only an invoice name a proforma and only a credit note name an invoice', function (): void {
        $clientId = invoiceTablesClient();
        $proforma = invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma']);
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);

        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'credit_note']));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['client_id' => $clientId, 'credited_invoice_id' => $invoice]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma', 'proforma_id' => $proforma]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertCreditNote($invoice, $clientId, ['proforma_id' => $proforma]));
    });

    it('refuses to delete a document that another document points at', function (): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        invoiceTablesInsertCreditNote($invoice, $clientId);

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from invoices where id = ?', [$invoice]));
    });

    it('refuses a document of a client that does not exist', function (): void {
        RawSql::expectSqlState('23503', fn () => invoiceTablesInsert(['client_id' => '01900000-0000-7000-8000-000000000000']));
    });
});

describe('the storno marker', function (): void {
    it('lets only a credit note cancel an invoice', function (): void {
        $clientId = invoiceTablesClient();
        $proforma = invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma']);
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);

        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['client_id' => $clientId, 'cancels_invoice' => true]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma', 'cancels_invoice' => true]));
        RawSql::expectSqlState('23514', fn () => DB::update('update invoices set cancels_invoice = true where id = ?', [$proforma]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertCreditNote($invoice, $clientId, ['cancels_invoice' => true]));
    });

    it('refuses a second storno of one invoice, also while it is a draft, and allows partial credit notes beside it', function (): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        invoiceTablesInsertCreditNote($invoice, $clientId, ['cancels_invoice' => true]);

        RawSql::expectSqlState('23505', fn () => invoiceTablesInsertCreditNote($invoice, $clientId, ['cancels_invoice' => true]));
        RawSql::expectAllowed(fn () => invoiceTablesInsertCreditNote($invoice, $clientId));
        RawSql::expectAllowed(fn () => invoiceTablesInsertCreditNote($invoice, $clientId, ['cancels_invoice' => false]));

        $other = invoiceTablesInsert(['client_id' => $clientId]);
        RawSql::expectAllowed(fn () => invoiceTablesInsertCreditNote($other, $clientId, ['cancels_invoice' => true]));
    });

    it('freezes the storno marker with the credit note at its own issue', function (): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        // A storno locks nothing (D-01): the cancelled invoice refuses a write here only because it is locked itself.
        invoiceTablesIssueLocked($invoice);
        $credit = invoiceTablesInsertCreditNote($invoice, $clientId, ['cancels_invoice' => true]);
        invoiceTablesInsertItem($credit, ['quantity' => '-2.0000', 'line_total_minor' => -100000]);
        invoiceTablesIssue($credit, invoiceTablesCreditFields());

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set cancels_invoice = false where id = ?', [$credit]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoices where id = ?', [$credit]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set updated_at = now() where id = ?', [$invoice]));
    });
});

describe('totals, signs and VAT', function (): void {
    it('keeps the total equal to the subtotal plus the VAT', function (): void {
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['subtotal_minor' => 100, 'vat_minor' => 0, 'total_minor' => 101]));
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['vat_mode' => 'payer', 'subtotal_minor' => 100, 'vat_minor' => 21, 'total_minor' => 121]));
    });

    it('gives an issued credit note a negative total and an issued invoice or proforma a positive one', function (): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        $proforma = invoiceTablesInsert(['client_id' => $clientId, 'kind' => 'proforma']);
        $credit = invoiceTablesInsertCreditNote($invoice, $clientId);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($invoice, ['subtotal_minor' => 0, 'total_minor' => 0]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($invoice, ['subtotal_minor' => -5, 'total_minor' => -5]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($proforma, ['subtotal_minor' => 0, 'total_minor' => 0]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields(['subtotal_minor' => 100000, 'total_minor' => 100000])));
        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields(['subtotal_minor' => 0, 'total_minor' => 0])));
        RawSql::expectAllowed(fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields()));
    });

    it('lets a draft hold any total while it is being built', function (): void {
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['subtotal_minor' => -50, 'total_minor' => -50]));
    });

    it('keeps the VAT at zero while the document is of a non-payer', function (): void {
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['vat_mode' => 'non_payer', 'subtotal_minor' => 100, 'vat_minor' => 100, 'total_minor' => 200]));
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['vat_mode' => 'payer', 'subtotal_minor' => 100, 'vat_minor' => 100, 'total_minor' => 200]));
    });
});

describe('what an issued document must carry', function (): void {
    it('requires the issue date, the due date, the variable symbol and the four snapshots of an invoice or a proforma', function (string $kind, string $missing): void {
        $id = invoiceTablesInsert(['kind' => $kind]);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($id, [$missing => null]));
        RawSql::expectAllowed(fn () => invoiceTablesIssue($id));
    })->with(fn () => collect(['invoice', 'proforma'])->crossJoin([
        'issue_date', 'due_date', 'variable_symbol', 'supplier_snapshot', 'customer_snapshot', 'bank_snapshot', 'appearance_snapshot',
    ])->all());

    it('requires the issue date and the supplier, customer and appearance snapshots of a credit note, and nothing else', function (string $missing): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        $credit = invoiceTablesInsertCreditNote($invoice, $clientId);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields([$missing => null])));
        RawSql::expectAllowed(fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields()));
    })->with(['issue_date', 'supplier_snapshot', 'customer_snapshot', 'appearance_snapshot']);

    it('gives a credit note neither a due date, a bank snapshot nor a variable symbol, and a draft no variable symbol', function (): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        $credit = invoiceTablesInsertCreditNote($invoice, $clientId);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($credit, invoiceTablesCreditFields(['variable_symbol' => '2026'.'0007'])));
        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set variable_symbol = '1' where id = ?", [$invoice]));
    });

    it('accepts a variable symbol of one to ten digits only', function (string $symbol, string $state): void {
        $id = invoiceTablesInsert();

        RawSql::expectSqlState($state, fn () => invoiceTablesIssue($id, ['variable_symbol' => $symbol]));
    })->with([
        'letters' => ['AB', '23514'],
        'a digit and a letter' => ['12A', '23514'],
        'empty' => ['', '23514'],
        // varchar(10) answers first, before the CHECK is evaluated.
        'eleven digits' => ['12345678901', '22001'],
    ]);

    it('accepts a variable symbol of ten digits', function (): void {
        $id = invoiceTablesInsert();

        RawSql::expectAllowed(fn () => invoiceTablesIssue($id, ['variable_symbol' => '1234567890']));
    });

    it('leaves the issue dates of a draft open', function (): void {
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['issue_date' => null, 'due_date' => null, 'taxable_supply_date' => null]));
        RawSql::expectAllowed(fn () => invoiceTablesInsert(['issue_date' => '2026-10-17', 'due_date' => '2026-10-31', 'taxable_supply_date' => '2026-10-17']));
    });
});

describe('the payment method of a document (Phase 14; D-01, D-02, OA-7)', function (): void {
    it('defaults a raw-inserted invoice or proforma draft to transfer, whoever writes it', function (string $kind): void {
        $id = invoiceTablesInsert(['kind' => $kind]);

        expect(DB::selectOne('select payment_method from invoices where id = ?', [$id])->payment_method)->toBe('transfer');
    })->with(['invoice', 'proforma']);

    it('keeps a raw-inserted credit note without a method and refuses a method on it', function (): void {
        $clientId = invoiceTablesClient();
        $invoice = invoiceTablesInsert(['client_id' => $clientId]);
        $credit = invoiceTablesInsertCreditNote($invoice, $clientId);

        expect(DB::selectOne('select payment_method from invoices where id = ?', [$credit])->payment_method)->toBeNull();

        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set payment_method = 'transfer' where id = ?", [$credit]));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsertCreditNote($invoice, $clientId, ['payment_method' => 'transfer']));
    });

    it('keeps an explicit method on insert and refuses any value but transfer and online', function (): void {
        $online = invoiceTablesInsert(['payment_method' => 'online']);

        expect(DB::selectOne('select payment_method from invoices where id = ?', [$online])->payment_method)->toBe('online');

        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['payment_method' => 'card']));
        RawSql::expectSqlState('23514', fn () => invoiceTablesInsert(['payment_method' => '']));
    });

    it('refuses to remove the method of an invoice or a proforma after insert', function (string $kind): void {
        $id = invoiceTablesInsert(['kind' => $kind]);

        RawSql::expectSqlState('23514', fn () => DB::update('update invoices set payment_method = null where id = ?', [$id]));
        RawSql::expectSqlState('23514', fn () => DB::update("update invoices set payment_method = 'card' where id = ?", [$id]));
        RawSql::expectAllowed(fn () => DB::update("update invoices set payment_method = 'online' where id = ?", [$id]));
    })->with(['invoice', 'proforma']);

    it('lets an issued online invoice or proforma carry no bank snapshot and refuses one with a snapshot (OA-7)', function (string $kind): void {
        $id = invoiceTablesInsert(['kind' => $kind, 'payment_method' => 'online']);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($id));
        RawSql::expectAllowed(fn () => invoiceTablesIssue($id, ['bank_snapshot' => null]));
    })->with(['invoice', 'proforma']);

    it('requires the bank snapshot of an issued transfer invoice or proforma (OA-7)', function (string $kind): void {
        $id = invoiceTablesInsert(['kind' => $kind, 'payment_method' => 'transfer']);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($id, ['bank_snapshot' => null]));
        RawSql::expectAllowed(fn () => invoiceTablesIssue($id));
    })->with(['invoice', 'proforma']);

    it('still requires the due date of an issued online document', function (): void {
        $id = invoiceTablesInsert(['payment_method' => 'online']);

        RawSql::expectSqlState('23514', fn () => invoiceTablesIssue($id, ['bank_snapshot' => null, 'due_date' => null]));
    });
});

/**
 * The arguments a trigger was created with, read from pg_trigger.tgargs (NUL-separated, each one terminated).
 *
 * @return list<string>
 */
function invoiceTablesTriggerArguments(string $table, string $trigger): array
{
    /** @var object{hex: string}|null $row */
    $row = DB::selectOne(
        'select encode(t.tgargs, \'hex\') as hex from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = ? and t.tgname = ? and not t.tgisinternal',
        [$table, $trigger],
    );

    expect($row)->not->toBeNull("The trigger {$trigger} is missing on {$table}.");

    $arguments = explode("\0", (string) hex2bin($row->hex));
    array_pop($arguments);

    return $arguments;
}

describe('the guards of the invoice tables', function (): void {
    it('keeps the guard functions and the triggers in place', function (): void {
        foreach (['kokpit_guard_child_of_frozen_parent', 'kokpit_guard_invoice_row', 'kokpit_guard_invoice_admin_issued'] as $function) {
            expect((int) DB::selectOne('select count(*) as n from pg_proc where proname = ?', [$function])->n)->toBe(1, "The function {$function} must exist.");
        }

        foreach ([
            ['invoices', 'invoices_row_guard'],
            ['invoices', 'invoices_truncate_guard'],
            ['invoice_items', 'invoice_items_parent_guard'],
            ['invoice_items', 'invoice_items_truncate_guard'],
            ['invoice_admin', 'invoice_admin_parent_guard'],
            ['invoice_admin', 'invoice_admin_issued_guard'],
            ['invoice_item_sources', 'invoice_item_sources_parent_guard'],
        ] as [$table, $trigger]) {
            expect((int) DB::selectOne(
                'select count(*) as n from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = ? and t.tgname = ? and not t.tgisinternal',
                [$table, $trigger],
            )->n)->toBe(1, "The trigger {$trigger} must exist on {$table}.");
        }

        expect((int) DB::selectOne("select count(*) as n from pg_trigger t join pg_class c on c.oid = t.tgrelid where c.relname = 'invoices' and t.tgname = 'invoices_frozen_guard' and not t.tgisinternal")->n)->toBe(0, 'The generic frozen guard would let an open issued row be deleted; invoices_row_guard replaces it.');
    });

    it('lists no writable column on a locked invoice item and keys the item guard on the lock', function (): void {
        $reason = 'Any write on the items of a locked invoice needs an owner decision amending D-12; the payment state and the internal note live in Admin-only tables. The children follow invoices.lock_state, not the state.';

        expect(invoiceTablesTriggerArguments('invoices', 'invoices_row_guard'))->toBe([], $reason)
            ->and(invoiceTablesTriggerArguments('invoice_items', 'invoice_items_parent_guard'))->toBe(['invoices', 'invoice_id', 'lock_state', 'open', ''], $reason);
    });

    it('catches an item guard that gained a writable column', function (): void {
        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_items'));
        DB::unprepared(Immutability::childGuardTriggerSql('invoice_items', 'invoice_id', 'invoices', 'lock_state', 'open', ['description']));

        expect(invoiceTablesTriggerArguments('invoice_items', 'invoice_items_parent_guard'))->not->toBe(['invoices', 'invoice_id', 'lock_state', 'open', '']);
    });

    it('has no generated column on a guarded table', function (): void {
        expect(DB::select("select attname from pg_attribute a join pg_class c on c.oid = a.attrelid where c.relname in ('invoices', 'invoice_items') and a.attgenerated <> '' and not a.attisdropped"))->toBe([]);
    });
});
