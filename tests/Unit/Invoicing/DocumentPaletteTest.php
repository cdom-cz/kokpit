<?php

declare(strict_types=1);

use App\Domain\Invoicing\Pdf\DocumentPalette;

/*
 * The palette of an invoice PDF is derived from one accent colour by fixed
 * rules (D-24, research Pattern 9); these tests pin the rules, not a list of
 * hex values.
 */

/**
 * Saturation and lightness of a #rrggbb colour, computed here independently of the class under test.
 *
 * @return array{0: float, 1: float}
 */
function paletteSaturationAndLightness(string $hex): array
{
    $red = hexdec(substr($hex, 1, 2)) / 255;
    $green = hexdec(substr($hex, 3, 2)) / 255;
    $blue = hexdec(substr($hex, 5, 2)) / 255;
    $max = max($red, $green, $blue);
    $min = min($red, $green, $blue);
    $lightness = ($max + $min) / 2;
    $delta = $max - $min;

    return [$delta === 0.0 ? 0.0 : $delta / (1 - abs(2 * $lightness - 1)), $lightness];
}

it('falls back to the default accent for anything that is not #rrggbb', function (?string $given): void {
    expect(DocumentPalette::fromAccent($given)->accent)->toBe('#ff6f00');
})->with([null, '', 'ff6f00', '#ff6f0', '#FF6F00ZZ', 'orange', '#ff6f00; color:red', "#ff6f00\n"]);

it('normalises an upper-case accent to lower case', function (): void {
    expect(DocumentPalette::fromAccent('#FF6F00')->accent)->toBe('#ff6f00')
        ->and(DocumentPalette::fromAccent(' #1D4ED8 ')->accent)->toBe('#1d4ed8');
});

it('darkens the accent text of the default accent until it reads on white and prints ink on the total bar', function (): void {
    $palette = DocumentPalette::fromAccent('#ff6f00');

    expect(DocumentPalette::contrast($palette->accentText, '#ffffff'))->toBeGreaterThanOrEqual(3.5)
        ->and(DocumentPalette::contrast($palette->accent, '#ffffff'))->toBeLessThan(3.5)
        ->and(paletteSaturationAndLightness($palette->accentText)[1])->toBeLessThan(paletteSaturationAndLightness($palette->accent)[1])
        ->and($palette->onAccent)->toBe($palette->ink)
        ->and(DocumentPalette::contrast($palette->ink, $palette->accent))->toBeGreaterThan(DocumentPalette::contrast('#ffffff', $palette->accent))
        ->and($palette->onAccentContrast())->toBeGreaterThan(4.5);
});

it('keeps a dark accent as its own text and prints white on the total bar', function (): void {
    $palette = DocumentPalette::fromAccent('#1d4ed8');

    expect($palette->accentText)->toBe('#1d4ed8')
        ->and($palette->onAccent)->toBe('#ffffff')
        ->and($palette->onAccentContrast())->toBeGreaterThan(4.5);
});

it('derives ink, muted, hairline, tint and row tint with the documented lightness and capped saturation', function (string $accent): void {
    $palette = DocumentPalette::fromAccent($accent);
    [$accentSaturation] = paletteSaturationAndLightness($palette->accent);

    $expected = [
        'ink' => [0.2, 0.11],
        'muted' => [0.08, 0.46],
        'hairline' => [0.12, 0.89],
        'tint' => [0.7, 0.95],
        'rowTint' => [0.1, 0.965],
    ];

    foreach ($expected as $name => [$saturationCap, $lightness]) {
        [$saturation, $actualLightness] = paletteSaturationAndLightness($palette->{$name});

        expect($actualLightness)->toEqualWithDelta($lightness, 0.005, $name.' lightness')
            // Rounding to 8-bit channels moves a dark colour's saturation a little more than its lightness.
            ->and($saturation)->toBeLessThanOrEqual(min($accentSaturation, $saturationCap) + 0.05, $name.' saturation');
    }
})->with(['#ff6f00', '#1d4ed8', '#16a34a', '#7c3aed', '#facc15', '#808080']);

it('gives every accent a readable accent text and total bar', function (string $accent): void {
    $palette = DocumentPalette::fromAccent($accent);

    expect(DocumentPalette::contrast($palette->accentText, '#ffffff'))->toBeGreaterThanOrEqual(3.5)
        ->and($palette->onAccentContrast())->toBeGreaterThanOrEqual(DocumentPalette::contrast($palette->accent, '#ffffff'));
})->with(['#ff6f00', '#1d4ed8', '#ffffff', '#000000', '#ffff00', '#00ffff', '#facc15', '#808080']);

it('measures a symmetric contrast between 1 and 21', function (): void {
    expect(DocumentPalette::contrast('#000000', '#ffffff'))->toEqualWithDelta(21.0, 0.01)
        ->and(DocumentPalette::contrast('#ffffff', '#000000'))->toEqualWithDelta(21.0, 0.01)
        ->and(DocumentPalette::contrast('#777777', '#777777'))->toEqualWithDelta(1.0, 0.0001)
        ->and(DocumentPalette::contrast('#ff6f00', '#1d4ed8'))->toEqualWithDelta(DocumentPalette::contrast('#1d4ed8', '#ff6f00'), 0.0000001);
});
