<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * Who issues the documents: the supplier data printed on invoices (group `supplier`).
 *
 * All values are plain strings; an empty string or null means "not filled in".
 * The settings page (plan 03-04) validates them and moves this class onto
 * ValidatedSettings.
 */
final class SupplierSettings extends Settings
{
    public string $company_name;

    public string $street;

    public string $city;

    public string $postal_code;

    /** ISO 3166-1 alpha-2 code. */
    public string $country;

    public ?string $company_id;

    public ?string $vat_id;

    public ?string $email;

    public ?string $phone;

    public ?string $website;

    public ?string $registration_note;

    public static function group(): string
    {
        return 'supplier';
    }
}
