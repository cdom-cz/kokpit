<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Widgets\HoursStats;
use App\Filament\Widgets\PartnerProjects;
use App\Filament\Widgets\PartnerTasks;
use App\Filament\Widgets\TodayPlan;
use App\Filament\Widgets\UnbilledPerClient;
use App\Filament\Widgets\UnpaidInvoices;
use App\Filament\Widgets\UnresolvedPayments;
use App\Filament\Widgets\UpcomingDeadlines;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\Widget;

/**
 * The panel's own dashboard. It replaces the stock dashboard, whose default widgets are
 * visible to every signed-in user and carry no access rule.
 *
 * The widgets depend on the role. A Partner with a client gets the open tasks of the own visible
 * projects, the ones waiting for their review first, and the visible projects (D-12); a Partner
 * with neither gets the neutral empty state. The Admin gets the time dashboard (RE-04; D-11): the
 * hours worked, right under them the Signal plan for today (full width), then the receivables card of the issued
 * documents (full width, sort 20, shown from the first issued invoice or proforma; IN-05), then the unbilled time per
 * client and the nearest task deadlines side by side. The unresolved card payments (sort 21, full width, PA-08) sit
 * directly under the receivables card and appear only while a duplicate, an unmatched payment or a refund waits.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'Landing page for both roles; the Partner widgets read through the Partner scope and the pinned Partner fields and name no time, rate, price or finance.')]
class Dashboard extends BaseDashboard
{
    use EnforcesPageAccessRule;

    /**
     * @return list<class-string<Widget>>
     */
    public function getWidgets(): array
    {
        $context = app(PartnerContext::class);

        if ($context->isAdmin()) {
            return [HoursStats::class, TodayPlan::class, UnpaidInvoices::class, UnresolvedPayments::class, UnbilledPerClient::class, UpcomingDeadlines::class];
        }

        if ($context->partnerClientId() === null) {
            return [];
        }

        return [PartnerTasks::class, PartnerProjects::class];
    }

    /**
     * One column below `lg`, two from there: the Partner dashboard is one column wide, the Admin's has the unbilled
     * table and the deadlines side by side (UI-SPEC Surface E).
     */
    public function getColumns(): int|array
    {
        return app(PartnerContext::class)->isAdmin() ? ['default' => 1, 'lg' => 2] : 1;
    }

    public function content(Schema $schema): Schema
    {
        if (app(PartnerContext::class)->isAdmin()) {
            return parent::content($schema);
        }

        if ($this->getWidgets() !== [] && $this->partnerHasContent()) {
            return parent::content($schema);
        }

        return $schema->components([
            EmptyState::make(__('kokpit.dashboard.empty_heading'))
                ->description(__('kokpit.dashboard.empty_description_partner'))
                ->icon(Heroicon::OutlinedInbox),
        ]);
    }

    /**
     * Whether the Partner has an open task or a visible project: with neither, the page keeps the
     * neutral empty state instead of two empty widgets. With one of them, each widget shows its
     * own short empty state when it is the empty one.
     */
    private function partnerHasContent(): bool
    {
        return PartnerTasks::openTaskCount() > 0 || PartnerProjects::visibleProjectCount() > 0;
    }
}
