<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Tests\Support\Canary;

/*
 * The record behind every queued report export (RE-03, D-09): owned by the Admin who made it, invisible to
 * a second Admin and to every Partner. Names, ids and paths are invented.
 */

/**
 * The filters of an invented export: validated ids and Prague days only.
 *
 * @return array<string, string|null>
 */
function reportExportFilters(): array
{
    return [
        'client' => '0190b3a0-0000-7000-8000-000000000001',
        'project' => null,
        'from' => '2026-09-01',
        'to' => '2026-09-30',
    ];
}

/**
 * Creates an export for the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 */
function reportExportCreate(array $overrides = []): ReportExport
{
    return ReportExport::query()->create([
        'format' => ReportExportFormat::Csv,
        'summary' => 'Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026',
        'filters' => reportExportFilters(),
        ...$overrides,
    ]);
}

describe('creating (Admin)', function (): void {
    it('stores a pending export for the signed-in Admin that expires seven days after creation', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);

        $before = CarbonImmutable::now();
        $export = reportExportCreate()->refresh();

        expect($export->user_id)->toBe($admin->getKey())
            ->and($export->status)->toBe(ReportExportStatus::Pending)
            ->and($export->format)->toBe(ReportExportFormat::Csv)
            // jsonb does not keep the key order, so the filters compare as a set of pairs.
            ->and($export->filters)->toEqual(reportExportFilters())
            ->and($export->path)->toBeNull()
            ->and($export->row_count)->toBeNull()
            ->and($export->completed_at)->toBeNull()
            ->and($export->expires_at->greaterThanOrEqualTo($before->addDays(7)->startOfSecond()))->toBeTrue()
            ->and($export->expires_at->lessThanOrEqualTo(CarbonImmutable::now()->addDays(7)))->toBeTrue();
    });

    it('refuses to create an export for nobody', function (): void {
        app(PartnerContext::class)->runAsSystem(static function (): void {
            reportExportCreate();
        });
    })->throws(LogicException::class);
});

describe('visibility', function (): void {
    it('shows a second Admin none of the exports of the first', function (): void {
        $first = Canary::admin();
        $second = Canary::admin();

        $this->actingAs($first);
        reportExportCreate();
        expect(ReportExport::query()->count())->toBe(1);

        $this->actingAs($second);
        expect(ReportExport::query()->count())->toBe(0);
    });

    it('shows a Partner with a client no export and denies every ability', function (): void {
        [$clientA] = Canary::twoClients();
        $this->actingAs(Canary::admin());
        $export = reportExportCreate();

        $partner = Canary::partnerFor($clientA);
        $this->actingAs($partner);

        expect(ReportExport::query()->count())->toBe(0);

        foreach (['viewAny', 'create'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, ReportExport::class))->toBeFalse($ability);
        }

        foreach (['view', 'update', 'delete'] as $ability) {
            expect(Gate::forUser($partner)->allows($ability, $export))->toBeFalse($ability);
        }
    });

    it('shows a Partner without a client nothing', function (): void {
        $this->actingAs(Canary::admin());
        reportExportCreate();

        $this->actingAs(Canary::partnerFor(null));

        expect(ReportExport::query()->count())->toBe(0);
    });

    it('grants the owner Admin the abilities', function (): void {
        $admin = Canary::admin();
        $this->actingAs($admin);
        $export = reportExportCreate();

        expect(Gate::forUser($admin)->allows('viewAny', ReportExport::class))->toBeTrue()
            ->and(Gate::forUser($admin)->allows('view', $export))->toBeTrue();
    });
});

describe('labels', function (): void {
    it('reads the Czech enum labels', function (): void {
        expect(ReportExportStatus::Pending->getLabel())->toBe('Připravuje se')
            ->and(ReportExportStatus::Ready->getLabel())->toBe('Hotovo')
            ->and(ReportExportStatus::Failed->getLabel())->toBe('Selhalo')
            ->and(ReportExportFormat::Csv->getLabel())->toBe('CSV')
            ->and(ReportExportFormat::Xlsx->getLabel())->toBe('XLSX')
            ->and(ReportExportStatus::Pending->getColor())->toBe('info')
            ->and(ReportExportStatus::Ready->getColor())->toBe('success')
            ->and(ReportExportStatus::Failed->getColor())->toBe('danger')
            ->and(ReportExportFormat::Xlsx->extension())->toBe('xlsx');
    });
});

