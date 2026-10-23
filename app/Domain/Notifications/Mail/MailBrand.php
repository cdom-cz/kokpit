<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Mail;

use App\Domain\Settings\DocumentImage;
use App\Domain\Settings\Settings\SupplierSettings;
use App\Domain\Shared\Auth\PartnerContext;
use Throwable;

/**
 * The supplier's branding as the shared e-mail layout prints it: the name in the header and the address, the
 * company numbers, the contacts and the registration note in the footer (D-03, D-04).
 *
 * The one reader of the supplier data for e-mail. Every layout view asks `MailBrand::current()` and never reads
 * `SupplierSettings` itself, because the settings fail closed outside a system context and a queued notification
 * (an invitation, a task e-mail) has no user and does not run as system. `read()` therefore runs inside
 * `PartnerContext::runAsSystem()`, so a worker, a Partner session and an operational alert all render the same
 * header and footer (research Pitfall 1).
 *
 * It reads a fixed whitelist of fields and nothing else: never a bank account, never the signature image (T-13-01).
 * The logo is the one stored image it reads, through DocumentImage::contents() and MailLogo::fromPng(); the bytes are
 * held here once per scope for the header (`hasLogo()`) and for the EmbedMailLogo listener, which share this one decider.
 * It holds raw values only; the views escape them and translate the labels when they render, so the locale of each
 * e-mail decides the labels (R-13-01-4).
 *
 * It never stops an e-mail: any failure of the read (an unreadable database during an outage the alert is about)
 * gives `fallback()`, the application name and nothing else (T-13-03).
 *
 * Bound with `scoped()` in AppServiceProvider, the scope PartnerContext uses: one read serves one request, console
 * run or queue job, and the next job reads again.
 */
final readonly class MailBrand
{
    /** The content id of the inline logo image; EmbedMailLogo embeds it and the header references it. */
    public const string LOGO_NAME = 'kokpit-logo.png';

    public function __construct(
        public string $name,
        public ?string $street = null,
        public ?string $postalCode = null,
        public ?string $city = null,
        public ?string $companyId = null,
        public ?string $vatId = null,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $website = null,
        public ?string $registrationNote = null,
        public ?string $logoPng = null,
        public ?int $logoWidth = null,
        public ?int $logoHeight = null,
    ) {}

    /** The brand of the current request, console run or queue job. */
    public static function current(): self
    {
        return app(self::class);
    }

    /** Reads the whitelisted supplier fields as a system run; the application name and nothing else on any failure. */
    public static function read(): self
    {
        try {
            return app(PartnerContext::class)->runAsSystem(static function (): self {
                $supplier = app(SupplierSettings::class);
                $logo = self::readLogo($supplier->logo_path);

                return new self(
                    name: self::blankToNull($supplier->company_name) ?? self::applicationName(),
                    street: self::blankToNull($supplier->street),
                    postalCode: self::blankToNull($supplier->postal_code),
                    city: self::blankToNull($supplier->city),
                    companyId: self::blankToNull($supplier->company_id),
                    vatId: self::blankToNull($supplier->vat_id),
                    email: self::blankToNull($supplier->email),
                    phone: self::blankToNull($supplier->phone),
                    website: self::blankToNull($supplier->website),
                    registrationNote: self::blankToNull($supplier->registration_note),
                    logoPng: $logo['png'] ?? null,
                    logoWidth: $logo['width'] ?? null,
                    logoHeight: $logo['height'] ?? null,
                );
            });
        } catch (Throwable) {
            return self::fallback();
        }
    }

    public static function fallback(): self
    {
        return new self(name: self::applicationName());
    }

    /** "Street, 100 00 Town": street, then postal code and city, blanks skipped. */
    public function addressLine(): ?string
    {
        $town = trim(implode(' ', array_filter([$this->postalCode, $this->city], static fn (?string $part): bool => $part !== null)));
        $parts = array_filter([$this->street, $town !== '' ? $town : null], static fn (?string $part): bool => $part !== null);

        return $parts === [] ? null : implode(', ', $parts);
    }

    public function hasLogo(): bool
    {
        return $this->logoPng !== null;
    }

    /**
     * The downscaled logo and its display size, or null when there is no logo, its object is gone or it cannot be
     * decoded: the header then shows the name as text and the layout holds no cid reference (D-03).
     *
     * @return array{png: string, width: int, height: int}|null
     */
    private static function readLogo(?string $path): ?array
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            $png = app(DocumentImage::class)->contents($path);

            return $png === null ? null : MailLogo::fromPng($png);
        } catch (Throwable) {
            return null;
        }
    }

    private static function applicationName(): string
    {
        $name = config('app.name');

        return is_string($name) && trim($name) !== '' ? trim($name) : 'Kokpit';
    }

    private static function blankToNull(?string $value): ?string
    {
        $trimmed = $value === null ? '' : trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
