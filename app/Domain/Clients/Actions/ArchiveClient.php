<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;

/**
 * Archives a client: a soft delete, never a removal (CL-05, D-11).
 *
 * The projects of the client stay but leave the pickers and every Partner view,
 * its Partner accounts can no longer log in, and its tags stay attached (see
 * the detach guard on Client). Archiving an archived client is a no-op that
 * leaves `deleted_at` as the first call set it.
 */
final class ArchiveClient
{
    public function handle(Client $client): void
    {
        // Another request may have archived the row since this instance was loaded.
        $client->refresh();

        if ($client->trashed()) {
            return;
        }

        $client->delete();
    }
}
