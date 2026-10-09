<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Shared\Auth\AccessRule;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Concerns\EnforcesPageAccessRule;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use UnitEnum;

/**
 * The System page (D-12, D-13): one row per health slot, rendered from the
 * indicator registry and refreshed by polling.
 *
 * The indicators run while the view renders (the computed property below),
 * never in mount(): the boot hook of EnforcesPageAccessRule refuses a Partner
 * first, so no indicator runs for anyone but the Admin.
 */
#[AccessRule(Audience::AdminOnly, reason: 'Infrastructure state (queue, scheduler, failures) is operator information and is never shown to a Partner.')]
class SystemPage extends Page
{
    use EnforcesPageAccessRule;

    protected static ?string $slug = 'system';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.pages.system-page';

    public static function getNavigationLabel(): string
    {
        return __('kokpit.system.navigation_label');
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return __('kokpit.system.navigation_group');
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::OutlinedServerStack;
    }

    public function getTitle(): string
    {
        return __('kokpit.system.title');
    }

    /**
     * One result per slot, in slot order. Computed per request, so every poll measures again.
     *
     * @return array<string, HealthResult>
     */
    #[Computed]
    public function results(): array
    {
        return app(HealthIndicatorRegistry::class)->results();
    }

    /**
     * When the results above were taken, in the display time zone.
     */
    #[Computed]
    public function checkedAt(): Carbon
    {
        return now('Europe/Prague');
    }
}
