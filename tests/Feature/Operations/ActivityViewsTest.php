<?php

declare(strict_types=1);

use App\Domain\Audit\ActivitySource;
use App\Domain\Shared\Models\Activity;
use App\Filament\Resources\ActivityResource;
use App\Filament\Resources\ActivityResource\Pages\ListActivities;
use App\Filament\Support\ActivityPresenter;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\Canary;
use Tests\Support\Filament\Fixtures\MountProbeWidget;
use Tests\Support\Filament\Fixtures\ProbeActivityHistoryRelationManager;
use Tests\Support\Filament\Fixtures\VisibleOverrideHistoryRelationManager;
use Tests\Support\Filament\Fixtures\VisibleOverrideWidget;
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

/**
 * Writes one activity row directly. Rows built this way carry exactly the
 * values a test needs, so a filter or an order is proven without the model
 * events that normally write them.
 *
 * @param  array<string, mixed>  $attributes
 */
function activityViewsRow(array $attributes = []): Activity
{
    return Activity::query()->forceCreate(array_merge([
        'log_name' => ActivityProbe::ALIAS,
        'description' => 'created',
        'event' => 'created',
        'subject_type' => ActivityProbe::ALIAS,
        'subject_id' => (string) Str::uuid7(),
        'source' => 'web',
        'attribute_changes' => ['attributes' => ['title' => 'Row title']],
    ], $attributes));
}

/**
 * An activity row as the presenter reads it, without touching the database.
 *
 * @param  array<string, mixed>  $changes
 */
function activityViewsChanges(array $changes, string $subjectType = ActivityProbe::ALIAS): string
{
    $activity = new Activity;
    $activity->forceFill(['subject_type' => $subjectType, 'attribute_changes' => $changes]);

    return ActivityPresenter::changes($activity);
}

it('filters the overview by source', function (): void {
    $this->actingAs(Canary::admin());
    $web = activityViewsRow(['source' => 'web']);
    $console = activityViewsRow(['source' => 'console']);
    $job = activityViewsRow(['source' => 'job']);
    $webhook = activityViewsRow(['source' => 'webhook']);

    Livewire::test(ListActivities::class)
        ->filterTable('source', 'job')
        ->assertCanSeeTableRecords([$job])
        ->assertCanNotSeeTableRecords([$web, $console, $webhook]);
});

it('filters the overview by event', function (): void {
    $this->actingAs(Canary::admin());
    $created = activityViewsRow(['event' => 'created']);
    $updated = activityViewsRow(['event' => 'updated']);
    $deleted = activityViewsRow(['event' => 'deleted']);

    Livewire::test(ListActivities::class)
        ->filterTable('event', 'deleted')
        ->assertCanSeeTableRecords([$deleted])
        ->assertCanNotSeeTableRecords([$created, $updated]);
});

it('filters the overview by user, including the no user choice', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);
    $byAdmin = activityViewsRow(['causer_type' => 'user', 'causer_id' => $admin->getKey()]);
    $byNobody = activityViewsRow(['source' => 'console']);

    Livewire::test(ListActivities::class)
        ->filterTable('causer', 'none')
        ->assertCanSeeTableRecords([$byNobody])
        ->assertCanNotSeeTableRecords([$byAdmin]);

    Livewire::test(ListActivities::class)
        ->filterTable('causer', $admin->getKey())
        ->assertCanSeeTableRecords([$byAdmin])
        ->assertCanNotSeeTableRecords([$byNobody]);
});

it('offers the user filter every user and the no user choice', function (): void {
    $admin = Canary::admin();
    $this->actingAs($admin);

    $options = Livewire::test(ListActivities::class)->instance()->getTable()->getFilter('causer')?->getOptions();

    expect($options)->toHaveKey('none')
        ->and($options)->toHaveKey($admin->getKey());
});

it('filters the overview by subject type and offers the merged probe alias only while it is merged', function (): void {
    $this->actingAs(Canary::admin());
    $probeRow = activityViewsRow(['subject_type' => ActivityProbe::ALIAS]);
    $otherRow = activityViewsRow(['subject_type' => 'user']);

    $list = Livewire::test(ListActivities::class);

    expect($list->instance()->getTable()->getFilter('subject_type')?->getOptions())->toHaveKey(ActivityProbe::ALIAS);

    $list->filterTable('subject_type', ActivityProbe::ALIAS)
        ->assertCanSeeTableRecords([$probeRow])
        ->assertCanNotSeeTableRecords([$otherRow]);

    ActivityProbe::restoreMorphMap();

    expect(Livewire::test(ListActivities::class)->instance()->getTable()->getFilter('subject_type')?->getOptions())
        ->not->toHaveKey(ActivityProbe::ALIAS);
});

