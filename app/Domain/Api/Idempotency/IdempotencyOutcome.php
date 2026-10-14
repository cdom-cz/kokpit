<?php

declare(strict_types=1);

namespace App\Domain\Api\Idempotency;

/**
 * What a claim of an Idempotency-Key found (D-07).
 */
enum IdempotencyOutcome
{
    /** The caller owns the key now and must run the request. */
    case Claimed;

    /** The same request already succeeded; its stored answer is replayed. */
    case Replay;

    /** The key was used for a different request. */
    case Mismatch;

    /** The same request is running right now. */
    case InProgress;
}
