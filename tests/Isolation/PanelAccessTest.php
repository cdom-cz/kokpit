<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Tests\Support\Canary;
use Tests\Support\Filament\Fixtures\UndeclaredPage;

/**
 * One user per state of the access matrix.
 *
 * @return array<string, Closure(): ?Authenticatable>
 */
function accessStates(): array
{
    return [
        'guest' => static fn () => null,
        'admin' => static fn () => Canary::admin(),
        'partner with a client' => static fn () => Canary::partnerFor(Canary::twoClients()[0]),
        'partner without a client' => static fn () => Canary::partnerFor(null),
        'user without a role' => static fn () => Canary::userWithoutRole(null),
        'user with a client but no role' => static fn () => Canary::userWithoutRole(Canary::twoClients()[0]),
    ];
}

it('opens the dashboard to an Admin', function (): void {
    $this->actingAs(Canary::admin())->get('/admin')
        ->assertOk()
        ->assertSee(__('kokpit.dashboard.empty_heading'))
        ->assertSee(__('kokpit.dashboard.empty_description_admin'));
});

it('opens the dashboard to a Partner with a client and shows the neutral text', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]))->get('/admin')
        ->assertOk()
        ->assertSee(__('kokpit.dashboard.empty_heading'))
        ->assertSee(__('kokpit.dashboard.empty_description_partner'))
        ->assertDontSee(__('kokpit.dashboard.empty_description_admin'));
});

it('refuses the dashboard to a Partner without a client', function (): void {
    $this->actingAs(Canary::partnerFor(null))->get('/admin')->assertForbidden();
});

it('refuses the dashboard to a user without a role', function (): void {
    $this->actingAs(Canary::userWithoutRole(null))->get('/admin')->assertForbidden();
});

it('sends a guest to the login page, not to a 403', function (): void {
    $this->get('/admin')->assertRedirectContains('/admin/login');
});

it('replaces the stock dashboard and its widgets', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->getPages())->toContain(Dashboard::class)
        ->and($panel->getPages())->not->toContain(Filament\Pages\Dashboard::class)
        ->and($panel->getWidgets())->toBe([])
        ->and((new Dashboard)->getWidgets())->toBe([]);

    $this->actingAs(Canary::admin())->get('/admin')
        ->assertOk()
        ->assertDontSee('fi-wi-account', false)
        ->assertDontSee('fi-wi-filament-info', false);
});

it('runs the panel with strict authorization and without global search', function (): void {
    $panel = Filament::getPanel('admin');

    expect($panel->isAuthorizationStrict())->toBeTrue()
        ->and($panel->getGlobalSearchProvider())->toBeNull();
});

it('lets the declaration drive the dashboard: PartnerAllowed admits an Admin and a Partner with a client only', function (): void {
    $rule = AccessRules::for(Dashboard::class);

    expect($rule?->audience)->toBe(Audience::PartnerAllowed);

    $expected = [
        'guest' => false,
        'admin' => true,
        'partner with a client' => true,
        'partner without a client' => false,
        'user without a role' => false,
        'user with a client but no role' => false,
    ];

    foreach (accessStates() as $state => $make) {
        $user = $make();
        $user !== null ? $this->actingAs($user) : auth()->logout();

        expect(Dashboard::canAccess())->toBe($expected[$state], $state)
            ->and(AccessRules::allows(Dashboard::class))->toBe($expected[$state], $state);
    }
});

it('denies a class without the attribute in every state, even to the Admin', function (): void {
    expect(AccessRules::for(UndeclaredPage::class))->toBeNull();

    foreach (accessStates() as $state => $make) {
        $user = $make();
        $user !== null ? $this->actingAs($user) : auth()->logout();

        expect(AccessRules::allows(UndeclaredPage::class))->toBeFalse($state);
    }
});

it('denies a class that does not exist and a subclass of a declared class', function (): void {
    $this->actingAs(Canary::admin());

    $subclass = new class extends Dashboard {};

    expect(AccessRules::allows('App\\Filament\\Pages\\DoesNotExist'))->toBeFalse()
        ->and(AccessRules::allows($subclass::class))->toBeFalse();
});
