<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Concerns\EnforcesPageAccessRule;
use App\Filament\Widgets\PartnerProjects;
use App\Filament\Widgets\PartnerTasks;
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
 * with neither gets the neutral empty state. The Admin has no widget yet.
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

        if ($context->isAdmin() || $context->partnerClientId() === null) {
            return [];
        }

        return [PartnerTasks::class, PartnerProjects::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function content(Schema $schema): Schema
    {
        if ($this->getWidgets() !== [] && $this->partnerHasContent()) {
            return parent::content($schema);
        }

        $description = app(PartnerContext::class)->isAdmin()
            ? __('kokpit.dashboard.empty_description_admin')
            : __('kokpit.dashboard.empty_description_partner');

        return $schema->components([
            EmptyState::make(__('kokpit.dashboard.empty_heading'))
                ->description($description)
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
