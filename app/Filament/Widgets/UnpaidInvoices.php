<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Invoicing\Enums\InvoiceKind;
use App\Domain\Invoicing\Enums\InvoiceListTab;
use App\Domain\Invoicing\Enums\InvoiceState;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Queries\ReceivablesReader;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Money\Money;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Resources\InvoiceResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * "Nezaplacené faktury" on the Admin dashboard: what is overdue, what is still due and which paid advances wait for
 * their invoice (IN-05; D-18, D-20, ruling R-28-1, UI-SPEC Surface J).
 *
 * - Po splatnosti: the count of overdue documents with their CZK sum, in danger above zero, "Nic po splatnosti" in
 *   success at zero;
 * - K úhradě: the count and the CZK sum of the issued documents that are unpaid and not yet late;
 * - Zálohy k vyfakturování: the paid proformas no invoice was made from, only above zero, in danger.
 *
 * Every figure is {@see ReceivablesReader::summary()}, the reader behind the invoice list tabs and the client panels,
 * so the dashboard and the overdue overview cannot disagree. Each stat links to its list tab. The card is not shown
 * (no tile, no placeholder) before the first issued invoice or proforma exists.
 *
 * Collapsible like the other Admin cards, under its own preference key; collapsed it keeps the overdue count in the
 * header (R-28-2). Admin only: a Partner is refused at boot, before anything is read, and the Admin's dashboard is the
 * only list that offers the widget.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Receivables, amounts and due dates are finance data; a Partner is refused at boot and never sees the card.')]
final class UnpaidInvoices extends StatsOverviewWidget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    protected static ?int $sort = 20;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.hours-stats';

    /** No live ticking: the figures are read again every 60 seconds, while the widget is visible. */
    protected ?string $pollingInterval = 'visible.60s';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /** Two stats per row at every width, so the optional third one wraps instead of stretching the row to three. */
    protected int|array|null $columns = 2;

    /**
     * The summary of the current render; read once, shared by the stats and the collapsed header.
     *
     * @var array{overdue: array{count: int, czk: Money}, unpaid: array{count: int, czk: Money}, awaiting_invoice: int}|null
     */
    private ?array $summary = null;

    /**
     * The declaration AND an issued invoice or proforma to report on: the card is quiet before the first one. The
     * declaration is asked first, so a Partner never reaches the query.
     */
    public static function canView(): bool
    {
        return AccessRules::allows(self::class)
            && Invoice::query()
                ->where('invoices.state', InvoiceState::Issued->value)
                ->whereIn('invoices.kind', [InvoiceKind::Invoice->value, InvoiceKind::Proforma->value])
                ->exists();
    }

    /**
     * A payment saved on a document page moves the derived states: the figures are read again on the render that
     * follows, so the listener only drops the figures of the current one.
     */
    #[On('invoice-payment-saved')]
    public function refreshAfterPayment(): void
    {
        $this->summary = null;
    }

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::UnpaidInvoices;
    }

    protected function cardHeading(): string
    {
        return __('invoices.dashboard.heading');
    }

    /**
     * The overdue count, as the "Po splatnosti" stat shows it, for the header of the collapsed card (R-28-2).
     */
    protected function collapsedSummary(): string
    {
        return __('invoices.dashboard.summary', ['count' => $this->summary()['overdue']['count']]);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $summary = $this->summary();

        $stats = [
            $this->overdueStat($summary['overdue']),
            Stat::make(__('invoices.dashboard.stats.to_pay'), (string) $summary['unpaid']['count'])
                ->description(__('invoices.dashboard.lines.czk', ['czk' => $summary['unpaid']['czk']->format('cs')]))
                ->url($this->tabUrl(InvoiceListTab::Unpaid)),
        ];

        if ($summary['awaiting_invoice'] > 0) {
            $stats[] = Stat::make(__('invoices.dashboard.stats.awaiting_invoice'), (string) $summary['awaiting_invoice'])
                ->description(__('invoices.dashboard.lines.awaiting_invoice'))
                ->descriptionColor('danger')
                ->url($this->tabUrl(InvoiceListTab::AwaitingInvoice));
        }

        return $stats;
    }

    /**
     * @param  array{count: int, czk: Money}  $overdue
     */
    private function overdueStat(array $overdue): Stat
    {
        $stat = Stat::make(__('invoices.dashboard.stats.overdue'), (string) $overdue['count'])
            ->url($this->tabUrl(InvoiceListTab::Overdue));

        if ($overdue['count'] === 0) {
            return $stat
                ->description(__('invoices.dashboard.lines.nothing_overdue'))
                ->descriptionColor('success');
        }

        return $stat
            ->description(__('invoices.dashboard.lines.czk', ['czk' => $overdue['czk']->format('cs')]))
            ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
            ->descriptionColor('danger')
            ->color('danger');
    }

    /**
     * The invoice list on one of its tabs.
     */
    private function tabUrl(InvoiceListTab $tab): string
    {
        return InvoiceResource::getUrl('index', ['tab' => $tab->value]);
    }

    /**
     * @return array{overdue: array{count: int, czk: Money}, unpaid: array{count: int, czk: Money}, awaiting_invoice: int}
     */
    private function summary(): array
    {
        return $this->summary ??= app(ReceivablesReader::class)->summary(ReceivablesReader::today());
    }
}
