<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Jobs\PruneExpiredReportExports;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
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