it('finds a row by its Europe/Prague day, not by its UTC day', function (): void {
    $this->actingAs(Canary::admin());
    // 23:30 UTC on the 8th is 01:30 on the 9th in Prague (summer time); 21:59 UTC on the 8th is still the 8th.
    $nextPragueDay = activityViewsRow(['created_at' => '2026-10-08 23:30:00+00']);
    $samePragueDay = activityViewsRow(['created_at' => '2026-10-08 21:59:59+00']);

    Livewire::test(ListActivities::class)
        ->filterTable('created_at', ['created_from' => '2026-10-09', 'created_until' => '2026-10-09'])
        ->assertCanSeeTableRecords([$nextPragueDay])
        ->assertCanNotSeeTableRecords([$samePragueDay]);

    Livewire::test(ListActivities::class)
        ->filterTable('created_at', ['created_from' => '2026-10-08', 'created_until' => '2026-10-08'])
        ->assertCanSeeTableRecords([$samePragueDay])
        ->assertCanNotSeeTableRecords([$nextPragueDay]);
});

it('lists rows with the same time by id descending, identically across two renders', function (): void {
    $this->actingAs(Canary::admin());
    $first = activityViewsRow(['id' => '00000000-0000-7000-8000-000000000001', 'created_at' => '2026-10-08 10:00:00+00']);
    $second = activityViewsRow(['id' => '00000000-0000-7000-8000-000000000002', 'created_at' => '2026-10-08 10:00:00+00']);
    $newest = activityViewsRow(['id' => '00000000-0000-7000-8000-000000000000', 'created_at' => '2026-10-08 11:00:00+00']);

    foreach ([1, 2] as $render) {
        Livewire::test(ListActivities::class)
            ->assertCanSeeTableRecords([$newest, $second, $first], inOrder: true);
    }
});

it('shows a boolean change in Czech words and an empty value as a dash', function (): void {
    expect(activityViewsChanges(['attributes' => ['is_open' => true], 'old' => ['is_open' => false]]))->toBe('is_open: ne -> ano')
        ->and(activityViewsChanges(['attributes' => ['note' => 'filled'], 'old' => ['note' => null]]))->toBe('note: — -> filled')
        ->and(activityViewsChanges(['attributes' => ['note' => null], 'old' => ['note' => 'filled']]))->toBe('note: filled -> —');
});

it('cuts a long value at 80 characters with an ellipsis', function (): void {
    $long = str_repeat('a', 200);

    $text = activityViewsChanges(['attributes' => ['note' => $long], 'old' => ['note' => 'short']]);

    expect($text)->toBe('note: short -> '.str_repeat('a', 80).'…');
});

it('uses a translated attribute name when one exists and the raw name otherwise', function (): void {
    // Load the group first: adding a line to an unloaded group would hide the rest of the file.
    trans('kokpit.activity.yes');
    app('translator')->addLines(['kokpit.activity.attributes.'.ActivityProbe::ALIAS.'.title' => 'Název'], 'cs');

    expect(activityViewsChanges(['attributes' => ['title' => 'New'], 'old' => ['title' => 'Old']]))->toBe('Název: Old -> New')
        ->and(activityViewsChanges(['attributes' => ['status' => 'done'], 'old' => ['status' => 'open']]))->toBe('status: open -> done')
        ->and(activityViewsChanges(['attributes' => ['title' => 'New'], 'old' => ['title' => 'Old']], 'unlabelled'))->toBe('title: Old -> New');
});

it('never renders the properties payload of a row', function (): void {
    $this->actingAs(Canary::admin());
    $canary = Canary::canary('properties');
    $row = activityViewsRow([
        'properties' => ['note' => $canary, 'nested' => ['deep' => $canary]],
        'attribute_changes' => ['attributes' => ['title' => 'Visible title']],
    ]);

    Livewire::test(ListActivities::class)
        ->assertCanSeeTableRecords([$row])
        ->assertSee('Visible title')
        ->assertDontSee($canary);
});

/**
 * Mounts a history relation manager for the owner record the way a resource
 * page does.
 *
 * @param  class-string<Component>  $manager
 */
function activityViewsMountHistory(string $manager, ActivityProbe $owner): Testable
{
    return Livewire::test($manager, ['ownerRecord' => $owner, 'pageClass' => ListActivities::class]);
}

/**
 * The users of the refusal matrix: every state that is not the Admin.
 *
 * @return array<string, Closure(): Authenticatable>
 */
function activityViewsRefusedUsers(): array
{
    return [
        'partner with a client' => fn () => Canary::partnerFor(Canary::twoClients()[0]),
        'partner without a client' => fn () => Canary::partnerFor(null),
        'user without a role' => fn () => Canary::userWithoutRole(null),
    ];
}

