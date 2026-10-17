<?php

declare(strict_types=1);

namespace App\Filament\Resources\ClientResource\Widgets;

use App\Domain\Clients\Models\Client;
use App\Domain\ExchangeRates\RateBook;
use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Reports\EntryAmounts;
use App\Domain\Reports\Queries\TimeReportReader;
use App\Domain\Reports\ReportFilters;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Domain\TimeTracking\Models\TimeEntry;
use App\Domain\TimeTracking\Support\DurationFormat;
use App\Domain\TimeTracking\Support\TimerClock;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Pages\TimeReportPage;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/**
 * "Přehled klienta" on the Admin client page (CL-03; D-04, D-20, ruling R-06-3, UI-SPEC Surface I): what is still to bill
 * and what is still to collect, three stats.
 *
 * - Nevyfakturováno: the unbilled billable time of the client over all time as H:MM, reserved time included (a draft
 *   does not take time out of the overview, R-27-1) with its share named, the amount in each currency of the entries
 *   (the client currency first) and in CZK, and the link to the time report of the client. The entries come from
 *   {@see TimeReportReader} and each is priced once by {@see EntryAmounts}, exactly like the report and the dashboard
 *   card, so the three can never disagree. Fixed-price hours carry the footnote and no amount.
 * - K úhradě: the issued, unpaid or partially paid documents (the overdue ones included) as a count and one amount per
 *   document currency (R-27-2), with the CZK total of {@see ReceivablesReader::summary()}.
 * - Po splatnosti: the overdue documents; danger above zero, "Nic po splatnosti" at zero.
 *
 * Amounts of different currencies are never added; only the CZK figure is a sum. Admin only: a Partner is refused at
 * boot, before anything is read. Registered on `ClientResource` and returned by `ViewClient`, not discovered at panel
 * level, so no dashboard without a record is offered the widget.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Unbilled time, amounts and receivables of a client are finance data; a Partner never sees them.')]
final class ClientOverviewStats extends StatsOverviewWidget
{
    use EnforcesWidgetAccessRule;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** No live ticking: the figures are read again every 60 seconds, while the widget is visible. */
    protected ?string $pollingInterval = 'visible.60s';

    #[Locked]
    public ?Model $record = null;

    /**
     * An entry saved or deleted anywhere: the figures are read again on the render that follows, so the listener has
     * nothing to do.
     */
    #[On('time-entry-saved')]
    #[On('time-entry-deleted')]
    public function refreshAfterTimeChange(): void {}

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $client = $this->record;

        if (! $client instanceof Client) {
            return [];
        }

        $today = ReceivablesReader::today();
        $reader = app(ReceivablesReader::class);
        $summary = $reader->summary($today, $client->id);
        $open = $reader->owedByCurrency($today, $client->id);
        $overdue = $reader->owedByCurrency($today, $client->id, InvoiceListTab::Overdue);

        return [
            $this->unbilledStat($client),
            $this->toPayStat($client, $summary, $open),
            $this->overdueStat($client, $summary, $overdue),
        ];
    }

    private function unbilledStat(Client $client): Stat
    {
        $unbilled = $this->unbilled($client);

        $lines = [
            $this->amountLine($client->currency, $unbilled['amounts'], $unbilled['czk'], $unbilled['czk_missing'] > 0, $unbilled['seconds'] === 0 || $unbilled['seconds'] > $unbilled['fixed']),
        ];

        if ($unbilled['reserved'] > 0) {
            $lines[] = e(__('invoices.client_panel.lines.in_drafts', ['duration' => DurationFormat::hoursMinutes($unbilled['reserved'])]));
        }

        if ($unbilled['fixed'] > 0) {
            $lines[] = e(__('invoices.client_panel.lines.fixed_price_note'));
        }

        $lines[] = '<a href="'.e($unbilled['report_url']).'" class="fi-link">'.e(__('invoices.client_panel.lines.show_in_report')).'</a>';

        return Stat::make(__('invoices.client_panel.stats.unbilled'), DurationFormat::hoursMinutes($unbilled['seconds']))
            ->description($this->lines($lines));
    }

    /**
     * @param  array{overdue: array{count: int, czk: Money}, unpaid: array{count: int, czk: Money}, awaiting_invoice: int}  $summary
     * @param  array<string, Money>  $open
     */
    private function toPayStat(Client $client, array $summary, array $open): Stat
    {
        $count = $summary['unpaid']['count'] + $summary['overdue']['count'];
        $czk = $summary['unpaid']['czk']->plus($summary['overdue']['czk']);

        $lines = [];

        if ($count > 0) {
            $lines[] = $this->amountLine($client->currency, $open, $czk, false, true);
            $lines[] = e(__('invoices.client_panel.lines.overdue_part', ['count' => $summary['overdue']['count']]));
        }

        return Stat::make(__('invoices.client_panel.stats.to_pay'), (string) $count)
            ->description($lines === [] ? null : $this->lines($lines));
    }

    /**
     * @param  array{overdue: array{count: int, czk: Money}, unpaid: array{count: int, czk: Money}, awaiting_invoice: int}  $summary
     * @param  array<string, Money>  $overdue
     */
    private function overdueStat(Client $client, array $summary, array $overdue): Stat
    {
        $count = $summary['overdue']['count'];

        if ($count === 0) {
            return Stat::make(__('invoices.client_panel.stats.overdue'), '0')
                ->description(__('invoices.client_panel.lines.nothing_overdue'));
        }

        return Stat::make(__('invoices.client_panel.stats.overdue'), (string) $count)
            ->description($this->lines([$this->amountLine($client->currency, $overdue, $summary['overdue']['czk'], false, true)]))
            ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
            ->descriptionColor('danger')
            ->color('danger');
    }

    /**
     * The unbilled billable time of the client over all time, priced by the report's own rule.
     *
     * @return array{seconds: int, reserved: int, fixed: int, amounts: array<string, Money>, czk: Money|null, czk_missing: int, report_url: string}
     */
    private function unbilled(Client $client): array
    {
        $now = TimerClock::now();
        $today = ReceivablesReader::today();

        $span = TimeEntry::query()
            ->where('time_entries.client_id', $client->id)
            ->toBase()
            ->selectRaw("MIN((time_entries.started_at AT TIME ZONE 'Europe/Prague')::date) AS first_day, MAX((time_entries.started_at AT TIME ZONE 'Europe/Prague')::date) AS last_day")
            ->first();

        // All time: from the first entry of the client to today, or to its last entry when that lies later.
        $first = $span?->first_day;
        $last = $span?->last_day;
        $from = is_string($first) ? $first : $today;
        $to = is_string($last) && $last > $today ? $last : $today;

        $filters = ReportFilters::period($from, $to, $client->id);
        $reader = app(TimeReportReader::class);
        $rates = RateBook::load(
            array_values(array_filter($reader->rateCurrencies($filters), static fn (string $currency): bool => $currency !== 'CZK')),
            $from,
            $to,
        );

        $seconds = 0;
        $reserved = 0;
        $fixed = 0;
        $amounts = [];
        $czk = null;
        $missing = 0;

        foreach ($reader->entries($filters, $now) as $entry) {
            if (! $entry->billable || $entry->billed || $entry->seconds <= 0) {
                continue;
            }

            $seconds += $entry->seconds;

            if ($entry->reserved) {
                $reserved += $entry->seconds;
            }

            if ($entry->billingType === 'fixed_price') {
                $fixed += $entry->seconds;
            }

            $priced = EntryAmounts::of($entry, $rates);

            if ($priced['amount'] !== null) {
                $currency = $priced['amount']->currency;
                $amounts[$currency] = isset($amounts[$currency]) ? $amounts[$currency]->plus($priced['amount']) : $priced['amount'];
            }

            if ($priced['czk'] !== null) {
                $czk = $czk === null ? $priced['czk'] : $czk->plus($priced['czk']);
            }

            if ($priced['czk_missing']) {
                $missing++;
            }
        }

        ksort($amounts);

        return [
            'seconds' => $seconds,
            'reserved' => $reserved,
            'fixed' => $fixed,
            'amounts' => $amounts,
            'czk' => $czk,
            'czk_missing' => $missing,
            'report_url' => TimeReportPage::getUrl($filters->toQuery()),
        ];
    }

    /**
     * One line of amounts: one per currency, the client currency first, then "v Kč: :czk" when any of them is not CZK.
     * Nothing is ever added across currencies; the CZK figure is the sum of the per-document conversions.
     *
     * @param  array<string, Money>  $amounts
     * @param  bool  $zeroWhenEmpty  show a zero amount in the client currency (instead of a dash) when there is none
     */
    private function amountLine(string $clientCurrency, array $amounts, ?Money $czk, bool $czkMissing, bool $zeroWhenEmpty): string
    {
        if ($amounts === []) {
            return e($zeroWhenEmpty ? Money::zero($clientCurrency)->format('cs') : (string) __('kokpit.time.empty_value'));
        }

        $ordered = isset($amounts[$clientCurrency]) ? [$clientCurrency => $amounts[$clientCurrency], ...$amounts] : $amounts;
        $parts = array_map(static fn (Money $amount): string => $amount->format('cs'), array_values($ordered));
        $line = implode(', ', $parts);

        if (array_keys($ordered) !== ['CZK']) {
            $line .= ' · '.__('invoices.dashboard.lines.czk', ['czk' => $czk === null ? __('kokpit.time.empty_value') : $czk->format('cs')]);

            if ($czkMissing) {
                $line .= ' ('.__('kokpit.dashboard.unbilled.missing_rate').')';
            }
        }

        return e($line);
    }

    /**
     * Lines of already escaped markup as one description.
     *
     * @param  list<string>  $lines
     */
    private function lines(array $lines): Htmlable
    {
        return new HtmlString(implode('<br>', $lines));
    }
}
