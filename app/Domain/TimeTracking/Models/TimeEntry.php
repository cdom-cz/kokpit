<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Models;

use App\Domain\Audit\LoggedAttributes;
use App\Domain\Audit\LogsAllowlistedActivity;
use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\DeniesPartners;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\KokpitModel;
use App\Domain\Tasks\Models\Task;
use App\Domain\TimeTracking\Enums\BillingBadge;
use App\Domain\TimeTracking\Enums\BillingState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of tracked time of one user, running or finished.
 *
 * Admin-only: measured time is closed to Partners by DeniesPartners and the
 * admin-only policy, so a Partner reads zero rows.
 *
 * `ended_at` null means the entry is running. The duration is the stored
 * generated column `duration_seconds`, exact whole seconds, null while running.
 * The client is always set; the project and the task are optional, and the
 * database refuses a client, project and task that disagree.
 *
 * Only the content columns are fillable. The ids, the instants and the billing
 * state are set with `forceFill` by the Actions, so a request can never re-point
 * an entry or bill it by mass assignment.
 *
 * Changes to the context, the instants, the billable flag and the billing state
 * are written to the activity log through an allowlist (D-06). The description
 * is free text and `long_running_notified_at` is bookkeeping: neither is logged.
 * Billing and unbilling save the model one entry at a time so each writes a row.
 *
 * The relations to client, project and task include archived rows: archiving
 * never stops a running timer and never hides the time already tracked.
 *
 * @property string $id
 * @property string $user_id
 * @property string $client_id
 * @property string|null $project_id
 * @property string|null $task_id
 * @property string|null $description
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property int|null $duration_seconds
 * @property bool $billable
 * @property BillingState $billing_state
 * @property CarbonImmutable|null $billed_at
 * @property CarbonImmutable|null $long_running_notified_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read int|null $elapsed_seconds only on rows read through the withElapsedSeconds scope
 */
#[Fillable(['description', 'billable'])]
#[LoggedAttributes(['client_id', 'project_id', 'task_id', 'started_at', 'ended_at', 'billable', 'billing_state', 'billed_at'])]
final class TimeEntry extends KokpitModel implements PartnerIsolated
{
    /** @use HasFactory<TimeEntryFactory> */
    use DeniesPartners, HasFactory, LogsAllowlistedActivity;

    public function isRunning(): bool
    {
        return $this->ended_at === null;
    }

    public function isBilled(): bool
    {
        return $this->billing_state === BillingState::Billed;
    }

    /**
     * How the entry reads on the screens: to bill, billed, or not billable.
     */
    public function billingBadge(): BillingBadge
    {
        return BillingBadge::for($this);
    }

    /**
     * Adds `elapsed_seconds` to every row: the exact stored duration of a finished
     * entry, the seconds from the start to `$now` of a running one (never negative).
     *
     * The instant is bound from the PHP clock, never SQL `now()`, because
     * PostgreSQL's `now()` ignores a frozen test clock and would make the screens
     * disagree with the timer.
     *
     * @param  Builder<TimeEntry>  $query
     */
    public function scopeWithElapsedSeconds(Builder $query, CarbonInterface $now): void
    {
        $query
            ->addSelect($this->qualifyColumn('*'))
            ->selectRaw(
                'COALESCE(time_entries.duration_seconds, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - time_entries.started_at))::int)) AS elapsed_seconds',
                [$now->utc()->format('Y-m-d H:i:sP')],
            );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Client, $this>
     */
    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Task, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withTrashed();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'billed_at' => 'immutable_datetime',
            'long_running_notified_at' => 'immutable_datetime',
            'billable' => 'boolean',
            'billing_state' => BillingState::class,
            'duration_seconds' => 'integer',
        ];
    }

    protected static function newFactory(): TimeEntryFactory
    {
        return TimeEntryFactory::new();
    }
}
