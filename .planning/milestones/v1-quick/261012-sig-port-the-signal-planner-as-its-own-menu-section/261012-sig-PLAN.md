---
phase: quick-261012-sig
plan: 01
type: execute
wave: 1
depends_on: []
autonomous: false
---

# Quick task: port the planner "Signal" as its own menu section

## Objective

Bring the logic of the separate single-user planner "Signal" (daily tasks by colour, deep-work blocks,
weekly goals, Friday recap, overview, reflection, settings) into Kokpit as its own navigation group,
running in Livewire requests. Each Admin has the own records. Out of scope by decision of the owner:
the timer, the login, the projects of the original (cdom / ideatech / q2), any link to Kokpit tasks and
any data import from the original database.

## Decisions

- Projects are dropped: a task has a title, a day and a colour only.
- A recurring template stores its weekdays as a bit mask (Monday = bit 0) instead of an array column.
- The planner models are Admin-only (`DeniesPartners`) and owner-scoped (`OwnedByUser`, `OwnerScope`):
  the scope constrains the Admin to the own rows too, and only an explicit system run sees every row.
- The limits (three main and three medium tasks a day, three goals a week) are enforced twice: in the
  Action under a transaction advisory lock of the user's day or week, and in the database (position
  CHECK and a deferrable unique key for the goals).
- Deep-work blocks and goals cannot be ticked on a future day.

## Tasks

1. Schema, models, owner scope, morph aliases, policies, registry tests.
2. Pure rules, statistics, calendar and the Actions, with unit tests.
3. The five pages, the day component, translations, feature and isolation tests.
