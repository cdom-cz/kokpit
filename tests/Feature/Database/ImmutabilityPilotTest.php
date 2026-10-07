<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Support\RawSql;

/*
 * The pilot table exists only inside each test; PostgreSQL DDL is
 * transactional, so RefreshDatabase rolls it back and the schema catalogue
 * test never sees it.
 */
function createPilotDocuments(): void
{
    Schema::create('pilot_documents', function ($table) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        $table->text('status')->default('draft');
        $table->text('number')->nullable();
        $table->timestampTz('issued_at')->nullable();
        $table->bigInteger('amount_minor');
        $table->char('currency', 3);
        $table->text('note')->nullable();
        $table->timestampsTz();
    });

    DB::statement("ALTER TABLE pilot_documents ADD CONSTRAINT pilot_documents_status_check CHECK (status IN ('draft', 'issued'))");
    DB::statement("ALTER TABLE pilot_documents ADD CONSTRAINT pilot_documents_issued_check CHECK (status = 'draft' OR (number IS NOT NULL AND issued_at IS NOT NULL))");
    DB::statement('ALTER TABLE pilot_documents ADD CONSTRAINT pilot_documents_amount_check CHECK (amount_minor >= 0)');
    DB::statement("ALTER TABLE pilot_documents ADD CONSTRAINT pilot_documents_currency_check CHECK (currency ~ '^[A-Z]{3}\$')");
    DB::statement('CREATE UNIQUE INDEX pilot_documents_number_unique ON pilot_documents (number) WHERE number IS NOT NULL');

    DB::unprepared(Immutability::guardTriggerSql('pilot_documents', 'status', 'draft', ['note']));

    Schema::create('pilot_document_lines', function ($table) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        // Default NO ACTION: a referenced parent delete is refused with 23503 (foreign_key_violation).
        $table->foreignUuid('document_id')->constrained('pilot_documents');
        $table->bigInteger('amount_minor');
        $table->timestampsTz();
    });
}

function insertPilotDraft(int $amount = 1000): string
{
    /** @var object{id: string} $row */
    $row = DB::selectOne(
        'insert into pilot_documents (amount_minor, currency) values (?, ?) returning id',
        [$amount, 'CZK'],
    );

    return $row->id;
}

function issuePilot(string $id, string $number): void
{
    DB::update("update pilot_documents set status = 'issued', number = ?, issued_at = now() where id = ?", [$number, $id]);
}

beforeEach(function () {
    createPilotDocuments();
});

it('installs the generic guard function through a migration', function () {
    $count = DB::selectOne("select count(*) as n from pg_proc where proname = 'kokpit_guard_frozen_row'");

    expect((int) $count->n)->toBe(1);
});

it('refuses a raw-SQL change to an issued row and keeps its operational note editable', function () {
    $id = insertPilotDraft();
    issuePilot($id, 'PILOT-'.random_int(1000, 9999));

    RawSql::expectSqlState('KP001', fn () => DB::update(
        'update pilot_documents set amount_minor = amount_minor + 1 where id = ?',
        [$id],
    ));

    RawSql::expectAllowed(fn () => DB::update(
        'update pilot_documents set note = ? where id = ?',
        ['follow-up '.bin2hex(random_bytes(3)), $id],
    ));
});

it('lets a draft be edited, issued and deleted', function () {
    $id = insertPilotDraft();

    RawSql::expectAllowed(fn () => DB::update('update pilot_documents set amount_minor = 2500 where id = ?', [$id]));
    RawSql::expectAllowed(fn () => issuePilot($id, 'PILOT-'.random_int(1000, 9999)));

    $draft = insertPilotDraft();
    RawSql::expectAllowed(fn () => DB::delete('delete from pilot_documents where id = ?', [$draft]));
});

it('refuses to change the number or the issue time of an issued row', function () {
    $id = insertPilotDraft();
    issuePilot($id, 'PILOT-'.random_int(1000, 9999));

    RawSql::expectSqlState('KP001', fn () => DB::update("update pilot_documents set number = 'PILOT-0' where id = ?", [$id]));
    RawSql::expectSqlState('KP001', fn () => DB::update("update pilot_documents set issued_at = now() + interval '1 day' where id = ?", [$id]));
    RawSql::expectSqlState('KP001', fn () => DB::update("update pilot_documents set status = 'draft' where id = ?", [$id]));
});

