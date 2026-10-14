<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\ExchangeRates\RateBook;
use App\Domain\Reports\EntryAmounts;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Queries\TimesheetQuery;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Pages\TimeReportPage;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;

/**
 * "Nevyfakturováno tento měsíc" on the Admin dashboard: for each client with unbilled billable time in the current
 * Prague month, the hours, the amount in the client currency and the amount in CZK (RE-04; D-11, UI-SPEC E2).
 *
 * The figures are the report's own: the entries come from {@see TimeReportReader}, every entry is priced once by
 * {@see EntryAmounts}, and the rounded per-entry amounts are summed per client, so the CZK figure of a client equals
 * the "Nevyfakturováno" v Kč of the report for that client and month. The time of every user counts (it is about
 * billing, not about who worked), unlike the hours stats. Hours are always shown; an amount exists for hourly
 * entries only, so a client with fixed-price hours alone shows dashes and the "Pevná cena" badge. An entry without
 * a CNB rate leaves the CZK sum and raises the "Chybí kurz" marker; the sum is never shown as zero then.
 *
 * Rows are ordered by hours, most first, at most ten. The footer "Celkem" counts every client, the rows below the
 * limit included, like the totals of the report that cover more than the rendered rows.
 *
 * The widget re-renders on the four time events and every 60 seconds while it is visible; nothing ticks.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Unbilled time, rates and amounts are finance data; a Partner is refused at boot and never sees the widget.')]
final class UnbilledPerClient extends TableWidget
{
    use EnforcesWidgetAccessRule;

    /** The most rows the widget shows. */
    public const int LIMIT = 10;

    protected static ?int $sort = 30;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /**
     * The rows and the totals of the current render; computed once, read by the table and its footer.
     *
     * @var array{rows: list<array{client_id: string, client_name: string, currency: string, seconds: int, amount: Money|null, czk: Money|null, czk_missing: bool, fixed: bool}>, totals: array{seconds: int, czk: Money|null, czk_missing: int, fixed: bool}}|null
     */
    private ?array $computed = null;

    /**
     * A timer started or stopped, an entry saved or deleted anywhere: the table is read again on the render that
     * follows, so the listener has nothing to do.
     */
    #[On('timer-started')]
    #[On('timer-stopped')]
    #[On('time-entry-saved')]
    #[On('time-entry-deleted')]
    public function refreshAfterTimeChange(): void
    {
        $this->computed = null;
    }

    /**
     * The clients with unbilled billable time this month, most hours first, at most {@see self::LIMIT}.
     *
     * @return list<array{client_id: string, client_name: string, currency: string, seconds: int, amount: Money|null, czk: Money|null, czk_missing: bool, fixed: bool}>
     */
    public function rows(): array
    {
        return array_slice($this->compute()['rows'], 0, self::LIMIT);
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('kokpit.dashboard.unbilled.heading'))
            ->headerActions([
                Action::make('report')
                    ->label(__('kokpit.dashboard.unbilled.report_link'))
                    ->link()
                    ->url(static fn (): string => TimeReportPage::getUrl(['period' => 'this_month'])),
            ])
            ->records(fn (): array => $this->rows())
            ->columns([
                ViewColumn::make('client_name')
                    ->label(__('kokpit.dashboard.unbilled.columns.client'))
                    ->view('filament.widgets.unbilled-per-client-cell')
                    ->viewData(['kind' => 'client']),
                TextColumn::make('hours')
                    ->label(__('kokpit.dashboard.unbilled.columns.hours'))
                    ->state(static fn (array $record): string => DurationFormat::hoursMinutes($record['seconds']))
                    ->alignment(Alignment::End)
                    ->extraCellAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
                TextColumn::make('amount')
                    ->label(__('kokpit.dashboard.unbilled.columns.amount'))
                    ->state(static fn (array $record): string => $record['amount']?->format('cs') ?? (string) __('kokpit.time.empty_value'))
                    ->alignment(Alignment::End)
                    ->extraCellAttributes(['style' => 'font-variant-numeric: tabular-nums;']),
                ViewColumn::make('czk')
                    ->label(__('kokpit.dashboard.unbilled.columns.amount_czk'))
                    ->view('filament.widgets.unbilled-per-client-cell')
                    ->viewData(['kind' => 'czk'])
                    ->alignment(Alignment::End),
            ])
            ->recordUrl(static fn (array $record): string => TimeReportPage::getUrl(['client' => $record['client_id'], 'period' => 'this_month']))
            ->paginated(false)
            ->poll('visible.60s')
            ->contentFooter(fn (): View => $this->footer())
            ->emptyStateIcon(Heroicon::OutlinedInbox)
            ->emptyStateHeading(__('kokpit.dashboard.unbilled.empty_heading'))
            ->emptyStateDescription(__('kokpit.dashboard.unbilled.empty_description'));
    }

    /**
     * The footer cells: "Celkem" over every client, the hours and the CZK total, the client-currency cell blank
     * because currencies mix, then the notes that apply.
     */
    private function footer(): View
    {
        $totals = $this->compute()['totals'];

        return view('filament.widgets.unbilled-per-client-footer', [
            'hours' => DurationFormat::hoursMinutes($totals['seconds']),
            'czk' => $totals['czk']?->format('cs'),
            'czkMissing' => $totals['czk_missing'],
            'fixed' => $totals['fixed'],
        ]);
    }

    /**
     * Reads the entries of the current Prague month once and sums the unbilled billable ones per client.
     *
     * @return array{rows: list<array{client_id: string, client_name: string, currency: string, seconds: int, amount: Money|null, czk: Money|null, czk_missing: bool, fixed: bool}>, totals: array{seconds: int, czk: Money|null, czk_missing: int, fixed: bool}}
     */
    private function compute(): array
    {
        if ($this->computed !== null) {
            return $this->computed;
        }

        $now = TimerClock::now();
        $today = app(TimesheetQuery::class)->today($now);
        $month = CarbonImmutable::createFromFormat('!Y-m-d', $today, 'UTC');
        assert($month instanceof CarbonImmutable);

        $from = $month->startOfMonth()->format('Y-m-d');
        $to = $month->endOfMonth()->format('Y-m-d');

        $filters = ReportFilters::period($from, $to);
        $reader = app(TimeReportReader::class);
        $rates = RateBook::load(
            array_values(array_filter($reader->rateCurrencies($filters), static fn (string $currency): bool => $currency !== 'CZK')),
            $from,
            $to,
        );

        $clients = [];
        $totalSeconds = 0;
        $totalCzk = null;
        $totalMissing = 0;
        $totalFixed = false;

        foreach ($reader->entries($filters, $now) as $entry) {
            if (! $entry->billable || $entry->billed || $entry->seconds <= 0) {
                continue;
            }

            $priced = EntryAmounts::of($entry, $rates);

            $client = $clients[$entry->clientId] ??= [
                'client_id' => $entry->clientId,
                'client_name' => $entry->clientName,
                'currency' => $entry->clientCurrency,
                'seconds' => 0,
                'amount' => null,
                'czk' => null,
                'czk_missing' => false,
                'fixed' => false,
            ];

            $client['seconds'] += $entry->seconds;
            $totalSeconds += $entry->seconds;

            if ($priced['amount'] !== null) {
                $client['amount'] = $client['amount'] === null ? $priced['amount'] : $client['amount']->plus($priced['amount']);
            }

            if ($priced['czk'] !== null) {
                $client['czk'] = $client['czk'] === null ? $priced['czk'] : $client['czk']->plus($priced['czk']);
                $totalCzk = $totalCzk === null ? $priced['czk'] : $totalCzk->plus($priced['czk']);
            }

            if ($priced['czk_missing']) {
                $client['czk_missing'] = true;
                $totalMissing++;
            }

            if ($entry->billingType === 'fixed_price') {
                $client['fixed'] = true;
                $totalFixed = true;
            }

            $clients[$entry->clientId] = $client;
        }

        $rows = array_values($clients);

        // Most hours first; equal hours by name, then by id, so the order is the same on every render.
        usort($rows, static fn (array $a, array $b): int => [$b['seconds'], mb_strtolower($a['client_name']), $a['client_id']]
            <=> [$a['seconds'], mb_strtolower($b['client_name']), $b['client_id']]);

        return $this->computed = [
            'rows' => $rows,
            'totals' => ['seconds' => $totalSeconds, 'czk' => $totalCzk, 'czk_missing' => $totalMissing, 'fixed' => $totalFixed],
        ];
    }
}
