<?php

declare(strict_types=1);

namespace App\Domain\Reports\Jobs;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\RateBook;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Projects\Models\Project;
use App\Domain\Reports\EntryAmounts;
use App\Domain\Reports\Enums\ReportExportFormat;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Exports\SpreadsheetCells;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Notifications\ReportExportFailedNotification;
use App\Domain\Reports\Notifications\ReportExportReadyNotification;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportEntry;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\ReportExportResource;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\DateTimeCell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Comment\Comment;
use OpenSpout\Common\Entity\Comment\TextRun;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;

/**
 * Builds the CSV or XLSX file of a report export in the background and tells the Admin where it is (RE-03, D-09;
 * UI-SPEC C3 and Surface H).
 *
 * The record is read in the system context (the job middleware), skipped when it is no longer pending, and its
 * stored filters are validated again like any client-controlled value. The entries come lazily from the same
 * reader the report page uses and are priced by the same `EntryAmounts`, so the file says what the report says
 * (research Pattern 5). They stream through the OpenSpout writer into a temporary file, which is then streamed
 * to the default (private) disk under the server-generated path of the record; memory does not grow with the
 * number of entries (research Pitfall 16). The timeout stays under the 300 s of the Horizon supervisor.
 *
 * In the CSV numbers are written as text from exact values: whole seconds, hours from integer arithmetic to four
 * decimals (half up) and amounts from `Money::toMajor()`, each with the decimal sign of the configuration, never
 * from a float. Every text goes through {@see SpreadsheetCells::text()}.
 *
 * The XLSX workbook holds the same entries as real numeric and date cells on the sheet "Záznamy" (hours
 * `0.0000`, amounts `#,##0.00`, a bold frozen header row, an auto filter and column widths) and, on the sheet
 * "Souhrn", the summary of the same filters. The summary is accumulated while the entries stream, so the reader
 * is read once; its figures are the sums of the very amounts written on the entry rows. A number is built from
 * the exact string only at the cell ({@see SpreadsheetCells::number()}); no cell of either sheet is a formula.
 *
 * The record is marked ready and the owner notified in one transaction, after the file is stored (pattern of
 * `NotifyLongRunningTimers`). A final failure is handled by {@see self::failed()}; the Phase 3 failed-job
 * alert reports the technical side on its own.
 */
#[Idempotent(how: 'identified by the export id; a record that is not pending is skipped and the file path is the export id, so a rerun cannot duplicate a file')]
#[Timeout(240)]
final class BuildReportExport extends KokpitJob
{
    /**
     * The keys of the twelve columns in file order; the header texts are `kokpit.exports.file.columns.<key>`.
     */
    public const array COLUMNS = ['date', 'client', 'project', 'task', 'user', 'seconds', 'hours', 'billable', 'billed', 'amount', 'currency', 'amount_czk'];

    /**
     * The widths of the twelve entry columns of the workbook, in characters, in the order of the columns.
     *
     * @var list<float>
     */
    private const array ENTRY_COLUMN_WIDTHS = [12.0, 28.0, 28.0, 36.0, 24.0, 10.0, 10.0, 16.0, 16.0, 14.0, 8.0, 14.0];

    /**
     * The widths of the three columns of the summary sheet: label, value, currency.
     *
     * @var list<float>
     */
    private const array SUMMARY_COLUMN_WIDTHS = [24.0, 40.0, 10.0];

    /** The time zone the dates of the notices are shown in. */
    private const string DISPLAY_TIMEZONE = 'Europe/Prague';

    /** Hours are written with this many decimals. */
    private const int HOURS_DECIMALS = 4;

    public function __construct(public readonly string $exportId) {}

