<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Support;

use App\Domain\Clients\Models\Client;
use App\Domain\Invoicing\Pdf\DocumentPalette;
use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\BankAccountSettings;
use App\Domain\Settings\Settings\InvoicingSettings;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Settings\VatMode;
use RuntimeException;

/**
 * Builds the snapshots an issued document freezes (D-12, D-21, D-23; ruling R-12-3): the supplier, the customer, the
 * bank account of the document currency and the whole appearance. Once written, a later change of the settings or of
 * the client never alters the document, and a regenerated PDF reads only these frozen values.
 *
 * The settings are read at the moment of the call, never held, so a long-lived worker never freezes stale values.
 * The two images are frozen as their stored object path plus the SHA-256 of the object ({@see DocumentImage::reference()}),
 * which makes a regeneration either identical to the issued PDF or a failure, never a different picture.
 */
final readonly class InvoiceSnapshot
{
    public function __construct(private DocumentImage $images) {}

    /**
     * The supplier as printed on the document.
     *
     * @return array{name: string, street: string, city: string, postal_code: string, country: string, company_id: string|null, vat_id: string|null, email: string|null, phone: string|null, website: string|null, registration_note: string|null, vat_payer: bool}
     */
    public function supplier(): array
    {
        $supplier = app(SupplierSettings::class);

        return [
            'name' => $supplier->company_name,
            'street' => $supplier->street,
            'city' => $supplier->city,
            'postal_code' => $supplier->postal_code,
            'country' => $supplier->country,
            'company_id' => $supplier->company_id,
            'vat_id' => $supplier->vat_id,
            'email' => $supplier->email,
            'phone' => $supplier->phone,
            'website' => $supplier->website,
            'registration_note' => $supplier->registration_note,
            'vat_payer' => app(InvoicingSettings::class)->vat_mode === VatMode::Payer,
        ];
    }

    /**
     * The customer's billing data as the client row holds it now.
     *
     * @return array{name: string, street: string|null, city: string|null, postal_code: string|null, country: string, company_id: string|null, vat_id: string|null}
     */
    public function customer(Client $client): array
    {
        return [
            'name' => $client->name,
            'street' => $client->street,
            'city' => $client->city,
            'postal_code' => $client->postal_code,
            'country' => $client->country,
            'company_id' => $client->company_number,
            'vat_id' => $client->tax_number,
        ];
    }

    /**
     * The bank account of the document currency in its stored shape, or null when none exists.
     *
     * @return array<string, string|null>|null
     */
    public function bank(string $currency): ?array
    {
        return app(BankAccountSettings::class)->forCurrency($currency)?->toArray();
    }

    /**
     * The appearance: the accent colour normalised through the palette, the footer and the logo and the signature as
     * `{path, sha256}` or null.
     *
     * @return array{accent: string, footer: string|null, logo: array{path: string, sha256: string}|null, signature: array{path: string, sha256: string}|null}
     *
     * @throws RuntimeException when a stored image cannot be read
     */
    public function appearance(): array
    {
        $invoicing = app(InvoicingSettings::class);
        $supplier = app(SupplierSettings::class);

        return [
            'accent' => DocumentPalette::fromAccent($invoicing->document_accent_color)->accent,
            'footer' => $invoicing->document_footer,
            'logo' => $this->images->reference($supplier->logo_path),
            'signature' => $this->images->reference($supplier->signature_path),
        ];
    }
}
