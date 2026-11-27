<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Knowledge\Images\ImageFormat;
use App\Domain\Knowledge\Images\ImagePipeline;
use GdImage;
use LogicException;

/**
 * Fictional image files for the image pipeline tests, all assembled at runtime with GD and pack(), so no binary
 * file is committed and no line of the repository looks like a real value (D-05, public repository, fictional data
 * only). Pictures are a red left half and a blue right half, so an orientation can be read from a single pixel. The
 * GPS coordinate is assembled from fragments; the sentinel is a random marker that must never reach a stored file.
 */
final class ImageFixtures
{
    /**
     * A PNG, left half red and right half blue. With $alpha every pixel has GD alpha 64 (about half transparent).
     */
    public static function png(int $width = 40, int $height = 20, bool $alpha = false): string
    {
        return self::render(self::halves($width, $height, $alpha), static fn (GdImage $image): bool => imagepng($image, null, 1));
    }

    /**
     * A PNG with a palette of three colours (not truecolor).
     */
    public static function palettePng(): string
    {
        $image = imagecreate(40, 20);
        self::must($image);

        $red = imagecolorallocate($image, 255, 0, 0);
        $blue = imagecolorallocate($image, 0, 0, 255);
        imagefilledrectangle($image, 0, 0, 19, 19, (int) $red);
        imagefilledrectangle($image, 20, 0, 39, 19, (int) $blue);

        return self::render($image, static fn (GdImage $image): bool => imagepng($image));
    }

    /**
     * A JPEG without any metadata of its own, left half red and right half blue.
     */
    public static function jpeg(int $width = 40, int $height = 20): string
    {
        return self::render(self::halves($width, $height, false), static fn (GdImage $image): bool => imagejpeg($image, null, 95));
    }

    /**
     * A JPEG with an Exif segment (Orientation, and a GPS section with a fictional latitude) and, when a sentinel is
     * given, a COM segment holding it; both are spliced in behind the start marker.
     */
    public static function gpsJpeg(int $orientation = 1, string $sentinel = ''): string
    {
        $jpeg = self::jpeg();

        // Little-endian TIFF: header (8), IFD0 with two entries at 8 (30 bytes), GPS IFD with two entries at 38
        // (30 bytes), the three rational numbers of the latitude at 68.
        $ifd0 = pack('v', 2)
            .pack('vvVV', 0x0112, 3, 1, $orientation)
            .pack('vvVV', 0x8825, 4, 1, 38)
            .pack('V', 0);
        $parts = [[intdiv(100, 8), 1], [intdiv(100, 3), 1], [intdiv(1000, 25), 1]];
        $gps = pack('v', 2)
            .pack('vvV', 1, 2, 2)."N\0\0\0"
            .pack('vvVV', 2, 5, 3, 68)
            .pack('V', 0);
        $rationals = implode('', array_map(static fn (array $part): string => pack('VV', $part[0], $part[1]), $parts));
        $app1 = "Exif\0\0".'II'.pack('vV', 42, 8).$ifd0.$gps.$rationals;

        $inserted = "\xFF\xE1".pack('n', strlen($app1) + 2).$app1;

        if ($sentinel !== '') {
            $inserted .= "\xFF\xFE".pack('n', strlen($sentinel) + 2).$sentinel;
        }

        return substr($jpeg, 0, 2).$inserted.substr($jpeg, 2);
    }

    /**
     * A lossy WebP, left half red and right half blue. With $alpha every pixel has GD alpha 64.
     */
    public static function webp(int $width = 40, int $height = 20, bool $alpha = false): string
    {
        return self::render(self::halves($width, $height, $alpha), static fn (GdImage $image): bool => imagewebp($image, null, 80));
    }

    /**
     * A still GIF without a transparent colour, left half red and right half blue.
     */
    public static function gif(int $width = 40, int $height = 20): string
    {
        return self::render(self::halves($width, $height, false), static fn (GdImage $image): bool => imagegif($image));
    }

    /**
     * A marker that must never be found in a stored file, built from fragments with a random part.
     */
    public static function sentinel(): string
    {
        return implode('-', ['kokpit', 'sentinel', bin2hex(random_bytes(4))]);
    }

