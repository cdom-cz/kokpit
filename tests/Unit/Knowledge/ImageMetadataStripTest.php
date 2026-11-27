<?php

declare(strict_types=1);

use App\Domain\Knowledge\Images\ImageFormat;
use App\Domain\Knowledge\Images\ImagePipeline;
use App\Domain\Knowledge\Images\ProcessedImage;
use Tests\Support\ImageFixtures;

/*
 * The image pipeline re-encodes every upload in its own format (D-08): metadata is gone, the JPEG orientation is
 * applied before the strip, the longer side is scaled down to 2400 px and never up. Unit tests run without the
 * application. Every file is built at runtime by Tests\Support\ImageFixtures; the GPS position is fictional.
 */

function imageStripRun(string $bytes): ProcessedImage
{
    return (new ImagePipeline)->process($bytes);
}

/**
 * The sections exif_read_data finds in the bytes, or false when it finds none. Read through a memory stream, so the
 * result does not depend on the URL wrappers of the PHP configuration.
 *
 * @return array<string, mixed>|false
 */
function imageStripExif(string $bytes): array|false
{
    $stream = fopen('php://memory', 'r+');
    fwrite($stream, $bytes);
    rewind($stream);

    $sections = exif_read_data($stream, null, true);
    fclose($stream);

    return $sections;
}

/**
 * @return array{int, int} width and height of the bytes
 */
function imageStripSize(string $bytes): array
{
    $size = getimagesizefromstring($bytes);

    return [$size[0], $size[1]];
}

it('reads the GPS position and the orientation of the fixture photo before the strip', function (): void {
    $sections = imageStripExif(ImageFixtures::gpsJpeg(6, ImageFixtures::sentinel()));

    expect($sections)->toBeArray()
        ->and($sections)->toHaveKey('GPS')
        ->and($sections['GPS'])->toHaveKey('GPSLatitudeRef')
        ->and($sections['IFD0']['Orientation'])->toBe(6);
});

it('returns a GPS photo with no Exif segment, no GPS section, no orientation and no sentinel', function (): void {
    $sentinel = ImageFixtures::sentinel();
    $input = ImageFixtures::gpsJpeg(6, $sentinel);

    $result = imageStripRun($input);

    expect(str_contains($input, "Exif\0\0"))->toBeTrue()
        ->and(str_contains($input, $sentinel))->toBeTrue()
        ->and(str_contains($result->bytes, "Exif\0\0"))->toBeFalse()
        ->and(str_contains($result->bytes, $sentinel))->toBeFalse();

    $sections = imageStripExif($result->bytes);

    expect($sections === false || ! array_key_exists('GPS', $sections))->toBeTrue()
        ->and($sections === false || ! isset($sections['IFD0']['Orientation']))->toBeTrue();
});

it('removes a sentinel from every metadata container of every format', function (string $format): void {
    $sentinel = ImageFixtures::sentinel();
    $input = ImageFixtures::withSentinel(match ($format) {
        'jpeg' => ImageFixtures::jpeg(),
        'png' => ImageFixtures::png(),
        'gif' => ImageFixtures::gif(),
        'webp' => ImageFixtures::webp(),
    }, $sentinel);

    $result = imageStripRun($input);

    expect(str_contains($input, $sentinel))->toBeTrue()
        ->and(str_contains($result->bytes, $sentinel))->toBeFalse()
        ->and(imagecreatefromstring($result->bytes))->toBeInstanceOf(GdImage::class);
})->with(['jpeg', 'png', 'gif', 'webp']);

it('removes a sentinel from the metadata of an alpha WebP that already has an extended header', function (): void {
    $sentinel = ImageFixtures::sentinel();
    $input = ImageFixtures::withSentinel(ImageFixtures::webp(40, 20, true), $sentinel);

    $result = imageStripRun($input);

    expect(str_contains($input, $sentinel))->toBeTrue()
        ->and(str_contains($result->bytes, $sentinel))->toBeFalse();
});

