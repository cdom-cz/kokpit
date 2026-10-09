<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\TimerLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use LogicException;
use RuntimeException;

/**
 * Test-only mutation for the timer concurrency harness; never used by
 * application code.
 *
 * Identical to TimerLock except that lock() takes no advisory lock, so two
 * parallel starts of one user are no longer serialized and only the partial
 * unique index time_entries_one_running_per_user stands between them and two
 * running timers.
 *
 * Without a rendezvous the double just skips the lock and the defect would show
 * at natural timing, which is not reliable. With a rendezvous directory and a
 * party count it forces the race: lock() arms a one-shot wait, and the wait runs
 * on the Eloquent `creating` event of TimeEntry. That event fires inside
 * StartTimer after the running entry of the user was read and before the INSERT,
 * so every party has read "nothing running" before any of them inserts. Each
 * party writes the file `<dir>/<pid>` and polls until the directory holds
 * `<parties>` files; a party that never arrives makes the others fail with
 * `rendezvous timeout` after 15 seconds, which fails the round loudly instead of
 * passing by luck.
 *
 * The rendezvous must never be combined with the real TimerLock: the second
 * party would wait on the advisory lock held by the first and never arrive.
 */
final class UnlockedTimerLock extends TimerLock
{
    private const int TIMEOUT_SECONDS = 15;

    private const int POLL_MICROSECONDS = 5000;

    private bool $armed = false;

    public function __construct(private readonly ?string $rendezvousDir = null, private readonly int $parties = 0)
    {
        if ($rendezvousDir === null) {
            return;
        }

        Event::listen('eloquent.creating: '.TimeEntry::class, function (): void {
            if (! $this->armed) {
                return;
            }

            $this->armed = false;
            $this->rendezvous();
        });
    }

    #[\Override]
    public function lock(User $user): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TimerLock::lock() must run inside a database transaction.');
        }

        $this->armed = $this->rendezvousDir !== null;
    }

    private function rendezvous(): void
    {
        $dir = (string) $this->rendezvousDir;

        file_put_contents($dir.'/'.getmypid(), '');

        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (count(glob($dir.'/*') ?: []) < $this->parties) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('rendezvous timeout');
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }
}
