<?php

declare(strict_types=1);

namespace Tests\Support\Probes;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use Illuminate\Queue\Attributes\Backoff;
use RuntimeException;

/**
 * A test-only job that always fails. The retry wait is zero so a worker run in
 * a test does not sleep. The message is built by the test at runtime and is
 * meant to be long, so the alert tests can prove that only the head of it leaves.
 */
#[Idempotent(how: 'Fails before it writes anything, so a second run changes nothing')]
#[Backoff(0)]
final class FailingProbeJob extends KokpitJob
{
    /** Counted in the worker process, which is the test process itself. */
    public static int $attempts = 0;

    public function __construct(public readonly string $message = 'Fictional job failure') {}

    public function handle(): never
    {
        self::$attempts++;

        throw new RuntimeException($this->message);
    }
}
