<?php

declare(strict_types=1);

namespace App\Domain\Reports\Jobs;

use App\Domain\ExchangeRates\RateBook;
use App\Domain\Identity\Models\User;
use App\Domain\Operations\Jobs\Idempotent;
use App\Domain\Operations\Jobs\KokpitJob;
use App\Domain\Reports\EntryAmounts;
use App\Domain\Reports\Enums\ReportExportStatus;
use App\Domain\Reports\Exports\SpreadsheetCells;
use App\Domain\Reports\Models\ReportExport;
use App\Domain\Reports\Notifications\ReportExportFailedNotification;
use App\Domain\Reports\Notifications\ReportExportReadyNotification;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportEntry;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\TimeTracking\Support\PragueDay;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Resources\ReportExportResource;
use App\Providers\LocalisationServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Options;
use OpenSpout\Writer\CSV\Writer;
use RuntimeException;
use Throwable;

/**
 * Builds the CSV file of a report export in the background and tells the Admin where it is (RE-03, D-09; UI-SPEC
 * C3 and Surface H).
 *
 * The record is read in the system context (the job middleware), skipped when it is no longer pending, and its
 * stored filters are validated again like any client-controlled value. The entries come lazily from the same
 * reader the report page uses and are priced by the same `EntryAmounts`, so the file says what the report says
 * (research Pattern 5). They stream through the OpenSpout writer into a temporary file, which is then streamed
 * to the default (private) disk under the server-generated path of the record; memory does not grow with the
 * number of entries (research Pitfall 16). The timeout stays under the 300 s of the Horizon supervisor.
 *
 * Numbers are written as text from exact values: whole seconds, hours from integer arithmetic to four decimals
 * (half up) and amounts from `Money::toMajor()`, each with the decimal sign of the configuration, never from a
 * float. Every text goes through {@see SpreadsheetCells::text()}.
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
            $count = $this->writeCsv($temporary, $reader, $filters, $now, $rates);
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
                $writer->addRow(new Row($this->entryCells($entry, $rates)));
                $count++;
            }
        } finally {
            $writer->close();
        }

        return $count;
    }

    /**
     * The twelve cells of one entry, in the order of {@see self::COLUMNS}. A fixed-price or non-billable entry has
     * empty amount cells and an entry without a CNB rate an empty CZK cell: never a zero.
     *
     * @return list<Cell>
     */
    private function entryCells(ReportEntry $entry, RateBook $rates): array
    {
        $priced = EntryAmounts::of($entry, $rates);
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
