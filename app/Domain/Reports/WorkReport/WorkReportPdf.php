<?php

declare(strict_types=1);

namespace App\Domain\Reports\WorkReport;

use App\Domain\Clients\Enums\InvoiceLanguage;
use App\Domain\TimeTracking\Support\DurationFormat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Dompdf\Dompdf;
use Dompdf\Options;
use Filament\Support\Facades\FilamentTimezone;
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
     * The PDF bytes of the report, starting `%PDF-`. The footer date is the day of `$generatedAt` in the panel
     * timezone, now when not given.
     */
    public function render(WorkReportSource $source, InvoiceLanguage $language, ?CarbonInterface $generatedAt = null): string
    {
        $html = $this->html($source, $language);
        $decoration = $this->decoration($source, $language, $generatedAt ?? CarbonImmutable::now());

        $this->raiseMemoryLimit();

        $dompdf = new Dompdf($this->options());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // The page count is known only after the layout. The decoration is a page script that receives the page
        // number and the page count (what the canvas placeholders PAGE_NUM and PAGE_COUNT stand for), because
        // the right-aligned label needs its own text width.
        $dompdf->getCanvas()->page_script($decoration);

        return (string) $dompdf->output();
    }

    /**
     * The standalone HTML of the report in the language, exactly what Dompdf is given.
     */
    public function html(WorkReportSource $source, InvoiceLanguage $language): string
    {
        return $this->withLocale($language->value, function () use ($source, $language): string {
            // A project row precedes its days only when the report holds several projects (UI-SPEC Surface D).
            $projectRows = count($source->projectTotals()) > 1;
            $sections = [];

            foreach ([true, false] as $billable) {
                if ($source->section($billable) === []) {
                    continue;
                }

                $sections[] = [
                    'heading' => __($billable ? 'work_report.billable' : 'work_report.non_billable'),
                    'totalLabel' => __($billable ? 'work_report.total_billable' : 'work_report.total_non_billable'),
                    'total' => DurationFormat::hoursMinutes($source->sectionSeconds($billable)),
                    'lines' => $this->lines($source->section($billable), $projectRows, $language),
                ];
            }

            $projects = [];

            if ($projectRows) {
                foreach ($source->projectTotals() as $total) {
                    $projects[] = [
                        'name' => $total['project'] ?? __('work_report.no_project'),
                        'hours' => DurationFormat::hoursMinutes($total['seconds']),
                    ];
                }
            }

            return view('pdf.work-report', [
                'language' => $language,
                'supplierLines' => $source->supplierLines,
                'clientName' => $source->clientName,
                'clientLines' => $source->clientLines,
                'from' => $this->date($source->from, $language),
                'to' => $this->date($source->to, $language),
                'projectName' => $source->projectName,
                'sections' => $sections,
                'empty' => $source->rows === [],
                'daysWorked' => $source->daysWorked(),
                'totalHours' => DurationFormat::hoursMinutes($source->totalSeconds()),
                'projects' => $projects,
            ])->render();
        });
    }

    /**
     * The printed lines of one section: an optional project row, then per day its task rows (the date on the
     * first one only) and the day's subtotal.
     *
     * @param  list<array{billable: bool, project: ?string, date: string, task: ?string, seconds: int}>  $rows
     * @return list<array{type: string, name?: string, date?: string, task?: ?string, hours?: string}>
     */
    private function lines(array $rows, bool $projectRows, InvoiceLanguage $language): array
    {
        $lines = [];
        $group = null;
        $project = null;
        $daySeconds = 0;

        foreach ($rows as $row) {
            // One project and one Prague day make a group; the rows of a group follow each other.
            $key = ($row['project'] ?? "\0").'|'.$row['date'];

            if ($key !== $group) {
                if ($group !== null) {
                    $lines[] = ['type' => 'day', 'hours' => DurationFormat::hoursMinutes($daySeconds)];
                }

                if ($projectRows && ($group === null || $row['project'] !== $project)) {
                    $lines[] = ['type' => 'project', 'name' => $row['project'] ?? __('work_report.no_project')];
                }

                $lines[] = ['type' => 'task', 'date' => $this->date($row['date'], $language), 'task' => $row['task'], 'hours' => DurationFormat::hoursMinutes($row['seconds'])];
                $group = $key;
                $project = $row['project'];
                $daySeconds = $row['seconds'];

                continue;
            }

            $lines[] = ['type' => 'task', 'date' => '', 'task' => $row['task'], 'hours' => DurationFormat::hoursMinutes($row['seconds'])];
            $daySeconds += $row['seconds'];
        }

        if ($group !== null) {
            $lines[] = ['type' => 'day', 'hours' => DurationFormat::hoursMinutes($daySeconds)];
        }

        return $lines;
    }

    /**
     * The footer and running header texts, resolved inside the client's language.
     */
    private function decoration(WorkReportSource $source, InvoiceLanguage $language, CarbonInterface $generatedAt): WorkReportPageDecoration
    {
        return $this->withLocale($language->value, fn (): WorkReportPageDecoration => new WorkReportPageDecoration(
            title: __('work_report.title'),
            client: $source->clientName,
            period: $this->date($source->from, $language).' – '.$this->date($source->to, $language),
            generated: __('work_report.generated', ['date' => $this->date($generatedAt->toImmutable()->setTimezone(FilamentTimezone::get())->format('Y-m-d'), $language)]),
            pageTemplate: __('work_report.page', ['n' => '{n}', 'total' => '{total}']),
        ));
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
     * The memory limit the renderer needs, or null when `$current` already suffices: lower than the floor and
     * not unlimited (`-1`) means the floor, anything else stays as it is.
     */
    public static function raisedMemoryLimit(string $current): ?string
    {
        $bytes = (int) ini_parse_quantity($current);

        return $bytes !== -1 && $bytes < ini_parse_quantity(self::MEMORY_FLOOR) ? self::MEMORY_FLOOR : null;
    }

    /**
     * Raises the memory limit to the floor, only when it is lower and not unlimited.
     */
    private function raiseMemoryLimit(): void
    {
        $raised = self::raisedMemoryLimit((string) ini_get('memory_limit'));

        if ($raised !== null) {
            ini_set('memory_limit', $raised);
        }
    }
}