    public function handle(TimeReportReader $reader): void
    {
        $export = ReportExport::query()->find($this->exportId);

        // Pruned in the meantime, or already built by an earlier run: nothing to do.
        if ($export === null || $export->status !== ReportExportStatus::Pending) {
            return;
        }

        $filters = ReportFilters::fromStored($export->filters);
        $now = TimerClock::now();
        $rates = RateBook::load(
            array_values(array_filter($reader->rateCurrencies($filters), static fn (string $currency): bool => $currency !== 'CZK')),
            $filters->from,
            $filters->to,
        );

        $temporary = tempnam(sys_get_temp_dir(), 'kokpit-export-');

        if ($temporary === false) {
            throw new RuntimeException('A temporary file for the export could not be created.');
        }

        try {
            $count = match ($export->format) {
                ReportExportFormat::Csv => $this->writeCsv($temporary, $reader, $filters, $now, $rates),
                ReportExportFormat::Xlsx => $this->writeXlsx($temporary, $reader, $filters, $now, $rates),
            };
            $this->store($export, $temporary);
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }

        DB::transaction(function () use ($export, $filters, $count): void {
            $locked = ReportExport::query()->whereKey($export->getKey())->lockForUpdate()->first();

            if ($locked === null || $locked->status !== ReportExportStatus::Pending) {
                return;
            }

            $locked->markReady($locked->storagePath(), $count, TimerClock::now());

            User::query()->find($locked->user_id)?->notifyNow(new ReportExportReadyNotification(
                format: $locked->format->getLabel(),
                period: self::periodText($filters->from, $filters->to),
                availableUntil: $locked->expires_at->setTimezone(self::DISPLAY_TIMEZONE)->format(LocalisationServiceProvider::DATE_FORMAT),
                url: ReportExportResource::getUrl('index'),
            ));
        });
    }

    /**
     * The export failed for good: the record is marked failed and the owner is told in the bell. A record that
     * is gone or no longer pending is left alone. The job middleware does not wrap this method, so the system
     * context is entered here.
     */
    public function failed(Throwable $exception): void
    {
        app(PartnerContext::class)->runAsSystem(function (): void {
            $export = ReportExport::query()->find($this->exportId);

            if ($export === null || $export->status !== ReportExportStatus::Pending) {
                return;
            }

            DB::transaction(function () use ($export): void {
                $export->markFailed();

                User::query()->find($export->user_id)?->notifyNow(new ReportExportFailedNotification(
                    period: self::storedPeriodText($export),
                    url: ReportExportResource::getUrl('index'),
                ));
            });
        });
    }

