<?php

declare(strict_types=1);

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FAQRCode\Google2FA;
use Tests\Support\Canary;

/**
 * Every route named horizon.* as a method and a concrete URI; placeholders get a fictional token.
 *
 * @return list<array{method: string, uri: string, name: string}>
 */
function horizonRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $name = (string) $route->getName();

        if (! str_starts_with($name, 'horizon.')) {
            continue;
        }

        $methods = array_values(array_filter($route->methods(), static fn (string $method): bool => $method !== 'HEAD'));
        $uri = (string) preg_replace('/\{[^}]+\}/', 'fictional-token', $route->uri());

        $routes[] = ['method' => $methods[0], 'uri' => '/'.ltrim($uri, '/'), 'name' => $name];
    }

    return $routes;
}

function horizonAdminWithSecret(): User
{
    $admin = Canary::admin();
    $admin->forceFill(['app_authentication_secret' => app(Google2FA::class)->generateSecretKey()])->save();

    return $admin;
}

it('lets the Admin open the dashboard', function (): void {
    $this->actingAs(Canary::admin())->get('/horizon')->assertOk();
});

it('refuses a Partner, a user without a role and a guest on every Horizon route', function (): void {
    [$clientId] = Canary::twoClients();
    $routes = horizonRoutes();
    $names = array_column($routes, 'name');

    expect(count($routes))->toBeGreaterThanOrEqual(10)
        ->and($names)->toContain('horizon.index');

    $partner = Canary::partnerFor($clientId);
    $noRole = Canary::userWithoutRole($clientId);

    foreach ($routes as $route) {
        $this->actingAs($partner)->call($route['method'], $route['uri'])->assertForbidden();
        $this->actingAs($noRole)->call($route['method'], $route['uri'])->assertForbidden();

        auth()->logout();
        $this->call($route['method'], $route['uri'])->assertForbidden();
    }
});

it('keeps a Partner and a guest out of the dashboard when the environment is local', function (): void {
    app()->detectEnvironment(static fn (): string => 'local');
    [$clientId] = Canary::twoClients();

    expect(app()->environment('local'))->toBeTrue();

    $this->actingAs(Canary::partnerFor($clientId))->get('/horizon')->assertForbidden();

    auth()->logout();
    $this->get('/horizon')->assertForbidden();
});

it('requires a stored authenticator secret from the Admin while two-factor enforcement is on', function (): void {
    config(['kokpit.require_admin_two_factor' => true]);

    $this->actingAs(Canary::admin())->get('/horizon')->assertForbidden();
    $this->actingAs(horizonAdminWithSecret())->get('/horizon')->assertOk();
});

it('allows the viewHorizon ability to the Admin and denies it to a Partner', function (): void {
    [$clientId] = Canary::twoClients();

    expect(Gate::forUser(Canary::admin())->allows('viewHorizon'))->toBeTrue()
        ->and(Gate::forUser(Canary::partnerFor($clientId))->allows('viewHorizon'))->toBeFalse();
});
