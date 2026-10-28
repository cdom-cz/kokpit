<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Tasks\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RawSql;

/*
 * The Admin-only half of the invoicing schema (IN-03, IN-04, IN-05, IN-10, FI-03; D-12, D-13, D-17, D-19, D-21,
 * D-23): invoice_admin with its PDF link, invoice_item_sources, invoice_payments and invoice_emails, written with
 * raw SQL so the proof holds for every writer. Clients, numbers and amounts are invented; no real business data.
 * The setting `kokpit.payment_deletion` is set here with set_config() the way DeletePayment of plan 10-41 does.
 */

beforeEach(function (): void {
    app(PartnerContext::class)->runAsSystem(function (): void {
        $this->adminTablesUser = User::factory()->create()->id;
        $this->adminTablesClient = Client::factory()->create()->id;
    });
});

/**
 * Inserts one draft document by raw SQL and returns its id. Overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function adminTablesDocument(string $clientId, array $overrides = []): string
{
    $row = [
        'client_id' => $clientId,
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
 * Issues the draft document by raw SQL; a credit note gets a negative total and no due date. The document is left
 * open unless the caller asks for the lock of a sent document (IN-11); a credit note is locked by its own issue.
 */
function adminTablesIssue(string $documentId, bool $locked = false): void
{
    static $sequence = 0;
    $sequence++;
    $number = '2026'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);

    /** @var object{kind: string} $document */
    $document = DB::selectOne('select kind from invoices where id = ?', [$documentId]);
    $creditNote = $document->kind === 'credit_note';
    $total = $creditNote ? -100000 : 100000;

    DB::update(
        'update invoices set state = \'issued\', number = ?, variable_symbol = ?, issue_date = \'2026-10-17\', due_date = ?, subtotal_minor = ?, total_minor = ?, '
        .'supplier_snapshot = ?, customer_snapshot = ?, bank_snapshot = ?, appearance_snapshot = ?, issued_at = \'2026-10-17 10:30:00+00\', lock_state = ? where id = ?',
        [
            $number,
            $creditNote ? null : $number,
            $creditNote ? null : '2026-10-31',
            $total,
            $total,
            json_encode(['name' => 'Example Supplier s.r.o.']),
            json_encode(['name' => 'Example Customer a.s.']),
            $creditNote ? null : json_encode(['account' => 'fictional']),
            json_encode(['accent' => '#336699']),
            $locked || $creditNote ? 'locked' : 'open',
            $documentId,
        ],
    );
}

/**
 * Inserts one line of the document by raw SQL and returns its id.
 */
function adminTablesItem(string $documentId, int $position = 1): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into invoice_items (invoice_id, position, description, quantity, unit, unit_price_minor, line_total_minor, created_at, updated_at) values (?, ?, 'Fictional consulting', 2, 'h', 50000, 100000, now(), now()) returning id",
        [$documentId, $position],
    );

    return $inserted->id;
}

/**
 * Inserts one admin row by raw SQL and returns its id. Overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function adminTablesAdminRow(string $documentId, array $overrides = []): string
{
    $row = ['invoice_id' => $documentId, 'created_at' => '2026-10-17 10:00:00+00', 'updated_at' => '2026-10-17 10:00:00+00', ...$overrides];
    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into invoice_admin (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * Inserts one stored file by raw SQL and returns its id. The owner defaults to nobody.
 */
function adminTablesFile(string $uploader, ?string $ownerType = null, ?string $ownerId = null): string
{
    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        "insert into documents (name, mime_type, size_bytes, uploaded_by_id, owner_type, owner_id, created_at, updated_at) values ('Fictional.pdf', 'application/pdf', 1024, ?, ?, ?, now(), now()) returning id",
        [$uploader, $ownerType, $ownerId],
    );

    return $inserted->id;
}

/**
 * Inserts one payment by raw SQL and returns its id. Overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function adminTablesPayment(string $documentId, string $userId, array $overrides = []): string
{
    $row = [
        'invoice_id' => $documentId,
        'currency' => 'CZK',
        'amount_minor' => 40000,
        'paid_on' => '2026-10-20',
        'method' => 'transfer',
        'created_by' => $userId,
        'created_at' => '2026-10-20 10:00:00+00',
        'updated_at' => '2026-10-20 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into invoice_payments (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * Deletes the payment the way DeletePayment does: the transaction-local flag names it, and the flag is cleared again.
 */
