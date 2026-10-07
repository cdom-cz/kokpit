<?php

declare(strict_types=1);

use App\Domain\Shared\Database\Immutability;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    DB::unprepared(Immutability::guardTriggerSql('pilot_documents', 'status', 'draft', ['note']));
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
