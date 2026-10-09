<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Jobs;

use App\Domain\Identity\RoleName;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Notifications\LongRunningTimerNotification;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\TimeEntryResource;
use Illuminate\Support\Facades\DB;

/**
 * Tells the Admin once, in the bell, that a timer has run longer than the threshold
 * (TI-09, D-07). Scheduled every five minutes on one server.
 *
 * It only announces. It never stops a timer, sends no e-mail, and never mentions a
 * finished entry, an entry under the threshold, or an entry whose owner is
 * deactivated or not the Admin. An owner who cannot be told is skipped without a
 * claim, so a reactivated Admin still hears of a timer that keeps running.
 *
 * The claim is one atomic update of `long_running_notified_at` where it is still null
 * and the entry is still running. It is an Eloquent builder update on purpose: it
 * raises no model event, so the internal marker writes no activity row. The synchronous
 * notification is sent in the same transaction, so a failure in it rolls the claim back
 * and the next run tries again.
 */
#[Idempotent(how: 'claims each running entry once through long_running_notified_at in the notification transaction, so a second run notifies nothing new')]
final class NotifyLongRunningTimers extends KokpitJob
{
    public function handle(): void
    {
        $hours = max(1, (int) config('kokpit.time.long_running_hours'));
        $now = TimerClock::now();
        $cutoff = $now->subHours($hours);

        $candidates = TimeEntry::query()
            ->whereNull('ended_at')
            ->whereNull('long_running_notified_at')
            ->where('started_at', '<=', $cutoff)
            ->with(['user', 'client'])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $entry) {
            $owner = $entry->user;
            $client = $entry->client;

            // Both foreign keys are not null, so a missing row cannot happen; the guard
            // keeps the types honest and skips the entry without a claim.
            if ($owner === null || $client === null) {
                continue;
            }

            if ($owner->deactivated_at !== null || ! $owner->hasRole(RoleName::Admin->value)) {
                continue;
            }

            DB::transaction(function () use ($entry, $owner, $client, $now): void {
                $claimed = TimeEntry::query()
                    ->whereKey($entry->getKey())
                    ->whereNull('ended_at')
                    ->whereNull('long_running_notified_at')
                    ->update(['long_running_notified_at' => $now]);

                if ($claimed !== 1) {
                    return;
                }

                $owner->notifyNow(new LongRunningTimerNotification(
                    clientName: $client->name,
                    elapsedSeconds: max(0, $now->getTimestamp() - $entry->started_at->getTimestamp()),
                    url: TimeEntryResource::getUrl('view', ['record' => $entry]),
                ));
            });
        }
    }
}
