---
phase: quick-261012-tsk
plan: 01
subsystem: tasks-admin-list
tags: [filament, tasks, sorting, filters]
requires: []
provides:
  - default order of the Admin task list by priority, then the nearest due date (undated last)
  - Done tasks hidden by default through a multi-state status filter
  - priority column sorted by weight
key-files:
  created: []
  modified:
    - app/Filament/Resources/TaskResource.php
    - app/Filament/Support/TaskColumns.php
    - tests/Feature/Tasks/TaskListFiltersTest.php
audit_acknowledged:
  milestone: v1
  at: 2026-10-30
  status: unknown
---

# Summary

- The default sort is `CASE priority` (Urgent 0 … Low 3), then `due_date ASC NULLS LAST`, then `id DESC`. The CASE values are enum constants only.
- The status filter is multiple. Its default is every `ProjectStatus` except `done`. Removing its indicator clears it and lists every state. Resetting the filters brings the default back.
- The Admin priority column sorts through the same CASE.
- The Partner list is unchanged.
- The two tests of the old `updated_at` order were replaced by tests of the new order. The status filter tests now pass arrays.

## Verification

The session had no PHP 8.5, and composer could not reach GitHub, so the suite was not run locally. `php -l` is clean on the changed files. The Filament 5 APIs used (`SelectFilter::multiple`, `getDefaultState`, `isMultiple`, `filterTable` with arrays, `sortable(query:)`) were checked against the Filament 5.x sources. CI runs the suite.
