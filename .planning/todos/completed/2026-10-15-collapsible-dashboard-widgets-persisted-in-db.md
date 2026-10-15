---
created: 2026-10-15T09:00:00.000Z
title: Make dashboard widgets collapsible with the state saved in the database
area: ui
severity: minor
files:
  - app/Filament/Pages/Dashboard.php
  - app/Filament/Widgets/HoursStats.php
  - app/Filament/Widgets/UnbilledPerClient.php
  - app/Filament/Widgets/UpcomingDeadlines.php
  - app/Filament/Widgets/TomorrowPlan.php
  - app/Filament/Widgets/PartnerTasks.php
  - app/Filament/Widgets/PartnerProjects.php
---

## Problem

The owner wants every tab/section on the home page (the dashboard) to be collapsible into a compact view, and the collapsed or expanded state must be saved in the database, so it survives reloads and devices. Today the dashboard widgets are always fully shown (only the tomorrow plan block has its own local expand toggle).

Clarified by the owner with a screenshot: the "tabs" are the dashboard widget cards (for example "Nevyfakturováno tento měsíc", "Nejbližší termíny" and the plan block). Each card is switched to a compact, low-height view on its own, independently of the others. The state is saved in the background right when it is toggled: there is no save button and none is wanted. State is per user. Whether the Partner dashboard widgets get the same behaviour is still to confirm (default: yes, same wrapper).

## Solution

TBD, needs a short design decision first. Suggested direction: one shared collapsible wrapper for all dashboard widgets (header with a chevron, `aria-expanded`, compact summary line when collapsed, e.g. only the card title and a short figure), and a per-user preference store (a small table or a JSON column on the user, keyed by widget id, with a database check on the allowed keys), written immediately on toggle (no save button, optimistic UI, silent on success, a small error toast only if the write fails) through a Livewire action that is authorised for the signed-in user only. Default expanded for new users. A Partner must only be able to store state for widgets a Partner can see.

Related: the "today's plan" change under the hours stats (replaces the tomorrow plan) touches the same dashboard, so do that first, then this.
