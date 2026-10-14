<?php

declare(strict_types=1);

namespace App\Domain\Reports\WorkReport;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\Project;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Database\CzechCollation;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonInterface;
use Closure;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Everything a work report of one client's period says, and nothing about money (RE-03; D-10).
 *
 * The source reads names, Prague days, billable flags and seconds. It never joins or names a billing table
 * (rates, fixed prices, amounts), so no price can reach the document, whatever the template does. It reads
 * no entry description either (U-6): descriptions may hold internal notes. A time entry belongs to the
 * Prague day it started (research A6), and a running entry counts at its elapsed time.
 *
 * Rows are grouped by section (the entry's own billable flag, billable first), project, Prague day and task,
 * so a report grows with days x tasks, not with the number of entries. A project row sorts by name in Czech
 * order, entries without a project last; the tasks of a day by their number, entries without a task last.
 *
 * The supplier and client lines are written in the client's invoice language, because the labels inside
 * them (the company ID line) are words of that language. Built from plain ids and days, not from the report
 * page's filters, so Phase 10 can build a source without the report page.
 *
 * Admin and system runs only: the rows are closed to Partners, and a Partner call is refused outright.
 */
final readonly class WorkReportSource
{
    /**
     * @param  list<string>  $supplierLines  name, address lines and company ID of the supplier
     * @param  list<string>  $clientLines  address lines and company ID of the client (the name is `$clientName`)
     * @param  list<array{billable: bool, project: ?string, date: string, task: ?string, seconds: int}>  $rows  `date` is a Prague day `Y-m-d`, `task` reads `KEY-N · title`; ordered by section, project, date, task
     */
    public function __construct(
        public array $supplierLines,
        public string $clientName,
        public array $clientLines,
        public InvoiceLanguage $clientLanguage,
        public string $from,
        public string $to,
        public ?string $projectName,
        public array $rows,
    ) {}

    /**
     * The rows of one section: the billable ones or the non-billable ones, in the order of the source.
     *
     * @return list<array{billable: bool, project: ?string, date: string, task: ?string, seconds: int}>
     */
    public function section(bool $billable): array
    {
        return array_values(array_filter($this->rows, static fn (array $row): bool => $row['billable'] === $billable));
    }

    /**
     * The seconds of one section.
     */
    public function sectionSeconds(bool $billable): int
    {
        return array_sum(array_column($this->section($billable), 'seconds'));
    }

    /**
     * The seconds of both sections together.
     */
    public function totalSeconds(): int
    {
        return array_sum(array_column($this->rows, 'seconds'));
    }

    /**
     * The number of Prague days with any work, a day counted once whatever the section, project or task.
     */
    public function daysWorked(): int
    {
        return count(array_unique(array_column($this->rows, 'date')));
    }

    /**
     * The seconds per project over both sections: the largest first, equal ones in the order of the rows,
     * the entries without a project last.
     *
     * @return list<array{project: ?string, seconds: int}>
     */
    public function projectTotals(): array
    {
        $named = [];
        $unnamed = 0;

        foreach ($this->rows as $row) {
            if ($row['project'] === null) {
                $unnamed += $row['seconds'];

                continue;
            }

            $named[$row['project']] = ($named[$row['project']] ?? 0) + $row['seconds'];
        }

        arsort($named);

        $totals = [];

        foreach ($named as $project => $seconds) {
            $totals[] = ['project' => (string) $project, 'seconds' => $seconds];
        }

        if ($unnamed > 0) {
            $totals[] = ['project' => null, 'seconds' => $unnamed];
        }

        return $totals;
    }

    /**
     * The report of one client's period, optionally of one project of that client.
     *
     * @param  string  $from  first Prague day `Y-m-d`
     * @param  string  $to  last Prague day `Y-m-d`, included
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException for an id that is no id or names nothing, a project of another client, a day that is not a calendar day, or a period that ends before it starts
     */
    public static function forPeriod(string $clientId, string $from, string $to, ?string $projectId, CarbonInterface $now): self
    {
        self::authorise();

        $start = PragueDay::day($from) ?? throw new InvalidArgumentException('Not a calendar day: '.$from);
        $end = PragueDay::day($to) ?? throw new InvalidArgumentException('Not a calendar day: '.$to);

        if ($start > $end) {
            throw new InvalidArgumentException('The period ends before it starts.');
        }

        if (! Str::isUuid($clientId) || ($projectId !== null && ! Str::isUuid($projectId))) {
            throw new InvalidArgumentException('The client and the project are identified by UUIDs.');
        }

        $client = Client::query()->withTrashed()->find($clientId)
            ?? throw new InvalidArgumentException('There is no such client.');

        $project = null;

        if ($projectId !== null) {
            $project = Project::query()->withTrashed()->where('client_id', $client->id)->find($projectId)
                ?? throw new InvalidArgumentException('There is no such project of the client.');
        }

        $first = PragueDay::start($start);
        $last = PragueDay::endExclusive($end);

        return self::build(
            $client,
            $start,
            $end,
            $project?->name,
            static fn (Builder $query): Builder => $query
                ->where('time_entries.started_at', '>=', $first)
                ->where('time_entries.started_at', '<', $last)
                ->when($project !== null, static fn (Builder $query): Builder => $query->where('time_entries.project_id', $project?->id)),
            $now,
        );
    }

    /**
     * The report of exactly the listed time entries of one client, for the work report Phase 10 attaches to
     * the invoice of those entries. The period is the first and the last Prague day of the entries; the
     * project line appears when all entries belong to one project. An entry is counted once, however often
     * its id is listed.
     *
     * @param  list<string>  $entryIds
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException for an empty list, an id that is no id or names no entry, or entries of more than one client
     */
    public static function fromEntryIds(array $entryIds, CarbonInterface $now): self
    {
        self::authorise();

        $ids = array_values(array_unique($entryIds));

        if ($ids === []) {
            throw new InvalidArgumentException('A work report needs at least one time entry.');
        }

        foreach ($ids as $id) {
            if (! Str::isUuid($id)) {
                throw new InvalidArgumentException('Time entries are identified by UUIDs.');
            }
        }

        $entries = TimeEntry::query()->whereIn('id', $ids)->reorder()->get(['id', 'client_id', 'project_id', 'started_at']);

        if ($entries->count() !== count($ids)) {
            throw new InvalidArgumentException('Some of the time entries do not exist.');
        }

        $clientIds = $entries->pluck('client_id')->unique()->values();

        if ($clientIds->count() !== 1) {
            throw new InvalidArgumentException('A work report covers the time entries of one client.');
        }

        $client = Client::query()->withTrashed()->find((string) $clientIds->first())
            ?? throw new InvalidArgumentException('There is no such client.');

        $days = $entries
            ->map(static fn (TimeEntry $entry): string => $entry->started_at->toImmutable()->setTimezone(FilamentTimezone::get())->format('Y-m-d'))
            ->sort()
            ->values();

        $projectIds = $entries->pluck('project_id')->unique()->values();
        $projectName = null;

        if ($projectIds->count() === 1 && is_string($projectIds->first())) {
            $projectName = Project::query()->withTrashed()->where('client_id', $client->id)->find($projectIds->first())?->name;
        }

        return self::build(
            $client,
            (string) $days->first(),
            (string) $days->last(),
            $projectName,
            static fn (Builder $query): Builder => $query->whereIn('time_entries.id', $ids),
            $now,
        );
    }

    /**
     * Only the Admin and a system run read a work report.
     *
     * @throws AuthorizationException
     */
    private static function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The work report is available to the Admin only.');
        }
    }

    /**
     * The source of one client's entries chosen by `$constrain`, written in the client's invoice language.
     *
     * @param  Closure(Builder<TimeEntry>): Builder<TimeEntry>  $constrain
     */
    private static function build(Client $client, string $from, string $to, ?string $projectName, Closure $constrain, CarbonInterface $now): self
    {
        $language = $client->invoice_language;

        // The label inside a line (the company ID line) is a word of the client's language. App::withLocale does
        // not exist; the locale is restored even when a read throws.
        $original = App::getLocale();
        App::setLocale($language->value);

        try {
            return new self(
                supplierLines: self::supplierLines(),
                clientName: $client->name,
                clientLines: self::clientLines($client),
                clientLanguage: $language,
                from: $from,
                to: $to,
                projectName: $projectName,
                rows: self::rows($client->id, $constrain, $now),
            );
        } finally {
            App::setLocale($original);
        }
    }

    /**
     * @return list<string>
     */
    private static function supplierLines(): array
    {
        $supplier = app(SupplierSettings::class);

        return self::lines(
            [$supplier->company_name],
            $supplier->street,
            $supplier->postal_code,
            $supplier->city,
            $supplier->country,
            $supplier->company_id,
        );
    }

    /**
     * @return list<string>
     */
    private static function clientLines(Client $client): array
    {
        return self::lines([], $client->street, $client->postal_code, $client->city, $client->country, $client->company_number);
    }

    /**
     * The printed lines of a party: the given first lines, the street, the postal code with the city, the
     * country and the company ID line, each only when filled in.
     *
     * @param  list<string>  $first
     * @return list<string>
     */
    private static function lines(array $first, ?string $street, ?string $postalCode, ?string $city, ?string $country, ?string $companyId): array
    {
        $lines = $first;

        foreach ([$street, trim(($postalCode ?? '').' '.($city ?? '')), $country] as $line) {
            if ($line !== null && trim($line) !== '') {
                $lines[] = trim($line);
            }
        }

        if ($companyId !== null && trim($companyId) !== '') {
            $lines[] = __('work_report.company_id', ['id' => trim($companyId)]);
        }

        return $lines;
    }

    /**
     * One grouped query: names, the Prague day, the billable flag and the summed seconds. The tables joined
     * are projects and tasks, for names only. Archived projects and tasks are read too.
     *
     * @param  Closure(Builder<TimeEntry>): Builder<TimeEntry>  $constrain  chooses the entries of the client
     * @return list<array{billable: bool, project: ?string, date: string, task: ?string, seconds: int}>
     */
    private static function rows(string $clientId, Closure $constrain, CarbonInterface $now): array
    {
        $day = "(time_entries.started_at AT TIME ZONE 'Europe/Prague')::date";

        $query = $constrain(
            TimeEntry::query()
                ->leftJoin('projects', 'projects.id', '=', 'time_entries.project_id')
                ->leftJoin('tasks', 'tasks.id', '=', 'time_entries.task_id')
                ->where('time_entries.client_id', $clientId),
        )
            ->reorder()
            ->selectRaw(
                'time_entries.billable AS billable, projects.name AS project_name, '.$day.' AS day, '
                .'tasks.reference AS task_reference, tasks.title AS task_title, '
                .'SUM('.TimeEntry::elapsedSecondsSql('time_entries').')::bigint AS seconds',
                [$now->utc()->format('Y-m-d H:i:sP')],
            )
            ->groupBy([
                'time_entries.billable', 'projects.id', 'projects.name', DB::raw($day),
                'tasks.id', 'tasks.number', 'tasks.reference', 'tasks.title',
            ])
            ->orderByDesc('time_entries.billable')
            ->orderByRaw('projects.id IS NULL');

        CzechCollation::orderBy($query, 'projects.name');

        $result = $query
            ->orderBy('projects.id')
            ->orderByRaw($day)
            ->orderByRaw('tasks.number IS NULL')
            ->orderBy('tasks.number')
            ->orderBy('tasks.id')
            ->toBase()
            ->get();

        $rows = [];

        foreach ($result as $row) {
            $rows[] = [
                'billable' => (bool) $row->billable,
                'project' => is_string($row->project_name) ? $row->project_name : null,
                'date' => (string) $row->day,
                'task' => is_string($row->task_reference) && is_string($row->task_title) ? $row->task_reference.' · '.$row->task_title : null,
                'seconds' => (int) $row->seconds,
            ];
        }

        return $rows;
    }
}
