<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets `Referrer-Policy: no-referrer` on the response.
 *
 * The invitation link carries its token in the URL. With this header a browser
 * sends no Referer from the page to any third party (an image, a font, a link), so
 * the token cannot leak that way (T-04-43).
 */
final class SetNoReferrerPolicy
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }
}
