<?php

declare(strict_types=1);

use App\Domain\Clients\Models\Client;
use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use App\Domain\Shared\Auth\KokpitPolicy;
use App\Domain\Shared\Auth\NotPartnerScoped;
use App\Domain\Shared\Auth\PartnerIsolated;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use App\Domain\Shared\Policies\AdminOnlyPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Tests\Support\CanaryRecord;
use Tests\Support\ModelDeclaration;

/*
 * No model can exist without an isolation decision (D-03): it is either
 * PartnerIsolated (scoped, with a policy on KokpitPolicy) or explicitly
 * #[NotPartnerScoped] with a written reason.
 */

it('finds every model of the application, so the scan cannot pass vacuously', function (): void {
    expect(ModelDeclaration::appModels())->toEqualCanonicalizing([
        Activity::class,
        Client::class,
        Media::class,
        Permission::class,
        PersonalAccessToken::class,
        Project::class,
        Role::class,
        SettingsProperty::class,
        Tag::class,
        User::class,
        WebhookCall::class,
    ]);
});

it('declares the isolation of every concrete model on the class itself', function (): void {
    $problems = [];

    foreach (ModelDeclaration::appModels() as $class) {
        $problems = [...$problems, ...ModelDeclaration::problems($class)];
    }

    expect($problems)->toBe([]);
});

it('reports a model with neither declaration, with both, and without a reason', function (): void {
    $neither = new class extends Model {};
    $both = new #[NotPartnerScoped(reason: 'authentication reads it')] class extends Model implements PartnerIsolated
    {
        public function constrainForPartner(Builder $query, string $clientId): void {}
    };
    $blank = new #[NotPartnerScoped(reason: '  ')] class extends Model {};
    $fine = new #[NotPartnerScoped(reason: 'authentication reads it')] class extends Model {};
    $inherited = new class extends User {};

    expect(ModelDeclaration::problems($neither::class))->toHaveCount(1)
        ->and(ModelDeclaration::problems($neither::class)[0])->toContain('neither')
        ->and(ModelDeclaration::problems($both::class))->toHaveCount(1)
        ->and(ModelDeclaration::problems($both::class)[0])->toContain('both')
        ->and(ModelDeclaration::problems($blank::class))->toHaveCount(1)
        ->and(ModelDeclaration::problems($blank::class)[0])->toContain('without a reason')
        ->and(ModelDeclaration::problems($fine::class))->toBe([])
        ->and(ModelDeclaration::problems($inherited::class))->toHaveCount(1);
});

it('gives every PartnerIsolated model a policy that extends KokpitPolicy', function (): void {
    $isolated = array_values(array_filter(
        [...ModelDeclaration::appModels(), CanaryRecord::class],
        static fn (string $class): bool => is_subclass_of($class, PartnerIsolated::class),
    ));

    expect($isolated)->toContain(CanaryRecord::class, Client::class, Project::class, Media::class, Tag::class, Activity::class, WebhookCall::class, SettingsProperty::class);

    foreach ($isolated as $class) {
        expect(Gate::getPolicyFor($class))->toBeInstanceOf(KokpitPolicy::class, "no KokpitPolicy for {$class}");
    }
});

it('closes the five package models to Partners with the admin-only policy', function (): void {
    foreach ([Media::class, Tag::class, Activity::class, WebhookCall::class, SettingsProperty::class] as $class) {
        expect(Gate::getPolicyFor($class))->toBeInstanceOf(AdminOnlyPolicy::class);
    }
});

it('declares the four authentication models as not partner scoped, each with a reason', function (): void {
    foreach ([User::class, Role::class, Permission::class, PersonalAccessToken::class] as $class) {
        $attributes = (new ReflectionClass($class))->getAttributes(NotPartnerScoped::class);

        expect($attributes)->toHaveCount(1)
            ->and(trim((string) $attributes[0]->getArguments()['reason']))->not->toBe('')
            ->and(is_subclass_of($class, PartnerIsolated::class))->toBeFalse();
    }
});