describe('naming', function (): void {
    it('builds the storage path from the export id and the format only', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate(['format' => ReportExportFormat::Xlsx]);

        expect($export->storagePath())->toBe('report-exports/'.$export->getKey().'.xlsx')
            ->and(reportExportCreate()->storagePath())->toEndWith('.csv');
    });

    it('builds the download name from the stored period only, with no client or project name', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate(['summary' => 'Příklad Klient s.r.o., Projekt Alfa, 1. 9. 2026 – 30. 9. 2026']);

        expect($export->downloadFileName())->toBe('report-casu-2026-09-01_2026-09-30.csv')
            ->and($export->downloadFileName())->not->toContain('Klient')
            ->and($export->downloadFileName())->not->toContain('Alfa')
            ->and(reportExportCreate(['format' => ReportExportFormat::Xlsx])->downloadFileName())->toBe('report-casu-2026-09-01_2026-09-30.xlsx');
    });

    it('never lets a tampered period reach the download name', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate(['filters' => ['from' => '../../etc/passwd', 'to' => '2026-09-30']]);

        expect($export->downloadFileName())->toBe('report-casu.csv');

        $missing = reportExportCreate(['filters' => ['client' => null]]);

        expect($missing->downloadFileName())->toBe('report-casu.csv');
    });
});

describe('lifecycle', function (): void {
    it('marks an export ready with the path, the row count, the completion time and a fresh expiry', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate();
        $now = CarbonImmutable::parse('2026-10-01 10:00:00');

        $export->markReady($export->storagePath(), 12, $now);
        $fresh = ReportExport::query()->findOrFail($export->getKey());

        expect($fresh->status)->toBe(ReportExportStatus::Ready)
            ->and($fresh->path)->toBe($export->storagePath())
            ->and($fresh->row_count)->toBe(12)
            ->and($fresh->completed_at?->equalTo($now))->toBeTrue()
            ->and($fresh->expires_at->equalTo($now->addDays(7)))->toBeTrue();
    });

    it('marks an export failed and keeps the expiry of its creation', function (): void {
        $this->actingAs(Canary::admin());
        $export = reportExportCreate();
        $expires = ReportExport::query()->findOrFail($export->getKey())->expires_at;

        $export->markFailed();
        $fresh = ReportExport::query()->findOrFail($export->getKey());

        expect($fresh->status)->toBe(ReportExportStatus::Failed)
            ->and($fresh->path)->toBeNull()
            ->and($fresh->completed_at)->toBeNull()
            ->and($fresh->expires_at->equalTo($expires))->toBeTrue();
    });

    it('is downloadable only when ready, unexpired and with a path', function (): void {
        $this->actingAs(Canary::admin());
        $now = CarbonImmutable::parse('2026-10-01 10:00:00');

        $pending = reportExportCreate();
        $failed = reportExportCreate();
        $failed->markFailed();
        $ready = reportExportCreate();
        $ready->markReady($ready->storagePath(), 1, $now);
        $atExpiry = $ready->expires_at;

        expect($pending->isDownloadable($now))->toBeFalse()
            ->and($failed->isDownloadable($now))->toBeFalse()
            ->and($ready->isDownloadable($now))->toBeTrue()
            ->and($ready->isDownloadable($atExpiry->subSecond()))->toBeTrue()
            ->and($ready->isDownloadable($atExpiry))->toBeFalse()
            ->and($ready->isDownloadable($atExpiry->addDay()))->toBeFalse();
    });

    it('reads the retention from the configuration and never returns less than one day', function (): void {
        expect(ReportExport::ttlDays())->toBe(7);

        config(['kokpit.exports.ttl_days' => 3]);
        expect(ReportExport::ttlDays())->toBe(3);

        config(['kokpit.exports.ttl_days' => 0]);
        expect(ReportExport::ttlDays())->toBe(1);

        config(['kokpit.exports.ttl_days' => -5]);
        expect(ReportExport::ttlDays())->toBe(1);
    });

    it('uses the configured retention for a new record', function (): void {
        config(['kokpit.exports.ttl_days' => 2]);
        $this->actingAs(Canary::admin());

        $export = reportExportCreate()->refresh();

        expect($export->expires_at->lessThanOrEqualTo(CarbonImmutable::now()->addDays(2)))->toBeTrue()
            ->and($export->expires_at->greaterThan(CarbonImmutable::now()->addDay()))->toBeTrue();
    });
});
