<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\ExchangeRates\ExchangeRateMissing;
use App\Domain\Invoicing\Enums\InvoiceItemOrigin;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Enums\ItemGrouping;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Invoicing\Support\TimeItemGrouping;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\Queries\EffectiveBillingSql;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OverflowException;

/**
 * The time a draft can bill: the picker of unbilled time and its live summary (IN-02, D-02, D-04, D-07; research
 * Pattern 5, UI-SPEC Surface B).
 *
 * A candidate is an entry of the chosen client that started inside the period of Prague days, is finished, is
 * billable, is unbilled, is held by no draft, and whose effective billing type is hourly. The effective billing type
 * and the rate come from {@see EffectiveBillingSql}, the SQL that the report shares and a parity test pins to the
 * resolvers, never from a second implementation of the chain. An entry of a fixed-price project or task is never a
 * candidate (ruling R-13-1: it stays unbilled until the Admin adds the fixed amount and ticks the time option, D-07),
 * and neither is one of a task that is explicitly non-billable. A running entry is not finished and a reserved one is
 * held by another draft (D-04), so both stay out, and the entries of another client are out by the client filter.
 *
 * A period is half-open in UTC instants built from the Prague days, so a 23- or 25-hour day cannot shift a boundary
 * (research A6). The projects are the chosen ones, and `withoutProject` adds the entries that have none (TI-03);
 * with neither, nothing is a candidate. Archived clients, projects and tasks stay in.
 *
 * The summary is the live result of the picker (UI-SPEC Surface B): how many candidates there are, their exact seconds,
 * what they are worth in the currency of the draft as the lines {@see TimeItemGrouping} would build today, how many
 * entries of the period another draft already holds (they still count as unbilled everywhere else, R-06-3), and which
 * of the chosen projects have a fixed price, whose hours are never added (R-13-1).
 *
 * The raw joins bypass the Partner scope of the joined tables, so this reader refuses everyone but the Admin and a
 * system run (T-10-36).
 */
final class InvoiceableEntries
{
    public function __construct(private readonly TimeItemGrouping $grouping) {}

