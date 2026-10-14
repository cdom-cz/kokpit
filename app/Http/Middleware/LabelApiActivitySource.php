<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Audit\ActivitySource;
use App\Domain\Audit\ActivitySourceLabel;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Labels every activity row written while an API request runs with the source "api" (OD-4).
 *
 * The label comes from the execution context only: this middleware sits on the api route group and
 * nothing in the request can choose it (Phase 3 D-08, T-07-35).
 */
final class LabelApiActivitySource
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response */
        return app(ActivitySource::class)->as(ActivitySourceLabel::Api, fn (): Response => $next($request));
    }
}
