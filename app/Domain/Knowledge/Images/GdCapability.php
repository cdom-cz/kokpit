<?php

declare(strict_types=1);

namespace App\Domain\Knowledge\Images;

/**
 * Probe of the GD extension the image attachments need (IMG-02): reading and
 * re-encoding PNG, JPEG, WebP and GIF. It answers which formats GD cannot
 * handle, so the readiness gate of a deploy can fail on a container whose PHP
 * has no GD or a GD built without a codec instead of the first image upload
 * failing in production.
 *
 * Pure: the capability list comes from gd_info() or from the array given to
 * the constructor (a test seam). The result names formats only, never a path,
 * a version string or a configuration value.
 */
final class GdCapability
{
    /**
     * gd_info() key => the format name reported when the key is not exactly true.
     * GIF needs both reading and writing.
     *
     * @var array<string, string>
     */
    public const array REQUIRED = [
        'PNG Support' => 'PNG',
        'JPEG Support' => 'JPEG',
        'WebP Support' => 'WebP',
        'GIF Read Support' => 'GIF',
        'GIF Create Support' => 'GIF',
    ];

    /**
     * @param  array<string, mixed>|null  $info  a gd_info() result; null reads the real one
     */
    public function __construct(private readonly ?array $info = null) {}

    /**
     * The formats GD cannot handle, once each, in the order of REQUIRED; the
     * single entry 'GD' when the extension itself is absent.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $info = $this->info;

        if ($info === null) {
            if (! function_exists('gd_info')) {
                return ['GD'];
            }

            $info = gd_info();
        }

        $missing = [];

        foreach (self::REQUIRED as $key => $format) {
            if (($info[$key] ?? null) !== true) {
                $missing[] = $format;
            }
        }

        return array_values(array_unique($missing));
    }
}
