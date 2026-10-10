<?php

declare(strict_types=1);

namespace App\Domain\Signal\Support;

use Illuminate\Support\Facades\DB;

/**
 * A transaction-scoped advisory lock keyed by text, so two parallel requests of one user that change
 * the same day or week (the per-day limits, the three goals, the order of a group) run one after the
 * other. It must be taken inside DB::transaction(); PostgreSQL releases it on commit or rollback.
 */
final class SignalLock
{
    public static function take(string ...$parts): void
    {
        DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['signal:'.implode(':', $parts)]);
    }
}
