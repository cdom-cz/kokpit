<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

/**
 * The four image formats an article may carry (D-06). The value is the MIME type the stored file is served with;
 * the type is always decided by the content of the file, never by its name or by the MIME type of the client (D-07).
 */
enum ImageFormat: string
{
    case Png = 'image/png';
    case Jpeg = 'image/jpeg';
    case Webp = 'image/webp';
    case Gif = 'image/gif';

    /**
     * The file name extension of the format, without a dot.
     */
    public function extension(): string
    {
        return match ($this) {
            self::Png => 'png',
            self::Jpeg => 'jpg',
            self::Webp => 'webp',
            self::Gif => 'gif',
        };
    }

    /**
     * The IMAGETYPE constant that getimagesizefromstring reports for the format.
     */
    public function imageType(): int
    {
        return match ($this) {
            self::Png => IMAGETYPE_PNG,
            self::Jpeg => IMAGETYPE_JPEG,
            self::Webp => IMAGETYPE_WEBP,
            self::Gif => IMAGETYPE_GIF,
        };
    }

    /**
     * The format whose magic bytes the input starts with, or null when it starts with none of them. Only the first
     * bytes are read: PNG "\x89PNG\r\n\x1a\n", JPEG "\xFF\xD8\xFF", GIF "GIF87a" or "GIF89a", WebP "RIFF", four bytes
     * and "WEBP".
     */
    public static function sniff(string $bytes): ?self
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return self::Png;
        }

        if (str_starts_with($bytes, "\xFF\xD8\xFF")) {
            return self::Jpeg;
        }

        if (str_starts_with($bytes, 'GIF87a') || str_starts_with($bytes, 'GIF89a')) {
            return self::Gif;
        }

        if (strlen($bytes) >= 12 && str_starts_with($bytes, 'RIFF') && substr($bytes, 8, 4) === 'WEBP') {
            return self::Webp;
        }

        return null;
    }
}
