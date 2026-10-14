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
