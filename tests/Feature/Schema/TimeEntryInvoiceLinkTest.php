<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RawSql;

/*
 * The link between a time entry and the document that reserves or bills it (IN-02, IN-10, D-03 to D-05, D-14,
 * D-15, TI-08). Every case is raw SQL, so the proof holds for every writer. Clients, numbers and amounts are
 * invented; no real business data.
 *
 * States of an entry: free (no link, unbilled), reserved (link, unbilled, by a draft), billed by a document
 * (link, billed, the document issued), billed by hand (no link, billed; Phase 6).
 */

beforeEach(function (): void {
    app(PartnerContext::class)->runAsSystem(function (): void {
        $this->timeLinkUser = User::factory()->create()->id;
        $this->timeLinkClientA = Client::factory()->create()->id;
        $this->timeLinkClientB = Client::factory()->create()->id;
    });
});

/**
 * Inserts one draft document by raw SQL and returns its id.
 *
 * @param  array<string, mixed>  $overrides
 */
function timeLinkDocument(string $clientId, array $overrides = []): string
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
 * Issues the draft document by raw SQL; a credit note gets a negative total and no due date.
 */
function timeLinkIssue(string $documentId): void
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
        .'supplier_snapshot = ?, customer_snapshot = ?, bank_snapshot = ?, appearance_snapshot = ?, issued_at = \'2026-10-17 10:30:00+00\' where id = ?',
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
            $documentId,
        ],
    );
}

/**
 * A draft credit note of the invoice, issued; $cancels says whether it is the full storno.
 */
function timeLinkIssuedCreditNote(string $invoiceId, string $clientId, bool $cancels): string
{
    $creditNote = timeLinkDocument($clientId, [
        'kind' => 'credit_note',
        'credited_invoice_id' => $invoiceId,
        'cancels_invoice' => $cancels ? 'true' : 'false',
    ]);
    timeLinkIssue($creditNote);

    return $creditNote;
}

/**
 * Inserts one finished one-hour entry by raw SQL and returns its id. Overrides replace single columns.
 *
 * @param  array<string, mixed>  $overrides
 */
function timeLinkEntry(string $userId, string $clientId, array $overrides = []): string
{
    $row = [
        'user_id' => $userId,
        'client_id' => $clientId,
        'started_at' => '2026-10-12 10:00:00+00',
        'ended_at' => '2026-10-12 11:00:00+00',
        'created_at' => '2026-10-12 12:00:00+00',
        'updated_at' => '2026-10-12 12:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into time_entries (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

/**
 * Reserves the entry for the document by raw SQL.
 */
function timeLinkReserve(string $entryId, string $documentId): void
{
    DB::update('update time_entries set invoice_id = ? where id = ?', [$documentId, $entryId]);
}

/**
 * Bills the reserved entry with a snapshot of 1,000.00 an hour and 1,000.00 in total.
 */
function timeLinkBill(string $entryId): void
{
    DB::update(
        "update time_entries set billing_state = 'billed', billed_at = '2026-10-17 10:30:00+00', billed_rate_minor = 100000, billed_rate_currency = 'CZK', billed_amount_minor = 100000 where id = ?",
        [$entryId],
    );
}

/**
 * The entry as a row.
 */
function timeLinkRow(string $entryId): object
{
    /** @var object $row */
    $row = DB::selectOne('select * from time_entries where id = ?', [$entryId]);

    return $row;
}

/**
 * A billed entry of the draft's client: reserved, the draft issued, then billed with its snapshot.
 *
 * @return array{0: string, 1: string} the entry id and the invoice id
 */
function timeLinkBilledEntry(object $test): array
{
    $invoice = timeLinkDocument($test->timeLinkClientA);
    $entry = timeLinkEntry($test->timeLinkUser, $test->timeLinkClientA);
    timeLinkReserve($entry, $invoice);
    timeLinkIssue($invoice);
    timeLinkBill($entry);

    return [$entry, $invoice];
}

describe('reserving an entry', function (): void {
    it('lets a draft reserve a free entry and release it again', function (): void {
        $draft = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);

        RawSql::expectAllowed(fn () => timeLinkReserve($entry, $draft));
        expect(timeLinkRow($entry)->invoice_id)->toBe($draft)
            ->and(timeLinkRow($entry)->billing_state)->toBe('unbilled');

        RawSql::expectAllowed(fn () => DB::update('update time_entries set invoice_id = null where id = ?', [$entry]));
        expect(timeLinkRow($entry)->invoice_id)->toBeNull();
    });

    it('refuses a second draft taking an entry the first draft holds', function (): void {
        $first = timeLinkDocument($this->timeLinkClientA);
        $second = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);
        timeLinkReserve($entry, $first);

        RawSql::expectSqlState('KP001', fn () => timeLinkReserve($entry, $second));

        expect(timeLinkRow($entry)->invoice_id)->toBe($first);
    });

    it('refuses to reserve an entry that is billed by hand', function (): void {
        $draft = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA, ['billing_state' => 'billed', 'billed_at' => '2026-10-13 09:00:00+00']);

        RawSql::expectSqlState('KP001', fn () => timeLinkReserve($entry, $draft));
    });

    it('refuses to reserve an entry for an issued document', function (): void {
        $issued = timeLinkDocument($this->timeLinkClientA);
        timeLinkIssue($issued);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);

        RawSql::expectSqlState('KP001', fn () => timeLinkReserve($entry, $issued));
        RawSql::expectSqlState('KP001', fn () => timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA, ['invoice_id' => $issued]));
    });

    it('lets a new entry be created already reserved by a draft', function (): void {
        $draft = timeLinkDocument($this->timeLinkClientA);

        RawSql::expectAllowed(fn () => timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA, ['invoice_id' => $draft]));
    });

    it('refuses an entry of another client (composite key)', function (): void {
        $draft = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientB);

        RawSql::expectSqlState('23503', fn () => timeLinkReserve($entry, $draft));
    });

    it('refuses to bill and reserve in one statement, or to bill by hand while keeping nothing of the reservation', function (): void {
        $draft = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);

        RawSql::expectSqlState('KP001', fn () => DB::update(
            "update time_entries set invoice_id = ?, billing_state = 'billed', billed_at = now() where id = ?",
            [$draft, $entry],
        ));

        timeLinkReserve($entry, $draft);

        RawSql::expectSqlState('KP001', fn () => DB::update(
            "update time_entries set invoice_id = null, billing_state = 'billed', billed_at = now() where id = ?",
            [$entry],
        ));
    });
});

