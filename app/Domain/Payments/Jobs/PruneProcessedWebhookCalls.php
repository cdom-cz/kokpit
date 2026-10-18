<?php

declare(strict_types=1);

namespace App\Domain\Payments\Jobs;

use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Shared\Models\WebhookCall;

/**
 * Deletes the Stripe events that were processed more than 90 days ago (PA-08, D-17). Scheduled daily on one server.
 *
 * What is prunable is decided by {@see WebhookCall::prunable()}: processed rows only, never a failed or waiting one.
 * The model denies Partners and is scoped, so the delete runs in the system context the job middleware enters; a
 * console command outside that context would see no row. The delete goes in chunks (MassPrunable), so a large backlog
 * is never held in memory.
 */
#[Idempotent(how: 'deletes only processed events past the retention; a second run finds nothing more to delete')]
final class PruneProcessedWebhookCalls extends KokpitJob
{
    public function handle(): void
    {
        (new WebhookCall)->pruneAll();
    }
}
