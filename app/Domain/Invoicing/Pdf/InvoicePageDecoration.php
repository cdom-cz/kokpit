<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Pdf;

use Dompdf\Canvas;
use Dompdf\FontMetrics;
use RuntimeException;

/**
 * What the invoice draws on every page outside the flowing HTML (UI-SPEC Surface H; D-24, ruling R-15-1): the
 * accent stripes at the top and the bottom edge, the hairline above the footer and the right-aligned page label.
 *
 * It is a PHP callable the application hands to the Dompdf canvas (`page_script`), not PHP inside the HTML, so
 * `isPhpEnabled` stays off. Drawing the stripes here rather than as fixed HTML puts them on every page without
 * depending on how the layout breaks. The colours come from the palette of the snapshotted accent and the label
 * text arrives already translated; this class only places them. Sizes are points.
 */
final readonly class InvoicePageDecoration
{
    /** The stripe height at the top and at the bottom (ruling R-15-1: D-24 wins over UI-SPEC U-9). */
    public const float STRIPE = 7.0;

    private const string FONT = 'Roboto';

    private const string FALLBACK_FONT = 'DejaVu Sans';

    private const float SIZE = 8.0;

    private const float MARGIN = 36.0;

    /** Distance of the hairline from the bottom edge. */
    private const float HAIRLINE_FROM_BOTTOM = 44.0;

    /** Distance of the bottom of the page label from the bottom edge. */
    private const float LABEL_FROM_BOTTOM = 24.0;

    private const float HAIRLINE_WIDTH = 0.75;

    /**
     * @param  string  $pageTemplate  "Strana {n} / {total}" / "Page {n} / {total}"
     */
    public function __construct(
        private DocumentPalette $palette,
        private string $pageTemplate,
    ) {}

    public function __invoke(int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics): void
    {
        $font = $fontMetrics->getFont(self::FONT) ?? $fontMetrics->getFont(self::FALLBACK_FONT)
            ?? throw new RuntimeException('Neither '.self::FONT.' nor '.self::FALLBACK_FONT.' is available to the renderer.');
        $width = $canvas->get_width();
        $height = $canvas->get_height();
        $accent = self::rgb($this->palette->accent);

        $canvas->filled_rectangle(0.0, 0.0, $width, self::STRIPE, $accent);
        $canvas->filled_rectangle(0.0, $height - self::STRIPE, $width, self::STRIPE, $accent);

        $ruleY = $height - self::HAIRLINE_FROM_BOTTOM;
        $canvas->line(self::MARGIN, $ruleY, $width - self::MARGIN, $ruleY, self::rgb($this->palette->hairline), self::HAIRLINE_WIDTH);

        $label = str_replace(['{n}', '{total}'], [(string) $pageNumber, (string) $pageCount], $this->pageTemplate);
        // Dompdf places text from its top, so the top is the size above the wanted bottom.
        $y = $height - self::LABEL_FROM_BOTTOM - self::SIZE;

        $canvas->text($width - self::MARGIN - $fontMetrics->getTextWidth($label, $font, self::SIZE), $y, $label, $font, self::SIZE, self::rgb($this->palette->muted));
    }

    /**
     * @return array{0: float, 1: float, 2: float} red, green and blue in 0..1 of a #rrggbb colour
     */
    private static function rgb(string $hex): array
    {
        return [
            hexdec(substr($hex, 1, 2)) / 255.0,
            hexdec(substr($hex, 3, 2)) / 255.0,
            hexdec(substr($hex, 5, 2)) / 255.0,
        ];
    }
}
