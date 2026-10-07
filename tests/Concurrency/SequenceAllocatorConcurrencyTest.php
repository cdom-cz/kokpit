<?php

declare(strict_types=1);

use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\Support\UnlockedSequenceAllocator;

/*
 * Real parallel PHP processes against PostgreSQL.
 *
 * This suite has no RefreshDatabase wrapper on purpose: the workers are other
 * processes, so only committed rows are visible to them and to the assertions.
 * Every test creates its own probe table and counter key (random hex suffix)
 * and removes both again in afterEach.
 *
 * A passing run proves gap-free allocation only because the mutation test
 * shows the same harness failing against an allocator without the row lock.
 */

const CONCURRENCY_WORKERS = 8;
const CONCURRENCY_PER_WORKER = 25;
const CONCURRENCY_BARRIER_SECONDS = 3.0;
const CONCURRENCY_TIMEOUT_SECONDS = 60;

/**
 * Bookkeeping for the tables and counter keys created by the running test.
 */
function concurrencyScratch(): ArrayObject
{
    static $scratch = null;

    return $scratch ??= new ArrayObject(['tables' => [], 'keys' => []]);
}

/**
 * @return array{probe: string, key: string}
 */
function concurrencyNewProbe(): array
{
    $suffix = bin2hex(random_bytes(6));
    $probe = "seq_probe_{$suffix}";
    $key = "probe:{$suffix}";

    DB::statement("CREATE TABLE {$probe} (n bigint PRIMARY KEY, pid integer NOT NULL)");

    $scratch = concurrencyScratch();
    $scratch['tables'] = [...$scratch['tables'], $probe];
    $scratch['keys'] = [...$scratch['keys'], $key];

    return ['probe' => $probe, 'key' => $key];
}

/**
 * Starts the workers behind one shared barrier and waits for all of them.
 *
 * @param  class-string<SequenceAllocator>  $allocator
 * @param  array<string, string>  $envOverrides
 * @return list<array{exit: int|null, summary: array{pid: int, committed: int, rolled_back: int, errors: list<string>}|null, stderr: string}>
 */
function concurrencyRunWorkers(string $probe, string $key, string $allocator, int $failEvery, array $envOverrides = []): array
{
    expect(DB::transactionLevel())->toBe(0, 'the parent must not hold a transaction while workers run');

    $connection = (string) config('database.default');
    $barrier = number_format(microtime(true) + CONCURRENCY_BARRIER_SECONDS, 6, '.', '');

    $env = array_merge([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => $connection,
        'DB_HOST' => (string) config("database.connections.{$connection}.host"),
        'DB_PORT' => (string) config("database.connections.{$connection}.port"),
        'DB_DATABASE' => (string) config("database.connections.{$connection}.database"),
        'DB_USERNAME' => (string) config("database.connections.{$connection}.username"),
        'DB_PASSWORD' => (string) config("database.connections.{$connection}.password"),
        'DB_URL' => '',
        'KOKPIT_BARRIER_AT' => $barrier,
    ], $envOverrides);

    $processes = [];
    for ($i = 0; $i < CONCURRENCY_WORKERS; $i++) {
        $process = new Process(
            [PHP_BINARY, __DIR__.'/worker.php', $barrier, (string) CONCURRENCY_PER_WORKER, $key, $probe, (string) $failEvery, $allocator],
            dirname(__DIR__, 2),
            $env,
        );
        $process->setTimeout(CONCURRENCY_TIMEOUT_SECONDS);
        $process->start();
        $processes[] = $process;
    }

    $results = [];
    foreach ($processes as $process) {
        $process->wait();

        $decoded = json_decode(trim($process->getOutput()), true);

        $results[] = [
            'exit' => $process->getExitCode(),
            'summary' => is_array($decoded) && isset($decoded['pid'], $decoded['committed'], $decoded['rolled_back'], $decoded['errors']) ? $decoded : null,
            'stderr' => $process->getErrorOutput(),
        ];
    }

    return $results;
}

/**
 * @return list<int>
 */
function concurrencyProbeNumbers(string $probe): array
{
    return DB::table($probe)->orderBy('n')->pluck('n')->map(fn (mixed $n): int => (int) $n)->all();
}

