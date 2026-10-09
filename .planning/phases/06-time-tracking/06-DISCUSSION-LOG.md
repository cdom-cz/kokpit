# Phase 6: Time Tracking - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-09
**Phase:** 6-Time Tracking
**Areas discussed:** Timer UI and behaviour, Billable default, Manual entries / overlaps / timesheet, Billed locking and forgotten timer

---

## Timer placement

| Option | Description | Selected |
|--------|-------------|----------|
| Panel top bar | Widget in the Filament topbar, visible on all panel pages | ✓ |
| Floating bottom bar | Fixed bar at the bottom, may cover content | |
| Sidebar block | Hidden in collapsed or mobile menu | |

**User's choice:** Panel top bar

## Start behaviour

| Option | Description | Selected |
|--------|-------------|----------|
| Auto-stop + start without context | Starts stop the running timer; bar start needs only a client | ✓ |
| Auto-stop + selection dialog first | Dialog before running | |
| Confirm before switching | Breaks the two-click limit | |

**User's choice:** Auto-stop + start without context

## Billable default

| Option | Description | Selected |
|--------|-------------|----------|
| No new project value | False only from non-billable task type; fixed project stays true | ✓ |
| Project flag | Admin-only boolean in project_billing | |
| Hourly true, fixed false | Fixed price defaults to false | |

**User's choice:** No new project value

## Overlaps

| Option | Description | Selected |
|--------|-------------|----------|
| Allow, warn only | No DB block, warning in form and timesheet | ✓ |
| Forbid in DB | Exclusion constraint | |
| Allow silently | No check | |

**User's choice:** Allow, warn only

## Timesheet

| Option | Description | Selected |
|--------|-------------|----------|
| Weekly grid + daily list | Rows client/project/task by days with totals | ✓ |
| Grouped list only | One filtered table | |
| Calendar week | Timeline blocks | |

**User's choice:** Weekly grid + daily list

## Forgotten timer

| Option | Description | Selected |
|--------|-------------|----------|
| 12 h, banner + bell | Config threshold, warning state, one-time bell notification | ✓ |
| 8 h, banner + bell + e-mail | Shorter threshold with e-mail | |
| Visual warning only | No notifications | |

**User's choice:** 12 h, banner + bell

## Billed marking and unlocking

| Option | Description | Selected |
|--------|-------------|----------|
| Bulk action + "Cancel billing" | Confirmed actions, logged, unlock only through the action | ✓ |
| Single entry only | No bulk | |
| Mandatory note | Reason required for mark and cancel | |

**User's choice:** Bulk action + "Cancel billing"

## Claude's Discretion

Class names, running timer storage, DB constraints, rate snapshot mechanics, duration display format, top bar widget layout, list columns and filters, PR-05 overview structure, notification wording.

## Deferred Ideas

- Calendar-style week timeline
- E-mail channel for the forgotten timer warning