it('refuses to delete an issued row', function () {
    $id = insertPilotDraft();
    issuePilot($id, 'PILOT-'.random_int(1000, 9999));

    RawSql::expectSqlState('KP001', fn () => DB::delete('delete from pilot_documents where id = ?', [$id]));
});

it('rejects an issued row without a number with a check violation', function () {
    RawSql::expectSqlState('23514', fn () => DB::insert(
        "insert into pilot_documents (status, amount_minor, currency) values ('issued', 100, 'CZK')",
    ));
});

it('rejects a negative amount with a check violation', function () {
    RawSql::expectSqlState('23514', fn () => DB::insert(
        "insert into pilot_documents (amount_minor, currency) values (-1, 'CZK')",
    ));
});

it('rejects a lower-case currency with a check violation', function () {
    RawSql::expectSqlState('23514', fn () => DB::insert(
        "insert into pilot_documents (amount_minor, currency) values (100, 'czk')",
    ));
});

it('rejects a duplicate issued number through the partial unique index', function () {
    $number = 'PILOT-'.random_int(1000, 9999);
    issuePilot(insertPilotDraft(), $number);
    $second = insertPilotDraft();

    RawSql::expectSqlState('23505', fn () => issuePilot($second, $number));
});

it('allows two drafts that both have a NULL number', function () {
    RawSql::expectAllowed(function () {
        insertPilotDraft();
        insertPilotDraft();
    });

    expect((int) DB::selectOne('select count(*) as n from pilot_documents where number is null')->n)->toBe(2);
});

it('refuses to delete a draft that a line still references', function () {
    $id = insertPilotDraft();
    DB::insert('insert into pilot_document_lines (document_id, amount_minor) values (?, 100)', [$id]);

    RawSql::expectSqlState('23503', fn () => DB::delete('delete from pilot_documents where id = ?', [$id]));
});

it('answers a RESTRICT foreign key with its own SQLSTATE 23001, which is why the pilot uses NO ACTION', function () {
    Schema::create('pilot_restrict_lines', function ($table) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        $table->foreignUuid('document_id')->constrained('pilot_documents')->restrictOnDelete();
    });
    $id = insertPilotDraft();
    DB::insert('insert into pilot_restrict_lines (document_id) values (?)', [$id]);

    RawSql::expectSqlState('23001', fn () => DB::delete('delete from pilot_documents where id = ?', [$id]));
});

it('refuses TRUNCATE of a table that has the truncate guard installed', function () {
    Schema::create('pilot_truncate_guarded', function ($table) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        $table->text('status')->default('draft');
        $table->timestampsTz();
    });
    DB::unprepared(Immutability::truncateGuardSql('pilot_truncate_guarded'));
    DB::insert('insert into pilot_truncate_guarded default values');

    RawSql::expectSqlState('KP001', fn () => DB::unprepared('TRUNCATE pilot_truncate_guarded'));

    expect((int) DB::selectOne('select count(*) as n from pilot_truncate_guarded')->n)->toBe(1);
});

it('lets TRUNCATE through when the truncate guard is dropped again', function () {
    Schema::create('pilot_truncate_guarded', function ($table) {
        $table->uuid('id')->primary()->default(DB::raw('uuidv7()'));
        $table->timestampsTz();
    });
    DB::unprepared(Immutability::truncateGuardSql('pilot_truncate_guarded'));
    DB::unprepared(Immutability::dropTruncateGuardSql('pilot_truncate_guarded'));

    RawSql::expectAllowed(fn () => DB::unprepared('TRUNCATE pilot_truncate_guarded'));
});

it('fails the helper when a statement that must be rejected is accepted', function () {
    $id = insertPilotDraft();

    expect(fn () => RawSql::expectSqlState('KP001', fn () => DB::update('update pilot_documents set amount_minor = 5 where id = ?', [$id])))
        ->toThrow(AssertionFailedError::class, 'was accepted');
});

it('fails the helper when the SQLSTATE differs from the expected one', function () {
    expect(fn () => RawSql::expectSqlState('23505', fn () => DB::insert("insert into pilot_documents (amount_minor, currency) values (-1, 'CZK')")))
        ->toThrow(AssertionFailedError::class, '23514');
});

it('keeps the surrounding transaction usable after a rejected statement', function () {
    RawSql::expectSqlState('23514', fn () => DB::insert("insert into pilot_documents (amount_minor, currency) values (-1, 'CZK')"));

    expect((int) DB::selectOne('select 1 as n')->n)->toBe(1);
});
