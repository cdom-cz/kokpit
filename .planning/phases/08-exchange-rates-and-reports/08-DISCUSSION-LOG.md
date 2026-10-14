# Phase 8: Exchange Rates and Reports - Discussion Log

> **Audit trail only.** Do not use as input to planning, research, or execution agents.
> Decisions are captured in CONTEXT.md — this log preserves the alternatives considered.

**Date:** 2026-10-14
**Phase:** 8-Exchange Rates and Reports
**Areas discussed:** CNB rate for CZK conversion, Time report and effective rate, Exports and PDF work report, Dashboard

---

## CNB rate for CZK conversion

| Option | Description | Selected |
|--------|-------------|----------|
| Rate of the entry day | Each entry converted with the rate of its day (previous valid on weekends/holidays) | ✓ |
| Rate at end of period | One rate for the whole report | |
| Today's rate | Always the latest rate | |

**User's choice:** Rate of the entry day.
**Notes:** Currencies: whole daily list (selected) vs only client currencies. Download timing: user asked to find out when CNB updates; research found publication once per working day after 14:30 Prague time, valid for following weekend/holiday. Chosen: run after 14:30, hourly retry until evening, alert after final failure. Manual download and backfill with a from-to range on the rates overview (selected) vs Artisan only.

---

## Time report and effective rate

| Option | Description | Selected |
|--------|-------------|----------|
| Fixed price shown, hours without amount | Hours, fixed price, effective rate | ✓ |
| Both side by side | Entries priced hourly plus fixed price | |

**User's choice:** Fixed price, hours without amount; effective rate = fixed price / all worked hours of the project; one page with filters and breakdown switch (vs several reports).
**Notes:** —

---

## Exports and PDF work report

| Option | Description | Selected |
|--------|-------------|----------|
| Flat entry rows + XLSX summary | Respects report filters, kept 7 days, bell notification | ✓ |
| Aggregated table only | | |

**User's choice:** Flat rows plus summary. PDF in client language, no prices, generated on demand (storing/attaching in Phases 9/10).
**Notes:** Alternatives for PDF: with amounts; always Czech.

---

## Dashboard

| Option | Description | Selected |
|--------|-------------|----------|
| Three blocks + placeholders | Hours, unbilled per client, deadlines | Extended by user |

**User's choice (free text):** Hours today and this week, unbilled time per client for the current month, worked time for the current month, nearest deadlines of the first ten tasks, a tile for unpaid invoices, and a collapsible next-day planning table (collapsed by default, or only top three tasks visible).
**Notes:** Clarified that the planning table is the existing Signal "Dnes a zítra" functionality; dashboard only reads it. Partner dashboard: user wants a list of their projects and open tasks with a call to action, tasks "ke kontrole" highlighted; confirmed as the `in_review` task status.

---

## Claude's Discretion

Rates table design, rounding, missing-rate behaviour, XLSX library, widget structure, health thresholds, wording, query performance.

## Deferred Ideas

None.
