<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\Sequences\SequenceAllocator;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Test-only mutation for the concurrency harness; never used by application code.
 *
 * Identical to SequenceAllocator::next() except that the SELECT takes no row
 * lock. Two parallel callers can read the same counter value, so the harness
 * must see duplicates. If it does not, a passing run against the real
 * allocator would prove nothing.
 */
final class UnlockedSequenceAllocator extends SequenceAllocator
{
    #[\Override]
    public function next(string $scopeKey): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('SequenceAllocator::next() must run inside the caller\'s transaction.');
        }

        DB::insert(
            'INSERT INTO number_sequences (scope_key, next_value, created_at, updated_at)
             VALUES (?, 1, now(), now()) ON CONFLICT (scope_key) DO NOTHING',
            [$scopeKey],
        );

        // The only difference to the real allocator: no FOR UPDATE.
        $row = DB::selectOne('SELECT next_value FROM number_sequences WHERE scope_key = ?', [$scopeKey]);

        if ($row === null) {
            throw new RuntimeException('Counter row vanished after insert.');
        }

        DB::update(
            'UPDATE number_sequences SET next_value = next_value + 1, updated_at = now() WHERE scope_key = ?',
            [$scopeKey],
        );

        return (int) $row->next_value;
    }
}
