<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use App\Domain\Projects\Models\ProjectBilling;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Updates the billing data and terms of a client (CL-01).
 *
 * It applies the same rules and conversions as CreateClient (the rate through
 * Money::fromMajor in the client currency) and never reads the typed defaults:
 * an existing client keeps what the Admin saved, whatever the defaults say now.
 *
 * The currency cannot change while any project of the client, archived ones
 * included, holds an hourly rate or a fixed price: that money is stored in the
 * client currency, so the change is a field error on `currency` (research item
 * 3). Without project money the currency changes together with the rate, which
 * the caller sends in the new currency. A company number held by another client
 * in the same country is a field error on `company_number` (D-09).
 *
 * @phpstan-import-type ClientData from CreateClient
 */
final class UpdateClient
{
    /**
     * @param  ClientData  $data
     */
    public function handle(Client $client, array $data): Client
    {
        $attributes = ClientInput::attributes($data);

        if ($attributes['currency'] !== $client->currency && ProjectBilling::clientHoldsMoney($client->id)) {
            throw ValidationException::withMessages(['currency' => __('kokpit.clients.errors.currency_locked')]);
        }

        // The transaction is caught from the outside: a unique violation inside it has
        // already rolled the update back (savepoint when nested) before it is translated.
        try {
            return DB::transaction(static function () use ($client, $attributes): Client {
                $client->update($attributes);

                return $client->refresh();
            });
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'clients_country_company_number_unique')) {
                throw $e;
            }

            ClientInput::refuseCompanyNumber($attributes, $client->id);
        }
    }
}
