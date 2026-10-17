<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Queries;

use App\Domain\Reports\Queries\EffectiveBillingSql;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\PragueDay;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

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
 * The raw joins bypass the Partner scope of the joined tables, so this reader refuses everyone but the Admin and a
 * system run (T-10-36).
 */
final class InvoiceableEntries
{
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

        $rows = $query
            ->whereNull('time_entries.invoice_id')
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
