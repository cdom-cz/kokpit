<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Domain\Dashboard\Enums\DashboardWidget;
use App\Domain\Payments\Queries\UnresolvedPayments as UnresolvedPaymentsReader;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\CollapsibleDashboardWidget;
use App\Filament\Concerns\EnforcesWidgetAccessRule;
use App\Filament\Resources\CardPaymentResource;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Livewire\Attributes\On;

/**
 * "Nevyřešené platby" on the Admin dashboard: the duplicate payments, unmatched payments and refund events that wait
 * for the Admin (PA-08; ruling R11-17-1, UI-SPEC Surface J, U-9).
 *
 * One stat with the count in danger and the non-zero parts as its description ("duplicitní 1, nespárované 3"), linked
 * to the "Nevyřešené" tab of the overview. Every figure is {@see UnresolvedPaymentsReader::counts()}, the reader behind
 * the navigation badge and the tab counts, so the card and the queue cannot disagree.
 *
 * The card exists only above zero: the dashboard leaves it out while nothing is unresolved (no tile, no placeholder),
 * so the card is itself the alert. Collapsed it keeps the count in its header. Admin only: a Partner is refused at
 * boot, before anything is read.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Unresolved card payments are finance data: Stripe references, amounts and notes; a Partner is refused at boot and never sees the card.')]
final class UnresolvedPayments extends StatsOverviewWidget
{
    use CollapsibleDashboardWidget;
    use EnforcesWidgetAccessRule;

    protected static ?int $sort = 21;

    /** The page tests and the Admin's first look read the widget as rendered, not as a placeholder. */
    protected static bool $isLazy = false;

    /** @var view-string */
    protected string $view = 'filament.widgets.unresolved-payments';

    /** No live ticking: the figures are read again every 60 seconds, while the widget is visible. */
    protected ?string $pollingInterval = 'visible.60s';

    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /**
     * The counts of the current render; read once, shared by the stat, the header and the visibility of the card.
     *
     * @var array{total: int, duplicate: int, unmatched: int, refund: int}|null
     */
    private ?array $counts = null;

    /**
     * The declaration AND at least one unresolved record: the dashboard leaves the card out otherwise. The declaration
     * is asked first, so a Partner never reaches the query.
     */
    public static function canView(): bool
    {
        return AccessRules::allows(self::class)
            && app(UnresolvedPaymentsReader::class)->counts()['total'] > 0;
    }

    /**
     * Only the declaration is enforced at boot. Both the trait and Filament's own boot check also ask {@see canView()},
     * which turns false when the last record is resolved while the card is open; the poll that follows must not end in
     * a 403 but in the empty root of the view. The user the declaration refuses is still stopped before any code runs.
     */
    public function bootEnforcesWidgetAccessRule(): void
    {
        abort_unless(AccessRules::allows(self::class), 403);
    }

    /**
     * Filament's boot check, narrowed to the declaration for the same reason as {@see bootEnforcesWidgetAccessRule()}.
     */
    public function bootCanAuthorizeAccess(): void
    {
        abort_unless(AccessRules::allows(self::class), 403);
    }

    /**
     * A resolution on the record page: the figures are read again on the render that follows, so the listener only
     * drops the figures of the current one.
     */
    #[On('card-payment-resolved')]
    public function refreshAfterResolution(): void
    {
        $this->counts = null;
    }

    public static function dashboardWidget(): DashboardWidget
    {
        return DashboardWidget::UnresolvedPayments;
    }

    /**
     * Whether the card has anything to show; the view renders an empty root otherwise.
     */
    public function hasUnresolved(): bool
    {
        return $this->counts()['total'] > 0;
    }

    protected function cardHeading(): string
    {
        return __('kokpit.dashboard.unresolved_payments.heading');
    }

    /**
     * The count, for the header of the collapsed card (R-28-2 pattern).
     */
    protected function collapsedSummary(): string
    {
        return __('kokpit.dashboard.unresolved_payments.summary', ['count' => $this->counts()['total']]);
    }

    /**
     * @return array<Stat>
     */
    protected function getStats(): array
    {
        $counts = $this->counts();

        return [
            Stat::make(__('kokpit.dashboard.unresolved_payments.stat'), (string) $counts['total'])
                ->description($this->parts($counts))
                ->descriptionIcon(Heroicon::OutlinedExclamationTriangle)
                ->descriptionColor('danger')
                ->color('danger')
                ->url(CardPaymentResource::getUrl('index')),
        ];
    }

    /**
     * The parts above zero in the order duplicate, unmatched, refund, each in its Czech plural form.
     *
     * @param  array{total: int, duplicate: int, unmatched: int, refund: int}  $counts
     */
    private function parts(array $counts): string
    {
        $parts = [];

        foreach (['duplicate', 'unmatched', 'refund'] as $type) {
            if ($counts[$type] > 0) {
                $parts[] = trans_choice('kokpit.dashboard.unresolved_payments.parts.'.$type, $counts[$type], ['count' => $counts[$type]]);
            }
        }

        return implode(', ', $parts);
    }

    /**
     * @return array{total: int, duplicate: int, unmatched: int, refund: int}
     */
    private function counts(): array
    {
        return $this->counts ??= app(UnresolvedPaymentsReader::class)->counts();
    }
}
