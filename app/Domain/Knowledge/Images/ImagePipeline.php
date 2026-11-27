<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

use ErrorException;
use finfo;
use GdImage;
use ValueError;

/**
 * The one place where a raw upload becomes the bytes that are stored (IMG-01, IMG-02). It takes the bytes of an upload
 * and returns either the re-encoded image (the same format, no metadata, the JPEG orientation applied, at most
 * MAX_EDGE pixels on the longer side) or throws ImageRefused with the reason. Only bytes that GD encoded itself
 * leave it; no byte of the upload is ever copied into the result.
 *
 * Order of the checks, each before the more expensive next one:
 *  1. size of the upload; 2. magic bytes decide the format (file name and client MIME type are never read; an SVG
 *  is recognised for the message only); 3. the header must agree with the format and give a size; 4. pixel limit
 *  and memory preflight, before anything is decoded (decompression bomb); 5. animation, by structure; 6. decode;
 *  7. palette to truecolor; 8. JPEG orientation; 9. scale down onto a fresh canvas; 10. encode in the same format;
 *  11. read the size back from the output; 12. result.
 *
 * Every GD and header call runs with a local error handler that turns a warning or a notice into an ErrorException
 * and restores the previous handler afterwards, so the behaviour is the same with and without the error handler of the
 * application, and no warning ever reaches the caller. GD's deprecated destroy function is not called (GD objects are
 * freed by the garbage collector) and its scale function is not used (it fails on an alpha WebP).
 *
 * Accepted tradeoffs: the JPEG re-encode is lossy; ICC profiles are dropped, so a Display P3 screenshot shifts
 * slightly towards sRGB; a resized GIF is re-quantised; a 16-bit PNG becomes 8-bit; GD writes its own creator comment
 * into a JPEG, which carries nothing of the upload.
 */
final class ImagePipeline
{
    public const int MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const int MAX_PIXELS = 24_000_000;

    public const int MAX_EDGE = 2400;

    /**
     * The bound of the database column that stores the file (R-20-01-1).
     */
    public const int MAX_STORED_BYTES = 32 * 1024 * 1024;

    public const int JPEG_QUALITY = 85;

    public const int WEBP_QUALITY = 85;

    public const int PNG_COMPRESSION = 9;

    public const int MEMORY_HEADROOM = 16 * 1024 * 1024;

    /**
     * @param  int|null  $memoryLimitBytes  the memory limit the preflight works against: null reads memory_limit of the
     *                                      PHP configuration, a negative number means no limit
     */
    public function __construct(private readonly ?int $memoryLimitBytes = null) {}

    /**
     * @throws ImageRefused
     */
    public function process(string $bytes): ProcessedImage
    {
        $size = strlen($bytes);

        if ($size === 0) {
            throw new ImageRefused(ImageRefusal::NotAnImage);
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            throw new ImageRefused(ImageRefusal::TooLarge);
        }

        $format = ImageFormat::sniff($bytes);

        if ($format === null) {
            throw new ImageRefused($this->looksLikeSvg($bytes) ? ImageRefusal::Svg : ImageRefusal::NotAnImage);
        }

        [$width, $height] = $this->readHeader($bytes, $format);

        $this->guardPixels($width, $height);

        try {
            $animated = self::guarded(static fn (): ?bool => Animation::isAnimated($format, $bytes));
        } catch (ErrorException|ValueError) {
            $animated = null;
        }

        if ($animated === null) {
            throw new ImageRefused(ImageRefusal::NotAnImage);
        }

        if ($animated) {
            throw new ImageRefused(ImageRefusal::Animated);
        }

        try {
            $source = self::guarded(static fn (): GdImage|false => imagecreatefromstring($bytes));
        } catch (ErrorException|ValueError) {
            $source = false;
        }

        if ($source === false) {
            throw new ImageRefused(ImageRefusal::NotAnImage);
        }

        try {
            $output = self::guarded(fn (): string => $this->reencode($source, $format, $bytes));
        } catch (ErrorException|ValueError) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        try {
            $info = self::guarded(static fn (): array|false => getimagesizefromstring($output));
        } catch (ErrorException|ValueError) {
            $info = false;
        }

        if (! is_array($info) || ! is_int($info[0]) || ! is_int($info[1]) || $info[0] < 1 || $info[1] < 1) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        if (strlen($output) > self::MAX_STORED_BYTES) {
            throw new ImageRefused(ImageRefusal::TooLarge);
        }

        return new ProcessedImage($output, $format, $info[0], $info[1], hash('sha256', $output));
    }