    /**
     * The image with the sentinel written into every metadata container of its format: JPEG COM and APP1 segments,
     * PNG tEXt and eXIf chunks, a GIF comment extension, a WebP EXIF chunk.
     */
    public static function withSentinel(string $bytes, string $sentinel): string
    {
        return match (ImageFormat::sniff($bytes)) {
            ImageFormat::Jpeg => self::jpegWithSentinel($bytes, $sentinel),
            ImageFormat::Png => self::pngWithSentinel($bytes, $sentinel),
            ImageFormat::Gif => self::gifWithSentinel($bytes, $sentinel),
            ImageFormat::Webp => self::webpWithSentinel($bytes, $sentinel),
            null => throw new LogicException('Not an image fixture.'),
        };
    }

    /**
     * Whether the pixel is clearly red (and not blue). Decodes the bytes with GD.
     */
    public static function pixelIsRedDominant(string $bytes, int $x, int $y): bool
    {
        $color = self::pixel($bytes, $x, $y);

        return (($color >> 16) & 0xFF) > 128 && (($color >> 16) & 0xFF) > ($color & 0xFF) + 100;
    }

    /**
     * The GD alpha value (0 opaque to 127 transparent) of the pixel.
     */
    public static function pixelAlpha(string $bytes, int $x, int $y): int
    {
        return (self::pixel($bytes, $x, $y) >> 24) & 0x7F;
    }

    /**
     * An SVG, assembled from fragments so that no line looks like live markup.
     */
    public static function svg(): string
    {
        return '<'.'svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></'.'svg>';
    }

    /**
     * The same SVG behind an XML prolog.
     */
    public static function svgWithProlog(): string
    {
        return '<'.'?xml version="1.0" encoding="UTF-8"?>'."\n".self::svg();
    }

    /**
     * Plain text; the tests give it the name of a screenshot, but the name never reaches the pipeline.
     */
    public static function renamedText(): string
    {
        return "This is plain text, not an image.\nIt only carries a name that ends in png.\n";
    }

    /**
     * A BMP made by GD's BMP encoder (GD decodes BMP, the magic-byte sniff does not admit it).
     */
    public static function bmp(): string
    {
        return self::render(self::halves(40, 20, false), static fn (GdImage $image): bool => imagebmp($image));
    }

    /**
     * A PNG whose compressed image data is overwritten, so it cannot be decoded.
     */
    public static function corruptPng(): string
    {
        $png = self::png();
        $position = strpos($png, 'IDAT');

        if ($position === false) {
            throw new LogicException('The fixture PNG has no image data.');
        }

        return substr($png, 0, $position + 4).str_repeat("\xFF", 12).substr($png, $position + 16);
    }

    /**
     * A GIF cut off in the middle of its image data.
     */
    public static function truncatedGif(): string
    {
        return substr(self::gif(), 0, 30);
    }

    /**
     * A PNG header followed by a chunk whose length runs far past the end of the data, before any image data.
     */
    public static function brokenChunkPng(): string
    {
        return self::headerOnlyPng(40, 20).pack('N', 0x7FFFFFF0).'tEXt'.'abc';
    }

    /**
     * One byte more than the upload limit, starting with PNG magic bytes.
     */
    public static function oversized(): string
    {
        $magic = "\x89PNG\r\n\x1a\n";

        return $magic.str_repeat("\0", ImagePipeline::MAX_UPLOAD_BYTES + 1 - strlen($magic));
    }

    /**
     * A 33-byte PNG header that claims 30000 x 30000 pixels.
     */
    public static function pixelBomb(): string
    {
        return self::headerOnlyPng(30000, 30000);
    }

