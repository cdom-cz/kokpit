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
 * invented; no real business data. Issued numbers are composed from fragments.
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

        RawSql::expectAllowed(fn () => DB::update("update invoices set note = 'Fictional note', total_minor = 5 where id = ?", [$invoiceId]));
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

describe('an issued invoice', function (): void {
    it('accepts no write at all, a timestamp-only one included', function (): void {
        [$invoiceId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set total_minor = 7 where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set note = 'later' where id = ?", [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set updated_at = now() where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set cancels_invoice = true where id = ?', [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoices set state = 'draft' where id = ?", [$invoiceId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoices where id = ?', [$invoiceId]));
    });

    it('refuses TRUNCATE of both tables', function (): void {
        [$invoiceId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoices CASCADE'));
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoice_items'));

        expect((int) DB::selectOne('select count(*) as n from invoices where id = ?', [$invoiceId])->n)->toBe(1);
    });

    it('refuses to add, change or delete an item, a timestamp-only change included', function (): void {
        [$invoiceId, $itemId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        RawSql::expectSqlState('KP001', fn () => invoiceTablesInsertItem($invoiceId, ['position' => 3]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_items set description = 'Renamed' where id = ?", [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set updated_at = now() where id = ?', [$itemId]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_items where id = ?', [$itemId]));
    });

    it('refuses to move an item of a draft under an issued document and the other way round', function (): void {
        [$draftId, $draftItem] = invoiceTablesDraftWithItems();
        [$issuedId, $issuedItem] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($issuedId);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set invoice_id = ?, position = 9 where id = ?', [$issuedId, $draftItem]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set invoice_id = ?, position = 9 where id = ?', [$draftId, $issuedItem]));
    });

    it('proves each guard by a mutation: without the item guard or with the implied timestamp the same statement is accepted', function (): void {
        [$invoiceId, $itemId] = invoiceTablesDraftWithItems();
        invoiceTablesIssue($invoiceId);

        $insertItem = fn () => RawSql::expectSqlState('KP001', fn () => invoiceTablesInsertItem($invoiceId, ['position' => 3]));
        $touchInvoice = fn () => RawSql::expectSqlState('KP001', fn () => DB::update('update invoices set updated_at = now() where id = ?', [$invoiceId]));
        $touchItem = fn () => RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_items set updated_at = now() where id = ?', [$itemId]));

        $insertItem();
        $touchInvoice();
        $touchItem();

        DB::unprepared(Immutability::dropChildGuardTriggerSql('invoice_items'));
        expect($insertItem)->toThrow(AssertionFailedError::class, 'was accepted');
        expect($touchItem)->toThrow(AssertionFailedError::class, 'was accepted');

        DB::unprepared(Immutability::dropGuardTriggerSql('invoices'));
        DB::unprepared(Immutability::guardTriggerSql('invoices', 'state', 'draft', []));
        expect($touchInvoice)->toThrow(AssertionFailedError::class, 'was accepted');
    });
});