    /**
     * The pixel size from the header alone, without decoding. The header type must be the one the magic bytes
     * announced.
     *
     * @return array{int, int}
     */
    private function readHeader(string $bytes, ImageFormat $format): array
    {
        // A real PNG always starts with its IHDR chunk; without the check, PNG magic bytes in front of other data
        // would make getimagesizefromstring report an arbitrary size.
        if ($format === ImageFormat::Png && substr($bytes, 12, 4) !== 'IHDR') {
            throw new ImageRefused(ImageRefusal::NotAnImage);
        }

        try {
            $info = self::guarded(static fn (): array|false => getimagesizefromstring($bytes));
        } catch (ErrorException|ValueError) {
            $info = false;
        }

        if (! is_array($info) || ! is_int($info[0]) || ! is_int($info[1]) || ! is_int($info[2])
            || $info[0] < 1 || $info[1] < 1 || $info[2] !== $format->imageType()) {
            throw new ImageRefused(ImageRefusal::NotAnImage);
        }

        return [$info[0], $info[1]];
    }

    /**
     * The pixel cap and the memory preflight. GD holds 4 bytes per pixel of a truecolor image: the decoded source,
     * the canvas (at most MAX_EDGE squared) and some headroom must fit into what the memory limit leaves.
     */
    private function guardPixels(int $width, int $height): void
    {
        $pixels = $width * $height;

        if ($pixels > self::MAX_PIXELS) {
            throw new ImageRefused(ImageRefusal::TooManyPixels);
        }

        $limit = $this->memoryLimit();

        if ($limit < 0) {
            return;
        }

        $needed = $pixels * 4 + min($pixels, self::MAX_EDGE * self::MAX_EDGE) * 4 + self::MEMORY_HEADROOM;

        if ($needed > $limit - memory_get_usage(true)) {
            throw new ImageRefused(ImageRefusal::TooManyPixels);
        }
    }

    /**
     * The memory limit in bytes, -1 for no limit.
     */
    private function memoryLimit(): int
    {
        if ($this->memoryLimitBytes !== null) {
            return $this->memoryLimitBytes;
        }

        $value = trim((string) ini_get('memory_limit'));

        if ($value === '' || $value === '-1') {
            return -1;
        }

        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => $number,
        };
    }

    /**
     * An SVG is recognised for the message only: after an optional UTF-8 byte order mark and whitespace the text
     * starts with an XML prolog, an svg element or an svg doctype, or the content type of the buffer is SVG.
     */
    private function looksLikeSvg(string $bytes): bool
    {
        $head = substr($bytes, 0, 2048);

        if (str_starts_with($head, "\xEF\xBB\xBF")) {
            $head = substr($head, 3);
        }

        $head = ltrim($head);

        foreach (['<?xml', '<svg', '<!doctype svg'] as $prefix) {
            if (strncasecmp($head, $prefix, strlen($prefix)) === 0) {
                return true;
            }
        }

        if (! class_exists(finfo::class)) {
            return false;
        }

        try {
            $type = self::guarded(static fn (): string|false => (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes));
        } catch (ErrorException|ValueError) {
            return false;
        }

        return $type === 'image/svg+xml';
    }

    /**
     * Steps 7 to 10: truecolor, orientation, scale down onto a fresh canvas, encode.
     */
    private function reencode(GdImage $source, ImageFormat $format, string $original): string
    {
        if (! imageistruecolor($source)) {
            imagepalettetotruecolor($source);
        }

        if ($format === ImageFormat::Jpeg) {
            $source = JpegOrientation::apply($source, JpegOrientation::read($original));
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1.0, self::MAX_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if ($canvas === false) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        if ($format === ImageFormat::Jpeg) {
            $background = imagecolorallocate($canvas, 255, 255, 255);
            imagefill($canvas, 0, 0, (int) $background);
        } else {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        }

        if (! imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height)) {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        return $this->encode($canvas, $format);
    }

    private function encode(GdImage $canvas, ImageFormat $format): string
    {
        ob_start();

        try {
            $written = match ($format) {
                ImageFormat::Png => imagepng($canvas, null, self::PNG_COMPRESSION),
                ImageFormat::Jpeg => imagejpeg($canvas, null, self::JPEG_QUALITY),
                ImageFormat::Webp => imagewebp($canvas, null, self::WEBP_QUALITY),
                ImageFormat::Gif => imagegif($canvas),
            };
        } catch (\Throwable $exception) {
            ob_end_clean();

            throw $exception;
        }

        $output = ob_get_clean();

        if (! $written || ! is_string($output) || $output === '') {
            throw new ImageRefused(ImageRefusal::Failed);
        }

        return $output;
    }

    /**
     * Runs the callback with a local error handler: any warning or notice becomes an ErrorException, and the
     * previous handler is restored whatever happens.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws ErrorException
     */
    private static function guarded(callable $callback): mixed
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            return $callback();
        } finally {
            restore_error_handler();
        }
    }
}
