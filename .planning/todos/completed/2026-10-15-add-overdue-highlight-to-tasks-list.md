---
created: 2026-10-15T08:00:00.000Z
title: Add overdue highlight to the admin tasks list
area: ui
severity: minor
files:
  - app/Filament/Resources/TaskResource.php
  - app/Filament/Widgets/UpcomingDeadlines.php
  - resources/views/filament/widgets/upcoming-deadlines-cell.blade.php
  - lang/cs/kokpit.php
---

## Problem

The new "Nejbližší termíny" dashboard widget (plan 08-20) highlights overdue open tasks: the due date in the danger colour, a "Po termínu" badge with a warning icon, and a line "Po termínu o N dnech". The Admin tasks page (`/admin/tasks`) shows the due date without any such signal, so an overdue task there looks the same as any other. The owner wants the same highlight on the tasks list.

## Solution

Reuse the overdue rendering and the Czech copy (`Po termínu`, `Po termínu o :count dnech` with plurals) of the deadlines widget rather than duplicating it, e.g. a shared cell view or column helper. Only open tasks (not done or cancelled) count as overdue, with the day boundary on the Prague clock like the widget. Check the Partner view of the tasks list stays unchanged unless the owner wants it there too. Do this after plan 08-20 is merged so the widget's final markup is the source of truth.
