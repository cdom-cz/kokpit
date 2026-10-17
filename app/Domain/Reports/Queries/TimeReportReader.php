<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\ReportEntry;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\LazyCollection;

/**
 * The time entries of a report period as one row each, with the effective billing resolved in the SQL
 * (RE-01, RE-02; D-01, D-07; research Pattern 5).
 *
 * One statement joins the client, the user, the project, the task and the billing rows. The effective billing
 * type and the hourly rate, written once in {@see EffectiveBillingSql} and shared with the picker of unbilled time,
 * follow the order of `TaskBillingResolver` and `TimeEntryRateResolver`, which stay the specification (a parity test
 * pins the SQL to them): the task's own row, the parent task's row (a subtask only, because a root task has no
 * parent), the project's row and for the rate the client. A task row of type
 * `inherit` defers its type; only null defers an amount, so a rate of zero is a value and stops the search. The
 * rate and its currency come from the same level (the columns are a pair, both null or both set), and the global
 * default rate never applies here because every client stores a rate.
 *
 * A time entry belongs to the Prague day it started (research A6); the period is a half-open range of UTC
 * instants, so a 23- or 25-hour day cannot shift a boundary. A finished entry counts with its stored duration, a
 * running one with the seconds from its start to the PHP clock instant that is bound, never SQL `now()`.
 * Archived clients, projects and tasks stay in the report, and the entries of every user are read: the split by
 * user needs them.
 *
 * The snapshot a billing took (rate, currency and amount of the exact seconds, TI-08) and the reserved flag (a
 * draft holds the entry, it is still unbilled; D-04) come straight from the time entry: no second rate chain.
 *
 * The raw joins bypass the Partner scope of the joined tables, so this reader refuses everyone but the Admin
 * and a system run, like `TimeEntryRateResolver` (T-08-31, T-08-33).
 */
final class TimeReportReader
{
    /**
     * The entries of the period in the order they started, one at a time.
     *
     * @return LazyCollection<int, ReportEntry>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function entries(ReportFilters $filters, CarbonInterface $now): LazyCollection
    {
        $this->authorise();

        $query = $this->base($filters)
            ->selectRaw(
                'time_entries.id AS id, time_entries.user_id AS user_id, time_entries.billable AS billable, '
                .'time_entries.billing_state AS billing_state, '
                ."(time_entries.started_at AT TIME ZONE 'Europe/Prague')::date AS work_date, "
                .TimeEntry::elapsedSecondsSql('time_entries').' AS seconds, '
                .'u.name AS user_name, c.id AS client_id, c.name AS client_name, c.currency AS client_currency, '
                .'p.id AS project_id, p.name AS project_name, p.key AS project_key, '
                .'t.id AS task_id, t.reference AS task_reference, t.title AS task_title, '
                .EffectiveBillingSql::BILLING_TYPE.' AS billing_type, pb.billing_type AS project_billing_type, '
                .EffectiveBillingSql::RATE_MINOR.' AS rate_minor, '
                .EffectiveBillingSql::RATE_CURRENCY.' AS rate_currency, '
                .'time_entries.billed_rate_minor AS billed_rate_minor, time_entries.billed_rate_currency AS billed_rate_currency, '
                .'time_entries.billed_amount_minor AS billed_amount_minor, '
                ."(time_entries.invoice_id IS NOT NULL AND time_entries.billing_state = 'unbilled') AS reserved",
                [$now->utc()->format('Y-m-d H:i:sP')],
            )
            ->orderBy('time_entries.started_at')
            ->orderBy('time_entries.id');

        return $query->toBase()->cursor()->map(static function (object $row): ReportEntry {
            $rateMinor = $row->rate_minor ?? null;
            $rateCurrency = $row->rate_currency ?? null;
            $billedRateMinor = $row->billed_rate_minor ?? null;
            $billedCurrency = $row->billed_rate_currency ?? null;
            $billedAmountMinor = $row->billed_amount_minor ?? null;
            $hasSnapshot = $billedRateMinor !== null && $billedAmountMinor !== null && is_string($billedCurrency);

            return new ReportEntry(
                id: (string) $row->id,
                workDate: (string) $row->work_date,
                seconds: (int) $row->seconds,
                billable: (bool) $row->billable,
                billed: $row->billing_state === 'billed',
                userId: (string) $row->user_id,
                userName: (string) $row->user_name,
                clientId: (string) $row->client_id,
                clientName: (string) $row->client_name,
                clientCurrency: (string) $row->client_currency,
                projectId: is_string($row->project_id) ? $row->project_id : null,
                projectName: is_string($row->project_name) ? $row->project_name : null,
                projectKey: is_string($row->project_key) ? $row->project_key : null,
                taskId: is_string($row->task_id) ? $row->task_id : null,
                taskReference: is_string($row->task_reference) ? $row->task_reference : null,
                taskTitle: is_string($row->task_title) ? $row->task_title : null,
                billingType: (string) $row->billing_type,
                rate: $rateMinor !== null && is_string($rateCurrency) ? Money::ofMinor((int) $rateMinor, $rateCurrency) : null,
                projectBillingType: is_string($row->project_billing_type ?? null) ? $row->project_billing_type : null,
                billedRate: $hasSnapshot ? Money::ofMinor((int) $billedRateMinor, $billedCurrency) : null,
                billedAmount: $hasSnapshot ? Money::ofMinor((int) $billedAmountMinor, $billedCurrency) : null,
                reserved: (bool) ($row->reserved ?? false),
            );
        });
    }

    /**
     * The currencies of the hourly rates and of the billing snapshots the entries of the period are priced in, sorted: what the CNB lookup of
     * a report has to load, so a report asks the rate tables only for the currencies it can use.
     *
     * @return list<string>
     *
     * @throws AuthorizationException when neither the Admin nor a system run asks
     */
    public function rateCurrencies(ReportFilters $filters): array
    {
        $this->authorise();

        // A billed entry converts at its snapshot currency, which may differ from today's effective rate currency.
        $effective = $this->base($filters)
            ->reorder()
            ->toBase()
            ->selectRaw('DISTINCT '.EffectiveBillingSql::RATE_CURRENCY.' AS currency')
            ->pluck('currency');
        $billed = $this->base($filters)
            ->reorder()
            ->toBase()
            ->selectRaw('DISTINCT time_entries.billed_rate_currency AS currency')
            ->pluck('currency');

        $currencies = $effective->merge($billed)
            ->filter(static fn (mixed $currency): bool => is_string($currency))
            ->map(static fn (mixed $currency): string => (string) $currency)
            ->unique()
            ->sort()
            ->values();

        return array_values($currencies->all());
    }

    /**
     * The entries of the period joined with everything the report names or resolves.
     *
     * @return Builder<TimeEntry>
     */
    private function base(ReportFilters $filters): Builder
    {
        return EffectiveBillingSql::joins(TimeEntry::query())
            ->join('users AS u', 'u.id', '=', 'time_entries.user_id')
            ->where('time_entries.started_at', '>=', $filters->startUtc())
            ->where('time_entries.started_at', '<', $filters->endUtcExclusive())
            ->when($filters->clientId !== null, static fn (Builder $query): Builder => $query->where('time_entries.client_id', $filters->clientId))
            ->when($filters->projectId !== null, static fn (Builder $query): Builder => $query->where('time_entries.project_id', $filters->projectId));
    }

    /**
     * @throws AuthorizationException
     */
    private function authorise(): void
    {
        $context = app(PartnerContext::class);

        if (! $context->isAdmin() && ! $context->isSystem()) {
            throw new AuthorizationException('The time report is available to the Admin only.');
        }
    }
}
