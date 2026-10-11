---
phase: quick-261012-sig
plan: 01
subsystem: signal-planner
tags: [signal, planner, livewire, filament]
requires: []
provides:
  - "Navigation group Signal: Dnes a zítra, Týden, Přehled, Reflexe, Nastavení"
  - "Owner-scoped, Admin-only planner tables signal_*"
affects: [app/Domain/Signal, app/Livewire/Signal, app/Filament/Pages/Signal, database/migrations]
status: complete-pending-ci
---

# Quick task 261012-sig: the planner Signal as its own menu section

The planner runs entirely in Livewire requests (`SignalDay` component and five Filament pages). Every
write goes through an Action in `App\Domain\Signal\Actions`; the pure rules live in `SignalRules`,
`SignalStats` and `SignalCalendar` (Europe/Prague days as strings, ISO weeks).

## Verification

- The pure rules, statistics and calendar are covered by unit tests (`tests/Unit/Signal`).
- The schema constraints are covered by `tests/Feature/Schema/SignalTablesTest.php`.
- The Actions, the screens and the isolation between two Admins and from a Partner are covered by
  `tests/Feature/Signal` and `tests/Isolation/SignalIsolationTest.php`.
- Written without a working PHP 8.5 / PostgreSQL 18 / Composer environment: the unit tests were run
  through a small local harness and the SQL of the migrations against PostgreSQL 16; the Laravel,
  Filament and Livewire parts are verified by CI only.
