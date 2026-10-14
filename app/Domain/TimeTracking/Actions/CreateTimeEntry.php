<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Billing\BillableDefault;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Records a finished time entry by hand (TI-02, TI-03, TI-08).
 *
 * A manual entry is always finished: Konec is required and must lie strictly
 * after Začátek. A running entry exists only through StartTimer. The client is
 * always required; the project and the task are optional, so an entry with only
 * a client is valid. The context rules and their field errors live in
 * TimeEntryInput::context(); the same class parses the two instants (the
 * application timezone, which is UTC, or an ISO 8601 string with an offset) and
 * truncates them to the whole second, so the stored duration is exact. The optional
 * `external` JSON object of an import helper (D-15) is checked by
 * TimeEntryInput::external() and stored as given; the panel never sends it. A day
 * that changes the clock is just two UTC instants apart.
 *
 * `billable` is the given bool, or the D-03 default of the task when it is
 * absent or null. Ids and instants are set with `forceFill`, so the payload can
 * never smuggle them in. The only cap on the duration is the integer column limit
 * `TimeEntryInput::MAX_DURATION_SECONDS` (about 68 years), refused under `ended_at`;
 * there is still no ban on a time ahead of now: a typo is corrected by editing.
 *
 * Lock order: the task, project and client rows FOR SHARE. A manual entry never
 * touches the running timer, so it takes no timer lock.
 *
 * @phpstan-type EntryData array{client_id?: mixed, project_id?: mixed, task_id?: mixed, description?: mixed, started_at?: mixed, ended_at?: mixed, billable?: mixed, external?: mixed}
 */
final class CreateTimeEntry
{
    public function __construct(private readonly BillableDefault $billableDefault) {}

    /**
     * @param  EntryData  $data
     *
     * @throws ValidationException
     */
    public function handle(User $actor, array $data): TimeEntry
    {
        Gate::forUser($actor)->authorize('create', TimeEntry::class);

        $description = TimeEntryInput::description($data['description'] ?? null);
        $start = TimeEntryInput::instant($data['started_at'] ?? null, 'started_at');
        $end = TimeEntryInput::instant($data['ended_at'] ?? null, 'ended_at');
        $external = TimeEntryInput::external($data['external'] ?? null);

        return DB::transaction(function () use ($actor, $data, $description, $start, $end, $external): TimeEntry {
            $context = TimeEntryInput::context($data);

            if ($start === null) {
                throw ValidationException::withMessages(['started_at' => __('kokpit.time.errors.start_required')]);
            }

            if ($end === null) {
                throw ValidationException::withMessages(['ended_at' => __('kokpit.time.errors.end_required')]);
            }

            TimeEntryInput::assertEndAfterStart($start, $end);
            TimeEntryInput::assertDurationFits($start, $end, 'ended_at');

            $billable = is_bool($data['billable'] ?? null)
                ? $data['billable']
                : $this->billableDefault->for($context['task']);

            $entry = (new TimeEntry(['description' => $description, 'billable' => $billable]))->forceFill([
                'user_id' => $actor->getKey(),
                'client_id' => $context['client']->getKey(),
                'project_id' => $context['project']?->getKey(),
                'task_id' => $context['task']?->getKey(),
                'started_at' => $start,
                'ended_at' => $end,
                'external' => $external,
            ]);
            $entry->save();

            return $entry->refresh();
        });
    }
}
