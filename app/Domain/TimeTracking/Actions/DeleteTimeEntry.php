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

/**
 * Deletes a time entry (TI-02).
 *
 * The row is read again under a row lock, so a stale page cannot delete an
 * entry that was billed in the meantime: a billed entry is refused with the
 * DomainException `kokpit.time.errors.locked` and stays (TI-05, D-06: it is
 * unlocked only through the cancel action of the billing). A KP001 from the
 * guard trigger, the writer-independent backstop, is translated to the same
 * message. A running entry can be deleted; the list does not offer it, the
 * stop of the bar is the normal path.
 */
final class DeleteTimeEntry
{
    /**
     * @throws DomainException the entry is billed
     */
    public function handle(User $actor, TimeEntry $entry): void
    {
        Gate::forUser($actor)->authorize('delete', $entry);

        try {
            DB::transaction(function () use ($entry): void {
                $locked = TimeEntry::query()->whereKey($entry->getKey())->lockForUpdate()->firstOrFail();

                if ($locked->billing_state === BillingState::Billed) {
                    throw TimeEntryInput::locked();
                }

                $locked->delete();
            });
        } catch (QueryException $e) {
            if (TimeEntryInput::isFrozenRowRefusal($e)) {
                throw TimeEntryInput::locked();
            }

            throw $e;
        }
    }
}
