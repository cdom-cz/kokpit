<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Mail;

use GdImage;
use Throwable;

/**
 * The supplier logo as the e-mail carries it: a PNG small enough to ship with every message, and the size it is
 * shown at (R-13-04-1).
 *
 * The stored logo may be up to 4000 px and 1 MB. It would travel with every e-mail, so it is scaled down once (never
 * up) to fit 360 x 120 px, twice the display size so it stays sharp on dense screens, keeping the alpha channel and
 * the proportions. The display size fits 180 x 60 px and is never larger than the image itself.
 *
 * Any GD failure gives null and the layout falls back to the name as text; a logo never stops an e-mail (T-13-05).
 */
final class MailLogo
{
    public const int MAX_WIDTH = 360;

    public const int MAX_HEIGHT = 120;

    public const int DISPLAY_WIDTH = 180;

    public const int DISPLAY_HEIGHT = 60;

    /**
     * @return array{png: string, width: int, height: int}|null the PNG and its display size, or null when the bytes
     *                                                          are not an image GD can handle
     */
    public static function fromPng(string $png): ?array
    {
        if ($png === '') {
            return null;
        }

        $source = null;
        $scaled = null;

        try {
            $info = @getimagesizefromstring($png);
            $source = @imagecreatefromstring($png);

            if ($info === false || ! $source instanceof GdImage) {
                return null;
            }

            [$width, $height] = [$info[0], $info[1]];

            if ($width < 1 || $height < 1) {
                return null;
            }

            $factor = min(1.0, self::MAX_WIDTH / $width, self::MAX_HEIGHT / $height);
            $targetWidth = max(1, (int) round($width * $factor));
            $targetHeight = max(1, (int) round($height * $factor));

            if (! imageistruecolor($source)) {
                imagepalettetotruecolor($source);
            }

            $image = $source;

            if ($targetWidth !== $width || $targetHeight !== $height) {
                $scaled = imagescale($source, $targetWidth, $targetHeight, IMG_BICUBIC);

                if (! $scaled instanceof GdImage) {
                    return null;
                }

                $image = $scaled;
            }

            imagealphablending($image, false);
            imagesavealpha($image, true);

            ob_start();
            $written = imagepng($image, null, 6);
            $bytes = (string) ob_get_clean();

            if (! $written || $bytes === '') {
                return null;
            }

            $display = min(1.0, self::DISPLAY_WIDTH / $targetWidth, self::DISPLAY_HEIGHT / $targetHeight);

            return [
                'png' => $bytes,
                'width' => max(1, (int) round($targetWidth * $display)),
                'height' => max(1, (int) round($targetHeight * $display)),
            ];
        } catch (Throwable) {
            return null;
        } finally {
            unset($source, $scaled);
        }
    }
}
