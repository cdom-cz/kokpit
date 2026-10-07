<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * The panel's own dashboard: declared, widget-free and empty until later phases
 * add content. It replaces the stock dashboard, whose default widgets are
 * visible to every signed-in user and carry no access rule.
 */
#[AccessRule(Audience::PartnerAllowed, reason: 'Empty landing page for both roles; the Partner text names no time, rate, price or finance.')]
class Dashboard extends BaseDashboard
{
    use EnforcesPageAccessRule;

    /**
     * @return array<never>
     */
    public function getWidgets(): array
    {
        return [];
    }

    public function content(Schema $schema): Schema
    {
        $description = app(PartnerContext::class)->isAdmin()
            ? __('kokpit.dashboard.empty_description_admin')
            : __('kokpit.dashboard.empty_description_partner');

        return $schema->components([
            EmptyState::make(__('kokpit.dashboard.empty_heading'))
                ->description($description)
                ->icon(Heroicon::OutlinedInbox),
        ]);
    }
}
