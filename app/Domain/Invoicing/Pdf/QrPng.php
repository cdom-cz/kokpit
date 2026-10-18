<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use chillerlan\QRCode\Common\EccLevel;
use chillerlan\QRCode\Common\Version;
use chillerlan\QRCode\Output\QROutputInterface;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;

/**
 * Renders a payload as a QR code PNG data URI, entirely locally (D-21): no
 * remote request at render time. Error correction level M as the payment QR
 * standard asks, a quiet zone of four modules, and Version::AUTO because a
 * fixed version overflows with "code length overflow" on a long payload.
 */
final class QrPng
{
    /**
     * A "data:image/png;base64,..." URI ready for an img src.
     */
    public static function dataUri(string $payload): string
    {
        $options = new QROptions([
            'version' => Version::AUTO,
            'eccLevel' => EccLevel::M,
            'outputType' => QROutputInterface::GDIMAGE_PNG,
            'outputBase64' => true,
            'scale' => 6,
            'quietzoneSize' => 4,
            'imageTransparent' => false,
        ]);

        return (new QRCode($options))->render($payload);
    }
}
