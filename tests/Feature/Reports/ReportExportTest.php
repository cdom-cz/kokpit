<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\Tasks\Actions\CreateTask;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Filament\Pages\TimeReportPage;
use App\Filament\Resources\ReportExportResource;
use App\Filament\Resources\ReportExportResource\Pages\ListReportExports;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Support\Canary;

/*
 * The CSV export of the time report (RE-03, D-09; UI-SPEC C1, C3, H): the Admin asks for it on the report page,
 * a queued job writes the file to the private disk and the bell says where it is. Every name, rate and amount
 * below is invented.
 */

const EXPORT_CSV_HEADER = ['Datum', 'Klient', 'Projekt', 'Úkol', 'Uživatel', 'Sekundy', 'Hodiny', 'Fakturovatelné', 'Vyfakturováno', 'Částka', 'Měna', 'Částka v Kč'];

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Storage::fake();
    $this->admin = Canary::admin();
    $this->actingAs($this->admin);
    // Wednesday 2026-10-14, 12:00 in Prague. Last month is September 2026.
    $this->travelTo(CarbonImmutable::parse('2026-10-14 10:00:00', 'UTC'));
});

/**
 * Runs a callable as a system run, as console and seed code would.
 *
 * @template T
 *
 * @param  Closure(): T  $callback
 * @return T
 */
function exportBuildSystem(Closure $callback): mixed
{
    return app(PartnerContext::class)->runAsSystem($callback);
}

/**
 * A fictional client with a rate in its own currency.
 */
function exportBuildClient(string $name, string $currency = 'EUR', string $rate = '100'): Client
{
    return exportBuildSystem(static fn (): Client => Client::factory()->create([
        'name' => $name,
        'currency' => $currency,
        'hourly_rate' => Money::fromMajor($rate, $currency),
    ]));
}

/**
 * A project written through the domain Action, so its billing row exists.
 *
 * @param  array<string, mixed>  $data
 */
function exportBuildProject(Client $client, string $name, string $key, array $data = []): Project
{
    return app(CreateProject::class)->handle($client, [
        'name' => $name,
        'key' => $key,
        'billing_type' => 'hourly',
        ...$data,
    ]);
}

function exportBuildTask(User $admin, Project $project, string $title): Task
{
    return app(CreateTask::class)->handle($admin, $project, ['title' => $title])->refresh();
}

/**
 * A stored entry of the signed-in Admin from UTC instants on the task, the project or the client.
 *
 * @param  array<string, mixed>  $attributes
 */
function exportBuildEntry(Task|Project|Client $target, string $start, string $end, array $attributes = []): TimeEntry
{
    $factory = match (true) {
        $target instanceof Task => TimeEntry::factory()->forTask($target),
        $target instanceof Project => TimeEntry::factory()->forProject($target),
        default => TimeEntry::factory()->state(['client_id' => $target->id]),
    };

    return $factory->create([
        'user_id' => test()->admin->id,
        'started_at' => CarbonImmutable::parse($start, 'UTC'),
        'ended_at' => $end === '' ? null : CarbonImmutable::parse($end, 'UTC'),
        ...$attributes,
    ])->refresh();
}

/**
 * Stores one invented CNB list row in the system context.
 */
function exportBuildRate(string $date, string $currency, string $rate, int $unit = 1): void
{
    exportBuildSystem(static function () use ($date, $currency, $rate, $unit): void {
        ExchangeRate::query()->insert([
            'rate_date' => $date,
            'currency' => $currency,
            'unit_amount' => $unit,
            'rate' => $rate,
            'fetched_at' => $date.' 14:45:00+00',
            'created_at' => $date.' 14:45:00+00',
            'updated_at' => $date.' 14:45:00+00',
        ]);
    });
}

