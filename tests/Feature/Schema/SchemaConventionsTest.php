<?php

declare(strict_types=1);

use App\Domain\Identity\Models\Permission;
use App\Domain\Identity\Models\Role;
use Illuminate\Database\Eloquent\Relations\Relation;
use Spatie\Permission\Models\Permission as BasePermission;
use Spatie\Permission\Models\Role as BaseRole;
use Tests\Support\ModelRules;
use Tests\Support\PgSchema;

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
 * description => [config key, expected subclass, package base class].
 * Plan 02-04 adds the remaining packages here.
 *
 * @return array<string, array{string, class-string, class-string}>
 */
function packageModelRegistry(): array
{
    return [
        'permission roles' => ['permission.models.role', Role::class, BaseRole::class],
        'permission permissions' => ['permission.models.permission', Permission::class, BasePermission::class],
    ];
}

it('R8: package models are the registered HasUuids subclasses', function () {
    $entries = [];
    foreach (packageModelRegistry() as $description => [$configKey, $expected, $base]) {
        $entries[$description] = ['registered' => config($configKey), 'expected' => $expected, 'base' => $base];
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
        ->toContain('not configured: registered NULL')
        ->and(ModelRules::unregisteredPackageModels([
            'fine' => ['registered' => Role::class, 'expected' => Role::class, 'base' => BaseRole::class],
        ]))->toBe([]);
});
