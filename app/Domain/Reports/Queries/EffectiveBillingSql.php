<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\TimeTracking\Models\TimeEntry;
use Illuminate\Database\Eloquent\Builder;

/**
 * The SQL of the effective billing of a time entry, written once for every reader that prices entries in a single
 * statement: the time report and the picker of unbilled time (research Pattern 5).
 *
 * The effective billing type and the hourly rate follow the order of `TaskBillingResolver` and
 * `TimeEntryRateResolver`, which stay the specification and which a parity test pins both readers to: the task's own
 * row, the parent task's row (a subtask only, because a root task has no parent), the project's row and, for the
 * rate, the client. A task row of type `inherit` defers its type; only null defers an amount, so a rate of zero is a
 * value and stops the search. The rate and its currency come from the same level (the columns are a pair, both null
 * or both set), and the global default rate never applies because every client stores a rate.
 *
 * The fragments name the aliases that {@see EffectiveBillingSql::joins()} creates: `c` (client), `p` (project),
 * `t` (task), `tb` (task billing), `ptb` (parent task billing) and `pb` (project billing). The raw joins bypass the
 * Partner scope of the joined tables, so a reader that uses them refuses everyone but the Admin and a system run.
 */
final class EffectiveBillingSql
{
    /**
     * The effective billing type of the entry: the first level that is not `inherit`, hourly for an entry without
     * a project.
     */
    public const string BILLING_TYPE = "COALESCE(NULLIF(tb.billing_type, 'inherit'), NULLIF(ptb.billing_type, 'inherit'), pb.billing_type, 'hourly')";

    public const string RATE_MINOR = 'COALESCE(tb.hourly_rate_minor, ptb.hourly_rate_minor, pb.hourly_rate_minor, c.hourly_rate_minor)';

    public const string RATE_CURRENCY = 'COALESCE(tb.hourly_rate_currency, ptb.hourly_rate_currency, pb.hourly_rate_currency, c.hourly_rate_currency)';

    /**
     * Joins the client, the project, the task and the three billing rows to a query of time entries.
     *
     * @param  Builder<TimeEntry>  $query
     * @return Builder<TimeEntry>
     */
    public static function joins(Builder $query): Builder
    {
        return $query
            ->join('clients AS c', 'c.id', '=', 'time_entries.client_id')
            ->leftJoin('projects AS p', 'p.id', '=', 'time_entries.project_id')
            ->leftJoin('tasks AS t', 't.id', '=', 'time_entries.task_id')
            ->leftJoin('task_billing AS tb', 'tb.task_id', '=', 't.id')
            ->leftJoin('task_billing AS ptb', 'ptb.task_id', '=', 't.parent_id')
            ->leftJoin('project_billing AS pb', 'pb.project_id', '=', 'time_entries.project_id');
    }
}
