<?php

declare(strict_types=1);

namespace App\Domain\Clients\Actions;

use App\Domain\Clients\Enums\ClientStage;
use App\Domain\Clients\Models\Client;
use App\Domain\Settings\Settings\DefaultsSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\PaymentSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use Illuminate\Support\Facades\DB;

/**
 * Creates a client with its billing data and terms (CL-01).
 *
 * The Action owns the rules; the Admin form is a thin adapter around it. The
 * hourly rate arrives as text in major units and is converted with
 * Money::fromMajor in the client currency, so it is stored exactly and a
 * malformed amount is a field error, never a rounding (see ClientInput).
 *
 * A key the caller leaves out is filled from the typed settings (D-13): currency,
 * rate and invoice language from DefaultsSettings, payment terms from
 * InvoicingSettings, the online-payment flag from PaymentSettings, the country
 * from SupplierSettings, the stage `active`. The values are copied into the
 * client row here and never read again: UpdateClient does not touch the settings,
 * so a later change of a default reaches only clients created afterwards. A key
 * that is present, even empty, is the caller's value and is validated as such.
 *
 * @phpstan-type ClientData array{
 *     name: string,
 *     company_number?: string|null,
 *     tax_number?: string|null,
 *     country?: string,
 *     street?: string|null,
 *     city?: string|null,
 *     postal_code?: string|null,
 *     stage?: string,
 *     currency?: string,
 *     hourly_rate?: string|null,
 *     payment_terms_days?: int|float|string|null,
 *     invoice_email?: string|null,
 *     invoice_language?: string,
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
        $attributes = ClientInput::attributes($this->withDefaults($data));

        return DB::transaction(static fn (): Client => Client::query()->create($attributes)->refresh());
    }

    /**
     * Adds every key the caller left out from the typed settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withDefaults(array $data): array
    {
        $defaults = app(DefaultsSettings::class);

        if (! array_key_exists('currency', $data)) {
            $data['currency'] = $defaults->default_currency;
        }

        // The default rate is an amount in the default currency: for a client in
        // another currency it would silently be the wrong money, so it stays missing
        // and the rate is reported as a field error.
        if (! array_key_exists('hourly_rate', $data)
            && is_string($data['currency'])
            && strtoupper(trim($data['currency'])) === $defaults->default_currency) {
            $data['hourly_rate'] = $defaults->default_hourly_rate->toMajor();
        }

        if (! array_key_exists('invoice_language', $data)) {
            $data['invoice_language'] = $defaults->default_invoice_language->value;
        }

        if (! array_key_exists('payment_terms_days', $data)) {
            $data['payment_terms_days'] = app(InvoicingSettings::class)->payment_due_days;
        }

        if (! array_key_exists('online_payment_enabled', $data)) {
            $data['online_payment_enabled'] = app(PaymentSettings::class)->online_payments_enabled;
        }

        if (! array_key_exists('country', $data)) {
            $data['country'] = app(SupplierSettings::class)->country;
        }

        if (! array_key_exists('stage', $data)) {
            $data['stage'] = ClientStage::Active->value;
        }

        return $data;
    }
}
