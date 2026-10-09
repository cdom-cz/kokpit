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
use App\Domain\TimeTracking\Enums\BillingState;
use Carbon\CarbonImmutable;
use Database\Factories\TimeEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