describe('billing an entry', function (): void {
    it('refuses to bill an entry while its document is still a draft', function (): void {
        $draft = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);
        timeLinkReserve($entry, $draft);

        RawSql::expectSqlState('KP001', fn () => timeLinkBill($entry));
    });

    it('bills an entry with a snapshot once its document is issued', function (): void {
        [$entry, $invoice] = timeLinkBilledEntry($this);

        $row = timeLinkRow($entry);

        expect($row->billing_state)->toBe('billed')
            ->and($row->invoice_id)->toBe($invoice)
            ->and($row->billed_rate_minor)->toBe(100000)
            ->and($row->billed_rate_currency)->toBe('CZK')
            ->and($row->billed_amount_minor)->toBe(100000);
    });

    it('still bills an entry by hand without a link (Phase 6)', function (): void {
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);

        RawSql::expectAllowed(fn () => DB::update("update time_entries set billing_state = 'billed', billed_at = now() where id = ?", [$entry]));
        RawSql::expectAllowed(fn () => DB::update("update time_entries set billing_state = 'unbilled', billed_at = null where id = ?", [$entry]));
    });

    it('freezes the link and the snapshot of a billed entry', function (): void {
        [$entry] = timeLinkBilledEntry($this);
        $otherInvoice = timeLinkDocument($this->timeLinkClientA);
        timeLinkIssue($otherInvoice);

        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set billed_amount_minor = 1 where id = ?', [$entry]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set billed_rate_minor = 1 where id = ?', [$entry]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update time_entries set billed_rate_currency = 'EUR' where id = ?", [$entry]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set invoice_id = ? where id = ?', [$otherInvoice, $entry]));
        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set invoice_id = null where id = ?', [$entry]));
        RawSql::expectSqlState('KP001', fn () => DB::update("update time_entries set started_at = '2026-10-12 09:00:00+00' where id = ?", [$entry]));
    });

    it('freezes the snapshot of an entry that was billed by hand', function (): void {
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA, ['billing_state' => 'billed', 'billed_at' => '2026-10-13 09:00:00+00']);

        RawSql::expectSqlState('KP001', fn () => DB::update("update time_entries set billed_rate_minor = 5, billed_rate_currency = 'CZK' where id = ?", [$entry]));
    });

    it('refuses a snapshot on an entry that is not billed, and half a snapshot', function (): void {
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);

        RawSql::expectSqlState('23514', fn () => DB::update("update time_entries set billed_rate_minor = 5, billed_rate_currency = 'CZK' where id = ?", [$entry]));
        RawSql::expectSqlState('23514', fn () => DB::update('update time_entries set billed_amount_minor = 5 where id = ?', [$entry]));

        $billable = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);
        RawSql::expectSqlState('23514', fn () => DB::update("update time_entries set billing_state = 'billed', billed_at = now(), billed_rate_minor = 5 where id = ?", [$billable]));
        RawSql::expectSqlState('23514', fn () => DB::update("update time_entries set billing_state = 'billed', billed_at = now(), billed_rate_minor = -5, billed_rate_currency = 'CZK' where id = ?", [$billable]));
    });
});

