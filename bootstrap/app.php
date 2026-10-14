<?php

declare(strict_types=1);

use App\Http\Middleware\LabelApiActivitySource;
use App\Http\Middleware\SetNoReferrerPolicy;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ValidateSignature;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The application containers are reachable only through the TLS-terminating Zerops
        // balancer, which sets the forwarded headers; without this every generated URL is http://.
        // Only the headers the balancer needs are trusted: client address, port and scheme. X-Forwarded-Host
        // stays untrusted, so a spoofed host header can never change the URLs Laravel generates (mail links,
        // Filament redirects).
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Laravel sorts the limiter ahead of every other route middleware. The invitation route (US-02)
        // needs the no-referrer header and the signature check in front of the limiter, so an unsigned
        // request answers 403 without being counted and the 429 still carries the header.
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: SetNoReferrerPolicy::class);
        $middleware->prependToPriorityList(before: ThrottleRequests::class, prepend: ValidateSignature::class);

        // Every route of routes/api.php writes its activity rows with the source 'api' (OD-4); the docs
        // routes are not in the api group.
        $middleware->api(append: [LabelApiActivitySource::class]);

        // Sanctum 4 registers no route middleware aliases, so `abilities:` and `ability:` on the
        // /api/v1 routes would otherwise fail with "Target class [abilities] does not exist".
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
