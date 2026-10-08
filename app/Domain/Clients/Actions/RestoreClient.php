<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;

/**
 * Restores an archived client (CL-05, D-11): its Partners can log in again and
 * its client-visible projects reappear for them. Restoring an active client is
 * a no-op.
 */
final class RestoreClient
{
    public function handle(Client $client): void
    {
        // Another request may have restored the row since this instance was loaded.
        $client->refresh();

        if (! $client->trashed()) {
            return;
        }

        $client->restore();
    }
}