function adminTablesDeleteFlagged(string $paymentId, ?string $flag = null): void
{
    DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$flag ?? $paymentId]);
    DB::delete('delete from invoice_payments where id = ?', [$paymentId]);
    DB::select("select set_config('kokpit.payment_deletion', '', true)");
}

/**
 * An issued invoice with an admin row, a line and a source of that line, built while the invoice was a draft. It is
 * locked like a sent document unless the caller asks for an open one.
 *
 * @return array{invoice: string, admin: string, item: string, source: string}
 */
function adminTablesIssuedWithSide(object $test, bool $locked = true): array
{
    $invoice = adminTablesDocument($test->adminTablesClient);
    $admin = adminTablesAdminRow($invoice, ['internal_note' => 'Fictional note']);
    $item = adminTablesItem($invoice);

    /** @var object{id: string} $source */
    $source = DB::selectOne("insert into invoice_item_sources (invoice_item_id, invoice_id, origin, created_at, updated_at) values (?, ?, 'manual', '2026-10-17 10:00:00+00', '2026-10-17 10:00:00+00') returning id", [$item, $invoice]);

    adminTablesIssue($invoice, $locked);

    return ['invoice' => $invoice, 'admin' => $admin, 'item' => $item, 'source' => $source->id];
}

describe('invoice_admin', function (): void {
    it('keeps the internal note editable under a lock and freezes the CNB columns, the row and the second row', function (): void {
        ['invoice' => $invoice, 'admin' => $admin] = adminTablesIssuedWithSide($this);

        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set internal_note = 'Changed note', internal_note_updated_at = now(), updated_at = now() where id = ?", [$admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set internal_note = null, internal_note_updated_at = now() where id = ?', [$admin]));

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_admin set exchange_rate = 24.5, exchange_unit_amount = 1, exchange_rate_date = '2026-10-17', czk_total_minor = 5 where id = ?", [$admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_admin set exchange_rate_date = '2026-10-17' where id = ?", [$admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set invoice_id = ? where id = ?', [adminTablesDocument($this->adminTablesClient), $admin]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_admin where id = ?', [$admin]));
        RawSql::expectSqlState('KP001', fn () => adminTablesAdminRow($invoice));
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoice_admin'));
    });

    it('takes the CNB figures while the document is open, and never changes its document or deletes the row', function (): void {
        ['invoice' => $invoice, 'admin' => $admin] = adminTablesIssuedWithSide($this, locked: false);

        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set exchange_rate = 24.5, exchange_unit_amount = 1, exchange_rate_date = '2026-10-17', czk_total_minor = 24500, updated_at = now() where id = ?", [$admin]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set internal_note = 'Changed note', internal_note_updated_at = now() where id = ?", [$admin]));

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set invoice_id = ? where id = ?', [adminTablesDocument($this->adminTablesClient), $admin]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_admin where id = ?', [$admin]));
        RawSql::expectSqlState('23505', fn () => adminTablesAdminRow($invoice));
    });

    it('takes a card switch change, a first pay token and a bank account id on an open issued document (OA-1, D-03, D-05)', function (): void {
        ['admin' => $admin] = adminTablesIssuedWithSide($this, locked: false);
        $token = str_repeat('d', 32);
        $account = (string) Str::uuid7();

        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set card_payment_enabled = true where id = ?', [$admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set card_payment_enabled = false where id = ?', [$admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set card_payment_enabled = true, pay_token = ? where id = ?', [$token, $admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set card_payment_enabled = false where id = ?', [$admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set bank_account_id = ? where id = ?', [$account, $admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set bank_account_id = null where id = ?', [$admin]));

        $row = DB::selectOne('select card_payment_enabled, pay_token, bank_account_id from invoice_admin where id = ?', [$admin]);

        expect($row->card_payment_enabled)->toBeFalse()
            ->and($row->pay_token)->toBe($token)
            ->and($row->bank_account_id)->toBeNull();
    });

    it('never changes or removes a set pay token of an issued document, open or locked, and keeps it after a switch-off', function (bool $locked): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        $token = str_repeat('b', 32);
        $admin = adminTablesAdminRow($invoice, ['card_payment_enabled' => true, 'pay_token' => $token]);
        adminTablesIssue($invoice, $locked);

        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_admin set pay_token = '".str_repeat('c', 32)."' where id = ?", [$admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pay_token = null where id = ?', [$admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pay_token = null, card_payment_enabled = false where id = ?', [$admin]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_admin where id = ?', [$admin]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set internal_note = 'Still editable' where id = ?", [$admin]));

        expect(DB::selectOne('select pay_token from invoice_admin where id = ?', [$admin])->pay_token)->toBe($token);
    })->with(['open' => [false], 'locked' => [true]]);

    it('lets the token of an open issued document outlive a switch-off, and freezes the switch once locked', function (): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        $token = str_repeat('e', 32);
        $admin = adminTablesAdminRow($invoice, ['card_payment_enabled' => true, 'pay_token' => $token]);
        adminTablesIssue($invoice, false);

        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set card_payment_enabled = false where id = ?', [$admin]));
        expect(DB::selectOne('select card_payment_enabled, pay_token from invoice_admin where id = ?', [$admin]))
            ->card_payment_enabled->toBeFalse()
            ->pay_token->toBe($token);

        DB::update("update invoices set lock_state = 'locked' where id = ?", [$invoice]);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set card_payment_enabled = true where id = ?', [$admin]));
    });

    it('freezes the switch, a first token and the bank account id of a locked issued document (T-14-08)', function (): void {
        ['admin' => $admin] = adminTablesIssuedWithSide($this);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set card_payment_enabled = true where id = ?', [$admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pay_token = ? where id = ?', [str_repeat('f', 32), $admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set bank_account_id = ? where id = ?', [(string) Str::uuid7(), $admin]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set internal_note = 'Still editable', internal_note_updated_at = now(), updated_at = now() where id = ?", [$admin]));

        // A stored account id is frozen as well.
        $invoice = adminTablesDocument($this->adminTablesClient);
        $withAccount = adminTablesAdminRow($invoice, ['bank_account_id' => (string) Str::uuid7()]);
        adminTablesIssue($invoice, true);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set bank_account_id = null where id = ?', [$withAccount]));
    });

    it('leaves the switch, the token and the bank account id of a draft free, and checks the token format only', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        $admin = adminTablesAdminRow($draft);

        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set card_payment_enabled = true, bank_account_id = ? where id = ?', [(string) Str::uuid7(), $admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set pay_token = ? where id = ?', [str_repeat('g', 32), $admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set pay_token = ?, card_payment_enabled = false where id = ?', [str_repeat('h', 32), $admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set pay_token = null, bank_account_id = null where id = ?', [$admin]));

        RawSql::expectSqlState('23514', fn () => DB::update('update invoice_admin set pay_token = ? where id = ?', [str_repeat('g', 31), $admin]));
        RawSql::expectSqlState('23514', fn () => DB::update('update invoice_admin set pay_token = ? where id = ?', [str_repeat('g', 31).'-', $admin]));
        RawSql::expectSqlState('22P02', fn () => DB::update("update invoice_admin set bank_account_id = 'not-a-uuid' where id = ?", [$admin]));
    });

    it('stays freely editable and deletable on a draft', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        $admin = adminTablesAdminRow($draft);

        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set exchange_rate = 24.5, exchange_unit_amount = 1, exchange_rate_date = '2026-10-17', czk_total_minor = 24500 where id = ?", [$admin]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_admin where id = ?', [$admin]));
    });

    it('keeps the exchange trio all or none and the CZK total exactly with it', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);

        RawSql::expectSqlState('23514', fn () => adminTablesAdminRow($draft, ['exchange_rate' => '24.5']));
        RawSql::expectSqlState('23514', fn () => adminTablesAdminRow($draft, ['exchange_rate' => '24.5', 'exchange_unit_amount' => 1]));
        RawSql::expectSqlState('23514', fn () => adminTablesAdminRow($draft, ['exchange_rate_date' => '2026-10-17']));
        RawSql::expectSqlState('23514', fn () => adminTablesAdminRow($draft, ['czk_total_minor' => 5]));
        RawSql::expectSqlState('23514', fn () => adminTablesAdminRow($draft, ['exchange_rate' => '24.5', 'exchange_unit_amount' => 1, 'exchange_rate_date' => '2026-10-17']));
        RawSql::expectSqlState('23514', fn () => adminTablesAdminRow($draft, ['exchange_rate' => '0', 'exchange_unit_amount' => 1, 'exchange_rate_date' => '2026-10-17', 'czk_total_minor' => 5]));
        RawSql::expectAllowed(fn () => adminTablesAdminRow($draft, ['exchange_rate' => '24.5', 'exchange_unit_amount' => 1, 'exchange_rate_date' => '2026-10-17', 'czk_total_minor' => 24500]));
    });

    it('keeps one admin row per document and a note of at most 2000 characters', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        adminTablesAdminRow($draft);

        RawSql::expectSqlState('23505', fn () => adminTablesAdminRow($draft));
        RawSql::expectSqlState('23514', fn () => DB::update('update invoice_admin set internal_note = ? where invoice_id = ?', [str_repeat('x', 2001), $draft]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set internal_note = ? where invoice_id = ?', [str_repeat('x', 2000), $draft]));
    });
});

