<?php

declare(strict_types=1);

namespace App\Domain\Clients\Ares;

/**
 * The registry data ARES returns for one company, reduced to what the client form
 * fills (CL-04, D-08). Built only from the response, never from user input.
 *
 * @phpstan-type CompanyArray array{companyNumber: string, name: string, taxNumber: ?string, street: ?string, city: ?string, postalCode: ?string, country: string}
 */
final readonly class AresCompany
{
    public function __construct(
        public string $companyNumber,
        public string $name,
        public ?string $taxNumber,
        public ?string $street,
        public ?string $city,
        public ?string $postalCode,
        public string $country,
    ) {}

    /**
     * Maps a decoded ARES response. A response without `ico` or `obchodniJmeno` is malformed.
     *
     * @param  array<array-key, mixed>  $payload
     *
     * @throws AresLookupFailed
     */
    public static function fromResponse(array $payload): self
    {
        $number = self::text($payload['ico'] ?? null);
        $name = self::text($payload['obchodniJmeno'] ?? null);

        if ($number === null || $name === null) {
            throw new AresLookupFailed(AresFailure::Malformed);
        }

        $seat = is_array($payload['sidlo'] ?? null) ? $payload['sidlo'] : [];

        return new self(
            companyNumber: $number,
            name: $name,
            taxNumber: self::text($payload['dic'] ?? null),
            street: self::streetLine($seat),
            city: self::text($seat['nazevObce'] ?? null),
            postalCode: self::postalCode($seat['psc'] ?? null),
            country: self::text($seat['kodStatu'] ?? null) ?? 'CZ',
        );
    }

    /**
     * Rebuilds the company from the array that toArray() produced (the cache).
     *
     * @param  CompanyArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['companyNumber'],
            $data['name'],
            $data['taxNumber'],
            $data['street'],
            $data['city'],
            $data['postalCode'],
            $data['country'],
        );
    }

    /**
     * @return CompanyArray
     */
    public function toArray(): array
    {
        return [
            'companyNumber' => $this->companyNumber,
            'name' => $this->name,
            'taxNumber' => $this->taxNumber,
            'street' => $this->street,
            'city' => $this->city,
            'postalCode' => $this->postalCode,
            'country' => $this->country,
        ];
    }

    /**
     * The values for the client form, keyed by form field name. `tax_number` is
     * left out when ARES published none, so a typed tax number is never erased.
     *
     * @return array<string, string|null>
     */
    public function formState(): array
    {
        $state = [
            'name' => $this->name,
            'company_number' => $this->companyNumber,
            'street' => $this->street,
            'city' => $this->city,
            'postal_code' => $this->postalCode,
            'country' => $this->country,
        ];

        if ($this->taxNumber !== null) {
            $state['tax_number'] = $this->taxNumber;
        }

        return $state;
    }

    /**
     * Street name (falling back to the municipality part, the municipality, then
     * the text address) plus the house number as `domovni/orientacni`.
     *
     * @param  array<array-key, mixed>  $seat
     */
    private static function streetLine(array $seat): ?string
    {
        $name = self::text($seat['nazevUlice'] ?? null)
            ?? self::text($seat['nazevCastiObce'] ?? null)
            ?? self::text($seat['nazevObce'] ?? null)
            ?? self::text($seat['textovaAdresa'] ?? null);

        $number = self::text($seat['cisloDomovni'] ?? null);
        $orientation = self::text($seat['cisloOrientacni'] ?? null);

        if ($number !== null && $orientation !== null) {
            $number .= '/'.$orientation;
        }

        $line = trim(($name ?? '').' '.($number ?? ''));

        return $line === '' ? null : $line;
    }

    /**
     * ARES publishes the postal code as an integer, so a leading zero is lost.
     */
    private static function postalCode(mixed $psc): ?string
    {
        $text = self::text($psc);

        return $text === null ? null : str_pad($text, 5, '0', STR_PAD_LEFT);
    }

    private static function text(mixed $value): ?string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
