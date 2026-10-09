<?php

declare(strict_types=1);

namespace App\Domain\TimeTracking;

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * The per-user lock behind every timer decision.
 *
 * StartTimer and StopTimer take it first inside their transaction, before any
 * row lock, so one user's starts and stops serialize while different users never
 * wait on each other. It is a transaction-scoped advisory lock: it is released
 * with the commit or the rollback, never by hand. The partial unique index
 * time_entries_one_running_per_user stays the authority behind it; the lock only
 * keeps the race out of the normal case.
 *
 * Not final on purpose: the concurrency harness resolves it from the container
 * and a test double that skips the lock extends it. The production Actions carry
 * no test hook.
 */
class TimerLock
{
    public const string KEY_PREFIX = 'kokpit:timer:';

    /**
     * Takes the timer lock of the user until the surrounding transaction ends.
     *
     * @throws LogicException outside a database transaction
     */
    public function lock(User $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TimerLock::lock() must run inside a database transaction.');
        }

        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', [self::KEY_PREFIX.$user->getKey()]);
    }
}
