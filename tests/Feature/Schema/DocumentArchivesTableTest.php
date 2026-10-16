<?php

declare(strict_types=1);

use App\Domain\Documents\Enums\DocumentArchiveStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * The storage end of the ZIP archives of documents (DO-02, D-08), proven by raw SQL so no model, cast or Action
 * stands between a value and its constraint. Every value is invented.
 */

/** A server-shaped archive path with an invented UUID. */
const ARCHIVE_ROW_PATH = 'document-archives/0190b3a0-0000-7000-8000-000000000001.zip';

/**
 * Inserts one archive row by raw SQL with valid defaults and a freshly created Admin as the owner; overrides
 * replace single columns (the column names come from the tests only).
 *
 * @param  array<string, mixed>  $overrides
 */
function documentArchivesRawInsert(array $overrides = []): string
{
    $row = [
        'user_id' => (string) Canary::admin()->getKey(),
        'summary' => '2 soubory, 3 KB',
        'document_ids' => '["0190b3a0-0000-7000-8000-0000000000a1", "0190b3a0-0000-7000-8000-0000000000a2"]',
        'document_count' => 2,
        'total_bytes' => 3072,
        'expires_at' => '2026-10-23 10:00:00+00',
        'created_at' => '2026-10-16 10:00:00+00',
        'updated_at' => '2026-10-16 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into document_archives (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

it('gives a row inserted without an id a database-generated UUID version 7 and the pending status', function (): void {
    $id = documentArchivesRawInsert();
    $row = DB::selectOne('select id, status from document_archives where id = ?', [$id]);

    expect($row->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($row->status)->toBe('pending');
});

describe('checks (23514)', function (): void {
    it('refuses an unknown status', function (): void {
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['status' => 'done']));
    });

    it('refuses a ready row without a path or without a completion time', function (): void {
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert([
            'status' => 'ready',
            'completed_at' => '2026-10-16 10:05:00+00',
        ]));
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert([
            'status' => 'ready',
            'path' => ARCHIVE_ROW_PATH,
        ]));
    });

    it('accepts a ready row with both', function (): void {
        RawSql::expectAllowed(fn () => documentArchivesRawInsert([
            'status' => 'ready',
            'path' => ARCHIVE_ROW_PATH,
            'completed_at' => '2026-10-16 10:05:00+00',
        ]));
    });

    it('refuses a path outside the archive area or of another shape', function (string $path): void {
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['path' => $path]));
    })->with([
        'traversal' => '../x.zip',
        'not a uuid' => 'document-archives/abc.zip',
        'other directory' => 'elsewhere/0190b3a0-0000-7000-8000-000000000001.zip',
        'absolute' => '/document-archives/0190b3a0-0000-7000-8000-000000000001.zip',
        'nested' => 'document-archives/../0190b3a0-0000-7000-8000-000000000001.zip',
        'other extension' => 'document-archives/0190b3a0-0000-7000-8000-000000000001.tar',
        'trailing text' => 'document-archives/0190b3a0-0000-7000-8000-000000000001.zip/x',
    ]);

    it('accepts the server-generated path', function (): void {
        RawSql::expectAllowed(fn () => documentArchivesRawInsert(['path' => ARCHIVE_ROW_PATH]));
    });

    it('refuses document ids that are not a JSON array', function (string $ids): void {
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['document_ids' => $ids]));
    })->with(['empty object' => '{}', 'string' => '"x"', 'number' => '1', 'null literal' => 'null']);

    it('refuses a count that does not match the number of ids, and a zero count', function (): void {
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['document_count' => 3]));
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['document_count' => 1]));
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['document_count' => 0, 'document_ids' => '[]']));
        RawSql::expectAllowed(fn () => documentArchivesRawInsert(['document_count' => 1, 'document_ids' => '["0190b3a0-0000-7000-8000-0000000000a1"]']));
    });

    it('refuses a negative total size and accepts zero', function (): void {
        RawSql::expectSqlState('23514', fn () => documentArchivesRawInsert(['total_bytes' => -1]));
        RawSql::expectAllowed(fn () => documentArchivesRawInsert(['total_bytes' => 0]));
    });

    it('keeps the enum values equal to the constraint list', function (): void {
        foreach (DocumentArchiveStatus::cases() as $case) {
            RawSql::expectAllowed(fn () => documentArchivesRawInsert($case === DocumentArchiveStatus::Ready
                ? ['status' => 'ready', 'path' => ARCHIVE_ROW_PATH, 'completed_at' => '2026-10-16 10:05:00+00']
                : ['status' => $case->value]));
        }
    });
});

describe('references (23503)', function (): void {
    it('refuses an owner that is not a user', function (): void {
        RawSql::expectSqlState('23503', fn () => documentArchivesRawInsert(['user_id' => '0190b3a0-0000-7000-8000-0000000000ff']));
    });

    it('refuses to delete a user who still owns an archive', function (): void {
        $id = documentArchivesRawInsert();
        $userId = DB::selectOne('select user_id from document_archives where id = ?', [$id])->user_id;

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from users where id = ?', [$userId]));
    });
});
