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

/*
 * The tasks table is readable by every Partner of the client for the tasks of the
 * client-visible projects (TA-01, D-13), so its column list is pinned in
 * migration order as well. A new column fails this test until a reviewer has
 * decided that every Partner may read it.
 */

it('pins the Partner-safe column list of the tasks table', function (): void {
    expect(Schema::getColumnListing('tasks'))->toBe([
        'id',
        'project_id',
        'parent_id',
        'depth',
        'parent_depth',
        'number',
        'reference',
        'title',
        'description',
        'status',
        'priority',
        'position',
        'start_date',
        'due_date',
        'completed_at',
        'assignee_id',
        'requester_id',
        'escalated_at',
        'escalated_by_id',
        'deleted_at',
        'created_at',
        'updated_at',
    ], 'The tasks table is readable by Partners. Put Admin-only attributes (billing terms, the private checklist, internal comments) into task_billing, task_checklist_items or an internal comment; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no tasks column whose name looks like money, a rate, an estimate, billing or an internal note', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('tasks'),
        static fn (string $column): bool => preg_match('/money|rate|price|estimate|billing|internal|note|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes belong in task_billing, task_checklist_items or an internal comment, not in the Partner-readable tasks table.');
});

/*
 * The task_comments table is readable by every Partner of the client for the
 * non-internal comments of the visible tasks (TA-07, D-08), so its column list is
 * pinned in migration order as well. The internal flag is a column of this table:
 * the model scope drops every internal row for a Partner, and a new column fails
 * this test until a reviewer has decided that every Partner may read it.
 */

it('pins the Partner-safe column list of the task_comments table', function (): void {
    expect(Schema::getColumnListing('task_comments'))->toBe([
        'id',
        'task_id',
        'author_id',
        'body',
        'is_internal',
        'is_escalation',
        'created_at',
        'updated_at',
    ], 'The task_comments table is readable by Partners for non-internal rows. Put Admin-only attributes into an Admin-only table; extend this list only after reviewing that every Partner may read the new column.');
});

it('has no task_comments column whose name looks like money, a rate, a price, an estimate or billing', function (): void {
    $suspicious = array_values(array_filter(
        Schema::getColumnListing('task_comments'),
        static fn (string $column): bool => preg_match('/money|rate|price|estimate|billing|cost|budget/i', $column) === 1,
    ));

    expect($suspicious)->toBe([], 'Admin-only attributes do not belong in the Partner-readable task_comments table.');
});
