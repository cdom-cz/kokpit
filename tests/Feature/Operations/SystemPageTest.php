<?php

declare(strict_types=1);

use App\Domain\Operations\Health\HealthIndicatorRegistry;
use App\Domain\Operations\Health\HealthResult;
use App\Domain\Operations\Health\HealthSlot;
use App\Domain\Operations\Health\HealthStatus;
use App\Filament\Pages\SystemPage;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
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
        ->toBeGreaterThanOrEqual(3);
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

it('answers 200, names the exception class and never prints the exception message when an indicator throws', function (): void {
    $secret = 'connection '.implode('.', ['db', 'internal']).' refused';
    app(HealthIndicatorRegistry::class)->replace(
        HealthSlot::SchedulerHeartbeat,
        new HealthProbeIndicator(HealthSlot::SchedulerHeartbeat, new RuntimeException($secret)),
    );

    $response = $this->actingAs(Canary::admin())->get('/admin/system')->assertOk();

    $response->assertSee(RuntimeException::class)
        ->assertDontSee($secret);

    // The other five slots are still there.
    foreach (HealthSlot::cases() as $slot) {
        $response->assertSee(__('enums.health_slot.'.$slot->value));
    }
});

it('renders each status with its colour badge and its Czech label', function (): void {
    $statuses = [
        HealthSlot::FailedJobs->value => HealthStatus::Ok,
        HealthSlot::OldestPendingJob->value => HealthStatus::Warning,
        HealthSlot::SchedulerHeartbeat->value => HealthStatus::Error,
        HealthSlot::LastRateDate->value => HealthStatus::NotAvailable,
    ];

    foreach ($statuses as $slot => $status) {
        app(HealthIndicatorRegistry::class)->replace(
            HealthSlot::from($slot),
            new HealthProbeIndicator(HealthSlot::from($slot), new HealthResult($status, 'v')),
        );
    }

    $html = (string) $this->actingAs(Canary::admin())->get('/admin/system')->assertOk()->getContent();

    foreach ([HealthStatus::Ok, HealthStatus::Warning, HealthStatus::Error, HealthStatus::NotAvailable] as $status) {
        expect($html)->toContain(__('enums.health_status.'.$status->value));
    }

    // Filament gives its badge the grey look without a colour class, so only the three coloured
    // statuses leave a class to assert on; grey is the absence of the other three below.
    expect(substr_count($html, 'fi-color-success'))->toBe(1)
        ->and(substr_count($html, 'fi-color-warning'))->toBe(1)
        ->and(substr_count($html, 'fi-color-danger'))->toBe(1);

    expect(HealthStatus::Ok->getColor())->toBe('success')
        ->and(HealthStatus::Warning->getColor())->toBe('warning')
        ->and(HealthStatus::Error->getColor())->toBe('danger')
        ->and(HealthStatus::NotAvailable->getColor())->toBe('gray');
});

it('shows the checked-at time in Europe/Prague, in summer and in winter time', function (): void {
    $admin = Canary::admin();

    Carbon::setTestNow('2026-07-01 10:00:00 UTC');
    $this->actingAs($admin)->get('/admin/system')->assertOk()->assertSee('1. 7. 2026 12:00:00');

    Carbon::setTestNow('2026-01-15 10:00:00 UTC');
    $this->actingAs($admin)->get('/admin/system')->assertOk()->assertSee('15. 1. 2026 11:00:00');

    Carbon::setTestNow();
});
