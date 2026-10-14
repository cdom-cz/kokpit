---
created: 2026-10-14T20:53:28.840Z
title: Redesign unreadable time report layout
area: ui
severity: major
gap: true
files:
  - app/Filament/Pages/TimeReportPage.php
  - resources/views/filament/pages/time-report.blade.php
---

## Problem

The time report page (`/admin/time-report`, plan 08-09) works functionally, but the owner judges it practically unusable because it is hard to read (reported with a screenshot during phase 8 execution). To be treated as a gap against the phase 8 goal (RE-01, RE-02), not as polish.

Observed in the screenshot:

- The client > project > task tree puts every task title into a very narrow first column, so titles wrap to one word per line and each task row becomes very tall.
- Seven numeric columns (worked, billable, non-billable, billed, unbilled, amount, amount in CZK) compete for width with the name column.
- Client, project and task levels are hard to tell apart; the indentation eats the little width the name column has.
- The page is a very long scroll with no way to collapse levels.

## Solution

TBD, needs a design proposal first (UI-SPEC amendment before code). Options to evaluate: a wider or sticky name column, single-line truncation with a tooltip, collapsible client/project levels (collapsed by default), fewer or grouped columns, clearer visual hierarchy per level.

Relation to the plans: plan 08-14 owns the split child rows, toggles, badges and footnotes of this page, and 08-09 owns the reader and the page. Revisit the layout before or while 08-14 runs, then close the gap through phase verification (`/gsd-plan-phase 8 --gaps`).
