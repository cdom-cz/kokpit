<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use Illuminate\Support\Facades\DB;

/**
 * Makes a contact the primary contact of its client (CL-02, D-12).
 *
 * The current primary is demoted first and the given contact promoted second,
 * because the partial unique index `contacts_one_primary_per_client` is checked
 * per statement and cannot be deferred (research Pitfall 10). Both writes run in
 * one transaction with the client row locked, so two Admin sessions switching the
 * primary of the same client at the same moment are serialised; the index is the
 * backstop behind the lock. Making the current primary primary again changes
 * nothing. The demotion saves through the model, so the activity log sees it.
 */
final class SetPrimaryContact
{
    public function handle(Contact $contact): void
    {
        DB::transaction(static function () use ($contact): void {
            // Serialises every contact write of this client; the archived client is locked too.
            Client::query()->withTrashed()->whereKey($contact->client_id)->lockForUpdate()->firstOrFail();

            // Another session may have moved the flag or removed the row since this instance was loaded.
            $fresh = Contact::query()->whereKey($contact->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->is_primary) {
                $contact->refresh();

                return;
            }

            foreach (Contact::query()->where('client_id', $fresh->client_id)->where('is_primary', true)->get() as $current) {
                $current->forceFill(['is_primary' => false])->save();
            }

            $fresh->forceFill(['is_primary' => true])->save();

            $contact->refresh();
        });
    }
}
