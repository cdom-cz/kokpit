<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Models\Client;
use Illuminate\Support\Facades\DB;

/**
 * Creates a client with its billing data and terms (CL-01).
 *
 * The Action owns the rules; the Admin form is a thin adapter around it. The
 * hourly rate arrives as text in major units and is converted with
 * Money::fromMajor in the client currency, so it is stored exactly and a
 * malformed amount is a field error, never a rounding (see ClientInput). This plan
 * requires every key; the fallback to the typed defaults arrives with plan 04-10.
 *
 * @phpstan-type ClientData array{
 *     name: string,
 *     company_number?: string|null,
 *     tax_number?: string|null,
 *     country: string,
 *     street?: string|null,
 *     city?: string|null,
 *     postal_code?: string|null,
 *     stage: string,
 *     currency: string,
 *     hourly_rate: string|null,
 *     payment_terms_days: int|float|string|null,
 *     invoice_email?: string|null,
 *     invoice_language: string,
 *     online_payment_enabled?: bool|null,
 * }
 */
final class CreateClient
{
    /**
     * @param  ClientData  $data
     */
    public function handle(array $data): Client
    {
        $attributes = ClientInput::attributes($data);

        return DB::transaction(static fn (): Client => Client::query()->create($attributes)->refresh());
    }
}
