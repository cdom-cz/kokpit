<?php

declare(strict_types=1);

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
