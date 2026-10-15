<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Jobs\PruneExpiredReportExports;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\Canary;

/*
 * D-09: a finished export file is kept seven days on the private disk, then a daily job deletes the file and the
 * record, for every owner. The disk is faked; every value is invented.
 */

/**
 * Makes an export of the given Admin whose expiry is set directly, with its file on the faked disk when asked.
 *
 * @param  'ready'|'failed'|'pending'  $state
 */
function pruneExportMake(User $owner, CarbonImmutable $expiresAt, string $state = 'ready', bool $withFile = true): ReportExport
{
    test()->actingAs($owner);

    $export = ReportExport::query()->create([
        'format' => ReportExportFormat::Csv,
        'summary' => 'Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026',
        'filters' => ['client' => null, 'project' => null, 'from' => '2026-09-01', 'to' => '2026-09-30'],
    ]);

    if ($state === 'ready') {
        $export->markReady($export->storagePath(), 3, CarbonImmutable::now());

        if ($withFile) {
            Storage::disk()->put($export->storagePath(), "a;b\n1;2\n");
        }
    } elseif ($state === 'failed') {
        $export->markFailed();
    }

    $export->forceFill(['expires_at' => $expiresAt])->save();

    return $export;
}

/** The ids of every export, whoever owns it, read as a system run. */
function pruneExportIds(): array
{
    return app(PartnerContext::class)->runAsSystem(
        static fn (): array => ReportExport::query()->orderBy('id')->pluck('id')->all(),
    );
}

beforeEach(function (): void {
    Storage::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-14 08:00:00', 'UTC'));
});

it('deletes the file and the record of an expired export and keeps a fresh one with its file', function (): void {
    $admin = Canary::admin();
    $expired = pruneExportMake($admin, CarbonImmutable::now()->subDay());
    $fresh = pruneExportMake($admin, CarbonImmutable::now()->addDay());
    $expiredPath = $expired->storagePath();
    $freshPath = $fresh->storagePath();

    expect(Storage::disk()->exists($expiredPath))->toBeTrue();

    PruneExpiredReportExports::dispatchSync();

    expect(Storage::disk()->exists($expiredPath))->toBeFalse()
        ->and(pruneExportIds())->toBe([$fresh->getKey()])
        ->and(Storage::disk()->exists($freshPath))->toBeTrue();
});

it('deletes an expired failed export without a path and an expired ready export whose file is already gone', function (): void {
    $admin = Canary::admin();
    $failed = pruneExportMake($admin, CarbonImmutable::now()->subDay(), 'failed');
    $pending = pruneExportMake($admin, CarbonImmutable::now()->subHour(), 'pending');
    $fileGone = pruneExportMake($admin, CarbonImmutable::now()->subMinute(), 'ready', withFile: false);
    $fresh = pruneExportMake($admin, CarbonImmutable::now()->addHour(), 'failed');

    expect($failed->path)->toBeNull()
        ->and(Storage::disk()->exists($fileGone->storagePath()))->toBeFalse();

    PruneExpiredReportExports::dispatchSync();

    expect(pruneExportIds())->toBe([$fresh->getKey()])
        ->and(ReportExport::withoutGlobalScopes()->whereKey($pending->getKey())->exists())->toBeFalse();
});

it('prunes the expired exports of two different Admins in the same run', function (): void {
    $first = Canary::admin();
    $second = Canary::admin();
    $firstExport = pruneExportMake($first, CarbonImmutable::now()->subDay());
    $secondExport = pruneExportMake($second, CarbonImmutable::now()->subDay());
    $secondFresh = pruneExportMake($second, CarbonImmutable::now()->addDay());

    // The job is dispatched with nobody signed in: only the system context makes the other owners visible.
    auth()->logout();

    PruneExpiredReportExports::dispatchSync();

    expect(pruneExportIds())->toBe([$secondFresh->getKey()])
        ->and(Storage::disk()->exists($firstExport->storagePath()))->toBeFalse()
        ->and(Storage::disk()->exists($secondExport->storagePath()))->toBeFalse()
        ->and(Storage::disk()->exists($secondFresh->storagePath()))->toBeTrue();
});

it('deletes nothing more and raises nothing on a second run', function (): void {
    $admin = Canary::admin();
    pruneExportMake($admin, CarbonImmutable::now()->subDay());
    $fresh = pruneExportMake($admin, CarbonImmutable::now()->addDay());

    PruneExpiredReportExports::dispatchSync();
    PruneExpiredReportExports::dispatchSync();

    expect(pruneExportIds())->toBe([$fresh->getKey()])
        ->and(Storage::disk()->exists($fresh->storagePath()))->toBeTrue();
});

it('keeps an export until the very instant of its expiry has passed', function (): void {
    $admin = Canary::admin();
    $atTheInstant = pruneExportMake($admin, CarbonImmutable::now());

    PruneExpiredReportExports::dispatchSync();

    expect(pruneExportIds())->toBe([$atTheInstant->getKey()]);

    $this->travelTo(CarbonImmutable::now()->addSecond());
    PruneExpiredReportExports::dispatchSync();

    expect(pruneExportIds())->toBe([]);
});

it('deletes 250 expired records while reading them in chunks', function (): void {
    $admin = Canary::admin();
    pruneExportMake($admin, CarbonImmutable::now()->subDay(), 'failed');

    $rows = [];
    $expiredAt = CarbonImmutable::now()->subDay()->toDateTimeString();
    $now = CarbonImmutable::now()->toDateTimeString();

    for ($i = 0; $i < 249; $i++) {
        $rows[] = [
            'id' => (string) Str::uuid7(),
            'user_id' => $admin->getKey(),
            'format' => 'csv',
            'status' => 'failed',
            'summary' => 'Fiktivní zadání',
            'filters' => '{}',
            'expires_at' => $expiredAt,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    DB::table('report_exports')->insert($rows);
    expect(DB::table('report_exports')->count())->toBe(250);

    DB::enableQueryLog();
    PruneExpiredReportExports::dispatchSync();
    $selects = array_filter(
        array_column(DB::getQueryLog(), 'query'),
        static fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, '"report_exports"'),
    );

    // Every read of the table carries a limit, so no single query loads all 250 records.
    expect(DB::table('report_exports')->count())->toBe(0)
        ->and($selects)->not->toBeEmpty()
        ->and(count($selects))->toBeGreaterThan(1);

    foreach ($selects as $sql) {
        expect($sql)->toContain('limit');
    }
});

it('never touches a file outside the export area of the same disk', function (): void {
    $admin = Canary::admin();
    pruneExportMake($admin, CarbonImmutable::now()->subDay());
    Storage::disk()->put('invoices/keep-me.pdf', 'fictional');
    Storage::disk()->put('report-exports/not-a-record.csv', 'fictional');

    PruneExpiredReportExports::dispatchSync();

    expect(Storage::disk()->exists('invoices/keep-me.pdf'))->toBeTrue()
        ->and(Storage::disk()->exists('report-exports/not-a-record.csv'))->toBeTrue()
        ->and(pruneExportIds())->toBe([]);
});
