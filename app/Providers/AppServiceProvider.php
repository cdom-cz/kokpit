<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Notifications\Mail\EmbedMailLogo;
use App\Domain\Notifications\Mail\MailBrand;
use App\Filament\Support\AccentInsensitiveTextColumn;
use App\Support\ProductionConfigGuard;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The supplier branding of every e-mail: one read per request, console run or queue job, and the next job
        // reads again (the scope PartnerContext uses). MailBrand::read() runs as system and never throws (D-03).
        $this->app->scoped(MailBrand::class, static fn (): MailBrand => MailBrand::read());

        // Filament's Column::make() resolves a column through the container, so this binding is the one hook that makes
        // the search of every table text column ignore diacritics and letter case (CONTRIBUTING "Search").
        $this->app->bind(TextColumn::class, AccentInsensitiveTextColumn::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin two-factor enforcement may be off for local development and tests only (D-06).
        ProductionConfigGuard::check($this->app->isProduction(), config());

        // The invitation link (US-02): ten requests a minute per IP, against token guessing and
        // account-creation spam. Used by the route middleware `throttle:invitation`.
        // Every password the application sets is at least 12 characters (the install command and the
        // invitation accept page say so explicitly); this default covers the panel's reset and
        // profile pages, which use the framework default.
        Password::defaults(static fn (): Password => Password::min(12));

        // The supplier logo travels as one inline cid part of the message (D-03); domain folders are not discovered.
        Event::listen(MessageSending::class, EmbedMailLogo::class);

        RateLimiter::for('invitation', fn (Request $request): Limit => Limit::perMinute(10)->by($request->ip()));

        // File downloads and previews (D-10): the limit per minute is one value in config/kokpit.php, counted
        // per signed-in user and per IP address for the rare request without a session. Used by the route
        // middleware `throttle:document-files`.
        RateLimiter::for('document-files', fn (Request $request): Limit => Limit::perMinute(max(1, (int) config('kokpit.documents.download_rate_limit_per_minute')))
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
