<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Updates the billing data and terms of a client (CL-01).
 *
 * It applies the same rules and conversions as CreateClient (the rate through
 * Money::fromMajor in the client currency) and never reads the typed defaults:
 * an existing client keeps what the Admin saved, whatever the defaults say now.
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

        return DB::transaction(static function () use ($client, $attributes): Client {
            $client->update($attributes);

            return $client->refresh();
        });
    }
}