/**
 * The shared arrangement: a EUR client with a project that has its own rate of 120.00 EUR, two tasks, and
 * three entries of last month.
 *
 * Tuesday 2026-09-15 1:30 on the first task at 25.000 (180,00 EUR, 4500,00 Kč), Wednesday 2026-09-16 0:45 on
 * the second task at 25.500 (90,00 EUR, 2295,00 Kč), Saturday 2026-09-19 1:00 on the first task at the list
 * of Friday 2026-09-18, 24.000 (120,00 EUR, 2880,00 Kč).
 *
 * @return array{client: Client, project: Project, first: Task, second: Task}
 */
function exportBuildArrangement(): array
{
    $client = exportBuildClient('Example Client Alpha');
    $project = exportBuildProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
    $first = exportBuildTask(test()->admin, $project, 'Landing page');
    $second = exportBuildTask(test()->admin, $project, 'Contact form');

    exportBuildRate('2026-09-15', 'EUR', '25.000');
    exportBuildRate('2026-09-16', 'EUR', '25.500');
    exportBuildRate('2026-09-18', 'EUR', '24.000');

    exportBuildEntry($first, '2026-09-15 08:00:00', '2026-09-15 09:30:00');
    exportBuildEntry($second, '2026-09-16 08:00:00', '2026-09-16 08:45:00');
    exportBuildEntry($first, '2026-09-19 08:00:00', '2026-09-19 09:00:00');

    return ['client' => $client, 'project' => $project, 'first' => $first, 'second' => $second];
}

/**
 * The stored file of an export without its byte order mark, or null when there is none.
 */
function exportBuildFile(ReportExport $export): ?string
{
    if ($export->path === null || ! Storage::disk()->exists($export->path)) {
        return null;
    }

    $content = (string) Storage::disk()->get($export->path);

    return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
}

/**
 * The rows of a stored CSV as lists of cells, header row first.
 *
 * @return list<list<string|null>>
 */
function exportBuildRows(ReportExport $export): array
{
    $content = exportBuildFile($export);

    if ($content === null || $content === '') {
        return [];
    }

    return array_values(array_map(
        static fn (string $line): array => str_getcsv($line, ';', '"', ''),
        explode("\n", rtrim($content, "\n")),
    ));
}

/**
 * Asks for the CSV export on the report page for the given filters, as the Admin does with the menu item.
 *
 * @param  array<string, string>  $filters  keys client, project, period, from, to
 */
function exportBuildRequest(array $filters): ReportExport
{
    $page = Livewire::test(TimeReportPage::class);

    foreach ($filters as $key => $value) {
        $page->set($key, $value);
    }

    $page->callAction('exportCsv')->assertHasNoActionErrors();

    return ReportExport::query()->latest('created_at')->latest('id')->firstOrFail();
}

/**
 * The bell notifications of a user, newest first, as decoded Filament messages.
 *
 * @return list<array<string, mixed>>
 */
function exportBuildBell(User $user): array
{
    return DB::table('notifications')
        ->where('notifiable_id', $user->getKey())
        ->orderByDesc('created_at')
        ->get()
        ->map(static fn (object $row): array => json_decode((string) $row->data, true, 512, JSON_THROW_ON_ERROR))
        ->all();
}

