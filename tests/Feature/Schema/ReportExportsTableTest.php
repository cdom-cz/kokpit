<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use Illuminate\Support\Facades\DB;
use Tests\Support\Canary;
use Tests\Support\RawSql;

/*
 * The storage end of the report exports (RE-03, D-09), proven by raw SQL so no model, cast or Action stands
 * between a value and its constraint. Every value is invented.
 */

/**
 * Inserts one export row by raw SQL with valid defaults and a freshly created Admin as the owner; overrides replace
 * single columns (the column names come from the tests only).
 *
 * @param  array<string, mixed>  $overrides
 */
function reportExportsRawInsert(array $overrides = []): string
{
    $row = [
        'user_id' => (string) Canary::admin()->getKey(),
        'format' => 'csv',
        'summary' => 'Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026',
        'filters' => '{"from": "2026-09-01", "to": "2026-09-30"}',
        'expires_at' => '2026-10-08 10:00:00+00',
        'created_at' => '2026-10-01 10:00:00+00',
        'updated_at' => '2026-10-01 10:00:00+00',
        ...$overrides,
    ];

    $columns = array_keys($row);

    /** @var object{id: string} $inserted */
    $inserted = DB::selectOne(
        sprintf(
            'insert into report_exports (%s) values (%s) returning id',
            implode(', ', $columns),
            implode(', ', array_fill(0, count($columns), '?')),
        ),
        array_values($row),
    );

    return $inserted->id;
}

it('gives a row inserted without an id a database-generated UUID version 7 and the pending status', function (): void {
    $id = reportExportsRawInsert();
    $row = DB::selectOne('select id, status from report_exports where id = ?', [$id]);

    expect($row->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/')
        ->and($row->status)->toBe('pending');
});

describe('checks (23514)', function (): void {
    it('refuses an unknown format', function (): void {
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert(['format' => 'pdf']));
    });

    it('refuses an unknown status', function (): void {
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert(['status' => 'done']));
    });

    it('refuses a ready row without a path or without a completion time', function (): void {
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert([
            'status' => 'ready',
            'completed_at' => '2026-10-01 10:00:00+00',
        ]));
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert([
            'status' => 'ready',
            'path' => 'report-exports/0190b3a0-0000-7000-8000-000000000001.csv',
        ]));
    });

    it('accepts a ready row with both', function (): void {
        RawSql::expectAllowed(fn () => reportExportsRawInsert([
            'status' => 'ready',
            'path' => 'report-exports/0190b3a0-0000-7000-8000-000000000001.csv',
            'completed_at' => '2026-10-01 10:00:00+00',
        ]));
    });

    it('refuses a path outside the export area or of another shape', function (string $path): void {
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert(['path' => $path]));
    })->with([
        'traversal' => '../x.csv',
        'not a uuid' => 'report-exports/abc.csv',
        'other directory' => 'elsewhere/0190b3a0-0000-7000-8000-000000000001.csv',
        'absolute' => '/report-exports/0190b3a0-0000-7000-8000-000000000001.csv',
        'nested' => 'report-exports/../0190b3a0-0000-7000-8000-000000000001.csv',
        'other extension' => 'report-exports/0190b3a0-0000-7000-8000-000000000001.pdf',
        'trailing text' => 'report-exports/0190b3a0-0000-7000-8000-000000000001.csv/x',
    ]);

    it('accepts the server-generated path of both formats', function (string $extension): void {
        RawSql::expectAllowed(fn () => reportExportsRawInsert(['path' => 'report-exports/0190b3a0-0000-7000-8000-000000000001.'.$extension]));
    })->with(['csv', 'xlsx']);

    it('refuses filters that are not a JSON object', function (string $filters): void {
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert(['filters' => $filters]));
    })->with(['empty array' => '[]', 'string' => '"x"', 'number' => '1', 'null literal' => 'null']);

    it('refuses a negative row count and accepts zero', function (): void {
        RawSql::expectSqlState('23514', fn () => reportExportsRawInsert(['row_count' => -1]));
        RawSql::expectAllowed(fn () => reportExportsRawInsert(['row_count' => 0]));
    });

    it('keeps the enum values equal to the constraint lists', function (): void {
        foreach (ReportExportFormat::cases() as $case) {
            RawSql::expectAllowed(fn () => reportExportsRawInsert(['format' => $case->value]));
        }

        foreach (ReportExportStatus::cases() as $case) {
            RawSql::expectAllowed(fn () => reportExportsRawInsert($case === ReportExportStatus::Ready
                ? ['status' => 'ready', 'path' => 'report-exports/0190b3a0-0000-7000-8000-000000000001.csv', 'completed_at' => '2026-10-01 10:00:00+00']
                : ['status' => $case->value]));
        }
    });
});

describe('references (23503)', function (): void {
    it('refuses an owner that is not a user', function (): void {
        RawSql::expectSqlState('23503', fn () => reportExportsRawInsert(['user_id' => '0190b3a0-0000-7000-8000-0000000000ff']));
    });

    it('refuses to delete a user who still owns an export', function (): void {
        $id = reportExportsRawInsert();
        $userId = DB::selectOne('select user_id from report_exports where id = ?', [$id])->user_id;

        RawSql::expectSqlState('23503', fn () => DB::delete('delete from users where id = ?', [$userId]));
    });
});
