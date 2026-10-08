<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\PersonalAccessToken;
use App\Domain\Identity\Models\Role;
use App\Domain\Identity\Models\User;
use App\Domain\Shared\Models\Activity;
use App\Domain\Shared\Models\Media;
use App\Domain\Shared\Models\SettingsProperty;
use App\Domain\Shared\Models\Tag;
use App\Domain\Shared\Models\WebhookCall;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken as BasePersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Spatie\Activitylog\Models\Activity as BaseActivity;
use Spatie\LaravelSettings\Models\SettingsProperty as BaseSettingsProperty;
use Spatie\MediaLibrary\MediaCollections\Models\Media as BaseMedia;
use Spatie\Permission\Models\Permission as BasePermission;
use Spatie\Permission\Models\Role as BaseRole;
use Spatie\Tags\Tag as BaseTag;
use Spatie\WebhookClient\Models\WebhookCall as BaseWebhookCall;
use Tests\Support\ModelRules;
use Tests\Support\PgSchema;
use Tests\Support\Probes\PackageProbe;
use Tests\Support\Probes\ProbeNotification;

/**
 * Builds a synthetic catalogue row. Defaults describe a harmless uuid column.
 *
 * @param  array<string, mixed>  $overrides
 */
function schemaRow(array $overrides = []): object
{
    return (object) array_merge([
        'tbl' => 'widgets',
        'col' => 'name',
        'udt' => 'character varying',
        'typ' => 'character varying(255)',
        'auto_inc' => false,
        'is_fk' => false,
        'is_single_pk' => false,
        'default_expr' => null,
    ], $overrides);
}

/**
 * @param  list<string>  $violations
 */
function violationMessage(string $rule, array $violations): string
{
    return "{$rule} violated by: ".implode(', ', $violations);
}

it('R1: every id, *_id, foreign key and single-column primary key column is uuid', function () {
    $violations = PgSchema::nonUuidKeys(PgSchema::columns());

    expect($violations)->toBe([], violationMessage('R1', $violations));
});

it('R1 self-check: reports non-uuid keys and honours the exempt map', function () {
    $columns = collect([
        schemaRow(['col' => 'id', 'udt' => 'bigint']),
        schemaRow(['col' => 'owner_id', 'udt' => 'bigint']),
        schemaRow(['col' => 'parent', 'udt' => 'bigint', 'is_fk' => true]),
        schemaRow(['col' => 'code', 'udt' => 'text', 'is_single_pk' => true]),
        schemaRow(['col' => 'fine_id', 'udt' => 'uuid']),
    ]);

    expect(PgSchema::nonUuidKeys($columns, []))
        ->toBe(['widgets.code', 'widgets.id', 'widgets.owner_id', 'widgets.parent'])
        ->and(PgSchema::nonUuidKeys($columns, ['widgets.id' => 'reason']))
        ->not->toContain('widgets.id');
});

it('R2: no identity column and no nextval() default outside the exempt map', function () {
    $violations = PgSchema::autoIncrementing(PgSchema::columns());

    expect($violations)->toBe([], violationMessage('R2', $violations));
});

it('R2 self-check: reports auto-incrementing columns', function () {
    $columns = collect([
        schemaRow(['col' => 'id', 'udt' => 'bigint', 'auto_inc' => true]),
        schemaRow(['col' => 'name']),
    ]);

    expect(PgSchema::autoIncrementing($columns, []))->toBe(['widgets.id']);
});

it('R4: no column is a timestamp without time zone', function () {
    $violations = PgSchema::timestampsWithoutZone(PgSchema::columns());

    expect($violations)->toBe([], violationMessage('R4', $violations));
});

it('R4 self-check: reports timestamp without time zone but accepts timestamptz', function () {
    $columns = collect([
        schemaRow(['col' => 'created_at', 'udt' => 'timestamp without time zone', 'typ' => 'timestamp(0) without time zone']),
        schemaRow(['col' => 'updated_at', 'udt' => 'timestamp with time zone', 'typ' => 'timestamp(0) with time zone']),
    ]);

    expect(PgSchema::timestampsWithoutZone($columns))->toBe(['widgets.created_at']);
});

it('R5: every exempt entry still names an existing column', function () {
    $stale = PgSchema::staleExemptions(PgSchema::columns());

    expect($stale)->toBe([], violationMessage('R5', $stale));
});

it('R5 self-check: reports an exempt entry whose column is gone', function () {
    $columns = collect([schemaRow(['col' => 'id', 'udt' => 'uuid'])]);

    expect(PgSchema::staleExemptions($columns, ['widgets.id' => 'present', 'ghosts.id' => 'gone']))
        ->toBe(['ghosts.id']);
});

