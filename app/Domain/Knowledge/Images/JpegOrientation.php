<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

use GdImage;

/**
 * The visual orientation of a JPEG photo (D-08). GD ignores the Exif Orientation tag and the re-encode drops it,
 * so the pipeline reads the tag first and turns the pixels itself; otherwise a phone photo would end up lying on
 * its side once the tag is gone.
 *
 * The tag is read by a small bounded parser, not by ext-exif: the input is hostile, and the parser only ever looks
 * at the JPEG segment headers, the Exif header and one entry of the first IFD. Anything malformed or missing means
 * orientation 1 (upright).
 */
final class JpegOrientation
{
    /**
     * The Orientation of the JPEG (1 to 8), 1 when there is no readable tag.
     */
    public static function read(string $jpeg): int
    {
        $length = strlen($jpeg);

        if ($length < 4 || ! str_starts_with($jpeg, "\xFF\xD8")) {
            return 1;
        }

        $position = 2;

        while ($position + 4 <= $length) {
            if ($jpeg[$position] !== "\xFF") {
                return 1;
            }

            $marker = ord($jpeg[$position + 1]);

            if ($marker === 0xFF) {
                $position++;

                continue;
            }

            if ($marker === 0xD9 || $marker === 0xDA) {
                return 1;
            }

            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $position += 2;

                continue;
            }

            $segment = (ord($jpeg[$position + 2]) << 8) | ord($jpeg[$position + 3]);

            if ($segment < 2 || $position + 2 + $segment > $length) {
                return 1;
            }

            if ($marker === 0xE1) {
                $data = substr($jpeg, $position + 4, $segment - 2);

                if (str_starts_with($data, "Exif\0\0")) {
                    return self::fromTiff(substr($data, 6));
                }
            }

            $position += 2 + $segment;
        }

        return 1;
    }

    /**
     * The image turned and flipped so that it looks the way the photographer saw it. 1 and unknown values return the
     * image unchanged. GD rotates counter-clockwise, so a negative angle turns clockwise.
     */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        return match ($orientation) {
            2 => self::flip($image, IMG_FLIP_HORIZONTAL),
            3 => self::rotate($image, 180),
            4 => self::flip($image, IMG_FLIP_VERTICAL),
            5 => self::flip(self::rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => self::rotate($image, -90),
            7 => self::flip(self::rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => self::rotate($image, 90),
            default => $image,
        };
    }

    private static function fromTiff(string $tiff): int
    {
        $length = strlen($tiff);

        if ($length < 8) {
            return 1;
        }

        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $little = $order === 'II';

        if (self::short($tiff, 2, $little) !== 42) {
            return 1;
        }

        $ifd = self::long($tiff, 4, $little);

        if ($ifd === null || $ifd + 2 > $length) {
            return 1;
        }

        $count = self::short($tiff, $ifd, $little);

        if ($count === null) {
            return 1;
        }

        for ($index = 0; $index < $count; $index++) {
            $entry = $ifd + 2 + $index * 12;

            if ($entry + 12 > $length) {
                return 1;
            }

            if (self::short($tiff, $entry, $little) !== 0x0112) {
                continue;
            }

            if (self::short($tiff, $entry + 2, $little) !== 3 || self::long($tiff, $entry + 4, $little) !== 1) {
                return 1;
            }

            $value = self::short($tiff, $entry + 8, $little);

            return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
        }

        return 1;
    }

    private static function short(string $data, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) {
            return null;
        }

        $first = ord($data[$offset]);
        $second = ord($data[$offset + 1]);

        return $little ? ($second << 8) | $first : ($first << 8) | $second;
    }

    private static function long(string $data, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            return null;
        }

        $bytes = array_map(ord(...), str_split(substr($data, $offset, 4)));

        return $little
            ? ($bytes[3] << 24) | ($bytes[2] << 16) | ($bytes[1] << 8) | $bytes[0]
            : ($bytes[0] << 24) | ($bytes[1] << 16) | ($bytes[2] << 8) | $bytes[3];
    }

    private static function flip(GdImage $image, int $mode): GdImage
    {
        imageflip($image, $mode);

        return $image;
    }

    private static function rotate(GdImage $image, int $angle): GdImage
    {
        $fill = imagecolorallocatealpha($image, 0, 0, 0, 127);

        if ($fill === false) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        $rotated = imagerotate($image, $angle, $fill);

        if ($rotated === false) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        return $rotated;
    }
}
