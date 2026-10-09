<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Clients\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a contact to a client (CL-02, D-12).
 *
 * The first contact of a client becomes its primary contact. With `$makePrimary`
 * a later contact takes the flag over: the current primary is demoted first and
 * the new contact promoted second, because the partial unique index
 * `contacts_one_primary_per_client` is checked per statement and cannot be
 * deferred. Everything runs in one transaction with the client row locked, so two
 * contacts created at the same moment cannot both see "no primary yet" and both
 * claim the flag; the index is the database backstop behind it. `is_primary` is
 * never read from the input: it is not fillable and is set here with `forceFill`.
 *
 * Any number of contacts can be billing contacts (`is_billing`).
 */
final class CreateContact
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function handle(Client $client, array $data, bool $makePrimary = false): Contact
    {
        $attributes = ContactInput::attributes($data);

        return DB::transaction(static function () use ($client, $attributes, $makePrimary): Contact {
            // Serialises every contact write of this client; the archived client is locked too.
            Client::query()->withTrashed()->whereKey($client->getKey())->lockForUpdate()->firstOrFail();

            $contact = $client->contacts()->make($attributes);

            $primary = $makePrimary || ! $client->contacts()->exists();

            if ($primary) {
                // Demote before promote (research Pitfall 10). Through the models, not a
                // bulk update, so the demotion fires model events (the activity log of plan 04-13).
                foreach ($client->contacts()->where('is_primary', true)->get() as $current) {
                    $current->forceFill(['is_primary' => false])->save();
                }

                $contact->forceFill(['is_primary' => true]);
            }

            $contact->save();

            return $contact->refresh();
        });
    }
}
