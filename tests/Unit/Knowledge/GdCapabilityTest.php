<?php

declare(strict_types=1);

use App\Domain\Knowledge\Images\GdCapability;

/*
 * The GD probe is a pure class: it reads a gd_info() style array and names the formats GD cannot
 * handle (IMG-02). Unit tests run without the application.
 */

/**
 * @return array<string, bool>
 */
function completeGdInfo(): array
{
    return [
        'PNG Support' => true,
        'JPEG Support' => true,
        'WebP Support' => true,
        'GIF Read Support' => true,
        'GIF Create Support' => true,
    ];
}

it('reports nothing missing for a complete GD', function (): void {
    expect((new GdCapability(completeGdInfo()))->missing())->toBe([]);
});

it('names WebP when GD has no WebP support', function (): void {
    $info = array_merge(completeGdInfo(), ['WebP Support' => false]);

    expect((new GdCapability($info))->missing())->toBe(['WebP']);
});

it('names GIF once when both GIF keys are missing', function (): void {
    $info = completeGdInfo();
    unset($info['GIF Read Support'], $info['GIF Create Support']);

    expect((new GdCapability($info))->missing())->toBe(['GIF']);
});

it('names GIF when only one of its two directions is missing', function (): void {
    $info = array_merge(completeGdInfo(), ['GIF Create Support' => false]);

    expect((new GdCapability($info))->missing())->toBe(['GIF']);
});

it('names every format for an empty capability list, in a fixed order', function (): void {
    expect((new GdCapability([]))->missing())->toBe(['PNG', 'JPEG', 'WebP', 'GIF']);
});

it('counts only an exact true as support', function (): void {
    $info = array_merge(completeGdInfo(), ['JPEG Support' => 1, 'PNG Support' => 'yes']);

    expect((new GdCapability($info))->missing())->toBe(['PNG', 'JPEG']);
});

it('reads the real gd_info when no capability list is given', function (): void {
    expect((new GdCapability)->missing())->toBe([]);
});
