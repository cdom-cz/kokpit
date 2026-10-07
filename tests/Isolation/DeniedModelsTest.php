<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Auth\PartnerContext;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Canary;
use Tests\Support\Probes\PackageProbe;

afterEach(function (): void {
    PackageProbe::restoreMorphMap();
});

/**
 * One row in each of media, tags, activity log and webhook calls, created
 * through the packages' own APIs inside a system run. Returns the probe host.
 */
function seedAdminOnlyRows(): PackageProbe
{
    config(['media-library.disk_name' => 'local']);
    Storage::fake('local');

    return app(PartnerContext::class)->runAsSystem(function (): PackageProbe {
        $host = PackageProbe::provision();
        $host->addMediaFromString('Fictional probe file content')->usingFileName('probe-note.txt')->toMediaCollection('probe');
        $host->attachTag('fictional-topic');
        activity()->performedOn($host)->log('Fictional probe activity');

        /** @var class-string<WebhookCall> $webhook */
        $webhook = config('webhook-client.configs.0.webhook_model');
        $webhook::create(['name' => 'default', 'url' => 'https://'.implode('.', ['example', 'com']).'/hook', 'payload' => ['event' => 'fictional.probe']]);

        return $host;
    });
}

/**
 * Row counts of the four admin-only models as seen by the current user.
 *
 * @return array<string, int>
 */
function adminOnlyCounts(): array
{
    return [
        'media' => Media::query()->count(),
        'tags' => Tag::query()->count(),
        'activities' => Activity::query()->count(),
        'webhook calls' => WebhookCall::query()->count(),
    ];
}

it('shows an Admin the rows of the four package models', function (): void {
    seedAdminOnlyRows();
    $this->actingAs(Canary::admin());

    expect(adminOnlyCounts())->toBe(['media' => 1, 'tags' => 1, 'activities' => 1, 'webhook calls' => 1]);
});

it('shows a Partner with a client none of the package rows', function (): void {
    seedAdminOnlyRows();
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    expect(adminOnlyCounts())->toBe(['media' => 0, 'tags' => 0, 'activities' => 0, 'webhook calls' => 0]);
});

it('shows a Partner without a client, a guest and a role-less user none of the package rows', function (): void {
    seedAdminOnlyRows();

    expect(adminOnlyCounts())->toBe(['media' => 0, 'tags' => 0, 'activities' => 0, 'webhook calls' => 0]);

    $this->actingAs(Canary::partnerFor(null));
    expect(adminOnlyCounts())->toBe(['media' => 0, 'tags' => 0, 'activities' => 0, 'webhook calls' => 0]);

    $this->actingAs(Canary::userWithoutRole(Canary::twoClients()[0]));
    expect(adminOnlyCounts())->toBe(['media' => 0, 'tags' => 0, 'activities' => 0, 'webhook calls' => 0]);
});

it('keeps the media and tags of a host hidden from a Partner through the relations', function (): void {
    $host = seedAdminOnlyRows();
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]));

    expect($host->media()->count())->toBe(0)
        ->and($host->fresh()?->getMedia('probe'))->toHaveCount(0)
        ->and($host->fresh()?->tags)->toHaveCount(0);
});

it('denies a Partner every ability on the four package models and admits the Admin', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    $admin = Canary::admin();

    foreach ([Media::class, Tag::class, Activity::class, WebhookCall::class] as $class) {
        expect(Gate::forUser($partner)->allows('viewAny', $class))->toBeFalse()
            ->and(Gate::forUser($partner)->allows('create', $class))->toBeFalse()
            ->and(Gate::forUser($admin)->allows('viewAny', $class))->toBeTrue();
    }
});

it('leaves the four authentication models readable for a guest and for a Partner', function (): void {
    $partner = Canary::partnerFor(Canary::twoClients()[0]);
    Permission::findOrCreate('fictional-ability', 'web');
    $plain = $partner->createToken('probe')->plainTextToken;

    // A guest: authentication looks users, roles, permissions and tokens up before anyone is signed in.
    expect(User::query()->count())->toBe(1)
        ->and(Role::query()->count())->toBeGreaterThanOrEqual(2)
        ->and(Permission::query()->count())->toBe(1)
        ->and(PersonalAccessToken::findToken($plain)?->tokenable?->is($partner))->toBeTrue();

    $this->actingAs($partner);

    expect(User::query()->count())->toBe(1)
        ->and($partner->fresh()?->hasRole('partner'))->toBeTrue();
});

it('lets a Partner and an Admin into the panel with the declarations in place', function (): void {
    $this->actingAs(Canary::partnerFor(Canary::twoClients()[0]))->get('/admin')->assertSuccessful();

    $this->actingAs(Canary::admin())->get('/admin')->assertSuccessful();
});