    /**
     * The candidates in the order they started, one array each.
     *
     * @param  string  $clientId  the client of the draft
     * @param  string  $from  first Prague day `Y-m-d`
     * @param  string  $to  last Prague day `Y-m-d`, included
     * @param  list<string>  $projectIds  the projects whose time is offered
     * @param  bool  $withoutProject  whether the entries without a project are offered
     * @param  CarbonInterface  $now  the instant that bounds the seconds of an entry
     * @return list<array{id: string, seconds: int, project_id: ?string, project_name: ?string, task_id: ?string, task_reference: ?string, task_title: ?string, billing_type: string, rate_minor: ?int, rate_currency: ?string}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function candidates(string $clientId, string $from, string $to, array $projectIds, bool $withoutProject, CarbonInterface $now): array
    {
        $this->authorise();

        $query = $this->filtered($clientId, $from, $to, $projectIds, $withoutProject);

        if ($query === null) {
            return [];
        }

        return $this->rows($query->whereNull('time_entries.invoice_id'), $now);
    }

    /**
     * The eligible entries among the given ids, in the shape of {@see InvoiceableEntries::candidates()}: finished,
     * billable, unbilled, held by no draft, of the client and of an hourly effective billing type. Anything else in
     * the list (a running, billed, reserved, fixed-price or foreign entry, an unknown id) is left out; the caller
     * decides what that means. Used to add time that was tracked after a draft was made (D-06).
     *
     * @param  list<string>  $entryIds
     * @return list<array{id: string, seconds: int, project_id: ?string, project_name: ?string, task_id: ?string, task_reference: ?string, task_title: ?string, billing_type: string, rate_minor: ?int, rate_currency: ?string}>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function eligibleAmong(string $clientId, array $entryIds, CarbonInterface $now): array
    {
        $this->authorise();

        $entryIds = array_values(array_filter($entryIds, static fn (string $id): bool => Str::isUuid($id)));

        if ($entryIds === [] || ! Str::isUuid($clientId)) {
            return [];
        }

        return $this->rows(
            EffectiveBillingSql::joins(TimeEntry::query())
                ->where('time_entries.client_id', $clientId)
                ->whereIn('time_entries.id', $entryIds)
                ->whereNotNull('time_entries.ended_at')
                ->where('time_entries.billable', true)
                ->where('time_entries.billing_state', 'unbilled')
                ->whereNull('time_entries.invoice_id')
                ->whereRaw(EffectiveBillingSql::BILLING_TYPE." = 'hourly'"),
            $now,
        );
    }

    /**
     * The ids of the time that a fixed amount closes together with it: every finished, billable, unbilled entry of
     * the fixed-price project that no draft holds, up to now (ruling R-14-1). The draft stores no creation period, so
     * the whole unbilled past of the project is what "the time of the project" means. An entry of an hourly task
     * inside the project is not part of the fixed price and stays out.
     *
     * @return list<string> ids in the order the entries started
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function fixedPriceProjectEntries(string $projectId, CarbonInterface $now): array
    {
        $this->authorise();

        if (! Str::isUuid($projectId)) {
            return [];
        }

        $ids = EffectiveBillingSql::joins(TimeEntry::query())
            ->where('time_entries.project_id', $projectId)
            ->whereNotNull('time_entries.ended_at')
            ->where('time_entries.ended_at', '<=', $now->utc()->format('Y-m-d H:i:sP'))
            ->where('time_entries.billable', true)
            ->where('time_entries.billing_state', 'unbilled')
            ->whereNull('time_entries.invoice_id')
            ->whereRaw(EffectiveBillingSql::BILLING_TYPE." = 'fixed_price'")
            ->orderBy('time_entries.started_at')
            ->orderBy('time_entries.id')
            ->toBase()
            ->pluck('time_entries.id');

        return array_values($ids->map(static fn (mixed $id): string => (string) $id)->all());
    }

    /**
     * What issued invoices have already billed for the fixed price of the project: the sum of the fixed-amount lines
     * of issued invoices of the project, less what issued credit notes returned of those very lines (ruling R-14-2). A
     * proforma is left out because the invoice created from it carries the same lines; a draft does not count. One
     * amount per currency, never summed across currencies.
     *
     * @return list<Money> ordered by currency code
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function fixedAmountBilled(string $projectId): array
    {
        $this->authorise();

        if (! Str::isUuid($projectId)) {
            return [];
        }

        $credited = '(SELECT COALESCE(SUM(c.line_total_minor), 0) FROM invoice_items c JOIN invoices ci ON ci.id = c.invoice_id'
            ." WHERE c.credited_item_id = invoice_items.id AND ci.state = 'issued')";

        $rows = InvoiceItem::query()
            ->join('invoice_item_sources AS s', 's.invoice_item_id', '=', 'invoice_items.id')
            ->join('invoices AS i', 'i.id', '=', 'invoice_items.invoice_id')
            ->where('s.origin', InvoiceItemOrigin::FixedAmount->value)
            ->where('s.project_id', $projectId)
            ->where('i.state', InvoiceState::Issued->value)
            ->where('i.kind', InvoiceKind::Invoice->value)
            ->selectRaw('i.currency AS currency, invoice_items.line_total_minor + '.$credited.' AS net_minor')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $currency = (string) $row->currency;
            $totals[$currency] = ($totals[$currency] ?? 0) + (int) $row->net_minor;
        }

        ksort($totals);

        $amounts = [];

        foreach ($totals as $currency => $minor) {
            $amounts[] = Money::ofMinor($minor, (string) $currency);
        }

        return $amounts;
    }

    /**
     * Reads the shaped rows of a prepared query: the entry, its exact seconds up to `$now` and the effective billing.
     *
     * @param  Builder<TimeEntry>  $query
     * @return list<array{id: string, seconds: int, project_id: ?string, project_name: ?string, task_id: ?string, task_reference: ?string, task_title: ?string, billing_type: string, rate_minor: ?int, rate_currency: ?string}>
     */
    private function rows(Builder $query, CarbonInterface $now): array
    {
        $rows = $query
            ->selectRaw(
                'time_entries.id AS id, '.TimeEntry::elapsedSecondsSql('time_entries').' AS seconds, '
                .'p.id AS project_id, p.name AS project_name, '
                .'t.id AS task_id, t.reference AS task_reference, t.title AS task_title, '
                .EffectiveBillingSql::BILLING_TYPE.' AS billing_type, '
                .EffectiveBillingSql::RATE_MINOR.' AS rate_minor, '
                .EffectiveBillingSql::RATE_CURRENCY.' AS rate_currency',
                [$now->utc()->format('Y-m-d H:i:sP')],
            )
            ->orderBy('time_entries.started_at')
            ->orderBy('time_entries.id')
            ->toBase()
            ->get();

        $candidates = [];

        foreach ($rows as $row) {
            $candidates[] = [
                'id' => (string) $row->id,
                'seconds' => (int) $row->seconds,
                'project_id' => is_string($row->project_id) ? $row->project_id : null,
                'project_name' => is_string($row->project_name) ? $row->project_name : null,
                'task_id' => is_string($row->task_id) ? $row->task_id : null,
                'task_reference' => is_string($row->task_reference) ? $row->task_reference : null,
                'task_title' => is_string($row->task_title) ? $row->task_title : null,
                'billing_type' => (string) $row->billing_type,
                'rate_minor' => $row->rate_minor === null ? null : (int) $row->rate_minor,
                'rate_currency' => is_string($row->rate_currency) ? $row->rate_currency : null,
            ];
        }

        return $candidates;
    }

