<?php

declare(strict_types=1);

namespace Tests\Support;

use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use InvalidArgumentException;

/**
 * Reads a QR code back from a PNG data URI through the library's own reader,
 * so a test proves the rendered image carries the exact payload.
 */
final class QrDecode
{
    public static function payload(string $dataUri): string
    {
        $prefix = 'data:image/png;base64,';

        if (! str_starts_with($dataUri, $prefix)) {
            throw new InvalidArgumentException('Not a PNG data URI.');
        }

        $png = base64_decode(substr($dataUri, strlen($prefix)), true);

        if ($png === false) {
            throw new InvalidArgumentException('The data URI is not valid base64.');
        }

        $options = new QROptions(['readerUseImagickIfAvailable' => false]);

        return (string) (new QRCode($options))->readFromBlob($png);
    }
}
