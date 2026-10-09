<?php

declare(strict_types=1);

/*
 * Child process of the timer concurrency test.
 *
 *   php tests/Concurrency/timer-worker.php <startAt> <count> <userId> <clientId> <lockClass> [<rendezvousDir> <parties>]
 *
 * Boots the application, signs in the user (a worker has no session, so every
 * Partner scope would otherwise be fail-closed), waits for the shared start
 * barrier so the workers' transactions truly overlap, then calls StartTimer
 * <count> times for the client. A start that loses the race on the one-running
 * index (TimerRaceLost) is counted as `raced`, every other failure as `failed`
 * (with its message kept in the summary); neither is fatal. Prints one JSON
 * summary line and exits 1 when any start failed.
 *
 * <lockClass> is the short name `TimerLock` or `UnlockedTimerLock` and is bound
 * to TimerLock in the container as one instance before StartTimer is resolved.
 *
 * Optional, for the mutation run only: <rendezvousDir> and <parties> make the
 * UnlockedTimerLock hold every party between the running-row read and the insert
 * until all of them have arrived (see UnlockedTimerLock). They are refused with
 * the real TimerLock, which would deadlock the rendezvous, and with a count other
 * than 1, because the rendezvous is one-shot per party.
 *
 * Refuses to run unless the database name ends in `_test`.
 */

use App\Domain\Identity\Models\User;
use App\Domain\TimeTracking\Actions\StartTimer;
use App\Domain\TimeTracking\TimerLock;
use App\Domain\TimeTracking\TimerRaceLost;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Tests\Support\UnlockedTimerLock;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "timer-worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 6 && $argc !== 8) {
    $fail('expected 5 or 7 arguments: startAt count userId clientId lockClass [rendezvousDir parties]');
}

[, $startAtArg, $countArg, $userId, $clientId, $lockName] = $argv;
$rendezvousDir = $argv[6] ?? null;
$parties = (int) ($argv[7] ?? 0);

$count = (int) $countArg;
$uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D';

if ($count < 1) {
    $fail('count must be at least 1');
}

if (preg_match($uuid, $userId) !== 1 || preg_match($uuid, $clientId) !== 1) {
    $fail('the user id and the client id must be uuids');
}

$lockClasses = ['TimerLock' => TimerLock::class, 'UnlockedTimerLock' => UnlockedTimerLock::class];

if (! isset($lockClasses[$lockName])) {
    $fail('the lock class must be TimerLock or UnlockedTimerLock');
}

if ($rendezvousDir !== null) {
    if ($lockName !== 'UnlockedTimerLock') {
        $fail('the rendezvous would deadlock behind the real TimerLock: use it with UnlockedTimerLock only');
    }

    if ($count !== 1) {
        $fail('the rendezvous needs a count of exactly 1');
    }

    if (! is_dir($rendezvousDir) || $parties < 2 || $parties > 64) {
        $fail('the rendezvous needs an existing directory and between 2 and 64 parties');
    }

    $lock = new UnlockedTimerLock($rendezvousDir, $parties);
} else {
    $lockClass = $lockClasses[$lockName];
    $lock = new $lockClass;
}

$app->instance(TimerLock::class, $lock);

$user = User::query()->findOrFail($userId);
auth()->setUser($user);

$action = $app->make(StartTimer::class);

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();
$started = 0;
$raced = 0;
$failed = 0;
$lastError = null;

for ($i = 1; $i <= $count; $i++) {
    try {
        $action->handle($user, ['client_id' => $clientId]);

        $started++;
    } catch (TimerRaceLost) {
        $raced++;
    } catch (Throwable $e) {
        $failed++;
        $lastError = $e::class.': '.substr($e->getMessage(), 0, 160);
    }
}

echo json_encode([
    'pid' => $pid,
    'started' => $started,
    'raced' => $raced,
    'failed' => $failed,
    'last_error' => $lastError,
], JSON_THROW_ON_ERROR), "\n";

exit($failed === 0 ? 0 : 1);