it('lists only the history of its own record, newest first, with no actions', function (): void {
    $this->actingAs(Canary::admin());
    $one = ActivityProbe::query()->create(['title' => 'Probe one', 'status' => 'open']);
    $two = ActivityProbe::query()->create(['title' => 'Probe two', 'status' => 'open']);
    $this->travel(1)->minutes();
    $one->update(['status' => 'done']);

    $oneRows = Activity::query()->where('subject_id', $one->getKey())->orderByDesc('created_at')->orderByDesc('id')->get();
    $twoRows = Activity::query()->where('subject_id', $two->getKey())->get();

    expect($oneRows)->toHaveCount(2)
        ->and($twoRows)->toHaveCount(1);

    $component = activityViewsMountHistory(ProbeActivityHistoryRelationManager::class, $one)
        ->assertOk()
        ->assertCanSeeTableRecords($oneRows, inOrder: true)
        ->assertCanNotSeeTableRecords($twoRows)
        ->assertSee('status: open -> done');

    $table = $component->instance()->getTable();

    expect($table->getHeaderActions())->toBe([])
        ->and($table->getRecordActions())->toBe([])
        ->and($table->getBulkActions())->toBe([])
        ->and($component->instance()->isReadOnly())->toBeTrue();
});

it('does not repeat the subject column in the history of one record', function (): void {
    $this->actingAs(Canary::admin());
    $probe = ActivityProbe::query()->create(['title' => 'Probe one', 'status' => 'open']);

    $table = activityViewsMountHistory(ProbeActivityHistoryRelationManager::class, $probe)->instance()->getTable();

    expect(array_keys($table->getColumns()))->not->toContain('subject_type')
        ->and(array_keys(Livewire::test(ListActivities::class)->instance()->getTable()->getColumns()))->toContain('subject_type');
});

it('refuses a Partner before the history relation manager loads a row or runs mount()', function (): void {
    $this->actingAs(Canary::admin());
    $probe = ActivityProbe::query()->create(['title' => 'Probe one', 'status' => 'open']);
    auth()->logout();

    foreach (activityViewsRefusedUsers() as $state => $make) {
        ProbeActivityHistoryRelationManager::$mounted = false;
        $this->actingAs($make());

        $queries = [];
        DB::listen(static function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });

        activityViewsMountHistory(ProbeActivityHistoryRelationManager::class, $probe)->assertForbidden();

        expect(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'activity_log')))->toBe([], $state)
            ->and(ProbeActivityHistoryRelationManager::$mounted)->toBeFalse($state);
    }
});

it('refuses a Partner at boot even when the relation manager visibility check was overridden to pass', function (): void {
    $this->actingAs(Canary::admin());
    $probe = ActivityProbe::query()->create(['title' => 'Probe one', 'status' => 'open']);
    auth()->logout();

    foreach (activityViewsRefusedUsers() as $state => $make) {
        VisibleOverrideHistoryRelationManager::$mounted = false;
        $this->actingAs($make());

        activityViewsMountHistory(VisibleOverrideHistoryRelationManager::class, $probe)->assertForbidden();

        expect(VisibleOverrideHistoryRelationManager::$mounted)->toBeFalse($state);
    }

    VisibleOverrideHistoryRelationManager::$mounted = false;
    $this->actingAs(Canary::admin());

    activityViewsMountHistory(VisibleOverrideHistoryRelationManager::class, $probe)->assertOk();

    expect(VisibleOverrideHistoryRelationManager::$mounted)->toBeTrue();
});

it('refuses a Partner before the widget mount() runs and renders the widget for an Admin', function (): void {
    foreach (activityViewsRefusedUsers() as $state => $make) {
        MountProbeWidget::$mounted = false;
        $this->actingAs($make());

        Livewire::test(MountProbeWidget::class)->assertForbidden();

        expect(MountProbeWidget::$mounted)->toBeFalse($state);
    }

    MountProbeWidget::$mounted = false;
    $this->actingAs(Canary::admin());

    Livewire::test(MountProbeWidget::class)->assertOk();

    expect(MountProbeWidget::$mounted)->toBeTrue();
});

it('refuses a Partner at boot even when the widget visibility check was overridden to pass', function (): void {
    foreach (activityViewsRefusedUsers() as $state => $make) {
        VisibleOverrideWidget::$mounted = false;
        $this->actingAs($make());

        Livewire::test(VisibleOverrideWidget::class)->assertForbidden();

        expect(VisibleOverrideWidget::$mounted)->toBeFalse($state);
    }

    VisibleOverrideWidget::$mounted = false;
    $this->actingAs(Canary::admin());

    Livewire::test(VisibleOverrideWidget::class)->assertOk();

    expect(VisibleOverrideWidget::$mounted)->toBeTrue();
});

it('keeps the Activity model closed to every Partner state', function (): void {
    $this->actingAs(Canary::admin());
    ActivityProbe::query()->create(['title' => 'Probe one', 'status' => 'open']);
    auth()->logout();

    foreach (activityViewsRefusedUsers() as $state => $make) {
        $this->actingAs($make());

        expect(Activity::query()->count())->toBe(0, $state)
            ->and(Gate::allows('viewAny', Activity::class))->toBeFalse($state);
    }
});
