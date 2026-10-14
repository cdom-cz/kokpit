<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the public API documentation routes of Scribe (D-17, OD-6).
 *
 * The page, the spec and the Postman collection are generated at container start and never committed, so
 * before the first generation (or while the collection is disabled) the file a route serves does not
 * exist. Scribe's own route would then answer 500; this middleware answers 404 instead, looking at exactly
 * the location the package route reads (`vendor/knuckleswtf/scribe/routes/laravel.php`: the view
 * `scribe.index`, and `scribe/openapi.yaml` and `scribe/collection.json` on the `local` disk).
 *
 * Every response, the 404 included, carries `X-Robots-Tag: noindex, nofollow`.
 */
final class EnsureApiDocsGenerated
{
    private const string ROBOTS = 'noindex, nofollow';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->artefactExists((string) $request->route()?->getName())) {
            abort(404, headers: ['X-Robots-Tag' => self::ROBOTS]);
        }

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Robots-Tag', self::ROBOTS);

        return $response;
    }

    private function artefactExists(string $routeName): bool
    {
        $disk = Storage::disk('local');

        return match ($routeName) {
            'scribe' => View::exists('scribe.index') && $disk->exists('scribe/openapi.yaml'),
            'scribe.openapi' => $disk->exists('scribe/openapi.yaml'),
            'scribe.postman' => $disk->exists('scribe/collection.json'),
            default => false,
        };
    }
}
