<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\Models\ExchangeRate;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Actions\CreateProject;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Jobs\BuildReportExport;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Notifications\ReportExportFailedNotification;
use App\Domain\Reports\Notifications\ReportExportReadyNotification;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
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
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

/**
 * Makes a pending export of the signed-in Admin for the given stored filters, as the report page would.
 *
 * @param  array<string, mixed>  $filters
 */
function exportBuildRecord(array $filters): ReportExport
{
    return ReportExport::query()->create([
        'format' => ReportExportFormat::Csv,
        'summary' => 'Všichni klienti, všechny projekty, 1. 9. 2026 – 30. 9. 2026',
        'filters' => $filters,
    ]);
}

/**
 * The stored filters of a whole September 2026, optionally for one client.
 *
 * @return array{client: ?string, project: ?string, from: string, to: string}
 */
function exportBuildSeptember(?string $clientId = null): array
{
    return ['client' => $clientId, 'project' => null, 'from' => '2026-09-01', 'to' => '2026-09-30'];
}

/**
 * Runs the build of an export through the queue, as a worker would: without a signed-in user, so the job
 * middleware has to provide the system context. The Admin is signed in again afterwards.
 */
function exportBuildRun(ReportExport $export): void
{
    $admin = test()->admin;

    Auth::forgetUser();

    try {
        BuildReportExport::dispatch($export->getKey());
    } finally {
        test()->actingAs($admin);
    }
}

/**
 * The entry rows of an export keyed by their seconds column, so a test can pick an entry by its duration.
 *
 * @return array<string, list<string|null>>
 */
function exportBuildBySeconds(ReportExport $export): array
{
    $rows = exportBuildRows($export);
    array_shift($rows);

    return array_combine(array_map(static fn (array $row): string => (string) $row[5], $rows), $rows);
}

/**
 * The sum of the amount cells of a column of the entry rows in minor units; an empty cell counts nothing.
 *
 * @param  list<list<string|null>>  $rows  the entry rows without the header
 */
function exportBuildCzkMinor(array $rows): int
{
    $sum = 0;

    foreach ($rows as $row) {
        if (($row[11] ?? '') !== '') {
            $sum += Money::fromMajor(str_replace(',', '.', (string) $row[11]), 'CZK')->minor;
        }
    }

    return $sum;
}

