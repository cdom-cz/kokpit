<?php

declare(strict_types=1);

namespace App\Domain\Settings\Settings;

use App\Domain\Settings\DocumentImage;

/**
 * Who issues the documents: the supplier data printed on invoices (group `supplier`).
 *
 * All values are plain strings; an empty string or null means "not filled in".
 * The two image paths are the exception: only DocumentImage writes them.
 * The rules() below are checked by the settings page and again by save().
 */
class SupplierSettings extends ValidatedSettings
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

    /** Server-generated path of the stored logo (DocumentImage); never typed by a user. */
    public ?string $logo_path;

    /** Server-generated path of the stored signature or stamp (DocumentImage). */
    public ?string $signature_path;

    public static function group(): string
    {
        return 'supplier';
    }

    /**
     * @return array<string, list<string>>
     */
    public static function rules(): array
    {
        return [
            'company_name' => ['required', 'string', 'max:200'],
            'street' => ['string', 'max:200'],
            'city' => ['string', 'max:200'],
            'postal_code' => ['string', 'max:20'],
            'country' => ['required', 'regex:/^[A-Z]{2}$/'],
            'company_id' => ['nullable', 'regex:/^\d{8}$/'],
            'vat_id' => ['nullable', 'regex:/^[A-Z]{2}[0-9A-Z]{2,13}$/'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'max:40'],
            'website' => ['nullable', 'url'],
            'registration_note' => ['nullable', 'max:255'],
            'logo_path' => ['nullable', 'string', 'regex:'.DocumentImage::pathPattern('logo')],
            'signature_path' => ['nullable', 'string', 'regex:'.DocumentImage::pathPattern('signature')],
        ];
    }
}
