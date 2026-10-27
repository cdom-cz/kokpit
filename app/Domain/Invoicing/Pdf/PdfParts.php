<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use App\Domain\Settings\DocumentImage;
use Closure;
use UnexpectedValueException;

/**
 * The building blocks the invoice PDF and the cash receipt PDF share (ruling R-15-03-2, D-11): the party block of a
 * snapshot, the footer lines, a sized image of the appearance snapshot and a trimmed text. They read the frozen
 * snapshots only; the words come from the translator closure of the calling document, so a receipt passes its own
 * `cash_receipt_pdf` translator.
 */
final class PdfParts
{
    /**
     * One party block of a snapshot: address lines, the company and VAT ids, and the notes.
     *
     * @param  array<string, mixed>  $snapshot
     * @param  Closure(string, array<string, string>=): string  $t
     * @return array{label: string, name: string, lines: list<string>, ids: list<array{key: string, value: string}>, notes: list<string>, vatNote: string|null}
     */
    public static function party(string $label, array $snapshot, Closure $t, ?string $vatNote, bool $withContact): array
    {
        $lines = [];

        if (($street = self::text($snapshot['street'] ?? null)) !== null) {
            $lines[] = $street;
        }

        $city = trim(implode(' ', array_filter([self::text($snapshot['postal_code'] ?? null), self::text($snapshot['city'] ?? null)])));

        if ($city !== '') {
            $lines[] = $city;
        }

        $country = self::text($snapshot['country'] ?? null);

        if ($country !== null && $country !== 'CZ') {
            $lines[] = $country;
        }

        $ids = [];

        if (($companyId = self::text($snapshot['company_id'] ?? null)) !== null) {
            $ids[] = ['key' => $t('company_id'), 'value' => $companyId];
        }

        if (($vatId = self::text($snapshot['vat_id'] ?? null)) !== null) {
            $ids[] = ['key' => $t('vat_id'), 'value' => $vatId];
        }

        $notes = [];

        foreach (['registration_note', ...($withContact ? ['email', 'phone', 'website'] : [])] as $key) {
            if (($value = self::text($snapshot[$key] ?? null)) !== null) {
                $notes[] = $value;
            }
        }

        return [
            'label' => $label,
            'name' => self::text($snapshot['name'] ?? null) ?? '',
            'lines' => $lines,
            'ids' => $ids,
            'notes' => $notes,
            'vatNote' => $vatNote,
        ];
    }

    /**
     * @return list<string>
     */
    public static function footer(?string $footer): array
    {
        if ($footer === null) {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $footer) ?: []),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * An image of the appearance snapshot as a data URI, sized to fit the box without upscaling.
     *
     * @return array{uri: string, width: float, height: float}|null
     *
     * @throws UnexpectedValueException when the stored object is missing or no longer matches its hash
     */
    public static function image(DocumentImage $images, mixed $reference, int $maxWidth, int $maxHeight): ?array
    {
        if (! is_array($reference) || ! is_string($reference['path'] ?? null) || ! is_string($reference['sha256'] ?? null)) {
            return null;
        }

        $uri = $images->dataUri($reference['path'], $reference['sha256']);
        $size = getimagesizefromstring((string) base64_decode(substr($uri, strlen('data:image/png;base64,')), true));

        if ($size === false || $size[0] < 1 || $size[1] < 1) {
            throw new UnexpectedValueException('document image mismatch');
        }

        $scale = min(1.0, $maxWidth / $size[0], $maxHeight / $size[1]);

        return ['uri' => $uri, 'width' => round($size[0] * $scale, 2), 'height' => round($size[1] * $scale, 2)];
    }

    /**
     * The trimmed string, or null for a blank string and for a value that is not a string.
     */
    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