it('R6: every single-column uuid primary key defaults to uuidv7()', function () {
    $violations = PgSchema::missingUuidv7Default(PgSchema::columns());

    expect($violations)->toBe([], violationMessage('R6', $violations));
});

it('R6 self-check: reports a uuid primary key without the uuidv7() default', function () {
    $columns = collect([
        schemaRow(['col' => 'id', 'udt' => 'uuid', 'is_single_pk' => true, 'default_expr' => null]),
        schemaRow(['tbl' => 'gadgets', 'col' => 'id', 'udt' => 'uuid', 'is_single_pk' => true, 'default_expr' => 'gen_random_uuid()']),
        schemaRow(['tbl' => 'gizmos', 'col' => 'id', 'udt' => 'uuid', 'is_single_pk' => true, 'default_expr' => 'uuidv7()']),
    ]);

    expect(PgSchema::missingUuidv7Default($columns))->toBe(['gadgets.id', 'widgets.id']);
});

it('keeps the exempt map to the four documented framework columns with reasons', function () {
    expect(array_keys(PgSchema::EXEMPT))->toBe([
        'migrations.id',
        'failed_jobs.id',
        'sessions.id',
        'password_reset_tokens.email',
    ])->and(array_filter(PgSchema::EXEMPT, fn (string $reason): bool => trim($reason) === ''))->toBe([]);
});

it('no longer creates the unused jobs, job_batches, cache and cache_locks tables', function () {
    $tables = PgSchema::columns()->pluck('tbl')->unique()->all();

    expect($tables)->not->toContain('jobs')
        ->not->toContain('job_batches')
        ->not->toContain('cache')
        ->not->toContain('cache_locks');
});

it('R3: every <x>_type column has a uuid <x>_id sibling', function () {
    $violations = PgSchema::nonUuidMorphKeys(PgSchema::columns());

    expect($violations)->toBe([], violationMessage('R3', $violations));
});

it('R3 self-check: reports a morph pair whose id sibling is not uuid', function () {
    $columns = collect([
        schemaRow(['tbl' => 'bad_pivots', 'col' => 'model_type']),
        schemaRow(['tbl' => 'bad_pivots', 'col' => 'model_id', 'udt' => 'bigint']),
        schemaRow(['tbl' => 'good_pivots', 'col' => 'model_type']),
        schemaRow(['tbl' => 'good_pivots', 'col' => 'model_id', 'udt' => 'uuid']),
        schemaRow(['tbl' => 'lonely', 'col' => 'kind_type']),
    ]);

    expect(PgSchema::nonUuidMorphKeys($columns))->toBe(['bad_pivots.model_id']);
});

it('R7: the morph map is enforced, snake_case and made of HasUuids models', function () {
    $violations = ModelRules::morphMapViolations(Relation::morphMap(), Relation::requiresMorphMap());

    expect($violations)->toBe([], violationMessage('R7', $violations));
});

it('R7 self-check: reports an empty map, a missing enforcement, bad aliases and a class without HasUuids', function () {
    expect(ModelRules::morphMapViolations([], true))->toContain('the morph map is empty')
        ->and(ModelRules::morphMapViolations(['user' => Role::class], false))
        ->toContain('the morph map is not required (enforceMorphMap is not active)')
        ->and(ModelRules::morphMapViolations(['App\\Models\\User' => Role::class], true))
        ->toContain("alias 'App\\Models\\User' contains a backslash")
        ->and(ModelRules::morphMapViolations(['TimeEntry' => Role::class], true))
        ->toContain("alias 'TimeEntry' is not snake_case")
        ->and(ModelRules::morphMapViolations(['plain' => stdClass::class], true))
        ->toContain('stdClass (alias \'plain\') does not use HasUuids');
});

/**
 * Package models that must be registered as HasUuids subclasses:
 * description => [registered class as the package resolves it, expected subclass, package base class].
 *
 * @return array<string, array{mixed, class-string, class-string}>
 */
function packageModelRegistry(): array
{
    $registry = [
        'permission roles' => [config('permission.models.role'), Role::class, BaseRole::class],
        'permission permissions' => [config('permission.models.permission'), Permission::class, BasePermission::class],
        'medialibrary media' => [config('media-library.media_model'), Media::class, BaseMedia::class],
        'tags tags' => [config('tags.tag_model'), Tag::class, BaseTag::class],
        'activitylog activities' => [config('activitylog.activity_model'), Activity::class, BaseActivity::class],
        'settings properties' => [config('settings.repositories.database.model'), SettingsProperty::class, BaseSettingsProperty::class],
        'sanctum tokens' => [Sanctum::$personalAccessTokenModel, PersonalAccessToken::class, BasePersonalAccessToken::class],
    ];

    /** @var array<int|string, array<string, mixed>> $webhookConfigs */
    $webhookConfigs = config('webhook-client.configs', []);
    foreach ($webhookConfigs as $index => $webhookConfig) {
        $registry["webhook-client config {$index}"] = [$webhookConfig['webhook_model'] ?? null, WebhookCall::class, BaseWebhookCall::class];
    }

    return $registry;
}

