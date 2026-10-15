<?php

declare(strict_types=1);

use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Models\ReportExport;
use App\Filament\Resources\ReportExportResource;
use App\Filament\Resources\ReportExportResource\Pages\ListReportExports;
use Carbon\CarbonImmutable;
use Filament\Actions\Exceptions\ActionNotResolvableException;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\ApiFixtures;
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

describe('refused downloads', function (): void {
    it('offers no download for a pending, a failed or an expired export', function (): void {
        $pending = exportResourceMake();
        $failed = exportResourceMake();
        $failed->markFailed();
        $expired = exportResourceReady();
        $this->travelTo(CarbonImmutable::now()->addDays(8));

        Livewire::test(ListReportExports::class)
            ->assertCanSeeTableRecords([$pending, $failed, $expired])
            ->assertTableActionHidden('download', $pending)
            ->assertTableActionHidden('download', $failed)
            ->assertTableActionHidden('download', $expired);
    });

    it('answers a click on an export that expired after the render with no file', function (): void {
        $export = exportResourceReady();

        $page = Livewire::test(ListReportExports::class)->assertTableActionVisible('download', $export);

        $this->travelTo(CarbonImmutable::now()->addDays(8));

        // The action is visible only for a downloadable export, and that is decided again on the server for
        // the call, so the stale click reaches no handler and no file.
        $page->call('mountTableAction', 'download', (string) $export->getKey())
            ->call('callMountedTableAction')
            ->assertNoFileDownloaded();
    });

    it('tells the Admin the file is gone when it was removed from the disk and sends no file', function (): void {
        $export = exportResourceReady();

        $page = Livewire::test(ListReportExports::class)->assertTableActionVisible('download', $export);

        Storage::disk()->delete($export->storagePath());

        $page->callTableAction('download', $export)
            ->assertNoFileDownloaded()
            ->assertNotified(
                Notification::make()->danger()->title('Soubor už není k dispozici. Spusťte export znovu.'),
            );
    });

    it('does not download an export of another Admin and does not list it', function (): void {
        $second = Canary::admin();
        $this->actingAs($second);
        $foreign = exportResourceReady();

        $this->actingAs($this->admin);
        $own = exportResourceReady();

        $page = Livewire::test(ListReportExports::class)
            ->assertCanSeeTableRecords([$own])
            ->assertCanNotSeeTableRecords([$foreign]);

        // The owner scope hides the row, so a forged key does not even resolve to a record.
        expect(fn () => $page->callTableAction('download', $foreign))
            ->toThrow(ActionNotResolvableException::class);

        $page->assertNoFileDownloaded();
    });
});

describe('the table behaviour', function (): void {
    it('polls every 10 s while an export is pending and not otherwise', function (): void {
        exportResourceReady();

        expect(Livewire::test(ListReportExports::class)->instance()->getTable()->getPollingInterval())->toBeNull();

        exportResourceMake();

        expect(Livewire::test(ListReportExports::class)->instance()->getTable()->getPollingInterval())->toBe('10s');
    });

    it('lists the newest export first with 25 rows per page', function (): void {
        $older = exportResourceMake();
        $this->travelTo(CarbonImmutable::now()->addMinutes(5));
        $newer = exportResourceMake();

        $page = Livewire::test(ListReportExports::class)->assertCanSeeTableRecords([$newer, $older], inOrder: true);

        expect($page->instance()->getTable()->getDefaultPaginationPageOption())->toBe(25);
    });

    it('shows a failed row as Selhalo with the plain failure reason and no exception text', function (): void {
        $failed = exportResourceMake();
        $failed->markFailed();

        $page = Livewire::test(ListReportExports::class)
            ->assertCanSeeTableRecords([$failed])
            ->assertSee('Selhalo');

        $tooltip = $page->instance()->getTable()->getColumn('summary')->record($failed)->getTooltip();

        expect($tooltip)->toBe('Export se nepodařilo vytvořit. Spusťte ho znovu v Reportu času.');
    });

    it('shows the expiry for a ready row only', function (): void {
        $pending = exportResourceMake();

        $column = Livewire::test(ListReportExports::class)->instance()->getTable()->getColumn('expires_at');

        expect($column->record($pending)->getState())->toBeNull();
    });

    it('shows the empty state when there is no export', function (): void {
        Livewire::test(ListReportExports::class)
            ->assertSee('Zatím tu nejsou žádné exporty')
            ->assertSee('Export spustíte v Reportu času tlačítkem Exportovat report.');
    });

    it('has no create, edit, view, delete or bulk action', function (): void {
        $table = Livewire::test(ListReportExports::class)->instance()->getTable();

        expect(array_keys(ReportExportResource::getPages()))->toBe(['index'])
            ->and(ReportExportResource::canCreate())->toBeFalse()
            ->and(array_keys($table->getFlatRecordActions()))->toBe(['download'])
            ->and($table->getBulkActions())->toBe([])
            ->and($table->getHeaderActions())->toBe([])
            ->and(ReportExportResource::canGloballySearch())->toBeFalse();
    });
});

describe('who may open it', function (): void {
    it('refuses a Partner the list and its Livewire component', function (): void {
        $partner = Canary::partnerFor(ApiFixtures::client()->id);
        $this->actingAs($partner);

        $this->get(ReportExportResource::getUrl('index'))->assertForbidden();

        Livewire::test(ListReportExports::class)->assertForbidden();
    });

    it('refuses a forged download call by a Partner on a page mounted by the Admin', function (): void {
        $export = exportResourceReady();
        $partner = Canary::partnerFor(ApiFixtures::client()->id);

        // The page was mounted while the Admin was signed in; every later call is made as a Partner.
        foreach ([
            ['mountTableAction', ['download', (string) $export->getKey()]],
            ['callMountedTableAction', []],
        ] as [$method, $arguments]) {
            $this->actingAs($this->admin);
            $page = Livewire::test(ListReportExports::class);
            $this->actingAs($partner);

            $page->call($method, ...$arguments)->assertForbidden();
        }
    });

    it('sends a guest to the login', function (): void {
        auth()->logout();

        $this->get(ReportExportResource::getUrl('index'))->assertRedirect();
    });

    it('shows the Admin the item in the navigation without a group', function (): void {
        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
            ->all();

        expect($labels)->toContain('Exporty')
            ->and(ReportExportResource::getNavigationGroup())->toBeNull()
            ->and(ReportExportResource::getNavigationSort())->toBe(43);
    });

    it('shows a Partner no navigation item', function (): void {
        $this->actingAs(Canary::partnerFor(ApiFixtures::client()->id));

        $labels = collect(Filament::getNavigation())
            ->flatMap(static fn ($group): array => $group->getItems()->map(static fn ($item): string => (string) $item->getLabel())->all())
            ->all();

        expect($labels)->not->toContain('Exporty');
    });
});
