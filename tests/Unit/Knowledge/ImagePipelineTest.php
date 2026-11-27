<?php

declare(strict_types=1);

use App\Domain\Knowledge\Images\Animation;
use App\Domain\Knowledge\Images\ImageFormat;
use App\Domain\Knowledge\Images\ImagePipeline;
use App\Domain\Knowledge\Images\ImageRefusal;
use App\Domain\Knowledge\Images\ImageRefused;
use App\Domain\Knowledge\Images\JpegOrientation;
use Tests\Support\ImageFixtures;

/*
 * The refusals of the image pipeline (D-06, D-07, D-09): SVG, renamed files, oversized files, decompression bombs and
 * animated images each end as exactly one reason, with no PHP warning and no content of the file in the exception.
 * Unit tests run without the application. Every file is built at runtime by Tests\Support\ImageFixtures.
 */

/**
 * The refusal of the input, or null when the pipeline accepts it. Runs with a handler that fails the test on any
 * warning or notice that escapes the pipeline.
 */
function imagePipelineRefusal(string $bytes, ?ImagePipeline $pipeline = null): ?ImageRefusal
{
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException('A warning escaped the image pipeline: '.$message, 0, $severity);
    });

    try {
        ($pipeline ?? new ImagePipeline)->process($bytes);

        return null;
    } catch (ImageRefused $refused) {
        return $refused->refusal;
    } finally {
        restore_error_handler();
    }
}

it('accepts every supported format', function (string $name): void {
    $bytes = match ($name) {
        'png' => ImageFixtures::png(),
        'jpeg' => ImageFixtures::jpeg(),
        'webp' => ImageFixtures::webp(),
        'gif' => ImageFixtures::gif(),
        'palette png' => ImageFixtures::palettePng(),
    };

    expect(imagePipelineRefusal($bytes))->toBeNull();
})->with(['png', 'jpeg', 'webp', 'gif', 'palette png']);

it('refuses an SVG with its own reason', function (string $svg): void {
    expect(imagePipelineRefusal($svg))->toBe(ImageRefusal::Svg);
})->with(function (): array {
    return [
        'bare svg' => [ImageFixtures::svg()],
        'with an XML prolog' => [ImageFixtures::svgWithProlog()],
        'with a byte order mark and leading whitespace' => ["\xEF\xBB\xBF \n\t".ImageFixtures::svg()],
        'with a doctype' => ['<'.'!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">'.ImageFixtures::svg()],
        'in upper case' => [strtoupper(ImageFixtures::svg())],
    ];
});

it('refuses everything that is not an image as not an image', function (string $bytes): void {
    expect(imagePipelineRefusal($bytes))->toBe(ImageRefusal::NotAnImage);
})->with(function (): array {
    return [
        'plain text named like a screenshot' => [ImageFixtures::renamedText()],
        'an empty string' => [''],
        'exactly the upload limit of text' => [str_repeat('a', ImagePipeline::MAX_UPLOAD_BYTES)],
        'a BMP' => [ImageFixtures::bmp()],
        'a PNG with corrupted image data' => [ImageFixtures::corruptPng()],
        'a truncated GIF' => [ImageFixtures::truncatedGif()],
        'a PNG whose chunk runs past the end' => [ImageFixtures::brokenChunkPng()],
        'PNG magic bytes in front of JPEG data' => [ImageFixtures::pngMagicOverJpeg()],
        'a lone JPEG start marker' => ["\xFF\xD8\xFF"],
        'a lone RIFF header' => ['RIFF'."\0\0\0\0".'WEBP'],
    ];
});

it('refuses an upload over the limit as too large', function (): void {
    expect(strlen(ImageFixtures::oversized()))->toBe(ImagePipeline::MAX_UPLOAD_BYTES + 1)
        ->and(imagePipelineRefusal(ImageFixtures::oversized()))->toBe(ImageRefusal::TooLarge);
});

it('refuses a header that claims too many pixels before it decodes anything', function (): void {
    expect(strlen(ImageFixtures::pixelBomb()))->toBe(33)
        ->and(imagePipelineRefusal(ImageFixtures::pixelBomb()))->toBe(ImageRefusal::TooManyPixels);
});