describe('the PDF link of an issued document (ruling R-06-6)', function (): void {
    it('is refused on a draft', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        $admin = adminTablesAdminRow($draft);
        $file = adminTablesFile($this->adminTablesUser, 'invoice', $draft);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$file, $admin]));
        RawSql::expectSqlState('KP001', fn () => adminTablesAdminRow(adminTablesDocument($this->adminTablesClient), ['pdf_document_id' => $file]));
    });

    it('is set only to a file owned by the same invoice', function (): void {
        ['invoice' => $invoice, 'admin' => $admin] = adminTablesIssuedWithSide($this);
        $other = adminTablesDocument($this->adminTablesClient);
        $own = adminTablesFile($this->adminTablesUser, 'invoice', $invoice);
        $ofOther = adminTablesFile($this->adminTablesUser, 'invoice', $other);
        $ofProject = adminTablesFile($this->adminTablesUser, 'project', $invoice);
        $unowned = adminTablesFile($this->adminTablesUser);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$ofOther, $admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$ofProject, $admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$unowned, $admin]));
        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set pdf_document_id = ?, updated_at = now() where id = ?', [$own, $admin]));

        expect(DB::selectOne('select pdf_document_id from invoice_admin where id = ?', [$admin])->pdf_document_id)->toBe($own);
    });

    it('is never repointed or cleared while its file exists, and is cleared by deleting the file', function (): void {
        ['invoice' => $invoice, 'admin' => $admin] = adminTablesIssuedWithSide($this);
        $first = adminTablesFile($this->adminTablesUser, 'invoice', $invoice);
        $second = adminTablesFile($this->adminTablesUser, 'invoice', $invoice);
        DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$first, $admin]);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$second, $admin]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = null where id = ?', [$admin]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_admin set internal_note = 'Still editable' where id = ?", [$admin]));

        RawSql::expectAllowed(fn () => DB::delete('delete from documents where id = ?', [$first]));
        expect(DB::selectOne('select pdf_document_id from invoice_admin where id = ?', [$admin])->pdf_document_id)->toBeNull();

        RawSql::expectAllowed(fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$second, $admin]));
        expect(DB::selectOne('select pdf_document_id from invoice_admin where id = ?', [$admin])->pdf_document_id)->toBe($second);
    });

    it('proves the link guard by a mutation: without it the link is repointed', function (): void {
        ['invoice' => $invoice, 'admin' => $admin] = adminTablesIssuedWithSide($this);
        $first = adminTablesFile($this->adminTablesUser, 'invoice', $invoice);
        $second = adminTablesFile($this->adminTablesUser, 'invoice', $invoice);
        DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$first, $admin]);

        $repoint = fn () => RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_admin set pdf_document_id = ? where id = ?', [$second, $admin]));

        $repoint();

        DB::unprepared('DROP TRIGGER invoice_admin_pdf_link_guard ON invoice_admin');

        expect($repoint)->toThrow(AssertionFailedError::class, 'was accepted');
    });
});

