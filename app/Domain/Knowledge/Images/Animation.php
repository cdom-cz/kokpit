<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

/**
 * Tells an animated image from a still one by the structure of the file, before anything is decoded (D-09). GD reads
 * only the first frame of an animated GIF and decodes an animated PNG or WebP as a still, so without this check the
 * stored file would silently differ from the upload.
 *
 * D-09 names GIF only. Refusing animated PNG and animated WebP as well is a recorded extension (assumption A5): the
 * owner may relax it by deleting the PNG and WebP branches below, a change local to this class.
 *
 * Every walk is bounded by the length of the input and never reads past it.
 */
final class Animation
{
    /**
     * True when the image has more than one frame, false when it is a still, null when the structure is malformed or
     * truncated so that no answer can be given.
     */
    public static function isAnimated(ImageFormat $format, string $bytes): ?bool
    {
        return match ($format) {
            ImageFormat::Gif => self::gif($bytes),
            ImageFormat::Png => self::png($bytes),
            ImageFormat::Webp => self::webp($bytes),
            ImageFormat::Jpeg => false,
        };
    }

    /**
     * GIF: header, logical screen descriptor and global colour table, then blocks. Extensions are skipped by their
     * data sub-blocks, image descriptors are counted (with their local colour table and the LZW code size byte) and
     * the walk stops at the second frame.
     */
    private static function gif(string $data): ?bool
    {
        $length = strlen($data);

        if ($length < 13 || ! in_array(substr($data, 0, 6), ['GIF87a', 'GIF89a'], true)) {
            return null;
        }

        $flags = ord($data[10]);
        $position = 13;

        if (($flags & 0x80) !== 0) {
            $position += 3 * (1 << (($flags & 7) + 1));
        }

        $frames = 0;

        while ($position < $length) {
            $block = ord($data[$position++]);

            if ($block === 0x3B) {
                return $frames >= 2;
            }

            if ($block === 0x21) {
                $position++;

                if (! self::skipSubBlocks($data, $position)) {
                    return null;
                }

                continue;
            }

            if ($block === 0x2C) {
                if ($position + 9 > $length) {
                    return null;
                }

                $local = ord($data[$position + 8]);
                $position += 9;

                if (($local & 0x80) !== 0) {
                    $position += 3 * (1 << (($local & 7) + 1));
                }

                $position++;

                if (! self::skipSubBlocks($data, $position)) {
                    return null;
                }

                if (++$frames >= 2) {
                    return true;
                }

                continue;
            }

            return null;
        }

        return null;
    }

    /**
     * Moves the position over the data sub-blocks that start at it, up to and including the zero-length terminator.
     * False when the data ends first.
     */
    private static function skipSubBlocks(string $data, int &$position): bool
    {
        $length = strlen($data);

        while (true) {
            if ($position >= $length) {
                return false;
            }

            $size = ord($data[$position++]);

            if ($size === 0) {
                return true;
            }

            $position += $size;
        }
    }

    /**
     * PNG: chunks from offset 8 (4-byte big-endian length, 4-byte type, data, 4-byte CRC). An acTL chunk before the
     * first IDAT marks an animated PNG; the first IDAT without one marks a still.
     */
    private static function png(string $data): ?bool
    {
        $length = strlen($data);
        $position = 8;

        while ($position + 8 <= $length) {
            $size = (ord($data[$position]) << 24) | (ord($data[$position + 1]) << 16)
                | (ord($data[$position + 2]) << 8) | ord($data[$position + 3]);
            $type = substr($data, $position + 4, 4);

            if ($type === 'acTL') {
                return true;
            }

            if ($type === 'IDAT') {
                return false;
            }

            $position += 12 + $size;
        }

        return null;
    }

    /**
     * WebP: an extended file whose VP8X flag byte (offset 20) has the animation bit 0x02, or any top-level ANIM or
     * ANMF chunk, is animated.
     */
    private static function webp(string $data): ?bool
    {
        $length = strlen($data);

        if ($length >= 21 && substr($data, 12, 4) === 'VP8X' && (ord($data[20]) & 0x02) !== 0) {
            return true;
        }

        $position = 12;

        while ($position + 8 <= $length) {
            $type = substr($data, $position, 4);
            $size = ord($data[$position + 4]) | (ord($data[$position + 5]) << 8)
                | (ord($data[$position + 6]) << 16) | (ord($data[$position + 7]) << 24);

            if ($type === 'ANIM' || $type === 'ANMF') {
                return true;
            }

            $position += 8 + $size + ($size & 1);
        }

        return false;
    }
}
