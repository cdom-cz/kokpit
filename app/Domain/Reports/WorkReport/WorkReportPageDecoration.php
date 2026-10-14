<?php

declare(strict_types=1);

namespace App\Domain\Reports\WorkReport;

use Dompdf\Canvas;
use Dompdf\FontMetrics;
use RuntimeException;

/**
 * What the work report draws on every page outside the flowing HTML (UI-SPEC Surface D; D-10): the footer on
 * every page, and on the pages after the first the running header with its rule.
 *
 * It is a PHP callable the application hands to the Dompdf canvas (`page_script`), not PHP inside the HTML,
 * so `isPhpEnabled` stays off. The texts arrive already translated: the service resolves them inside the
 * client's language, this class only places them. Sizes are points, 8pt text, a 1pt rule, grayscale colours.
 *
 * The running header is one line inside the left and right margins; a client name too long for it is cut and
 * ends in an ellipsis, so the title and the period stay whole.
 */
final readonly class WorkReportPageDecoration
{
    private const string FONT = 'DejaVu Sans';

    private const float SIZE = 8.0;

    private const float MARGIN = 48.0;

    /** Top of the running header text (Dompdf places text from its top), inside the 72pt top margin. */
    private const float HEADER_Y = 30.0;

    /** Rule under the running header. */
    private const float RULE_Y = 46.0;

    /** Distance of the footer text top from the bottom edge, inside the 56pt bottom margin. */
    private const float FOOTER_FROM_BOTTOM = 28.0;

    /** Secondary text `#52525b`. */
    private const array TEXT = [0.322, 0.322, 0.357];

    /** Rules `#d4d4d8`. */
    private const array RULE = [0.831, 0.831, 0.847];

    /**
     * @param  string  $title  "Pracovní výkaz" / "Work report"
     * @param  string  $client  the client's name
     * @param  string  $period  the formatted `from – to`
     * @param  string  $generated  "Vytvořeno :date" / "Generated :date", filled in
     * @param  string  $pageTemplate  "Strana {n} / {total}" / "Page {n} / {total}"
     */
    public function __construct(
        private string $title,
        private string $client,
        private string $period,
        private string $generated,
        private string $pageTemplate,
    ) {}

    public function __invoke(int $pageNumber, int $pageCount, Canvas $canvas, FontMetrics $fontMetrics): void
    {
        $font = $fontMetrics->getFont(self::FONT) ?? throw new RuntimeException('The font '.self::FONT.' is not available to the renderer.');
        $width = $canvas->get_width();
        $height = $canvas->get_height();
        $available = $width - 2 * self::MARGIN;

        if ($pageNumber > 1) {
            $header = $this->header($fontMetrics, $font, $available);

            $canvas->text(self::MARGIN, self::HEADER_Y, $header, $font, self::SIZE, self::TEXT);
            $canvas->line(self::MARGIN, self::RULE_Y, $width - self::MARGIN, self::RULE_Y, self::RULE, 1.0);
        }

        $y = $height - self::FOOTER_FROM_BOTTOM;
        $page = str_replace(['{n}', '{total}'], [(string) $pageNumber, (string) $pageCount], $this->pageTemplate);

        $canvas->text(self::MARGIN, $y, $this->generated, $font, self::SIZE, self::TEXT);
        $canvas->text($width - self::MARGIN - $fontMetrics->getTextWidth($page, $font, self::SIZE), $y, $page, $font, self::SIZE, self::TEXT);
    }

    /**
     * "title · client · period", the client cut with an ellipsis until the line fits.
     */
    private function header(FontMetrics $fontMetrics, string $font, float $available): string
    {
        $client = $this->client;

        while (true) {
            $line = $this->title.' · '.$client.' · '.$this->period;

            if ($client === '' || $fontMetrics->getTextWidth($line, $font, self::SIZE) <= $available) {
                return $line;
            }

            // One character less each round; the ellipsis replaces the cut part.
            $client = rtrim(mb_substr(rtrim($client, '…'), 0, -1)).'…';

            if ($client === '…') {
                return $this->title.' · … · '.$this->period;
            }
        }
    }
}
