<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Models\ReportExport;
use App\Filament\Resources\ReportExportResource;
use App\Filament\Resources\ReportExportResource\Pages\ListReportExports;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The Admin-only "Exporty" list (RE-03, D-09; UI-SPEC Surface C2): the own exports newest first and the one
 * checked download. Every name and period below is invented.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Creates an export for the signed-in user.
 *
 * @param  array<string, mixed>  $overrides
 */
function exportResourceMake(array $overrides = []): ReportExport
{
    return ReportExport::query()->create([
        'format' => ReportExportFormat::Csv,
        'summary' => 'Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026',
        'filters' => [
            'client' => null,
            'project' => null,
            'from' => '2026-09-01',
            'to' => '2026-09-30',
        ],
        ...$overrides,
    ]);
}

/**
 * A finished export whose file sits on the default disk under its server-generated path.
 *
 * @param  array<string, mixed>  $overrides
 */
function exportResourceReady(array $overrides = [], string $content = "Datum;Klient\n"): ReportExport
{
    $export = exportResourceMake($overrides);

    Storage::disk()->put($export->storagePath(), $content);
    $export->markReady($export->storagePath(), 0, CarbonImmutable::now());

    return $export->refresh();
}

describe('the list (Admin)', function (): void {
    it('shows the own ready export with its summary, format, status and expiry and downloads it', function (): void {
        $export = exportResourceReady(content: "Datum;Klient\n2026-09-01;Example\n");

        $this->get(ReportExportResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Exporty')
            ->assertSee('Hotové exporty se uchovávají 7 dní, potom se smažou.');

        Livewire::test(ListReportExports::class)
            ->assertOk()
            ->assertCanSeeTableRecords([$export])
            ->assertSee('Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026')
            ->assertSee('CSV')
            ->assertSee('Hotovo')
            ->assertSee('21. 10. 2026 12:00')
            ->assertTableActionVisible('download', $export)
            ->callTableAction('download', $export)
            ->assertFileDownloaded('report-casu-2026-09-01_2026-09-30.csv', "Datum;Klient\n2026-09-01;Example\n");
    });
});