describe('the CSV export of the report page', function (): void {
    it('queues the export, builds the file in the background, notifies the Admin and offers the download', function (): void {
        $set = exportBuildArrangement();

        Livewire::test(TimeReportPage::class)
            ->set('client', $set['client']->id)
            ->set('period', 'last_month')
            ->callAction('exportCsv')
            ->assertHasNoActionErrors()
            ->assertNotified('Export se připravuje');

        $exports = ReportExport::query()->get();
        expect($exports)->toHaveCount(1);

        $export = $exports->first();
        expect($export->user_id)->toBe($this->admin->id)
            ->and($export->format)->toBe(ReportExportFormat::Csv)
            ->and($export->summary)->toBe('Example Client Alpha, Všechny projekty, 1. 9. 2026 – 30. 9. 2026')
            ->and($export->filters)->toEqualCanonicalizing(['client' => $set['client']->id, 'project' => null, 'from' => '2026-09-01', 'to' => '2026-09-30'])
            ->and($export->status)->toBe(ReportExportStatus::Ready)
            ->and($export->row_count)->toBe(3)
            ->and($export->path)->toBe('report-exports/'.$export->id.'.csv')
            ->and($export->expires_at->equalTo($export->completed_at->addDays(7)))->toBeTrue();

        expect(str_starts_with((string) Storage::disk()->get($export->path), "\xEF\xBB\xBF"))->toBeTrue();

        $rows = exportBuildRows($export);
        $name = $this->admin->name;

        expect($rows)->toHaveCount(4)
            ->and($rows[0])->toBe(EXPORT_CSV_HEADER)
            ->and($rows[1])->toBe(['2026-09-15', 'Example Client Alpha', 'Example Project Web', 'Landing page', $name, '5400', '1,5000', 'ano', 'ne', '180,00', 'EUR', '4500,00'])
            ->and($rows[2])->toBe(['2026-09-16', 'Example Client Alpha', 'Example Project Web', 'Contact form', $name, '2700', '0,7500', 'ano', 'ne', '90,00', 'EUR', '2295,00'])
            ->and($rows[3])->toBe(['2026-09-19', 'Example Client Alpha', 'Example Project Web', 'Landing page', $name, '3600', '1,0000', 'ano', 'ne', '120,00', 'EUR', '2880,00']);

        $bell = exportBuildBell($this->admin);
        expect($bell)->toHaveCount(1)
            ->and($bell[0]['title'])->toBe('Export je připraven')
            ->and($bell[0]['body'])->toBe('CSV, 1. 9. 2026 – 30. 9. 2026. Soubor je ke stažení do 21. 10. 2026.')
            ->and($bell[0]['actions'][0]['label'])->toBe('Otevřít exporty')
            ->and($bell[0]['actions'][0]['url'])->toBe(ReportExportResource::getUrl('index'));

        Livewire::test(ListReportExports::class)
            ->assertCanSeeTableRecords([$export])
            ->callTableAction('download', $export)
            ->assertFileDownloaded('report-casu-2026-09-01_2026-09-30.csv');
    });

    it('offers the export menu and the "Exporty" link on the report page', function (): void {
        Livewire::test(TimeReportPage::class)
            ->assertActionExists('exportCsv', static fn ($action): bool => $action->getLabel() === 'Tabulka CSV')
            ->assertActionExists('exports', static fn ($action): bool => $action->getLabel() === 'Exporty'
                && $action->getUrl() === ReportExportResource::getUrl('index'));

        $html = (string) $this->get('/admin/time-report')->assertOk()->getContent();

        expect($html)->toContain('Exportovat report')->toContain('Tabulka CSV')->toContain('Exporty');
    });
});

describe('spreadsheet-safe cells in the file', function (): void {
    it('writes a client, project, task and user name that start like formulas with a leading apostrophe', function (): void {
        // Each name starts with a different dangerous character; the texts are assembled from fragments.
        $client = exportBuildClient('='.'ClientCanary');
        $project = exportBuildProject($client, '+'.'ProjectCanary', 'CAN');
        $task = exportBuildTask($this->admin, $project, '-'.'TaskCanary');
        $this->admin->forceFill(['name' => '@'.'UserCanary'])->save();
        exportBuildEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:00:00');

        $export = exportBuildRequest(['period' => 'last_month']);
        $rows = exportBuildRows($export);

        expect($rows)->toHaveCount(2)
            ->and($rows[1][1])->toBe("'=ClientCanary")
            ->and($rows[1][2])->toBe("'+ProjectCanary")
            ->and($rows[1][3])->toBe("'-TaskCanary")
            ->and($rows[1][4])->toBe("'@UserCanary");
    });
});
