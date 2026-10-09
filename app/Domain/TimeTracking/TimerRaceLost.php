<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking;

use DomainException;

/**
 * A start lost the race on time_entries_one_running_per_user: another timer of
 * the same user was started in the meantime.
 *
 * The advisory lock keeps this out of the normal case; the partial unique index
 * is the backstop, and this is its typed, translated face. Thrown outside the
 * rolled-back transaction, so the caller can show the message and let the user
 * try again. Nothing is retried here.
 */
final class TimerRaceLost extends DomainException
{
    public function __construct()
    {
        parent::__construct(__('kokpit.time.errors.start_race'));
    }
}