it('refuses a header that needs more memory than the limit leaves, and decodes only when the gate passes', function (): void {
    $header = ImageFixtures::headerOnlyPng(4000, 4000);

    // 4000 x 4000 is below the pixel cap but needs about 100 MB: refused by the memory preflight.
    expect(imagePipelineRefusal($header, new ImagePipeline(32 * 1024 * 1024)))->toBe(ImageRefusal::TooManyPixels)
        // Without a limit the header gate passes and the decode fails: the gate runs before the decode.
        ->and(imagePipelineRefusal($header, new ImagePipeline(-1)))->toBe(ImageRefusal::NotAnImage);
});

it('refuses an animated image', function (string $name): void {
    $bytes = match ($name) {
        'gif' => ImageFixtures::animatedGif(),
        'png' => ImageFixtures::apng(),
        'webp' => ImageFixtures::animatedWebp(),
    };

    expect(imagePipelineRefusal($bytes))->toBe(ImageRefusal::Animated);
})->with(['gif', 'png', 'webp']);

it('walks the structure of GIF, PNG and WebP to tell a still from an animation', function (): void {
    expect(Animation::isAnimated(ImageFormat::Gif, ImageFixtures::gif()))->toBeFalse()
        ->and(Animation::isAnimated(ImageFormat::Gif, ImageFixtures::animatedGif()))->toBeTrue()
        ->and(Animation::isAnimated(ImageFormat::Gif, ImageFixtures::truncatedGif()))->toBeNull()
        ->and(Animation::isAnimated(ImageFormat::Png, ImageFixtures::png()))->toBeFalse()
        ->and(Animation::isAnimated(ImageFormat::Png, ImageFixtures::apng()))->toBeTrue()
        ->and(Animation::isAnimated(ImageFormat::Png, ImageFixtures::brokenChunkPng()))->toBeNull()
        ->and(Animation::isAnimated(ImageFormat::Webp, ImageFixtures::webp()))->toBeFalse()
        ->and(Animation::isAnimated(ImageFormat::Webp, ImageFixtures::webp(40, 20, true)))->toBeFalse()
        ->and(Animation::isAnimated(ImageFormat::Webp, ImageFixtures::animatedWebp()))->toBeTrue()
        ->and(Animation::isAnimated(ImageFormat::Jpeg, ImageFixtures::jpeg()))->toBeFalse();
});

it('reads an orientation only from a well-formed Exif segment', function (): void {
    $jpeg = ImageFixtures::gpsJpeg(6);

    expect(JpegOrientation::read($jpeg))->toBe(6)
        ->and(JpegOrientation::read(ImageFixtures::jpeg()))->toBe(1)
        ->and(JpegOrientation::read(substr($jpeg, 0, 30)))->toBe(1)
        ->and(JpegOrientation::read(''))->toBe(1)
        ->and(JpegOrientation::read(ImageFixtures::gpsJpeg(9)))->toBe(1);
});

it('says nothing about the file in the exception', function (): void {
    $marker = ImageFixtures::sentinel();
    $input = ImageFixtures::renamedText().$marker;

    foreach ([$input, ImageFixtures::oversized(), ImageFixtures::svg(), ImageFixtures::animatedGif(), ImageFixtures::pixelBomb()] as $bytes) {
        try {
            (new ImagePipeline)->process($bytes);

            $this->fail('The pipeline accepted a file it must refuse.');
        } catch (ImageRefused $refused) {
            expect($refused->getMessage())->toBe('The image was refused.')
                ->and($refused->getMessage())->not->toContain($marker)
                ->and($refused->getPrevious())->toBeNull();
        }
    }

    expect(ImageRefusal::from('too_large')->messageKey())->toBe('knowledge.images.errors.too_large')
        ->and(ImageRefusal::TooManyPixels->messageKey())->toBe('knowledge.images.errors.too_many_pixels');
});

it('names neither the deprecated destroy function nor the scale function of GD', function (): void {
    $needles = ['image'.'destroy', 'image'.'scale'];
    $files = glob(dirname(__DIR__, 3).'/app/Domain/Knowledge/Images/*.php');

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        foreach ($needles as $needle) {
            expect(str_contains(strtolower((string) file_get_contents($file)), $needle))->toBeFalse($file.' names '.$needle);
        }
    }
});
