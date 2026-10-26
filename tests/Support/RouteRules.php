<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The declaration of the routes that need no login (AUD-02; D-06, D-11).
 *
 * An exception or a new public route is added here and only here, so it shows in the pull request diff;
 * no attribute or marker in production code serves this purpose. Keys follow R-12-01-1: the route name,
 * or "METHOD /uri" with the Livewire prefix written as "livewire-{hash}" for a route without a name.
 * Pure data: nothing in this class calls application code.
 *
 * Every entry carries a written reason. A public entry names the means that protects it instead of a
 * login (token, signature, guest-only, none, component); a closed entry names the status the route is
 * expected to answer, because a deliberately dead route is declared rather than silently skipped (R-12-01-3).
 *
 * Confirmed by the owner on 2026-10-24 in plan 12-08 (D-02); see .planning/phases/12-partner-audit/12-MATRIX-REVIEW.md.
 */
final class RouteRules
{
    /**
     * The means by which a public route is protected instead of a login.
     *
     * @var list<string>
     */
    public const array MEANS = ['token', 'signature', 'guest-only', 'none', 'component'];

    /**
     * The statuses a closed route may be expected to answer.
     *
     * @var list<int>
     */
    private const array CLOSED_STATUSES = [403, 404, 410];

    /**
     * Routes that carry no authentication middleware and are public on purpose.
     *
     * @return array<string, array{means: string, reason: string}>
     */
    public static function publicRoutes(): array
    {
        return [
            'GET /' => ['means' => 'none', 'reason' => 'entry redirect to the panel, returns no data'],
            'GET /up' => ['means' => 'none', 'reason' => 'health probe for the load balancer, returns no data'],
            'pay.show' => ['means' => 'token', 'reason' => 'stable pay URL printed on the PDF and sent by e-mail; the unguessable token is the only key, throttled'],
            'webhooks.stripe' => ['means' => 'signature', 'reason' => 'payment provider webhook intake, signature verified before any write, throttled'],
            'filament.admin.auth.login' => ['means' => 'guest-only', 'reason' => 'panel login page'],
            'filament.admin.auth.password-reset.request' => ['means' => 'guest-only', 'reason' => 'forgot-password page, throttled'],
            'filament.admin.auth.password-reset.reset' => ['means' => 'signature', 'reason' => 'password reset link'],
            'filament.admin.invitation.accept' => ['means' => 'signature', 'reason' => 'Partner invitation link, throttled'],
            'scribe' => ['means' => 'none', 'reason' => 'public API documentation by owner decision (config/scribe.php)'],
            'scribe.openapi' => ['means' => 'none', 'reason' => 'public API documentation by owner decision (config/scribe.php)'],
            'scribe.postman' => ['means' => 'none', 'reason' => 'public API documentation by owner decision (config/scribe.php)'],
            'sanctum.csrf-cookie' => ['means' => 'none', 'reason' => 'Sanctum SPA cookie route, sets only the XSRF cookie, unused by the application'],
            'storage.local' => ['means' => 'signature', 'reason' => 'framework local-disk serving, temporary signed URLs only'],
            'storage.local.upload' => ['means' => 'signature', 'reason' => 'framework local-disk upload, temporary signed URLs only'],
            'default-livewire.update' => ['means' => 'component', 'reason' => 'Livewire endpoint; every component checks access on mount and on every update (panel persistent middleware)'],
            'livewire.upload-file' => ['means' => 'signature', 'reason' => 'Livewire temporary upload, signed URL only'],
            'livewire.preview-file' => ['means' => 'signature', 'reason' => 'Livewire temporary file preview, signed URL only'],
            'GET /livewire-{hash}/livewire.js' => ['means' => 'none', 'reason' => 'static Livewire script asset'],
            'GET /livewire-{hash}/livewire.min.js.map' => ['means' => 'none', 'reason' => 'static Livewire script source map'],
            'GET /livewire-{hash}/livewire.csp.min.js.map' => ['means' => 'none', 'reason' => 'static Livewire script source map'],
            'GET /livewire-{hash}/js/{component}.js' => ['means' => 'none', 'reason' => 'static Livewire component script asset'],
            'GET /livewire-{hash}/css/{component}.css' => ['means' => 'none', 'reason' => 'static Livewire component style asset'],
            'GET /livewire-{hash}/css/{component}.global.css' => ['means' => 'none', 'reason' => 'static Livewire global component style asset'],
        ];
    }

    /**
     * Routes that are deliberately dead: they must answer the given status to everyone.
     *
     * @return array<string, array{status: int, reason: string}>
     */
    public static function closedRoutes(): array
    {
        return [
            'filament.exports.download' => ['status' => 404, 'reason' => 'Filament export action route; the application uses no export action and its table does not exist'],
            'filament.imports.failed-rows.download' => ['status' => 404, 'reason' => 'Filament import action route; the application uses no import action and its table does not exist'],
        ];
    }

    /**
     * What is wrong with a declaration: an empty or whitespace-only reason, a means outside MEANS, or a
     * closed status outside 403, 404 and 410. Empty when the declaration is fine.
     *
     * @param  array<string, array{means: string, reason: string}>  $public
     * @param  array<string, array{status: int, reason: string}>  $closed
     * @return list<string>
     */
    public static function problemsIn(array $public, array $closed): array
    {
        $problems = [];

        foreach ($public as $key => $entry) {
            if (trim($entry['reason']) === '') {
                $problems[] = "{$key} is declared public without a reason";
            }

            if (! in_array($entry['means'], self::MEANS, true)) {
                $problems[] = "{$key} is declared public with the unknown means '{$entry['means']}'";
            }
        }

        foreach ($closed as $key => $entry) {
            if (trim($entry['reason']) === '') {
                $problems[] = "{$key} is declared closed without a reason";
            }

            if (! in_array($entry['status'], self::CLOSED_STATUSES, true)) {
                $problems[] = "{$key} is declared closed with the unsupported status {$entry['status']}";
            }
        }

        return $problems;
    }

    /**
     * What is wrong with the real declaration.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        return self::problemsIn(self::publicRoutes(), self::closedRoutes());
    }
}