    /**
     * The PNG signature and a valid IHDR chunk, nothing else.
     */
    public static function headerOnlyPng(int $width, int $height): string
    {
        return "\x89PNG\r\n\x1a\n".self::pngChunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0));
    }

    /**
     * PNG magic bytes in front of the data of a JPEG.
     */
    public static function pngMagicOverJpeg(): string
    {
        return "\x89PNG\r\n\x1a\n".self::jpeg();
    }

    /**
     * A GIF with two frames: the single-frame GIF with its image block duplicated (GD alone would decode it as a
     * still).
     */
    public static function animatedGif(): string
    {
        $gif = self::gif();
        $position = self::gifFirstBlock($gif);
        $frame = substr($gif, $position, -1);

        return substr($gif, 0, $position).$frame.$frame."\x3B";
    }

    /**
     * An animated PNG: an acTL chunk with a valid CRC in front of the image data.
     */
    public static function apng(): string
    {
        $png = self::png();
        $position = strpos($png, 'IDAT');

        if ($position === false) {
            throw new LogicException('The fixture PNG has no image data.');
        }

        return substr($png, 0, $position - 4).self::pngChunk('acTL', pack('NN', 2, 0)).substr($png, $position - 4);
    }

    /**
     * An animated WebP: a VP8X chunk with the animation flag and an ANIM chunk.
     */
    public static function animatedWebp(): string
    {
        $payload = 'VP8X'.pack('V', 10).chr(0x02)."\0\0\0".self::u24(39).self::u24(19)
            .'ANIM'.pack('V', 6)."\0\0\0\0\0\0";

        return 'RIFF'.pack('V', 4 + strlen($payload)).'WEBP'.$payload;
    }

    private static function jpegWithSentinel(string $jpeg, string $sentinel): string
    {
        $comment = "\xFF\xFE".pack('n', strlen($sentinel) + 2).$sentinel;
        $exif = "Exif\0\0".$sentinel;
        $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

        return substr($jpeg, 0, 2).$app1.$comment.substr($jpeg, 2);
    }

    private static function pngWithSentinel(string $png, string $sentinel): string
    {
        $chunks = self::pngChunk('tEXt', "Comment\0".$sentinel).self::pngChunk('eXIf', $sentinel);

        return substr($png, 0, 33).$chunks.substr($png, 33);
    }

    private static function gifWithSentinel(string $gif, string $sentinel): string
    {
        $position = self::gifFirstBlock($gif);

        return substr($gif, 0, $position)."\x21\xFE".chr(strlen($sentinel)).$sentinel."\0".substr($gif, $position);
    }

    private static function webpWithSentinel(string $webp, string $sentinel): string
    {
        $payload = substr($webp, 12);
        $exif = 'EXIF'.pack('V', strlen($sentinel)).$sentinel.(strlen($sentinel) % 2 === 1 ? "\0" : '');

        if (substr($payload, 0, 4) === 'VP8X') {
            $payload[8] = chr(ord($payload[8]) | 0x08);
        } else {
            $size = getimagesizefromstring($webp);

            if ($size === false) {
                throw new LogicException('The fixture WebP has no size.');
            }

            $payload = 'VP8X'.pack('V', 10).chr(0x08)."\0\0\0".self::u24($size[0] - 1).self::u24($size[1] - 1).$payload;
        }

        $payload .= $exif;

        return 'RIFF'.pack('V', 4 + strlen($payload)).'WEBP'.$payload;
    }

    /**
     * The offset of the first image descriptor of a GIF written by GD (after the global colour table and any
     * extensions).
     */
    private static function gifFirstBlock(string $gif): int
    {
        $flags = ord($gif[10]);
        $position = 13;

        if (($flags & 0x80) !== 0) {
            $position += 3 * (1 << (($flags & 7) + 1));
        }

        while ($gif[$position] === "\x21") {
            $position += 2;

            while (($size = ord($gif[$position++])) !== 0) {
                $position += $size;
            }
        }

        return $position;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    }

    private static function u24(int $value): string
    {
        return substr(pack('V', $value), 0, 3);
    }

    private static function pixel(string $bytes, int $x, int $y): int
    {
        $image = imagecreatefromstring($bytes);
        self::must($image);

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return (int) imagecolorat($image, $x, $y);
    }

    /**
     * A truecolor picture, left half red and right half blue; with $alpha every colour has GD alpha 64.
     */
    private static function halves(int $width, int $height, bool $alpha): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        self::must($image);

        $transparency = $alpha ? 64 : 0;

        if ($alpha) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }

        $red = imagecolorallocatealpha($image, 255, 0, 0, $transparency);
        $blue = imagecolorallocatealpha($image, 0, 0, 255, $transparency);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, (int) $red);
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, (int) $blue);

        return $image;
    }

    /**
     * The bytes an encoder writes for the image.
     *
     * @param  callable(GdImage): bool  $encoder
     */
    private static function render(GdImage $image, callable $encoder): string
    {
        ob_start();
        $written = $encoder($image);
        $bytes = ob_get_clean();

        if (! $written || ! is_string($bytes) || $bytes === '') {
            throw new LogicException('GD could not encode the fixture.');
        }

        return $bytes;
    }

    /**
     * @phpstan-assert GdImage $image
     */
    private static function must(GdImage|false $image): void
    {
        if ($image === false) {
            throw new LogicException('GD could not create the fixture image.');
        }
    }
}