describe('releasing a billed entry', function (): void {
    it('refuses to unbill an entry and keep its link', function (): void {
        [$entry] = timeLinkBilledEntry($this);

        RawSql::expectSqlState('KP001', fn () => DB::update(
            "update time_entries set billing_state = 'unbilled', billed_at = null, billed_rate_minor = null, billed_rate_currency = null, billed_amount_minor = null where id = ?",
            [$entry],
        ));
    });

    it('refuses the release while the invoice is not cancelled, a partial credit note included', function (): void {
        [$entry, $invoice] = timeLinkBilledEntry($this);
        $release = fn () => DB::update(
            "update time_entries set invoice_id = null, billing_state = 'unbilled', billed_at = null, billed_rate_minor = null, billed_rate_currency = null, billed_amount_minor = null where id = ?",
            [$entry],
        );

        RawSql::expectSqlState('KP001', $release);

        timeLinkIssuedCreditNote($invoice, $this->timeLinkClientA, cancels: false);

        RawSql::expectSqlState('KP001', $release);
    });

    it('releases an entry once an issued storno credit note names the invoice, with no write on the invoice', function (): void {
        [$entry, $invoice] = timeLinkBilledEntry($this);
        $before = DB::selectOne('select xmin::text as version, updated_at from invoices where id = ?', [$invoice]);

        timeLinkIssuedCreditNote($invoice, $this->timeLinkClientA, cancels: true);

        RawSql::expectAllowed(fn () => DB::update(
            "update time_entries set invoice_id = null, billing_state = 'unbilled', billed_at = null, billed_rate_minor = null, billed_rate_currency = null, billed_amount_minor = null where id = ?",
            [$entry],
        ));

        $row = timeLinkRow($entry);
        $after = DB::selectOne('select xmin::text as version, updated_at from invoices where id = ?', [$invoice]);

        expect($row->invoice_id)->toBeNull()
            ->and($row->billing_state)->toBe('unbilled')
            ->and($row->billed_rate_minor)->toBeNull()
            ->and($after->version)->toBe($before->version)
            ->and($after->updated_at)->toBe($before->updated_at);
    });

    it('refuses to release while the storno credit note is only a draft', function (): void {
        [$entry, $invoice] = timeLinkBilledEntry($this);
        timeLinkDocument($this->timeLinkClientA, ['kind' => 'credit_note', 'credited_invoice_id' => $invoice, 'cancels_invoice' => 'true']);

        RawSql::expectSqlState('KP001', fn () => DB::update(
            "update time_entries set invoice_id = null, billing_state = 'unbilled', billed_at = null, billed_rate_minor = null, billed_rate_currency = null, billed_amount_minor = null where id = ?",
            [$entry],
        ));
    });
});

describe('moving an entry from a proforma to its invoice', function (): void {
    it('lets a billed entry move to the invoice created from its proforma and to no other', function (): void {
        $proforma = timeLinkDocument($this->timeLinkClientA, ['kind' => 'proforma']);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);
        timeLinkReserve($entry, $proforma);
        timeLinkIssue($proforma);
        timeLinkBill($entry);

        $stranger = timeLinkDocument($this->timeLinkClientA);
        timeLinkIssue($stranger);
        $invoice = timeLinkDocument($this->timeLinkClientA, ['proforma_id' => $proforma]);

        // Still a draft: the entry stays with the proforma.
        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set invoice_id = ? where id = ?', [$invoice, $entry]));

        timeLinkIssue($invoice);

        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set invoice_id = ? where id = ?', [$stranger, $entry]));
        RawSql::expectAllowed(fn () => DB::update('update time_entries set invoice_id = ? where id = ?', [$invoice, $entry]));

        $row = timeLinkRow($entry);

        expect($row->invoice_id)->toBe($invoice)
            ->and($row->billing_state)->toBe('billed')
            ->and($row->billed_amount_minor)->toBe(100000);
    });

    it('does not let an entry move off an invoice that is not a proforma', function (): void {
        [$entry] = timeLinkBilledEntry($this);
        $other = timeLinkDocument($this->timeLinkClientA);
        timeLinkIssue($other);

        RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set invoice_id = ? where id = ?', [$other, $entry]));
    });
});

describe('the guard itself', function (): void {
    it('proves the link guard by a mutation: without it a second draft takes the entry', function (): void {
        $first = timeLinkDocument($this->timeLinkClientA);
        $second = timeLinkDocument($this->timeLinkClientA);
        $entry = timeLinkEntry($this->timeLinkUser, $this->timeLinkClientA);
        timeLinkReserve($entry, $first);

        $overwrite = fn () => RawSql::expectSqlState('KP001', fn () => timeLinkReserve($entry, $second));

        $overwrite();

        DB::unprepared('DROP TRIGGER time_entries_link_guard ON time_entries');

        expect($overwrite)->toThrow(AssertionFailedError::class, 'was accepted');
    });

    it('keeps the generic frozen guard with duration_seconds mutable', function (): void {
        /** @var object{args: string} $trigger */
        $trigger = DB::selectOne("select encode(tgargs, 'escape') as args from pg_trigger where tgname = 'time_entries_frozen_guard' and not tgisinternal");

        $arguments = explode('\000', $trigger->args);
        $mutable = explode(',', $arguments[2]);

        expect($arguments[0])->toBe('billing_state')
            ->and($arguments[1])->toBe('unbilled')
            ->and($mutable)->toContain('duration_seconds')
            ->and($mutable)->toEqualCanonicalizing([
                'billing_state', 'billed_at', 'duration_seconds', 'invoice_id',
                'billed_rate_minor', 'billed_rate_currency', 'billed_amount_minor', 'updated_at',
            ]);
    });
});
