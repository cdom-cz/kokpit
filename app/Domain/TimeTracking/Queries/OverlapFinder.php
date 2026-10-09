<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking\Queries;

use App\Domain\TimeTracking\Models\TimeEntry;
use Carbon\CarbonInterface;

/**
 * Finds an entry of the same user that overlaps an interval (D-04).
 *
 * Overlaps are allowed: the result only feeds a warning on the entry form and
 * blocks nothing, and the database has no exclusion constraint. Two intervals
 * overlap when each starts strictly before the other ends, so entries that only
 * touch (one ends at 10:00, the next starts at 10:00) do not overlap. A running
 * entry has no end yet and counts as open-ended, as does a queried interval
 * without an end. A zero-length interval overlaps nothing, on either side: it
 * holds no time.
 *
 * Admin and system contexts only, like every read of tracked time.
 */
final class OverlapFinder
{
    /**
     * The first other entry of the user whose interval overlaps from..to, in
     * start order, or null. Client, project and task are loaded with their
     * archived rows, so the warning can name them.
     *
     * @param  CarbonInterface|null  $to  null for a running interval
     * @param  string|null  $exceptId  an entry that never counts, normally the one being edited
     */
    public function first(string $userId, CarbonInterface $from, ?CarbonInterface $to, ?string $exceptId = null): ?TimeEntry
    {
        if ($to !== null && $to->lessThanOrEqualTo($from)) {
            return null;
        }

        $query = TimeEntry::query()
            ->with(['client', 'project', 'task'])
            ->where('user_id', $userId)
            ->whereRaw("started_at < COALESCE(?::timestamptz, 'infinity'::timestamptz)", [$to?->utc()->format('Y-m-d H:i:sP')])
            ->whereRaw("COALESCE(ended_at, 'infinity'::timestamptz) > ?::timestamptz", [$from->utc()->format('Y-m-d H:i:sP')])
            // A zero-length entry holds no time, so it overlaps nothing.
            ->where(static function ($inner): void {
                $inner->whereNull('ended_at')->orWhereColumn('ended_at', '>', 'started_at');
            })
            ->orderBy('started_at')
            ->orderBy('id');

        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->first();
    }
}
