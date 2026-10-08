<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

/*
 * The projects table is readable by every Partner of the client, so its column
 * list is pinned (D-05, D-07, research Pitfall 8). A new column fails this test
 * until a reviewer has decided that every Partner may read it.
 */

it('pins the Partner-safe column list of the projects table', function (): void {
    $columns = Schema::getColumnListing('projects');
    sort($columns, SORT_STRING);

    expect($columns)->toBe([
        'client_id',
        'client_visible',
        'created_at',
        'deleted_at',
        'description',
        'end_date',
        'id',
        'key',
        'name',
        'priority',
        'start_date',
        'status',
        'updated_at',
    ], 'The projects table is readable by Partners. Put Admin-only attributes (rate, price, estimate, billing type, internal note) into project_billing or another Admin-only table; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no projects column whose name looks like money, an estimate, billing or an internal note', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('projects'),
        static fn (string $column): bool => preg_match('/rate|price|estimate|billing|note|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes belong in project_billing, not in the Partner-readable projects table.');
});