describe('invoice_item_sources', function (): void {
    it('is open while its document is open', function (): void {
        ['invoice' => $invoice, 'source' => $source] = adminTablesIssuedWithSide($this, locked: false);

        RawSql::expectAllowed(fn () => DB::update('update invoice_item_sources set price_manual = true, updated_at = now() where id = ?', [$source]));
        RawSql::expectAllowed(fn () => DB::insert("insert into invoice_item_sources (invoice_item_id, invoice_id, origin) values (?, ?, 'manual')", [adminTablesItem($invoice, 2), $invoice]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_item_sources where id = ?', [$source]));
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoice_item_sources'));
    });

    it('is frozen with its document once the document is locked, a timestamp-only write included', function (): void {
        ['invoice' => $invoice, 'source' => $source] = adminTablesIssuedWithSide($this);

        RawSql::expectSqlState('KP001', fn () => DB::update('update invoice_item_sources set price_manual = true where id = ?', [$source]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_item_sources set updated_at = '2030-01-01 00:00:00+00' where id = ?", [$source]));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_item_sources where id = ?', [$source]));
        RawSql::expectSqlState('KP001', fn () => DB::insert("insert into invoice_item_sources (invoice_item_id, invoice_id, origin) values (?, ?, 'manual')", [adminTablesItem(adminTablesDocument($this->adminTablesClient)), $invoice]));
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoice_item_sources'));
    });

    it('is freely editable and deletable on a draft', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        $item = adminTablesItem($draft);
        DB::insert("insert into invoice_item_sources (invoice_item_id, invoice_id, origin) values (?, ?, 'manual')", [$item, $draft]);

        RawSql::expectAllowed(fn () => DB::update('update invoice_item_sources set price_manual = true, updated_at = now() where invoice_item_id = ?', [$item]));
        RawSql::expectAllowed(fn () => DB::delete('delete from invoice_item_sources where invoice_item_id = ?', [$item]));
    });

    it('keeps its rules as checks and keys', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        $otherDraft = adminTablesDocument($this->adminTablesClient);
        $item = adminTablesItem($draft);
        $insert = fn (string $columns, array $values) => DB::insert("insert into invoice_item_sources (invoice_item_id, invoice_id, $columns) values (?, ?, ".implode(', ', array_fill(0, count($values), '?')).')', [$item, $draft, ...$values]);

        RawSql::expectSqlState('23514', fn () => $insert('origin', ['mystery']));
        RawSql::expectSqlState('23514', fn () => $insert('origin', ['time']));
        RawSql::expectSqlState('23514', fn () => $insert('origin, source_seconds', ['time', -1]));
        RawSql::expectSqlState('23514', fn () => $insert('origin, source_rate_minor', ['manual', 5]));
        RawSql::expectSqlState('23514', fn () => $insert('origin, source_rate_currency', ['manual', 'CZK']));
        RawSql::expectSqlState('23514', fn () => $insert('origin, conversion_snapshot', ['manual', '[1]']));
        RawSql::expectSqlState('23503', fn () => DB::insert("insert into invoice_item_sources (invoice_item_id, invoice_id, origin) values (?, ?, 'manual')", [$item, $otherDraft]));

        RawSql::expectAllowed(fn () => $insert('origin, source_seconds, source_rate_minor, source_rate_currency, price_manual', ['time', 3600, 100000, 'CZK', 'false']));
        RawSql::expectSqlState('23505', fn () => $insert('origin', ['manual']));
    });

    it('ties the task to its project', function (): void {
        app(PartnerContext::class)->runAsSystem(function (): void {
            $projectA = Project::factory()->create(['client_id' => $this->adminTablesClient]);
            $projectB = Project::factory()->create(['client_id' => $this->adminTablesClient]);
            $this->adminTablesProjectA = $projectA->id;
            $this->adminTablesProjectB = $projectB->id;
            $this->adminTablesTaskA = Task::factory()->create(['project_id' => $projectA->id])->id;
        });

        $draft = adminTablesDocument($this->adminTablesClient);
        $item = adminTablesItem($draft);
        $insert = fn (?string $project, ?string $task) => DB::insert("insert into invoice_item_sources (invoice_item_id, invoice_id, origin, project_id, task_id) values (?, ?, 'manual', ?, ?)", [$item, $draft, $project, $task]);

        RawSql::expectSqlState('23503', fn () => $insert($this->adminTablesProjectB, $this->adminTablesTaskA));
        RawSql::expectSqlState('23514', fn () => $insert(null, $this->adminTablesTaskA));
        RawSql::expectAllowed(fn () => $insert($this->adminTablesProjectA, $this->adminTablesTaskA));
    });
});

describe('invoice_payments', function (): void {
    it('is recorded only for an issued invoice or proforma, in the document currency, with an amount above zero', function (): void {
        $draft = adminTablesDocument($this->adminTablesClient);
        $creditNote = adminTablesDocument($this->adminTablesClient, ['kind' => 'credit_note', 'credited_invoice_id' => adminTablesDocument($this->adminTablesClient)]);
        $invoice = adminTablesDocument($this->adminTablesClient);
        $proforma = adminTablesDocument($this->adminTablesClient, ['kind' => 'proforma']);

        RawSql::expectSqlState('KP001', fn () => adminTablesPayment($draft, $this->adminTablesUser));

        adminTablesIssue($creditNote);
        adminTablesIssue($invoice);
        adminTablesIssue($proforma);

        RawSql::expectSqlState('KP001', fn () => adminTablesPayment($creditNote, $this->adminTablesUser));
        RawSql::expectSqlState('23503', fn () => adminTablesPayment($invoice, $this->adminTablesUser, ['currency' => 'EUR']));
        RawSql::expectSqlState('23514', fn () => adminTablesPayment($invoice, $this->adminTablesUser, ['amount_minor' => 0]));
        RawSql::expectSqlState('23514', fn () => adminTablesPayment($invoice, $this->adminTablesUser, ['method' => 'barter']));
        RawSql::expectAllowed(fn () => adminTablesPayment($invoice, $this->adminTablesUser));
        RawSql::expectAllowed(fn () => adminTablesPayment($proforma, $this->adminTablesUser, ['method' => 'cash', 'note' => 'Fictional note']));
    });

    it('is never updated, also under the deletion flag, and TRUNCATE is refused', function (): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        adminTablesIssue($invoice);
        $payment = adminTablesPayment($invoice, $this->adminTablesUser);

        foreach (['amount_minor = 1', "currency = 'EUR'", "paid_on = '2026-10-21'", "method = 'cash'", "note = 'changed'", 'updated_at = now()'] as $assignment) {
            RawSql::expectSqlState('KP001', fn () => DB::update("update invoice_payments set $assignment where id = ?", [$payment]));
        }

        RawSql::expectSqlState('KP001', function () use ($payment): void {
            DB::select("select set_config('kokpit.payment_deletion', ?, true)", [$payment]);
            DB::update('update invoice_payments set amount_minor = 1 where id = ?', [$payment]);
        });
        RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE invoice_payments CASCADE'));

        expect((int) DB::selectOne('select amount_minor from invoice_payments where id = ?', [$payment])->amount_minor)->toBe(40000);
    });

    it('is deleted only when the deletion flag names exactly that payment', function (): void {
        $proforma = adminTablesDocument($this->adminTablesClient, ['kind' => 'proforma']);
        adminTablesIssue($proforma);
        $payment = adminTablesPayment($proforma, $this->adminTablesUser);
        $another = adminTablesPayment($proforma, $this->adminTablesUser, ['amount_minor' => 100]);

        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_payments where id = ?', [$payment]));
        RawSql::expectSqlState('KP001', function () use ($payment): void {
            DB::unprepared("SET LOCAL kokpit.unrelated_setting = 'on'");
            DB::delete('delete from invoice_payments where id = ?', [$payment]);
        });
        RawSql::expectSqlState('KP001', fn () => adminTablesDeleteFlagged($payment, $another));
        RawSql::expectSqlState('KP001', fn () => adminTablesDeleteFlagged($payment, ''));
        RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_payments where invoice_id = ?', [$proforma]));

        expect((int) DB::selectOne('select count(*) as n from invoice_payments where invoice_id = ?', [$proforma])->n)->toBe(2);

        RawSql::expectAllowed(fn () => adminTablesDeleteFlagged($payment));

        expect((int) DB::selectOne('select count(*) as n from invoice_payments where id = ?', [$payment])->n)->toBe(0)
            ->and((int) DB::selectOne('select count(*) as n from invoice_payments where id = ?', [$another])->n)->toBe(1);
    });

    it('refuses to delete the source of a carried copy while the copy exists', function (): void {
        $proforma = adminTablesDocument($this->adminTablesClient, ['kind' => 'proforma']);
        adminTablesIssue($proforma);
        $source = adminTablesPayment($proforma, $this->adminTablesUser);
        $invoice = adminTablesDocument($this->adminTablesClient, ['proforma_id' => $proforma]);
        adminTablesIssue($invoice);
        $copy = adminTablesPayment($invoice, $this->adminTablesUser, ['carried_from_payment_id' => $source]);

        RawSql::expectSqlState('23503', fn () => adminTablesDeleteFlagged($source));
        RawSql::expectAllowed(fn () => adminTablesDeleteFlagged($copy));

        expect((int) DB::selectOne('select amount_minor from invoice_payments where id = ?', [$source])->amount_minor)->toBe(40000);

        RawSql::expectAllowed(fn () => adminTablesDeleteFlagged($source));
    });

    it('carries a proforma payment at most once', function (): void {
        $proforma = adminTablesDocument($this->adminTablesClient, ['kind' => 'proforma']);
        adminTablesIssue($proforma);
        $source = adminTablesPayment($proforma, $this->adminTablesUser);
        $invoice = adminTablesDocument($this->adminTablesClient, ['proforma_id' => $proforma]);
        adminTablesIssue($invoice);
        adminTablesPayment($invoice, $this->adminTablesUser, ['carried_from_payment_id' => $source]);

        RawSql::expectSqlState('23505', fn () => adminTablesPayment($invoice, $this->adminTablesUser, ['carried_from_payment_id' => $source]));
    });

    it('proves the payment guard by a mutation: without it a bare DELETE is accepted', function (): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        adminTablesIssue($invoice);
        $payment = adminTablesPayment($invoice, $this->adminTablesUser);

        $bareDelete = fn () => RawSql::expectSqlState('KP001', fn () => DB::delete('delete from invoice_payments where id = ?', [$payment]));

        $bareDelete();

        DB::unprepared('DROP TRIGGER invoice_payments_guard ON invoice_payments');

        expect($bareDelete)->toThrow(AssertionFailedError::class, 'was accepted');
    });
});

