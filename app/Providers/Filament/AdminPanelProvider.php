<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureAdminHasTwoFactor;
use App\Support\InitialsAvatarProvider;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Tests\Support\Filament\CanaryRecordResource;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        $panel = $this->configure($panel);

        // Test-only canary surface (D-04): registered only while the switch is on and the class
        // exists (autoload-dev is absent in production installs); ProductionConfigGuard refuses
        // the switch in production on top of that.
        if (config('kokpit.canary_harness') === true && class_exists(CanaryRecordResource::class)) {
            $panel->resources([CanaryRecordResource::class]);
        }

        return $panel;
    }

    private function configure(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->profile()
            ->spa()
            // The bell shows the Admin alerts of failed background jobs (D-11). The condition is
            // evaluated per request, so a Partner never gets the bell.
            ->databaseNotifications(static fn (): bool => app(PartnerContext::class)->isAdmin())
            ->databaseNotificationsPolling('30s')
            // A Resource without a policy method throws instead of being allowed (D-03). Pages and
            // widgets are covered by #[AccessRule], which strict authorization does not reach.
            ->strictAuthorization()
            // Global search is a Partner leakage surface; it comes back per resource together with
            // an explicit rule (UI-SPEC A-6).
            ->globalSearch(false)
            // Built-in TOTP with one-time recovery codes (D-07). Enforcement is per request in
            // the middleware below, because Filament evaluates isRequired once at route build.
            ->multiFactorAuthentication([AppAuthentication::make()->recoverable()], isRequired: true)
            ->multiFactorAuthenticationRequiredMiddlewareName(EnsureAdminHasTwoFactor::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverResources(in: app_path('Filament/Partner/Resources'), for: 'App\Filament\Partner\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