it('R8: package models are the registered HasUuids subclasses', function () {
    $entries = [];
    foreach (packageModelRegistry() as $description => [$registered, $expected, $base]) {
        $entries[$description] = ['registered' => $registered, 'expected' => $expected, 'base' => $base];
    }

    $violations = ModelRules::unregisteredPackageModels($entries);

    expect($violations)->toBe([], violationMessage('R8', $violations));
});

it('R8 self-check: reports an unregistered base model, a wrong parent and a missing HasUuids', function () {
    $violations = ModelRules::unregisteredPackageModels([
        'unregistered' => ['registered' => BaseRole::class, 'expected' => Role::class, 'base' => BaseRole::class],
        'wrong parent' => ['registered' => Permission::class, 'expected' => Permission::class, 'base' => BaseRole::class],
        'no uuids' => ['registered' => BaseRole::class, 'expected' => BaseRole::class, 'base' => BaseRole::class],
        'not configured' => ['registered' => null, 'expected' => Role::class, 'base' => BaseRole::class],
    ]);
    $joined = implode("\n", $violations);

    expect($joined)->toContain('unregistered: registered')
        ->toContain('wrong parent: ')
        ->toContain('no uuids: ')
        ->toContain('not configured: registered null')
        ->and(ModelRules::unregisteredPackageModels([
            'fine' => ['registered' => Role::class, 'expected' => Role::class, 'base' => BaseRole::class],
        ]))->toBe([]);
});

/**
 * One row through every package that writes a morph type column.
 */
function exercisePackages(): void
{
    config(['media-library.disk_name' => 'local']);
    Storage::fake('local');

    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('admin'));
    $user->givePermissionTo(Permission::findOrCreate('view-reports'));
    $user->createToken('probe');
    $user->notify(new ProbeNotification);

    $host = PackageProbe::provision();
    $host->addMediaFromString('Fictional probe file content')->usingFileName('probe-note.txt')->toMediaCollection('probe');
    $host->attachTag('fictional-topic');

    activity()->performedOn($user)->causedBy($user)->log('Fictional probe activity');
}

it('R9: after one exercising seed every stored morph type is a key of the morph map', function () {
    exercisePackages();

    $stored = [];
    foreach (PgSchema::morphTypeColumns(PgSchema::columns()) as [$table, $column]) {
        /** @var list<string> $values */
        $values = DB::table($table)->whereNotNull($column)->distinct()->pluck($column)->all();
        $stored["{$table}.{$column}"] = $values;
    }

    $violations = PgSchema::unmappedMorphTypes($stored, Relation::morphMap());
    $exercised = array_keys(array_filter($stored, fn (array $values): bool => $values !== []));

    PackageProbe::restoreMorphMap();

    expect($violations)->toBe([], violationMessage('R9', $violations))
        ->and($exercised)->toBe([
            'activity_log.causer_type',
            'activity_log.subject_type',
            'media.model_type',
            'model_has_permissions.model_type',
            'model_has_roles.model_type',
            'notifications.notifiable_type',
            'personal_access_tokens.tokenable_type',
            'taggables.taggable_type',
        ]);
});

it('R9 self-check: reports a stored morph type that is not a morph map key', function () {
    $violations = PgSchema::unmappedMorphTypes(
        ['media.model_type' => ['user', 'App\\Models\\Legacy'], 'notifications.notifiable_type' => ['user'], 'tags.empty_type' => []],
        ['user' => User::class],
    );

    expect($violations)->toBe(['media.model_type=App\\Models\\Legacy']);
});

it('R9 self-check: finds the morph type columns through their id sibling', function () {
    $columns = collect([
        schemaRow(['tbl' => 'media', 'col' => 'model_type']),
        schemaRow(['tbl' => 'media', 'col' => 'model_id', 'udt' => 'uuid']),
        schemaRow(['tbl' => 'tags', 'col' => 'kind_type']),
        schemaRow(['tbl' => 'tags', 'col' => 'type']),
    ]);

    expect(PgSchema::morphTypeColumns($columns))->toBe([['media', 'model_type']]);
});
