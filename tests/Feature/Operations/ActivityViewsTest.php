<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySource;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\Probes\ActivityProbe;
use Tests\Support\Probes\ActivityProbeJob;

/*
 * The audit trail on screen (D-07): a global read-only overview for the Admin,
 * closed to every Partner state. The probe model supplies the activity rows.
 */

beforeEach(function (): void {
    ActivityProbe::provision();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function (): void {
    ActivityProbe::restoreMorphMap();
});

/**
 * Makes the application read as a web request: the test runner is a console
 * process, so the console detector is replaced.
 */
function activityViewsWebRequest(): void
{
    app()->instance(ActivitySource::class, new ActivitySource(fn (): bool => false));
}

function activityViewsUrl(): string
{
    return route('filament.admin.resources.activity.index');
}

it('shows the Admin a probe change with its Czech event, subject, source and old to new value', function (): void {
    ActivityProbeJob::dispatch();
    $this->travel(1)->minutes();

    activityViewsWebRequest();
    $admin = Canary::admin();
    $this->actingAs($admin);

    $probe = ActivityProbe::query()->create(['title' => 'First title', 'status' => 'open']);
    $this->travel(1)->minutes();
    $probe->update(['status' => 'done']);

    $rows = Activity::query()->orderByDesc('created_at')->orderByDesc('id')->get();

    expect($rows)->toHaveCount(3);

    Livewire::test(ListActivities::class)
        ->assertOk()
        ->assertCanSeeTableRecords($rows, inOrder: true)
        ->assertSee('Vytvořeno')
        ->assertSee('Změněno')
        ->assertSee('status: open -> done')
        ->assertSee(ActivityProbe::ALIAS)
        ->assertSee('…'.substr((string) $probe->getKey(), -8))
        ->assertSee($admin->name)
        ->assertSee('Úloha na pozadí');
});

it('renders a row whose subject is gone and never queries the subject table', function (): void {
    activityViewsWebRequest();
    $this->actingAs(Canary::admin());

    $probe = ActivityProbe::query()->create(['title' => 'Short lived', 'status' => 'open']);
    $probe->delete();

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    Livewire::test(ListActivities::class)
        ->assertOk()
        ->assertSee('Smazáno')
        ->assertSee('Vytvořeno');

    $touchingSubject = array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'activity_probes'));

    expect($touchingSubject)->toBe([]);
});

it('refuses the overview to a Partner with a client, a Partner without a client and a user without a role', function (): void {
    activityViewsWebRequest();
    $this->actingAs(Canary::admin());
    ActivityProbe::query()->create(['title' => 'Hidden from partners', 'status' => 'open']);
    auth()->logout();

    foreach ([
        'partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]),
        'partner without a client' => fn () => Canary::partnerFor(null),
        'user without a role' => fn () => Canary::userWithoutRole(null),
    ] as $state => $make) {
        $this->actingAs($make())->get(activityViewsUrl())->assertForbidden();
    }
});

it('is read-only: no create, edit or view route and no create permission', function (): void {
    $this->actingAs(Canary::admin());

    expect(ActivityResource::canCreate())->toBeFalse()
        ->and(array_keys(ActivityResource::getPages()))->toBe(['index'])
        ->and(Route::has('filament.admin.resources.activity.create'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.activity.edit'))->toBeFalse()
        ->and(Route::has('filament.admin.resources.activity.view'))->toBeFalse();
});

it('shows the Czech navigation label and group to the Admin', function (): void {
    $this->actingAs(Canary::admin())->get('/admin')
        ->assertOk()
        ->assertSee('Historie změn')
        ->assertSee('Správa');
});

it('does not show the navigation entry to a Partner', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]))->get('/admin')
        ->assertOk()
        ->assertDontSee('Historie změn');
});
