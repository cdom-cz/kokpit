---
created: 2026-10-08T21:15:30.293Z
title: Sort tasks by priority then nearest due date by default
area: general
severity: minor
files:
  - app/Filament/Resources/TaskResource.php
  - app/Filament/Resources/TaskResource/Pages/ListTasks.php
  - app/Filament/Resources/TaskColumns.php
---

## Problem

The default ordering of the task list is `updated_at` descending, then `id` descending (set explicitly in plan 05-03, Phase 5 Tasks and Kanban, TA-05). The owner wants the default order to be driven by priority combined with the due date, nearest first, so that the most urgent work is on top.

## Solution

TBD. Proposed starting point: order by priority rank (highest first), then by due date ascending with null due dates last, then a stable tiebreaker (`id`) so paging stays deterministic. Apply it to the Admin list and to the Partner read-only list (same builder where possible), keep the user's own column sorting overriding the default, and update the stability test written in 05-03 (two pages with equal timestamps). Decide how priority and due date combine (strict priority first versus a weighted urgency) before implementing. Candidate for a quick task after Phase 5 or a follow-up inside it.
