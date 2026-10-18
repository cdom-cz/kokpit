<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Sequences\SequenceAllocator;
use RuntimeException;

/**
 * Test-only failure seam for the invoice issue concurrency harness; never used by application code.
 *
 * It is the real allocator, row lock included, but for the calls it was configured with it throws after the number
 * was taken and the counter advanced. The exception propagates out of the issue transaction, so the rollback must
 * take the number back (D-10): a failure after the allocation may never leave a gap. The calls are counted per
 * instance, that is per worker process.
 */
final class FailingAfterAllocationSequenceAllocator extends SequenceAllocator
{
    public const string MESSAGE = 'planned failure after the number was allocated';

    private int $calls = 0;

    /**
     * @param  list<int>  $failOnCalls  the 1-based call numbers that throw once the number was allocated
     */
    public function __construct(private readonly array $failOnCalls) {}

    #[\Override]
    public function next(string $scopeKey): int
    {
        $number = parent::next($scopeKey);

        $this->calls++;

        if (in_array($this->calls, $this->failOnCalls, true)) {
            throw new RuntimeException(self::MESSAGE);
        }

        return $number;
    }
}
