<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Probes\UnmappedProbe;
use Tests\Support\Uuids;

it('creates roles through the registered subclass with a version 7 uuid id', function () {
    expect(config('permission.models.role'))->toBe(Role::class);

    $role = Role::findOrCreate('admin');

    expect($role->id)->toBeString()->toMatch(Uuids::V7_PATTERN)
        ->and(Role::find($role->id)?->is($role))->toBeTrue();
});

it('creates permissions through the registered subclass with a version 7 uuid id', function () {
    expect(config('permission.models.permission'))->toBe(Permission::class);

    $permission = Permission::findOrCreate('view-reports');

    expect($permission->id)->toBeString()->toMatch(Uuids::V7_PATTERN)
        ->and(Permission::find($permission->id)?->is($permission))->toBeTrue();
});

it('stores the user alias and the user uuid when a role is assigned', function () {
    expect(config('permission.models.role'))->toBe(Role::class);

    $user = User::factory()->create();
    $role = Role::findOrCreate('admin');
    $user->assignRole($role);

    $row = DB::table('model_has_roles')->first();

    expect($row)->not->toBeNull()
        ->and($row->model_type)->toBe('user')
        ->and($row->model_id)->toBe($user->id)
        ->and($row->role_id)->toBe($role->id)
        ->and($user->fresh()?->hasRole('admin'))->toBeTrue();
});

it('stores the user alias when a permission is given directly and through a role', function () {
    expect(config('permission.models.permission'))->toBe(Permission::class);

    $user = User::factory()->create();
    $permission = Permission::findOrCreate('view-reports');
    $user->givePermissionTo($permission);
    $role = Role::findOrCreate('admin');
    $role->givePermissionTo($permission);

    expect(DB::table('model_has_permissions')->value('model_type'))->toBe('user')
        ->and(DB::table('role_has_permissions')->value('role_id'))->toBe($role->id)
        ->and($user->fresh()?->hasPermissionTo('view-reports'))->toBeTrue();
});

it('throws ClassMorphViolationException for a model missing from the morph map', function () {
    expect(fn () => (new UnmappedProbe)->getMorphClass())
        ->toThrow(ClassMorphViolationException::class);
});

it('throws MassAssignmentException when a non-fillable attribute is set outside production', function () {
    expect(fn () => User::create([
        'name' => 'Client Probe',
        'email' => exampleEmail(),
        'password' => 'not-a-real-secret',
        'client_id' => (string) Str::uuid7(),
    ]))->toThrow(MassAssignmentException::class);

    expect(User::query()->count())->toBe(0);
});

it('still fills the fillable user attributes', function () {
    $user = User::create([
        'name' => 'Fillable Probe',
        'email' => exampleEmail(),
        'password' => 'not-a-real-secret',
    ]);

    expect($user->client_id)->toBeNull()
        ->and(User::query()->count())->toBe(1);
});
