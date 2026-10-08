<?php

declare(strict_types=1);

use App\Domain\Shared\Auth\AccessRules;
use App\Domain\Shared\Auth\Audience;
use App\Filament\Pages\Dashboard;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Symfony\Component\Process\Process;
use Tests\Support\Canary;
use Tests\Support\CanaryRecord;
use Tests\Support\Filament\CanaryRecordResource;
use Tests\Support\Filament\CanaryRecordResource\Pages\ListCanaryRecords;
use Tests\Support\Filament\Fixtures\AccessOverrideMountProbePage;
use Tests\Support\Filament\Fixtures\AdminOnlyRelationManager;
use Tests\Support\Filament\Fixtures\AdminOnlyWidget;
use Tests\Support\Filament\Fixtures\MountProbePage;
use Tests\Support\Filament\Fixtures\PartnerAllowedWidget;
use Tests\Support\Filament\Fixtures\PolicyDeniedRelationManager;
use Tests\Support\Filament\Fixtures\PolicyDeniedResource;
use Tests\Support\Filament\Fixtures\UndeclaredCanaryRecordResource;
use Tests\Support\Filament\Fixtures\UndeclaredPage;
use Tests\Support\Filament\Fixtures\VisibleOverrideHistoryRelationManager;
use Tests\Support\Filament\Fixtures\VisibleOverrideWidget;
use Tests\Support\Probes\ActivityProbe;

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

/**
 * Evaluates a check once per state of the access matrix and returns the results
 * keyed by state name.
 *
 * @param  Closure(): bool  $check
 * @return array<string, bool>
 */
function accessByState(Closure $check): array
{
    $results = [];

    foreach (accessStates() as $state => $make) {
        $user = $make();
        $user !== null ? test()->actingAs($user) : auth()->logout();

        $results[$state] = $check();
    }

    return $results;
}

/**
 * The expected result for the Admin and for a Partner with a client; every other
 * state is denied.
 *
 * @return array<string, bool>
 */
function expectedAccess(bool $admin, bool $partnerWithClient): array
{
    return [
        'guest' => false,
        'admin' => $admin,
        'partner with a client' => $partnerWithClient,
        'partner without a client' => false,
        'user without a role' => false,
        'user with a client but no role' => false,
    ];
}

it('admits an Admin and a Partner with a client to a PartnerAllowed widget, nobody else', function (): void {
    expect(accessByState(fn () => PartnerAllowedWidget::canView()))->toBe(expectedAccess(admin: true, partnerWithClient: true));
});

it('admits only an Admin to an AdminOnly widget', function (): void {
    expect(accessByState(fn () => AdminOnlyWidget::canView()))->toBe(expectedAccess(admin: true, partnerWithClient: false));
});

it('hides an AdminOnly relation manager from a Partner although the parent check would admit one', function (): void {
    $owner = new CanaryRecord;

    expect(accessByState(fn () => CanaryRecordResource::canAccess()))->toBe(expectedAccess(admin: true, partnerWithClient: true))
        ->and(accessByState(fn () => AdminOnlyRelationManager::canViewForRecord($owner, ListCanaryRecords::class)))
        ->toBe(expectedAccess(admin: true, partnerWithClient: false));
});

it('keeps the parent policy check of a relation manager under a PartnerAllowed declaration', function (): void {
    expect(accessByState(fn () => PolicyDeniedRelationManager::canViewForRecord(new CanaryRecord, ListCanaryRecords::class)))
        ->toBe(expectedAccess(admin: true, partnerWithClient: false));
});

it('admits an Admin and a Partner with a client to the canary resource through attribute and policy', function (): void {
    expect(accessByState(fn () => CanaryRecordResource::canAccess()))->toBe(expectedAccess(admin: true, partnerWithClient: true));
});

it('keeps the policy check of a resource under a PartnerAllowed declaration', function (): void {
    expect(accessByState(fn () => PolicyDeniedResource::canAccess()))->toBe(expectedAccess(admin: true, partnerWithClient: false));
});

it('denies a resource without the attribute even though the policy grants viewAny', function (): void {
    expect(accessByState(fn () => Gate::allows('viewAny', CanaryRecord::class)))
        ->toBe(['guest' => false, 'admin' => true, 'partner with a client' => true, 'partner without a client' => false, 'user without a role' => false, 'user with a client but no role' => false])
        ->and(accessByState(fn () => UndeclaredCanaryRecordResource::canAccess()))
        ->toBe(expectedAccess(admin: false, partnerWithClient: false));
});

