<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Jobs\BuildReportExport;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\ReportFilters;
use App\Domain\Reports\TimeReport;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Resources\ReportExportResource;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * The time report "Report času": where the time went and what it is worth, per client, project and task, with the
 * billable and billed splits and the amounts in the client currency and in CZK (RE-01, RE-02; D-06, D-07).
 *
 * Admin only: a Partner is refused at boot, before anything is read, and on every Livewire update. The filters
 * live in the URL (`?client&project&period&from&to&split`) and are re-validated by `ReportFilters` on every
 * request, because a Livewire property is client-controlled (T-08-32): the properties are untyped on purpose so
 * a forged array or number reaches the validation instead of a type error, and anything invalid falls back to
 * its default. The strip is a plain GET form that submits on every change, so the report is built once per
 * request and SPA navigation shows the stock progress bar while a slow period loads.
 *
 * The header holds the export menu "Exportovat report" and the link "Exporty" to the list of finished exports
 * (RE-03, D-09). An export item records the request for the current filters, queues the build and answers with
 * the info toast; the file is built in the background and announced in the bell.
 */
#[AccessRule(Audience::AdminOnly, reason: 'The time report shows measured time, rates and amounts, which are closed to Partners.')]
class TimeReportPage extends Page
{
    use EnforcesPageAccessRule;

    protected static ?string $slug = 'time-report';

    protected static ?int $navigationSort = 42;

    protected string $view = 'filament.pages.time-report';

    #[Url]
    public mixed $client = null;

    #[Url]
    public mixed $project = null;

    #[Url]
    public mixed $period = 'this_month';

    #[Url]
    public mixed $from = null;

    #[Url]
    public mixed $to = null;

    #[Url]
    public mixed $split = 'none';

    private ?TimeReport $report = null;

    public static function getNavigationLabel(): string
    {
        return __('kokpit.report.navigation');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedDocumentChartBar;
    }

    public function getTitle(): string
    {
        return __('kokpit.report.title');
    }

    /**
     * The export menu and the link to the exports list (UI-SPEC C1). The menu items are added one per format.
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('exportCsv')
                    ->label(__('kokpit.report.export.csv'))
                    ->action(fn () => $this->queueExport(ReportExportFormat::Csv)),
            ])
                ->label(__('kokpit.report.export.group'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->button(),
            Action::make('exports')
                ->label(__('kokpit.report.export.exports_link'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->url(ReportExportResource::getUrl('index')),
        ];
    }

    /**
     * Records an export of the current filters, queues its build and tells the Admin it is under way. The access
     * rule is asked again on the action request, like the resource asks it on a download.
     */
    private function queueExport(ReportExportFormat $format): void
    {
        abort_unless(static::canAccess(), 403);

        $filters = $this->filters();

        $export = ReportExport::query()->create([
            'format' => $format,
            'summary' => $this->exportSummary($filters),
            'filters' => $filters->toArray(),
        ]);

        BuildReportExport::dispatch($export->getKey());

        Notification::make()
            ->info()
            ->title(__('kokpit.exports.queued.title'))
            ->body(__('kokpit.exports.queued.body'))
            ->send();
    }

    /**
     * The "Zadání" text of an export, built here from stored names and days only: the client name or "Všichni
     * klienti", the project name or "Všechny projekty" and the period.
     */
    private function exportSummary(ReportFilters $filters): string
    {
        $client = $filters->clientId === null ? null : Client::query()->withTrashed()->whereKey($filters->clientId)->value('name');
        $project = $filters->projectId === null ? null : Project::query()->withTrashed()->whereKey($filters->projectId)->value('name');
        $format = static fn (string $day): string => CarbonImmutable::parse($day)->format(LocalisationServiceProvider::DATE_FORMAT);

        return implode(', ', [
            is_string($client) ? $client : __('kokpit.exports.summary.all_clients'),
            is_string($project) ? $project : __('kokpit.exports.summary.all_projects'),
            __('kokpit.report.range', ['from' => $format($filters->from), 'to' => $format($filters->to)]),
        ]);
    }

    /**
     * Normalises a bad URL to the defaults. A refused custom range is the one exception: its properties are left
     * as they came, so every request reads the same refusal again and the page can say why this month is shown.
     */
    public function mount(): void
    {
        $filters = $this->filters();

        $this->client = $filters->clientId;
        $this->project = $filters->projectId;
        $this->split = $filters->split;

        if ($filters->rangeError) {
            return;
        }

        $this->period = $filters->period;
        $this->from = $filters->period === 'custom' ? $filters->from : null;
        $this->to = $filters->period === 'custom' ? $filters->to : null;
    }

    /**
     * The filters of this request, validated again from the client-controlled properties.
     */
    public function filters(): ReportFilters
    {
        return ReportFilters::fromInput([
            'client' => $this->client,
            'project' => $this->project,
            'period' => $this->period,
            'from' => $this->from,
            'to' => $this->to,
            'split' => $this->split,
        ], TimerClock::now());
    }

    /**
     * The report of the filters, built once per request.
     */
    public function report(): TimeReport
    {
        return $this->report ??= TimeReport::build($this->filters(), TimerClock::now());
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $filters = $this->filters();
        $format = static fn (string $day): string => CarbonImmutable::parse($day)->format(LocalisationServiceProvider::DATE_FORMAT);

        // The links keep the client, the project and the split, and set the period.
        $kept = array_diff_key($filters->toQuery(), ['period' => true, 'from' => true, 'to' => true]);
        $url = static fn (array $query): string => static::getUrl([...$kept, ...$query]);

        return [
            'filters' => $filters,
            'report' => $this->report(),
            'actionUrl' => static::getUrl(),
            'rangeText' => __('kokpit.report.range', ['from' => $format($filters->from), 'to' => $format($filters->to)]),
            'periodLinks' => [
                'this_month' => $url(['period' => 'this_month']),
                'last_month' => $url(['period' => 'last_month']),
                'custom' => $url(['period' => 'custom', 'from' => $filters->from, 'to' => $filters->to]),
            ],
            'clients' => $this->clientOptions(),
            'projects' => $filters->clientId === null ? [] : $this->projectOptions($filters->clientId),
        ];
    }

    /**
     * Every client, archived ones included (the report covers past work), in Czech order, the archived ones marked.
     *
     * @return list<array{id: string, label: string}>
     */
    private function clientOptions(): array
    {
        $query = Client::query()->withTrashed();
        CzechCollation::orderBy($query, 'name');

        return array_values($query->orderBy('id')->get(['id', 'name', 'deleted_at'])
            ->map(static fn (Client $client): array => [
                'id' => $client->id,
                'label' => $client->trashed() ? $client->name.' '.__('kokpit.report.filters.archived') : $client->name,
            ])
            ->all());
    }

    /**
     * The projects of the chosen client, archived ones included, in Czech order, the archived ones marked.
     *
     * @return list<array{id: string, label: string}>
     */
    private function projectOptions(string $clientId): array
    {
        $query = Project::query()->withTrashed()->where('client_id', $clientId);
        CzechCollation::orderBy($query, 'name');

        return array_values($query->orderBy('id')->get(['id', 'name', 'deleted_at'])
            ->map(static fn (Project $project): array => [
                'id' => $project->id,
                'label' => $project->trashed() ? $project->name.' '.__('kokpit.report.filters.archived') : $project->name,
            ])
            ->all());
    }
}
