<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

/**
 * Every colour of an invoice PDF, derived from the one accent colour the Admin
 * picks (D-24, research Pattern 9, the rules of the cdom tools invoice).
 *
 * The palette is never stored: it is computed from the accent frozen into the
 * issue snapshot. The arithmetic is floating point on purpose, it is colour and
 * not money. All fields are lower-case #rrggbb strings.
 */
final readonly class DocumentPalette
{
    public const string DEFAULT_ACCENT = '#ff6f00';

    private const string WHITE = '#ffffff';

    /** Minimum contrast of the accent text on white (WCAG AA for large and bold text). */
    private const float MIN_ACCENT_TEXT_CONTRAST = 3.5;

    private function __construct(
        public string $accent,
        public string $ink,
        public string $muted,
        public string $hairline,
        public string $tint,
        public string $rowTint,
        public string $accentText,
        public string $onAccent,
    ) {}

    /**
     * Anything that is not a #rrggbb colour (null, a name, a short form, text
     * with trailing characters) falls back to the default accent.
     */
    public static function fromAccent(?string $hex): self
    {
        $accent = self::normalise($hex);
        [$hue, $saturation, $lightness] = self::toHsl($accent);

        $ink = self::fromHsl($hue, min($saturation, 0.2), 0.11);

        $accentText = $accent;
        $accentLightness = $lightness;

        while (self::contrast($accentText, self::WHITE) < self::MIN_ACCENT_TEXT_CONTRAST && $accentLightness > 0.05) {
            $accentLightness -= 0.02;
            $accentText = self::fromHsl($hue, $saturation, $accentLightness);
        }

        return new self(
            accent: $accent,
            ink: $ink,
            muted: self::fromHsl($hue, min($saturation, 0.08), 0.46),
            hairline: self::fromHsl($hue, min($saturation, 0.12), 0.89),
            tint: self::fromHsl($hue, min($saturation, 0.7), 0.95),
            rowTint: self::fromHsl($hue, min($saturation, 0.1), 0.965),
            accentText: $accentText,
            onAccent: self::contrast($accent, self::WHITE) >= self::contrast($accent, $ink) ? self::WHITE : $ink,
        );
    }

    /**
     * WCAG contrast ratio of two #rrggbb colours, from 1 to 21; symmetric.
     */
    public static function contrast(string $a, string $b): float
    {
        $first = self::luminance($a);
        $second = self::luminance($b);

        return (max($first, $second) + 0.05) / (min($first, $second) + 0.05);
    }

    /**
     * The contrast of the text on the accent bar (the total), which the Settings
     * page shows to the Admin.
     */
    public function onAccentContrast(): float
    {
        return self::contrast($this->onAccent, $this->accent);
    }

    private static function normalise(?string $hex): string
    {
        $candidate = strtolower(trim($hex ?? ''));

        return preg_match('/^#[0-9a-f]{6}$/D', $candidate) === 1 ? $candidate : self::DEFAULT_ACCENT;
    }

    /**
     * @return array{0: float, 1: float, 2: float} red, green and blue in 0..1
     */
    private static function toRgb(string $hex): array
    {
        // Floats on purpose: hexdec() and an exact division give an int, and the grey test below compares floats.
        return [
            hexdec(substr($hex, 1, 2)) / 255.0,
            hexdec(substr($hex, 3, 2)) / 255.0,
            hexdec(substr($hex, 5, 2)) / 255.0,
        ];
    }

    private static function luminance(string $hex): float
    {
        [$red, $green, $blue] = array_map(
            static fn (float $channel): float => $channel <= 0.03928
                ? $channel / 12.92
                : (($channel + 0.055) / 1.055) ** 2.4,
            self::toRgb($hex),
        );

        return 0.2126 * $red + 0.7152 * $green + 0.0722 * $blue;
    }

    /**
     * @return array{0: float, 1: float, 2: float} hue in degrees, saturation and lightness in 0..1
     */
    private static function toHsl(string $hex): array
    {
        [$red, $green, $blue] = self::toRgb($hex);

        $max = max($red, $green, $blue);
        $min = min($red, $green, $blue);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;

        if ($delta < 1.0E-9) {
            return [0.0, 0.0, $lightness];
        }

        $saturation = $delta / (1 - abs(2 * $lightness - 1));

        $hue = match (true) {
            $max === $red => fmod(($green - $blue) / $delta, 6),
            $max === $green => ($blue - $red) / $delta + 2,
            default => ($red - $green) / $delta + 4,
        } * 60;

        return [$hue < 0 ? $hue + 360 : $hue, $saturation, $lightness];
    }

    private static function fromHsl(float $hue, float $saturation, float $lightness): string
    {
        $chroma = (1 - abs(2 * $lightness - 1)) * $saturation;
        $segment = $hue / 60;
        $second = $chroma * (1 - abs(fmod($segment, 2) - 1));
        $offset = $lightness - $chroma / 2;

        [$red, $green, $blue] = match (true) {
            $segment < 1 => [$chroma, $second, 0.0],
            $segment < 2 => [$second, $chroma, 0.0],
            $segment < 3 => [0.0, $chroma, $second],
            $segment < 4 => [0.0, $second, $chroma],
            $segment < 5 => [$second, 0.0, $chroma],
            default => [$chroma, 0.0, $second],
        };

        return sprintf(
            '#%02x%02x%02x',
            (int) round(max(0.0, min(1.0, $red + $offset)) * 255),
            (int) round(max(0.0, min(1.0, $green + $offset)) * 255),
            (int) round(max(0.0, min(1.0, $blue + $offset)) * 255),
        );
    }
}
