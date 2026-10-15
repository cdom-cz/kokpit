---
created: 2026-10-15T09:30:00.000Z
title: Show today's plan under the hours stats on the Admin dashboard
area: ui
severity: minor
files:
  - app/Filament/Widgets/TomorrowPlan.php
  - resources/views/filament/widgets/tomorrow-plan.blade.php
  - app/Filament/Pages/Dashboard.php
  - lang/cs/kokpit.php
  - tests/Feature/Dashboard/AdminDashboardTest.php
---

## Problem

Plan 08-20 added the "Plán na zítřek" (tomorrow's plan) block at the bottom of the Admin dashboard. The owner wants today's plan there instead of tomorrow's, and wants it placed directly under the row of hours stats (the "Dnes / Tento týden / Tento měsíc" stats of `HoursStats`), not at the bottom.

## Solution

Replace tomorrow with today in the widget: the day it reads from the Signal planner, the heading ("Plán na dnešek"), the Czech copy and the empty state, using the Prague day. Rename the widget and view if that keeps the code honest. Move it in `Dashboard::getWidgets()` so it renders right after `HoursStats`, full width, before the two-column deadlines and unbilled row. Update the tests that pin the order and the copy. Keep it read-only and collapsed to three items as in 08-20.

Run before the collapsible-widgets todo (`2026-10-15-collapsible-dashboard-widgets-persisted-in-db.md`), because both touch the dashboard layout.