    /**
     * Streams the header and the entries of the period into the file and returns the number of entry rows.
     */
    private function writeCsv(string $file, TimeReportReader $reader, ReportFilters $filters, CarbonImmutable $now, RateBook $rates): int
    {
        $options = new Options;
        $options->FIELD_DELIMITER = self::delimiter();
        $options->SHOULD_ADD_BOM = true;

        $writer = new Writer($options);
        $writer->openToFile($file);

        $count = 0;

        try {
            $writer->addRow(new Row(array_map(
                static fn (string $key): StringCell => SpreadsheetCells::text((string) __('kokpit.exports.file.columns.'.$key)),
                self::COLUMNS,
            )));

            foreach ($reader->entries($filters, $now) as $entry) {
                $writer->addRow(new Row($this->entryCells($entry, EntryAmounts::of($entry, $rates))));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return $count;
    }

    /**
     * Streams the entry sheet and then the summary sheet into the workbook and returns the number of entry rows.
     * The summary is accumulated in the same pass over the entries.
     */
    private function writeXlsx(string $file, TimeReportReader $reader, ReportFilters $filters, CarbonImmutable $now, RateBook $rates): int
    {
        $styles = self::styles();
        $writer = new XlsxWriter;
        $writer->openToFile($file);

        $count = 0;

        try {
            $sheet = $writer->getCurrentSheet();
            $sheet->setName((string) __('kokpit.exports.file.sheets.entries'));
            $sheet->setSheetView((new SheetView)->setFreezeRow(2));

            foreach (self::ENTRY_COLUMN_WIDTHS as $index => $width) {
                $sheet->setColumnWidth($width, $index + 1);
            }

            $writer->addRow(new Row(array_map(
                static fn (string $key): StringCell => SpreadsheetCells::text((string) __('kokpit.exports.file.columns.'.$key)),
                self::COLUMNS,
            ), $styles['bold']));

            $hours = ['worked' => 0, 'billable' => 0, 'non_billable' => 0, 'billed' => 0, 'unbilled' => 0, 'fixed' => 0, 'czk_missing' => 0];
            $amounts = [];
            $czk = null;

            foreach ($reader->entries($filters, $now) as $entry) {
                $priced = EntryAmounts::of($entry, $rates);
                $writer->addRow(new Row($this->xlsxEntryCells($entry, $priced, $styles)));
                $count++;

                $hours['worked'] += $entry->seconds;
                $hours[$entry->billable ? 'billable' : 'non_billable'] += $entry->seconds;

                if ($entry->billable) {
                    $hours[$entry->billed ? 'billed' : 'unbilled'] += $entry->seconds;

                    if ($entry->billingType === 'fixed_price') {
                        $hours['fixed'] += $entry->seconds;
                    }
                }

                if ($priced['czk_missing']) {
                    $hours['czk_missing']++;
                }

                if ($priced['amount'] !== null) {
                    $amounts[$priced['amount']->currency] = isset($amounts[$priced['amount']->currency])
                        ? $amounts[$priced['amount']->currency]->plus($priced['amount'])
                        : $priced['amount'];
                }

                if ($priced['czk'] !== null) {
                    $czk = $czk === null ? $priced['czk'] : $czk->plus($priced['czk']);
                }
            }

            // The filter spans the header and every entry row, so it still has a range for an empty period.
            $sheet->setAutoFilter(new AutoFilter(0, 1, count(self::COLUMNS) - 1, $count + 1));

            ksort($amounts);
            $this->writeSummarySheet($writer, $filters, $hours, $amounts, $czk, $styles);
        } finally {
            $writer->close();
        }

        return $count;
    }

    /**
     * The sheet "Souhrn": a label in the first column, the value in the second and, for an amount, its currency
     * in the third. Hours are numeric hours (`0.0000`), amounts numeric (`#,##0.00`).
     *
     * @param  array{worked: int, billable: int, non_billable: int, billed: int, unbilled: int, fixed: int, czk_missing: int}  $hours  whole seconds, and the number of entries without a CNB rate
     * @param  array<string, Money>  $amounts  the billable amounts per currency
     * @param  array{bold: Style, date: Style, hours: Style, amount: Style}  $styles
     */
    private function writeSummarySheet(XlsxWriter $writer, ReportFilters $filters, array $hours, array $amounts, ?Money $czk, array $styles): void
    {
        $sheet = $writer->addNewSheetAndMakeItCurrent();
        $sheet->setName((string) __('kokpit.exports.file.sheets.summary'));

        foreach (self::SUMMARY_COLUMN_WIDTHS as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $label = static function (string $key) use ($styles): StringCell {
            $cell = SpreadsheetCells::text((string) __('kokpit.exports.file.summary_rows.'.$key));
            $cell->setStyle($styles['bold']);

            return $cell;
        };

        $clientName = $filters->clientId === null ? null : Client::query()->withTrashed()->whereKey($filters->clientId)->value('name');
        $projectName = $filters->projectId === null ? null : Project::query()->withTrashed()->whereKey($filters->projectId)->value('name');

        $writer->addRow(new Row([$label('client'), SpreadsheetCells::text(is_string($clientName) ? $clientName : (string) __('kokpit.exports.summary.all_clients'))]));
        $writer->addRow(new Row([$label('project'), SpreadsheetCells::text(is_string($projectName) ? $projectName : (string) __('kokpit.exports.summary.all_projects'))]));
        $writer->addRow(new Row([$label('period'), SpreadsheetCells::text(self::periodText($filters->from, $filters->to))]));

        foreach (['worked', 'billable', 'non_billable', 'billed', 'unbilled'] as $key) {
            $writer->addRow(new Row([$label($key), SpreadsheetCells::number(self::hours($hours[$key]), $styles['hours'])]));
        }

        foreach ($amounts === [] ? [null] : array_values($amounts) as $amount) {
            $writer->addRow(new Row([
                $label('amounts'),
                SpreadsheetCells::number($amount === null ? '0.00' : $amount->toMajor(), $styles['amount']),
                SpreadsheetCells::text($amount?->currency),
            ]));
        }

        // The CZK total is never a silent zero: with entries that have no CNB rate it is a lower bound, and an
        // empty cell when no entry could be converted at all; either way the cell says why.
        $total = $czk === null && $hours['czk_missing'] > 0
            ? new EmptyCell(null, null)
            : SpreadsheetCells::number($czk === null ? '0.00' : $czk->toMajor(), $styles['amount']);
        $total->comment = $hours['czk_missing'] > 0 ? self::missingRateComment() : null;
        $writer->addRow(new Row([$label('amount_czk'), $total]));

        $notes = [];

        if ($hours['fixed'] > 0) {
            $notes[] = (string) __('kokpit.exports.file.notes.fixed_price');
        }

        if ($hours['czk_missing'] > 0) {
            $notes[] = (string) __('kokpit.exports.file.notes.missing_rate', ['count' => $hours['czk_missing']]);
        }

        if ($notes !== []) {
            $writer->addRow(new Row([$label('note'), SpreadsheetCells::text(implode(' ', $notes))]));
        }
    }

    /**
     * The twelve cells of one entry for the workbook, in the order of {@see self::COLUMNS}: the day as a date
     * cell, the seconds, the hours and the amounts as numeric cells, every text as an explicit string cell and
     * the cells that have no value, as in the CSV, really empty.
     *
     * @param  array{amount: Money|null, czk: Money|null, czk_missing: bool}  $priced
     * @param  array{bold: Style, date: Style, hours: Style, amount: Style}  $styles
     * @return list<Cell>
     */
    private function xlsxEntryCells(ReportEntry $entry, array $priced, array $styles): array
    {
        $yes = (string) __('kokpit.exports.file.yes');
        $no = (string) __('kokpit.exports.file.no');

        return [
            new DateTimeCell(new DateTimeImmutable($entry->workDate), $styles['date']),
            self::xlsxText($entry->clientName),
            self::xlsxText($entry->projectName),
            self::xlsxText($entry->taskTitle),
            self::xlsxText($entry->userName),
            SpreadsheetCells::integer($entry->seconds),
            SpreadsheetCells::number(self::hours($entry->seconds), $styles['hours']),
            SpreadsheetCells::text($entry->billable ? $yes : $no),
            SpreadsheetCells::text($entry->billed ? $yes : $no),
            $priced['amount'] === null ? new EmptyCell(null, null) : SpreadsheetCells::number($priced['amount']->toMajor(), $styles['amount']),
            self::xlsxText($priced['amount']?->currency),
            self::xlsxCzkCell($priced, $styles),
        ];
    }

    /**
     * The CZK amount cell of an entry: numeric, or empty when there is no amount, and then, when the amount
     * lacks only the CNB rate, carrying the comment "Chybí kurz ČNB".
     *
     * @param  array{amount: Money|null, czk: Money|null, czk_missing: bool}  $priced
     * @param  array{bold: Style, date: Style, hours: Style, amount: Style}  $styles
     */
    private static function xlsxCzkCell(array $priced, array $styles): Cell
    {
        if ($priced['czk'] !== null) {
            return SpreadsheetCells::number($priced['czk']->toMajor(), $styles['amount']);
        }

        $cell = new EmptyCell(null, null);
        $cell->comment = $priced['czk_missing'] ? self::missingRateComment() : null;

        return $cell;
    }

    /**
     * The comment of a CZK cell that has no CNB rate behind it.
     */
    private static function missingRateComment(): Comment
    {
        $comment = new Comment;
        $comment->addTextRun(new TextRun((string) __('kokpit.exports.file.missing_rate_comment')));

        return $comment;
    }

    /**
     * A text cell of the workbook: spreadsheet-safe, and a missing or empty text a really empty cell.
     */
    private static function xlsxText(?string $value): Cell
    {
        return $value === null || $value === '' ? new EmptyCell(null, null) : SpreadsheetCells::text($value);
    }

    /**
     * The styles of the workbook: the bold header and label, the date and the two number formats.
     *
     * @return array{bold: Style, date: Style, hours: Style, amount: Style}
     */
    private static function styles(): array
    {
        return [
            'bold' => (new Style)->setFontBold(),
            'date' => (new Style)->setFormat('d.m.yyyy'),
            'hours' => (new Style)->setFormat('0.0000'),
            'amount' => (new Style)->setFormat('#,##0.00'),
        ];
    }

    /**
     * The twelve cells of one entry, in the order of {@see self::COLUMNS}. A fixed-price or non-billable entry has
     * empty amount cells and an entry without a CNB rate an empty CZK cell: never a zero.
     *
     * @param  array{amount: Money|null, czk: Money|null, czk_missing: bool}  $priced
     * @return list<Cell>
     */
    private function entryCells(ReportEntry $entry, array $priced): array
    {
        $yes = (string) __('kokpit.exports.file.yes');
        $no = (string) __('kokpit.exports.file.no');

        return [
            SpreadsheetCells::text($entry->workDate),
            SpreadsheetCells::text($entry->clientName),
            SpreadsheetCells::text($entry->projectName),
            SpreadsheetCells::text($entry->taskTitle),
            SpreadsheetCells::text($entry->userName),
            SpreadsheetCells::integer($entry->seconds),
            SpreadsheetCells::decimal(self::hours($entry->seconds)),
            SpreadsheetCells::text($entry->billable ? $yes : $no),
            SpreadsheetCells::text($entry->billed ? $yes : $no),
            $priced['amount'] === null ? SpreadsheetCells::text(null) : SpreadsheetCells::decimal($priced['amount']->toMajor()),
            SpreadsheetCells::text($priced['amount']?->currency),
            $priced['czk'] === null ? SpreadsheetCells::text(null) : SpreadsheetCells::decimal($priced['czk']->toMajor()),
        ];
    }

    /**
     * Whole seconds as hours with four decimals, rounded half up, from integer arithmetic only: `61` is `0.0169`.
     */
    private static function hours(int $seconds): string
    {
        $scale = 10 ** self::HOURS_DECIMALS;
        $scaled = intdiv(max(0, $seconds) * $scale * 2 + 3600, 7200);

        return intdiv($scaled, $scale).'.'.str_pad((string) ($scaled % $scale), self::HOURS_DECIMALS, '0', STR_PAD_LEFT);
    }

    /**
     * Streams the finished file to the default (private) disk under the server-generated path of the record.
     */
    private function store(ReportExport $export, string $file): void
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The export file could not be read back.');
        }

        try {
            $stored = Storage::disk()->put($export->storagePath(), $stream);
        } finally {
            fclose($stream);
        }

        if ($stored === false) {
            throw new RuntimeException('The export file could not be stored.');
        }
    }

    /**
     * The column delimiter of the configuration: a single character, the semicolon of Czech Excel by default.
     */
    private static function delimiter(): string
    {
        $delimiter = config('kokpit.exports.csv.delimiter', ';');

        return is_string($delimiter) && strlen($delimiter) === 1 ? $delimiter : ';';
    }

    /**
     * The period of the stored filters as `from – to`; the creation day when the stored days are not valid, so
     * a record with damaged filters can still be reported.
     */
    private static function storedPeriodText(ReportExport $export): string
    {
        $from = PragueDay::day($export->filters['from'] ?? null);
        $to = PragueDay::day($export->filters['to'] ?? null);

        if ($from === null || $to === null) {
            $created = ($export->created_at ?? TimerClock::now())->setTimezone(self::DISPLAY_TIMEZONE)->format(LocalisationServiceProvider::DATE_FORMAT);

            return $created.' – '.$created;
        }

        return self::periodText($from, $to);
    }

    /**
     * Two Prague days as the notices and the summary show them: `1. 9. 2026 – 30. 9. 2026`.
     */
    private static function periodText(string $from, string $to): string
    {
        $format = static fn (string $day): string => CarbonImmutable::parse($day)->format(LocalisationServiceProvider::DATE_FORMAT);

        return (string) __('kokpit.report.range', ['from' => $format($from), 'to' => $format($to)]);
    }
}
