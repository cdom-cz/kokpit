<?php

declare(strict_types=1);

namespace App\Domain\Api\Jobs;

use App\Domain\Api\Models\IdempotencyKey;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;

/**
 * Deletes the idempotency rows whose 24 hours have passed (D-08). Scheduled daily on one server.
 *
 * Only expires_at decides: a live claim, completed or not, is never touched here. A row past its expiry is
 * already treated as absent by IdempotencyStore, so the purge only keeps the table small.
 */
#[Idempotent(how: 'deletes rows past expires_at; a second run finds nothing to delete')]
final class PurgeExpiredIdempotencyKeys extends KokpitJob
{
    public function handle(): void
    {
        IdempotencyKey::query()->where('expires_at', '<', now())->delete();
    }
}
