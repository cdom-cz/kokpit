<?php

declare(strict_types=1);

/*
 * Child process of the sequence allocator concurrency test.
 *
 *   php tests/Concurrency/worker.php <startAt> <count> <scopeKey> <probeTable> <failEvery> <allocator>
 *
 * Boots the application, waits for the shared start barrier so the workers'
 * transactions truly overlap, then runs <count> allocations, each in its own
 * transaction: allocate, sleep two milliseconds inside PostgreSQL to widen the
 * race window, insert (n, pid) into the probe table (primary key on n). Every
 * <failEvery>-th transaction throws after allocating, which must give its
 * number back. Prints one JSON summary line; exits 1 when any allocation
 * failed unexpectedly (for example a duplicate number rejected by the probe).
 */

use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$fail = static function (string $message): never {
    fwrite(STDERR, "worker: {$message}\n");
    exit(2);
};

// Never touch anything but a dedicated test database.
$connection = (string) config('database.default');
$database = (string) config("database.connections.{$connection}.database");
if ($connection !== 'pgsql' || ! str_ends_with($database, '_test')) {
    $fail('refusing to run: the database name must end in _test');
}

if ($argc !== 7) {
    $fail('expected 6 arguments: startAt count scopeKey probeTable failEvery allocator');
}

[, $startAtArg, $countArg, $scopeKey, $probeTable, $failEveryArg, $allocatorClass] = $argv;

$count = (int) $countArg;
$failEvery = (int) $failEveryArg;

if ($count < 1 || $failEvery < 0) {
    $fail('count must be at least 1 and failEvery must not be negative');
}

if (preg_match('/^seq_probe_[0-9a-f]+$/D', $probeTable) !== 1) {
    $fail('unexpected probe table name');
}

if (! is_a($allocatorClass, SequenceAllocator::class, true)) {
    $fail('the allocator must be a SequenceAllocator class');
}

$allocator = new $allocatorClass;

// The barrier timestamp is shared through the environment; the argument is the fallback.
$barrierEnv = getenv('KOKPIT_BARRIER_AT');
$barrierAt = (float) ($barrierEnv !== false && $barrierEnv !== '' ? $barrierEnv : $startAtArg);

// Open the connection before the barrier so the first query does not pay for it afterwards.
DB::select('select 1');

while (microtime(true) < $barrierAt) {
    usleep(100);
}

$pid = (int) getmypid();
$committed = 0;
$rolledBack = 0;
$errors = [];
$plannedRollback = 'planned rollback after allocating';

for ($i = 1; $i <= $count; $i++) {
    try {
        DB::transaction(function () use ($allocator, $scopeKey, $probeTable, $pid, $i, $failEvery, $plannedRollback): void {
            $n = $allocator->next($scopeKey);

            DB::select('select pg_sleep(0.002)');

            DB::table($probeTable)->insert(['n' => $n, 'pid' => $pid]);

            if ($failEvery > 0 && $i % $failEvery === 0) {
                throw new RuntimeException($plannedRollback);
            }
        });

        $committed++;
    } catch (RuntimeException $e) {
        if ($e->getMessage() === $plannedRollback) {
            $rolledBack++;
        } else {
            $errors[] = $e::class.': '.substr($e->getMessage(), 0, 160);
        }
    } catch (Throwable $e) {
        $errors[] = $e::class.': '.substr($e->getMessage(), 0, 160);
    }
}

echo json_encode([
    'pid' => $pid,
    'committed' => $committed,
    'rolled_back' => $rolledBack,
    'errors' => $errors,
], JSON_THROW_ON_ERROR), "\n";

exit($errors === [] ? 0 : 1);
