<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a contact (CL-02, D-12).
 *
 * The primary contact cannot be deleted while the client has other contacts:
 * the Admin hands the flag to another contact first, so a client never has
 * contacts without a primary. Deleting the only contact is allowed and leaves
 * the client without contacts; the next contact added becomes the primary.
 * The decision runs in a transaction with the client row locked, so it cannot
 * race a switch or an add of another session.
 */
final class DeleteContact
{
    /**
     * @throws ValidationException
     */
    public function handle(Contact $contact): void
    {
        DB::transaction(static function () use ($contact): void {
            Client::query()->withTrashed()->whereKey($contact->client_id)->lockForUpdate()->firstOrFail();

            $fresh = Contact::query()->whereKey($contact->getKey())->lockForUpdate()->first();

            // Already gone: another session deleted it.
            if ($fresh === null) {
                return;
            }

            if ($fresh->is_primary && Contact::query()->where('client_id', $fresh->client_id)->whereKeyNot($fresh->getKey())->exists()) {
                throw ValidationException::withMessages([
                    'contact' => __('kokpit.contacts.errors.primary_delete'),
                ]);
            }

            $fresh->delete();
        });
    }
}