beforeEach(function () {
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    $scratch = concurrencyScratch();

    foreach ($scratch['tables'] as $table) {
        Schema::dropIfExists($table);
    }

    if ($scratch['keys'] !== []) {
        DB::table('number_sequences')->whereIn('scope_key', $scratch['keys'])->delete();
    }

    $scratch['tables'] = [];
    $scratch['keys'] = [];
});

it('hands out exactly 1..200 to 8 parallel workers with no duplicate and no gap', function () {
    ['probe' => $probe, 'key' => $key] = concurrencyNewProbe();
    $total = CONCURRENCY_WORKERS * CONCURRENCY_PER_WORKER;

    $results = concurrencyRunWorkers($probe, $key, SequenceAllocator::class, 0);

    foreach ($results as $result) {
        expect($result['exit'])->toBe(0, "worker failed: {$result['stderr']}")
            ->and($result['summary'])->not->toBeNull()
            ->and($result['summary']['errors'] ?? null)->toBe([]);
    }

    expect(DB::table($probe)->count())->toBe($total)
        ->and(concurrencyProbeNumbers($probe))->toBe(range(1, $total))
        ->and((int) DB::table('number_sequences')->where('scope_key', $key)->value('next_value'))->toBe($total + 1)
        ->and(DB::table($probe)->distinct()->count('pid'))->toBeGreaterThanOrEqual(2);
});

it('keeps committed numbers consecutive when every fourth transaction is rolled back', function () {
    ['probe' => $probe, 'key' => $key] = concurrencyNewProbe();
    $rolledBackPerWorker = intdiv(CONCURRENCY_PER_WORKER, 4);
    $expected = CONCURRENCY_WORKERS * (CONCURRENCY_PER_WORKER - $rolledBackPerWorker);

    $results = concurrencyRunWorkers($probe, $key, SequenceAllocator::class, 4);

    foreach ($results as $result) {
        expect($result['exit'])->toBe(0, "worker failed: {$result['stderr']}")
            ->and($result['summary'])->not->toBeNull()
            ->and($result['summary']['rolled_back'] ?? null)->toBe($rolledBackPerWorker);
    }

    $committed = DB::table($probe)->count();

    expect($committed)->toBe($expected)
        ->and(concurrencyProbeNumbers($probe))->toBe(range(1, $committed))
        ->and((int) DB::table('number_sequences')->where('scope_key', $key)->value('next_value'))->toBe($committed + 1)
        ->and(DB::table($probe)->distinct()->count('pid'))->toBeGreaterThanOrEqual(2);
});

it('detects the defect when the allocator has no row lock (mutation run)', function () {
    ['probe' => $probe, 'key' => $key] = concurrencyNewProbe();
    $total = CONCURRENCY_WORKERS * CONCURRENCY_PER_WORKER;

    $results = concurrencyRunWorkers($probe, $key, UnlockedSequenceAllocator::class, 0);

    // Guard against a vacuous pass: every worker must have run and accounted for all of its attempts.
    $attempts = 0;
    $failures = 0;
    foreach ($results as $result) {
        expect($result['summary'])->not->toBeNull("worker produced no summary: {$result['stderr']}");

        $attempts += $result['summary']['committed'] + count($result['summary']['errors']);
        $failures += $result['exit'] === 0 ? 0 : 1;
    }

    expect($attempts)->toBe($total);

    $rows = DB::table($probe)->count();
    $detected = $rows < $total || $failures > 0;

    expect($detected)->toBeTrue('the harness did not notice the missing row lock')
        ->and($rows)->toBeLessThan($total);
});

it('refuses to run against a database that is not a test database', function () {
    ['probe' => $probe, 'key' => $key] = concurrencyNewProbe();

    $process = new Process(
        [PHP_BINARY, __DIR__.'/worker.php', number_format(microtime(true), 6, '.', ''), '1', $key, $probe, '0', SequenceAllocator::class],
        dirname(__DIR__, 2),
        ['APP_ENV' => 'testing', 'DB_DATABASE' => 'scratch_db', 'DB_URL' => ''],
    );
    $process->setTimeout(CONCURRENCY_TIMEOUT_SECONDS);
    $process->run();

    expect($process->getExitCode())->not->toBe(0)
        ->and($process->getErrorOutput())->toContain('_test')
        ->and(DB::table($probe)->count())->toBe(0);
});