it('leaves a page without the trait open, which is why the registry test exists', function (): void {
    $this->actingAs(Canary::partnerFor(null));

    expect(UndeclaredPage::canAccess())->toBeTrue();
});

it('refuses a Partner before the page mount() runs and runs it for an Admin', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach (['partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]), 'partner without a client' => fn () => Canary::partnerFor(null), 'user without a role' => fn () => Canary::userWithoutRole(null)] as $state => $make) {
        MountProbePage::$mounted = false;
        $this->actingAs($make());

        Livewire::test(MountProbePage::class)->assertForbidden();

        expect(MountProbePage::$mounted)->toBeFalse($state);
    }

    MountProbePage::$mounted = false;
    $this->actingAs(Canary::admin());

    Livewire::test(MountProbePage::class)->assertOk();

    expect(MountProbePage::$mounted)->toBeTrue();
});

it('refuses a Partner on a page that overrides canAccess() to always pass, before its mount() runs', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    foreach (['partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]), 'partner without a client' => fn () => Canary::partnerFor(null), 'user without a role' => fn () => Canary::userWithoutRole(null)] as $state => $make) {
        AccessOverrideMountProbePage::$mounted = false;
        $this->actingAs($make());

        Livewire::test(AccessOverrideMountProbePage::class)->assertForbidden();

        expect(AccessOverrideMountProbePage::$mounted)->toBeFalse($state);
    }

    AccessOverrideMountProbePage::$mounted = false;
    $this->actingAs(Canary::admin());

    Livewire::test(AccessOverrideMountProbePage::class)->assertOk();

    expect(AccessOverrideMountProbePage::$mounted)->toBeTrue();
});

it('refuses a Partner before a relation manager or a widget mount() runs, whatever their own visibility check says', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    ActivityProbe::provision();

    try {
        $this->actingAs(Canary::admin());
        $owner = ActivityProbe::query()->create(['title' => 'Boot order probe']);

        foreach (['partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]), 'partner without a client' => fn () => Canary::partnerFor(null), 'user without a role' => fn () => Canary::userWithoutRole(null)] as $state => $make) {
            VisibleOverrideHistoryRelationManager::$mounted = false;
            VisibleOverrideWidget::$mounted = false;
            $this->actingAs($make());

            Livewire::test(VisibleOverrideHistoryRelationManager::class, ['ownerRecord' => $owner, 'pageClass' => Dashboard::class])->assertForbidden();
            Livewire::test(VisibleOverrideWidget::class)->assertForbidden();

            expect(VisibleOverrideHistoryRelationManager::$mounted)->toBeFalse($state)
                ->and(VisibleOverrideWidget::$mounted)->toBeFalse($state);
        }
    } finally {
        ActivityProbe::restoreMorphMap();
    }
});

it('registers the canary resource and its routes while the harness is on', function (): void {
    expect(config('kokpit.canary_harness'))->toBeTrue()
        ->and(Filament::getPanel('admin')->getResources())->toContain(CanaryRecordResource::class)
        ->and(Route::has('filament.admin.resources.canary-records.index'))->toBeTrue()
        ->and(Route::has('filament.admin.resources.canary-records.view'))->toBeTrue();
});

/**
 * Runs a real artisan process with the given environment.
 *
 * @param  array<string, string>  $environment
 * @param  list<string>  $arguments
 */
function artisanProcess(array $environment, array $arguments): Process
{
    $process = new Process([PHP_BINARY, 'artisan', ...$arguments], base_path(), $environment);
    $process->run();

    return $process;
}

it('keeps the canary resource out of the panel while the harness is off', function (): void {
    $routes = fn (string $harness): string => artisanProcess(
        ['APP_ENV' => 'testing', 'KOKPIT_CANARY_HARNESS' => $harness],
        ['route:list', '--json', '--path=admin/canary-records'],
    )->getOutput();

    expect($routes('true'))->toContain('canary-records')
        ->and($routes('false'))->not->toContain('canary-records');
});

it('refuses to start a production process while the canary harness is on', function (): void {
    $run = fn (string $harness): Process => artisanProcess(
        ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'KOKPIT_REQUIRE_ADMIN_2FA' => 'true', 'KOKPIT_CANARY_HARNESS' => $harness, 'QUEUE_CONNECTION' => 'redis'],
        ['--version'],
    );

    $on = $run('true');
    $off = $run('false');

    expect($on->isSuccessful())->toBeFalse()
        ->and($on->getOutput().$on->getErrorOutput())->toContain('KOKPIT_CANARY_HARNESS')
        ->and($off->isSuccessful())->toBeTrue();
});
