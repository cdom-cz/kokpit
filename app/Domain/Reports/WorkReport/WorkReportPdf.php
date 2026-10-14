<?php

declare(strict_types=1);

namespace App\Domain\Reports\WorkReport;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\TimeTracking\Support\DurationFormat;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Traits\Localizable;

/**
 * Turns a work report source into a PDF (RE-03; D-10): Blade HTML through Dompdf, called directly (the only
 * way the spike measured).
 *
 * Dompdf runs closed: remote access, PHP and JavaScript are off, the default font is DejaVu Sans (the bundled
 * font that covers every Czech letter; the built-in Helvetica does not), and the chroot limits what the
 * renderer may read from disk to the PDF view directory. Every value reaches the template through Blade
 * `{{ }}` escaping, so a title such as `<b>x</b>` prints as text and can neither add markup nor name a file.
 *
 * The language is a parameter, not read from the source: the document is rendered with that locale and that
 * date format (Czech `j. n. Y`, English `Y-m-d`). Durations are `H:MM` in both. No amount, rate or currency
 * is ever passed to the template, because the source holds none.
 */
final class WorkReportPdf
{
    use Localizable;

    /** Memory the renderer is given at least; 124 MB at 500 raw rows was measured, rows here are days x tasks. */
    private const string MEMORY_FLOOR = '256M';

    /**
     * The PDF bytes of the report, starting `%PDF-`.
     */
    public function render(WorkReportSource $source, InvoiceLanguage $language): string
    {
        $html = $this->html($source, $language);

        $this->raiseMemoryLimit();

        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * The standalone HTML of the report in the language, exactly what Dompdf is given.
     */
    public function html(WorkReportSource $source, InvoiceLanguage $language): string
    {
        return $this->withLocale($language->value, function () use ($source, $language): string {
            $billable = [];
            $billableSeconds = 0;
            $previous = null;

            foreach ($source->rows as $row) {
                if (! $row['billable']) {
                    continue;
                }

                // The date shows on the first row of a day only: the rows of one project and day follow each other.
                $key = ($row['project'] ?? '').'|'.$row['date'];

                $billable[] = [
                    'date' => $key === $previous ? '' : $this->date($row['date'], $language),
                    'task' => $row['task'],
                    'hours' => DurationFormat::hoursMinutes($row['seconds']),
                ];
                $billableSeconds += $row['seconds'];
                $previous = $key;
            }

            return view('pdf.work-report', [
                'language' => $language,
                'supplierLines' => $source->supplierLines,
                'clientName' => $source->clientName,
                'clientLines' => $source->clientLines,
                'from' => $this->date($source->from, $language),
                'to' => $this->date($source->to, $language),
                'projectName' => $source->projectName,
                'billable' => $billable,
                'billableTotal' => DurationFormat::hoursMinutes($billableSeconds),
                'empty' => $source->rows === [],
            ])->render();
        });
    }

    private function options(): Options
    {
        $fontCache = storage_path('framework/dompdf');
        File::ensureDirectoryExists($fontCache);

        return new Options([
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'chroot' => resource_path('views/pdf'),
            'fontCache' => $fontCache,
            'tempDir' => $fontCache,
        ]);
    }

    /**
     * Czech `j. n. Y` (1. 9. 2026), English `Y-m-d`, from a Prague day `Y-m-d`.
     */
    private function date(string $day, InvoiceLanguage $language): string
    {
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $day, 'UTC');

        return $date === null ? $day : $date->format($language === InvoiceLanguage::Czech ? 'j. n. Y' : 'Y-m-d');
    }

    /**
     * Raises the memory limit to the floor, only when it is lower and not unlimited.
     */
    private function raiseMemoryLimit(): void
    {
        $current = (int) ini_parse_quantity((string) ini_get('memory_limit'));

        if ($current !== -1 && $current < ini_parse_quantity(self::MEMORY_FLOOR)) {
            ini_set('memory_limit', self::MEMORY_FLOOR);
        }
    }
}