it('returns every format as itself', function (string $format, ImageFormat $expected): void {
    $input = match ($format) {
        'jpeg' => ImageFixtures::jpeg(),
        'png' => ImageFixtures::png(),
        'gif' => ImageFixtures::gif(),
        'webp' => ImageFixtures::webp(),
    };

    $result = imageStripRun($input);

    expect($result->format)->toBe($expected)
        ->and($result->mimeType())->toBe($expected->value)
        ->and(ImageFormat::sniff($result->bytes))->toBe($expected);
})->with([
    'jpeg' => ['jpeg', ImageFormat::Jpeg],
    'png' => ['png', ImageFormat::Png],
    'gif' => ['gif', ImageFormat::Gif],
    'webp' => ['webp', ImageFormat::Webp],
]);

it('keeps the visual orientation of a phone photo', function (int $orientation, int $width, int $height, int $x, int $y): void {
    $result = imageStripRun(ImageFixtures::gpsJpeg($orientation));

    expect([$result->width, $result->height])->toBe([$width, $height])
        ->and(imageStripSize($result->bytes))->toBe([$width, $height])
        ->and(ImageFixtures::pixelIsRedDominant($result->bytes, $x, $y))->toBeTrue();
})->with([
    'orientation 6 turns clockwise, left half on top' => [6, 20, 40, 10, 5],
    'orientation 8 turns counter-clockwise, left half at the bottom' => [8, 20, 40, 10, 35],
    'orientation 3 turns by 180, left half on the right' => [3, 40, 20, 30, 10],
    'orientation 1 stays upright' => [1, 40, 20, 10, 10],
    'orientation 2 mirrors, left half on the right' => [2, 40, 20, 30, 10],
    'orientation 4 flips, left half stays on the left' => [4, 40, 20, 10, 10],
    'orientation 5 transposes, left half on top' => [5, 20, 40, 10, 5],
    'orientation 7 transverses, left half at the bottom' => [7, 20, 40, 10, 35],
]);

it('treats an untagged JPEG as upright', function (): void {
    $result = imageStripRun(ImageFixtures::jpeg());

    expect([$result->width, $result->height])->toBe([40, 20])
        ->and(ImageFixtures::pixelIsRedDominant($result->bytes, 10, 10))->toBeTrue();
});

it('scales the longer side down to 2400 px and never up', function (string $format, string $input, int $width, int $height): void {
    $result = imageStripRun($input);

    expect([$result->width, $result->height])->toBe([$width, $height])
        ->and(imageStripSize($result->bytes))->toBe([$width, $height])
        ->and($result->format->extension())->toBe($format);
})->with(function (): array {
    return [
        'wide png' => ['png', ImageFixtures::png(3000, 1000), 2400, 800],
        'tall webp' => ['webp', ImageFixtures::webp(1000, 3000), 800, 2400],
        'small gif' => ['gif', ImageFixtures::gif(100, 50), 100, 50],
        'exactly 2400' => ['png', ImageFixtures::png(2400, 10), 2400, 10],
    ];
});

it('keeps the alpha of a PNG and a WebP and accepts a palette PNG', function (): void {
    $png = imageStripRun(ImageFixtures::png(40, 20, true));
    $webp = imageStripRun(ImageFixtures::webp(40, 20, true));
    $palette = imageStripRun(ImageFixtures::palettePng());

    expect(ImageFixtures::pixelAlpha($png->bytes, 5, 5))->toBeGreaterThanOrEqual(60)
        ->and(ImageFixtures::pixelAlpha($png->bytes, 5, 5))->toBeLessThanOrEqual(68)
        ->and(ImageFixtures::pixelAlpha($webp->bytes, 5, 5))->toBeGreaterThan(0)
        ->and($palette->format)->toBe(ImageFormat::Png)
        ->and(ImageFixtures::pixelIsRedDominant($palette->bytes, 5, 5))->toBeTrue();
});

it('reports the hash, the size and the pixel size of the output bytes', function (): void {
    $result = imageStripRun(ImageFixtures::png(3000, 1000));

    expect($result->sha256)->toBe(hash('sha256', $result->bytes))
        ->and($result->size())->toBe(strlen($result->bytes))
        ->and([$result->width, $result->height])->toBe(imageStripSize($result->bytes));
});
