<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Domain\Shared\Auth\PartnerContext;
use App\Filament\Pages\Auth\AcceptInvitation;
use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Filament\Pages\Dashboard;
use App\Http\Middleware\EnsureAdminHasTwoFactor;
use App\Http\Middleware\SetNoReferrerPolicy;
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
use Illuminate\Support\Facades\Route;
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
            // The reset link the Admin sends from the client detail (US-02, D-04) opens Filament's
            // signed reset page, and passwordReset() is what registers it. It also registers the
            // public "forgot password" page, which is our own subclass that answers every address
            // the same way and mails only an active account. The reset password rule is in
            // AppServiceProvider.
            ->passwordReset(RequestPasswordReset::class)
            // The invitation link (US-02): a guest page on a signed route, named
            // filament.admin.invitation.accept. It has no path parameters; the invitation id and the
            // token travel as query parameters under the signature. The page is not registered as a
            // panel page and does not enable registration. The no-referrer header goes first, so the
            // 403 of a bad signature and the 429 of the limiter carry it as well; `signed` stays ahead
            // of the limiter, so an unsigned request answers 403 before it is counted.
            ->routes(fn () => Route::get('/invitation', AcceptInvitation::class)
                ->middleware([SetNoReferrerPolicy::class, 'signed', 'throttle:invitation'])
                ->name('invitation.accept'))
            ->spa()
            // The bell shows the Admin alerts of failed background jobs (D-11). The condition is
            // evaluated per request, so a Partner never gets the bell.
            ->databaseNotifications(static fn (): bool => app(PartnerContext::class)->isAdmin())
            ->databaseNotificationsPolling('30s')
            // A Resource without a policy method throws instead of being allowed (D-03). Pages and
            // widgets are covered by #[AccessRule], which strict authorization does not reach.
            ->strictAuthorization()
            // Global search is a Partner leakage surface, so it is opt-in per resource: only a
            // resource that declares $isGloballySearchable on its own class is searchable, and a
            // resource that inherits the default is not. TaskResource is the only one (an
            // Admin-only resource, found by KEY-N or title). A Partner sees no result at all,
            // because every searchable resource is closed to Partners (UI-SPEC A-6).
            ->globalSearch()
            ->globalSearchResourceOptIn()
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
