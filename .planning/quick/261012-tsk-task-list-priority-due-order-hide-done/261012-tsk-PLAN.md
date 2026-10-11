---
phase: quick-261012-tsk
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Filament/Resources/TaskResource.php
  - app/Filament/Support/TaskColumns.php
  - tests/Feature/Tasks/TaskListFiltersTest.php
---

# Admin task list: priority and due date order, Done hidden, multi-state filter

## Goal

On `/admin/tasks`:

1. The default order is priority (Urgent, High, Normal, Low), then the nearest due date. A task without a due date goes last within its priority. The id descending is the tie-breaker.
2. Done tasks are hidden by default.
3. The status filter takes several states at once.

## Tasks

1. `TaskColumns::orderByPriority()` orders by the priority weight with a CASE built from the `ProjectPriority` constants. The Admin priority column sorts through it instead of alphabetically.
2. `TaskResource::table()` default sort: `orderByPriority`, `due_date ASC NULLS LAST`, `id DESC`.
3. The `status` SelectFilter becomes `multiple()` with a default of every state except Done. An empty selection lists every state.
4. Tests in `TaskListFiltersTest`: Done hidden by default, the default selection, the multi-state filter, priority order, due date order within a priority, priority before due date, stable paging, and sorting the priority column by weight. The existing status filter calls move to arrays.
