<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Filament\Pages\SystemPage;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\Probes\HealthProbeIndicator;

/*
 * The System page (D-12, D-13): the Admin sees one row per health slot in Czech,
 * refreshed by polling; nobody else gets in and no indicator runs for them.
 */

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('shows the Admin the six Czech slot labels, a status label for each and the polling attribute', function (): void {
    $response = $this->actingAs(Canary::admin())->get('/admin/system')->assertOk();

    foreach (HealthSlot::cases() as $slot) {
        $response->assertSee(__('enums.health_slot.'.$slot->value));
    }

    $response->assertSee(__('enums.health_status.not_available'))
        ->assertSee(__('kokpit.system.not_available_yet'))
        ->assertSee(__('kokpit.system.navigation_label'))
        ->assertSee('wire:poll', false);

    expect(substr_count((string) $response->getContent(), __('enums.health_status.not_available')))
        ->toBeGreaterThanOrEqual(count(HealthSlot::cases()));
});

it('is reachable under the route name the failed-job alert links to', function (): void {
    expect(route('filament.admin.pages.system', absolute: false))->toBe('/admin/system');
});

it('refuses the System page to a Partner with a client, a Partner without a client and a user without a role, and runs no indicator for them', function (): void {
    $spies = [];

    foreach (HealthSlot::cases() as $slot) {
        $spies[$slot->value] = new HealthProbeIndicator($slot);
        app(HealthIndicatorRegistry::class)->replace($slot, $spies[$slot->value]);
    }

    $states = [
        'partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]),
        'partner without a client' => fn () => Canary::partnerFor(null),
        'user without a role' => fn () => Canary::userWithoutRole(null),
    ];

    foreach ($states as $make) {
        $this->actingAs($make())->get('/admin/system')->assertForbidden();
    }

    foreach ($spies as $spy) {
        expect($spy->checks)->toBe(0);
    }

    // The spy is wired to the page: for the Admin it is called, so the zero above is not vacuous.
    $this->actingAs(Canary::admin())->get('/admin/system')->assertOk();

    expect($spies[HealthSlot::FailedJobs->value]->checks)->toBeGreaterThan(0);
});

it('refuses a Partner the Livewire component of the System page', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    Livewire::test(SystemPage::class)->assertForbidden();
});

it('shows the result of a replaced indicator', function (): void {
    $result = new HealthResult(HealthStatus::Warning, '7 min', 'Fictional detail text');
    app(HealthIndicatorRegistry::class)->replace(
        HealthSlot::OldestPendingJob,
        new HealthProbeIndicator(HealthSlot::OldestPendingJob, $result),
    );

    $this->actingAs(Canary::admin())->get('/admin/system')
        ->assertOk()
        ->assertSee(__('enums.health_status.warning'))
        ->assertSee('7 min')
        ->assertSee('Fictional detail text');
});
