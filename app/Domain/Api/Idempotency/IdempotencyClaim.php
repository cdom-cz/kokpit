<?php

declare(strict_types=1);

namespace App\Domain\Api\Idempotency;

use App\Domain\Api\Models\IdempotencyKey;

/**
 * The result of IdempotencyStore::claim(): the outcome and, when there is one, the row it is about.
 *
 * The row is the claimed one for Claimed and the completed one for Replay; Mismatch and InProgress carry
 * none, because the caller must not touch a row it does not own.
 */
final readonly class IdempotencyClaim
{
    public function __construct(
        public IdempotencyOutcome $outcome,
        public ?IdempotencyKey $row = null,
    ) {}
}