    /**
     * The live result of the picker for the same filters.
     *
     * `estimate` is the sum of the line amounts a draft created now would get, in the draft currency, or null when a
     * CNB list that a converted line needs is missing (the draft would be refused then); `reserved_elsewhere` counts
     * the entries that fit the filters but are held by another draft; `fixed_price_projects` are the names of the
     * chosen projects billed for a fixed price.
     *
     * @param  list<string>  $projectIds
     * @return array{count: int, seconds: int, estimate: ?Money, reserved_elsewhere: int, fixed_price_projects: list<string>}
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     * @throws InvalidArgumentException when the currency is not valid
     */
    public function summary(string $clientId, string $from, string $to, array $projectIds, bool $withoutProject, ItemGrouping $grouping, string $currency, CarbonInterface $now): array
    {
        $candidates = $this->candidates($clientId, $from, $to, $projectIds, $withoutProject, $now);

        $estimate = Money::zero($currency);

        try {
            foreach ($this->grouping->lines($candidates, $grouping, $currency, 'cs', $now->setTimezone(FilamentTimezone::get())->format('Y-m-d')) as $line) {
                $estimate = $estimate->plus($line['line_total']);
            }
        } catch (ExchangeRateMissing|OverflowException) {
            $estimate = null;
        }

        $held = $this->filtered($clientId, $from, $to, $projectIds, $withoutProject);

        return [
            'count' => count($candidates),
            'seconds' => (int) array_sum(array_column($candidates, 'seconds')),
            'estimate' => $estimate,
            'reserved_elsewhere' => $held === null ? 0 : $held->whereNotNull('time_entries.invoice_id')->count(),
            'fixed_price_projects' => $this->fixedPriceProjects($clientId, $projectIds),
        ];
    }

    /**
     * The names of the chosen projects of the client that are billed for a fixed price, archived ones included.
     *
     * @param  list<string>  $projectIds
     * @return list<string>
     */
    private function fixedPriceProjects(string $clientId, array $projectIds): array
    {
        $projectIds = array_values(array_filter($projectIds, static fn (string $id): bool => Str::isUuid($id)));

        if ($projectIds === [] || ! Str::isUuid($clientId)) {
            return [];
        }

        $names = Project::query()
            ->withTrashed()
            ->where('client_id', $clientId)
            ->whereIn('id', $projectIds)
            ->whereHas('billing', static fn (Builder $billing): Builder => $billing->where('billing_type', 'fixed_price'))
            ->orderBy('name')
            ->orderBy('id')
            ->pluck('name')
            ->map(static fn (mixed $name): string => (string) $name);

        return array_values($names->all());
    }

    /**
     * The entries of the client in the period that are finished, billable, unbilled and hourly, restricted to the
     * chosen projects; null when the filter selects nothing at all. Whether a draft holds them is left to the caller.
     *
     * @param  list<string>  $projectIds
     * @return Builder<TimeEntry>|null
     */
    private function filtered(string $clientId, string $from, string $to, array $projectIds, bool $withoutProject): ?Builder
    {
        $projectIds = array_values(array_filter($projectIds, static fn (string $id): bool => Str::isUuid($id)));

        if (($projectIds === [] && ! $withoutProject) || ! Str::isUuid($clientId)) {
            return null;
        }

        return EffectiveBillingSql::joins(TimeEntry::query())
            ->where('time_entries.client_id', $clientId)
            ->where('time_entries.started_at', '>=', PragueDay::start($from))
            ->where('time_entries.started_at', '<', PragueDay::endExclusive($to))
            ->whereNotNull('time_entries.ended_at')
            ->where('time_entries.billable', true)
            ->where('time_entries.billing_state', 'unbilled')
            ->whereRaw(EffectiveBillingSql::BILLING_TYPE." = 'hourly'")
            ->where(static function (Builder $query) use ($projectIds, $withoutProject): void {
                if ($projectIds !== []) {
                    $query->whereIn('time_entries.project_id', $projectIds);
                }

                if ($withoutProject) {
                    $query->orWhereNull('time_entries.project_id');
                }
            });
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The unbilled time is available to the Admin only.');
        }
    }
}