describe('the file follows the report', function (): void {
    it('holds only the entries of the chosen project, and of every client when none is chosen', function (): void {
        $alpha = exportBuildClient('Example Client Alpha');
        $web = exportBuildProject($alpha, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $app = exportBuildProject($alpha, 'Example Project App', 'APP', ['hourly_rate' => '120']);
        $beta = exportBuildClient('Example Client Beta');
        exportBuildEntry($web, '2026-09-15 08:00:00', '2026-09-15 09:00:00');
        exportBuildEntry($web, '2026-09-16 08:00:00', '2026-09-16 09:00:00');
        exportBuildEntry($app, '2026-09-17 08:00:00', '2026-09-17 09:00:00');
        exportBuildEntry($beta, '2026-09-18 08:00:00', '2026-09-18 09:00:00');

        $project = exportBuildRows(exportBuildRequest(['client' => $alpha->id, 'project' => $web->id, 'period' => 'last_month']));
        $everything = exportBuildRows(exportBuildRequest(['period' => 'last_month']));

        expect($project)->toHaveCount(3)
            ->and(array_values(array_unique(array_column(array_slice($project, 1), 2))))->toBe(['Example Project Web'])
            ->and($everything)->toHaveCount(5)
            ->and(array_values(array_unique(array_column(array_slice($everything, 1), 1))))->toEqualCanonicalizing(['Example Client Alpha', 'Example Client Beta']);
    });

    it('sums to the CZK total and the worked seconds of the report, with an empty cell where the report has no amount', function (): void {
        $alpha = exportBuildClient('Example Client Alpha');
        $web = exportBuildProject($alpha, 'Example Project Web', 'WEB', ['hourly_rate' => '120']);
        $fixed = exportBuildProject($alpha, 'Example Project Fixed', 'FIX', ['billing_type' => 'fixed_price', 'hourly_rate' => null, 'fixed_price' => '5000']);
        $task = exportBuildTask($this->admin, $web, 'Landing page');
        exportBuildRate('2026-09-15', 'EUR', '25.000');

        // 1:30 unbilled, 0:45 billed, 0:20 non billable, 2:00 fixed price and 0:01:01.
        exportBuildEntry($task, '2026-09-15 08:00:00', '2026-09-15 09:30:00');
        exportBuildEntry($task, '2026-09-15 10:00:00', '2026-09-15 10:45:00', ['billing_state' => 'billed', 'billed_at' => CarbonImmutable::parse('2026-09-16 08:00:00', 'UTC')]);
        exportBuildEntry($task, '2026-09-15 11:00:00', '2026-09-15 11:20:00', ['billable' => false]);
        exportBuildEntry($fixed, '2026-09-15 12:00:00', '2026-09-15 14:00:00');
        exportBuildEntry($task, '2026-09-15 15:00:00', '2026-09-15 15:01:01');
        // 1:00 on the client itself on a day before the first stored list: priced, but without a CZK amount.
        exportBuildEntry($alpha, '2026-09-01 08:00:00', '2026-09-01 09:00:00');

        $export = exportBuildRequest(['period' => 'last_month']);
        $rows = exportBuildRows($export);
        $entries = array_slice($rows, 1);
        $bySeconds = exportBuildBySeconds($export);
        $summary = TimeReport::build(ReportFilters::period('2026-09-01', '2026-09-30'), CarbonImmutable::now())->summary;

        expect($entries)->toHaveCount(6)
            ->and($export->row_count)->toBe(6)
            ->and(array_sum(array_map(static fn (array $row): int => (int) $row[5], $entries)))->toBe($summary['worked'])
            ->and(exportBuildCzkMinor($entries))->toBe($summary['czk']['billable']?->minor)
            ->and($summary['czk_missing'])->toBe(1)
            // unbilled hourly: the EUR amount and the CZK amount at the list of the day
            ->and(array_slice($bySeconds['5400'], 7))->toBe(['ano', 'ne', '180,00', 'EUR', '4500,00'])
            // billed
            ->and(array_slice($bySeconds['2700'], 7))->toBe(['ano', 'ano', '90,00', 'EUR', '2250,00'])
            // non billable: empty amount cells, never a zero
            ->and(array_slice($bySeconds['1200'], 7))->toBe(['ne', 'ne', '', '', ''])
            // fixed price: billable hours without an amount
            ->and(array_slice($bySeconds['7200'], 7))->toBe(['ano', 'ne', '', '', ''])
            // 61 seconds: four decimals, half up
            ->and($bySeconds['61'][6])->toBe('0,0169')
            ->and(array_slice($bySeconds['61'], 7))->toBe(['ano', 'ne', '2,03', 'EUR', '50,83'])
            // no CNB rate for the day: the amount stays, the CZK cell is empty
            ->and(array_slice($bySeconds['3600'], 7))->toBe(['ano', 'ne', '100,00', 'EUR', '']);
    });

    it('writes the amount of a CZK client as its own CZK amount', function (): void {
        $client = exportBuildClient('Example Client Beta', 'CZK', '1000');
        exportBuildEntry($client, '2026-09-15 16:00:00', '2026-09-15 16:30:00');

        $export = exportBuildRequest(['client' => $client->id, 'period' => 'last_month']);

        expect(array_slice(exportBuildBySeconds($export)['1800'], 7))->toBe(['ano', 'ne', '500,00', 'CZK', '500,00']);
    });

    it('exports a running entry with its elapsed seconds at the clock of the job', function (): void {
        $client = exportBuildClient('Example Client Alpha', 'CZK', '1000');
        $project = exportBuildProject($client, 'Example Project Web', 'WEB', ['hourly_rate' => '1000']);
        // Started at 09:00 UTC, the frozen clock reads 10:00 UTC.
        exportBuildEntry($project, '2026-10-14 09:00:00', '');

        $rows = exportBuildRows(exportBuildRequest(['period' => 'this_month']));

        expect($rows)->toHaveCount(2)
            ->and(array_slice($rows[1], 4, 3))->toBe([$this->admin->name, '3600', '1,0000']);
    });

    it('produces a ready file with the header row only for a period without entries', function (): void {
        $export = exportBuildRequest(['period' => 'last_month']);

        expect($export->status)->toBe(ReportExportStatus::Ready)
            ->and($export->row_count)->toBe(0)
            ->and(exportBuildRows($export))->toBe([EXPORT_CSV_HEADER])
            ->and(exportBuildBell($this->admin))->toHaveCount(1);
    });
});

describe('the build job', function (): void {
    it('declares a timeout under the Horizon supervisor and runs as a system job without a signed-in user', function (): void {
        $attributes = (new ReflectionClass(BuildReportExport::class))->getAttributes(Timeout::class);
        $arrangement = exportBuildArrangement();

        $export = exportBuildRecord(exportBuildSeptember($arrangement['client']->id));
        exportBuildRun($export);
        $export->refresh();

        expect($attributes)->toHaveCount(1)
            ->and($attributes[0]->newInstance()->timeout)->toBe(240)
            ->and($export->status)->toBe(ReportExportStatus::Ready)
            ->and($export->row_count)->toBe(3);
    });

    it('does nothing when run again for a ready export: same path, same file, same row count and no second bell', function (): void {
        exportBuildArrangement();
        $export = exportBuildRecord(exportBuildSeptember());
        exportBuildRun($export);
        $export->refresh();

        $path = $export->path;
        $completedAt = $export->completed_at;
        Storage::disk()->put((string) $path, 'kept as it is');

        exportBuildRun($export);
        $export->refresh();

        expect($export->path)->toBe($path)
            ->and($export->row_count)->toBe(3)
            ->and($export->completed_at->equalTo($completedAt))->toBeTrue()
            ->and(Storage::disk()->get((string) $path))->toBe('kept as it is')
            ->and(exportBuildBell($this->admin))->toHaveCount(1);
    });

    it('skips an export that was pruned before the job ran', function (): void {
        $export = exportBuildRecord(exportBuildSeptember());
        $id = $export->getKey();
        $export->delete();

        BuildReportExport::dispatch($id);

        expect(Storage::disk()->allFiles())->toBe([])
            ->and(exportBuildBell($this->admin))->toBe([]);
    });
});

describe('a failing export', function (): void {
    it('marks the record failed, tells the owner in the bell and leaves the failed-job alert to Phase 3', function (): void {
        // The stored client no longer exists, so the stored filters are refused and the build fails for good.
        $missing = '018f0000-0000-7000-8000-000000000001';
        $export = exportBuildRecord(exportBuildSeptember($missing));
        $raised = [];
        Event::listen(JobFailed::class, static function (JobFailed $event) use (&$raised): void {
            $raised[] = $event->job->resolveName();
        });

        expect(fn () => exportBuildRun($export))->toThrow(InvalidArgumentException::class);

        $export->refresh();
        $titles = array_column(exportBuildBell($this->admin), 'title');
        $bell = array_values(array_filter(exportBuildBell($this->admin), static fn (array $message): bool => $message['title'] === 'Export se nepodařil'));

        expect($export->status)->toBe(ReportExportStatus::Failed)
            ->and($export->path)->toBeNull()
            ->and(Storage::disk()->allFiles())->toBe([])
            ->and($raised)->toBe([BuildReportExport::class])
            ->and($titles)->toContain('Export se nepodařil')
            ->and($bell)->toHaveCount(1)
            ->and($bell[0]['body'])->toBe('Export za 1. 9. 2026 – 30. 9. 2026 se nepodařilo vytvořit. Zkuste ho spustit znovu.')
            ->and($bell[0]['actions'][0]['url'])->toBe(ReportExportResource::getUrl('index'));
    });

    it('leaves a ready export as it is when a late failure arrives', function (): void {
        exportBuildArrangement();
        $export = exportBuildRecord(exportBuildSeptember());
        exportBuildRun($export);

        (new BuildReportExport($export->getKey()))->failed(new RuntimeException('late'));

        expect($export->refresh()->status)->toBe(ReportExportStatus::Ready)
            ->and(exportBuildBell($this->admin))->toHaveCount(1);
    });
});

describe('the bell notices', function (): void {
    it('name the format and the period only: no client name, amount or path', function (): void {
        $arrangement = exportBuildArrangement();
        $export = exportBuildRequest(['client' => $arrangement['client']->id, 'period' => 'last_month']);

        $text = json_encode(exportBuildBell($this->admin), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        expect($export->path)->not->toBeNull()
            ->and($text)->toContain('CSV, 1. 9. 2026 – 30. 9. 2026')
            ->and($text)->not->toContain('Example Client Alpha')
            ->and($text)->not->toContain('Example Project Web')
            ->and($text)->not->toContain('180')
            ->and($text)->not->toContain('4500')
            ->and($text)->not->toContain((string) $export->path)
            ->and($text)->not->toContain((string) $export->getKey())
            ->and($text)->not->toContain('.csv');
    });

    it('escape a value once, so markup reaches the bell as text and is not escaped twice', function (): void {
        $ready = (new ReportExportReadyNotification('CSV', '<b>x</b> & y', '1. 1. 2027', '/admin/report-exports'))->toDatabase($this->admin);
        $failed = (new ReportExportFailedNotification('<i>x</i> & y', '/admin/report-exports'))->toDatabase($this->admin);

        expect($ready['body'])->toBe('CSV, &lt;b&gt;x&lt;/b&gt; &amp; y. Soubor je ke stažení do 1. 1. 2027.')
            ->and($failed['body'])->toBe('Export za &lt;i&gt;x&lt;/i&gt; &amp; y se nepodařilo vytvořit. Zkuste ho spustit znovu.');
    });

    it('are sent to the database only and are not queued', function (): void {
        $ready = new ReportExportReadyNotification('CSV', 'p', 'd', '/u');
        $failed = new ReportExportFailedNotification('p', '/u');

        expect($ready->via($this->admin))->toBe(['database'])
            ->and($failed->via($this->admin))->toBe(['database'])
            ->and($ready)->not->toBeInstanceOf(ShouldQueue::class)
            ->and($failed)->not->toBeInstanceOf(ShouldQueue::class);
    });
});
