<?php

declare(strict_types=1);

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
 * Kokpit uses Sanctum for bearer tokens only. The REST API under /api/v1 is
 * called by scripts and integrations with a personal access token of the
 * Admin; the panel session is never an API credential.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Empty on purpose: there is no SPA that authenticates against the API with
    | cookies, so no request is treated as stateful.
    |
    */

    'stateful' => [],

    /*
    |--------------------------------------------------------------------------
    | Sanctum Guards
    |--------------------------------------------------------------------------
    |
    | Empty on purpose. Sanctum checks these guards before it looks at the bearer
    | token, and a signed-in browser session would then authenticate as a
    | TransientToken, which passes every ability check and carries no token for
    | rate limiting. With no guard listed, only a bearer token of an active Admin
    | opens the API; an Admin signed in to the panel still gets 401.
    |
    */

    'guard' => [],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | No global expiry. Each token carries its own optional `expires_at`, which
    | Sanctum checks on every request, and a token can always be revoked.
    |
    */

    'expiration' => null,

    /*
    |--------------------------------------------------------------------------
    | Token Prefix
    |--------------------------------------------------------------------------
    |
    | No prefix. One can be added later without invalidating tokens already
    | issued.
    |
    */

    'token_prefix' => '',

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware Sanctum uses when it authenticates a stateful request. Kept at
    | the package defaults; unused while `stateful` is empty.
    |
    */

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
