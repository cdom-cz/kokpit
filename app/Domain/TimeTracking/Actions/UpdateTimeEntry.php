<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Enums\BillingState;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimeEntryInput;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Edits an unbilled time entry (TI-02, TI-07).
 *
 * A key that is absent from the data keeps the stored value, `billable`
 * included: a task change never silently flips the flag (the form presets it,
 * D-03). The row is read again under a row lock, so a stale page cannot edit an
 * entry that was billed in the meantime: a billed entry is refused with the
 * DomainException `kokpit.time.errors.locked` (TI-05, D-06: unlocked only
 * through the cancel action of the billing). A KP001 from the guard trigger,
 * the writer-independent backstop, is translated to the same message.
 *
 * Context: the three ids are checked again only when one of them changes, so
 * the description of an entry whose task was archived later still saves. A
 * changed context goes through the rules of a start (TimeEntryInput::context).
 * Naming a new task derives the project and the client from it, and naming a
 * new project derives the client, unless the data names them too; so a form
 * that sends all three and a caller that sends only the task both work, and a
 * forged combination is still refused as inconsistent.
 *
 * Times: a finished entry takes a changed start and end, parsed and truncated
 * like a creation, and the end must lie strictly after the start whenever either
 * changes (an untouched zero-length entry from a same-second start can still have
 * its description edited). A running entry can change its start but keeps
 * `ended_at` null: a named end is ignored, the stop is StopTimer.
 *
 * Lock order: the task, project and client rows FOR SHARE (a changed context
 * only), after the entry row FOR UPDATE.
 *
 * @phpstan-type EntryChanges array{client_id?: mixed, project_id?: mixed, task_id?: mixed, description?: mixed, started_at?: mixed, ended_at?: mixed, billable?: mixed}
 */
final class UpdateTimeEntry
{
    private const array CONTEXT_KEYS = ['client_id', 'project_id', 'task_id'];

    /**
     * @param  EntryChanges  $data
     *
     * @throws ValidationException
     * @throws DomainException the entry is billed
     */
    public function handle(User $actor, TimeEntry $entry, array $data): TimeEntry
    {
        Gate::forUser($actor)->authorize('update', $entry);

        $description = array_key_exists('description', $data) ? TimeEntryInput::description($data['description']) : null;

        try {
            return DB::transaction(function () use ($entry, $data, $description): TimeEntry {
                $locked = TimeEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->billing_state === BillingState::Billed) {
                    throw TimeEntryInput::locked();
                }

                if ($this->contextChanged($locked, $data)) {
                    $context = TimeEntryInput::context($this->contextRequest($locked, $data));

                    $locked->forceFill([
                        'client_id' => $context['client']->getKey(),
                        'project_id' => $context['project']?->getKey(),
                        'task_id' => $context['task']?->getKey(),
                    ]);
                }

                $this->applyTimes($locked, $data);

                if (array_key_exists('description', $data)) {
                    $locked->description = $description;
                }

                if (is_bool($data['billable'] ?? null)) {
                    $locked->billable = $data['billable'];
                }

                $locked->save();

                return $locked->refresh();
            });
        } catch (QueryException $e) {
            if (TimeEntryInput::isFrozenRowRefusal($e)) {
                throw TimeEntryInput::locked();
            }

            throw $e;
        }
    }

    /**
     * Whether the data names a client, project or task that differs from the stored one.
     *
     * @param  EntryChanges  $data
     */
    private function contextChanged(TimeEntry $stored, array $data): bool
    {
        foreach (self::CONTEXT_KEYS as $key) {
            if (array_key_exists($key, $data) && $this->id($data[$key]) !== $stored->getAttribute($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ids to check: the named ones over the stored ones, where a new task
     * or project stands in for the ids below it that the data does not name.
     *
     * @param  EntryChanges  $data
     * @return array{client_id: mixed, project_id: mixed, task_id: mixed}
     */
    private function contextRequest(TimeEntry $stored, array $data): array
    {
        $namesTask = array_key_exists('task_id', $data);
        $namesProject = array_key_exists('project_id', $data);
        $newTask = $namesTask && $this->id($data['task_id']) !== null;
        $newProject = $namesProject && $this->id($data['project_id']) !== null;

        return [
            'task_id' => $namesTask ? $data['task_id'] : $stored->task_id,
            'project_id' => $namesProject ? $data['project_id'] : ($newTask ? null : $stored->project_id),
            'client_id' => array_key_exists('client_id', $data)
                ? $data['client_id']
                : ($newTask || $newProject ? null : $stored->client_id),
        ];
    }

    /**
     * Sets the changed instants on the locked row after the field checks.
     *
     * @param  EntryChanges  $data
     *
     * @throws ValidationException
     */
    private function applyTimes(TimeEntry $locked, array $data): void
    {
        $start = $locked->started_at;
        $end = $locked->ended_at;

        if (array_key_exists('started_at', $data)) {
            $start = TimeEntryInput::instant($data['started_at'], 'started_at')
                ?? throw ValidationException::withMessages(['started_at' => __('kokpit.time.errors.start_required')]);
        }

        // A running entry keeps ended_at null: the stop is StopTimer.
        if (! $locked->isRunning() && array_key_exists('ended_at', $data)) {
            $end = TimeEntryInput::instant($data['ended_at'], 'ended_at')
                ?? throw ValidationException::withMessages(['ended_at' => __('kokpit.time.errors.end_required')]);
        }

        $changed = ! $start->equalTo($locked->started_at) || ($end !== null && ($locked->ended_at === null || ! $end->equalTo($locked->ended_at)));

        if (! $locked->isRunning() && $changed) {
            TimeEntryInput::assertEndAfterStart($start, $end);
        }

        $locked->forceFill(['started_at' => $start, 'ended_at' => $end]);
    }

    private function id(mixed $value): mixed
    {
        return $value === '' ? null : $value;
    }
}
