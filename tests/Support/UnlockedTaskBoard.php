<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tasks\Board\TaskBoard;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Test-only mutation for the board concurrency harness; never used by
 * application code.
 *
 * Identical to TaskBoard except that lockBoard() takes no advisory lock. Two
 * parallel moves into one column can then read the same column and write the
 * same positions, so the harness must see a duplicate position, a failed move
 * or a lost move. If it does not, a clean run against the real board proves
 * nothing.
 *
 * The production class gets no hook for widening the race window: without the
 * lock the defect shows at natural timing.
 */
final class UnlockedTaskBoard extends TaskBoard
{
    #[\Override]
    public function lockBoard(): void
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('TaskBoard::lockBoard() must run inside a database transaction.');
        }
    }
}