/**
 * Inserts one e-mail row by raw SQL and returns its id. Overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function adminTablesEmail(object $test, string $invoiceId, array $overrides = []): string
{
    $row = [
        'invoice_id' => $invoiceId,
        'recipient' => 'jane@example.com',
        'subject' => 'Fictional invoice',
        'body' => 'Fictional body',
        'created_by' => $test->adminTablesUser,
        'created_at' => '2026-10-17 11:00:00+00',
        'updated_at' => '2026-10-17 11:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into invoice_emails (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

describe('invoice_emails', function (): void {
    it('ties the sent state to its time and a failure code to the failed state', function (): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        adminTablesIssue($invoice);

        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'sent']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'queued', 'sent_at' => '2026-10-17 11:05:00+00']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'queued', 'failure_code' => 'smtp']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'delivered']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['attempts' => -1]));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['body' => str_repeat('x', 5001)]));
        RawSql::expectAllowed(fn () => adminTablesEmail($this, $invoice, ['state' => 'sent', 'sent_at' => '2026-10-17 11:05:00+00']));
        RawSql::expectAllowed(fn () => adminTablesEmail($this, $invoice, ['state' => 'failed', 'failure_code' => 'smtp', 'attempts' => 3]));
    });

    it('allows the skipped state of Phase 16 without a send time or a failure code', function (): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        adminTablesIssue($invoice);

        RawSql::expectAllowed(fn () => adminTablesEmail($this, $invoice, ['state' => 'skipped']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'skipped', 'sent_at' => '2026-10-17 11:05:00+00']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'skipped', 'failure_code' => 'smtp']));
        RawSql::expectSqlState('23514', fn () => adminTablesEmail($this, $invoice, ['state' => 'skipp']));
    });

    it('moves its state and attempts freely, also for an issued document', function (): void {
        $invoice = adminTablesDocument($this->adminTablesClient);
        adminTablesIssue($invoice);
        $email = adminTablesEmail($this, $invoice);

        RawSql::expectAllowed(fn () => DB::update("update invoice_emails set state = 'sending', attempts = attempts + 1, updated_at = now() where id = ?", [$email]));
        RawSql::expectAllowed(fn () => DB::update("update invoice_emails set state = 'sent', sent_at = now() where id = ?", [$email]));
    });
});
