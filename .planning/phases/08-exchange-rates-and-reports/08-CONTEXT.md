# Phase 8: Exchange Rates and Reports - Context

**Gathered:** 2026-10-14
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin sees where time and money stand per client, project and period in client currency and CZK, can export the data and hand over a work report, and daily CNB rates feed every conversion. Delivers: CNB rate download, rates overview and conversion helper (EX-01..03); time report, billable/billed split, CSV/XLSX export, PDF work report, effective rate for fixed-price projects (RE-01..03, RE-05); dashboard (RE-04).

Not in this phase: storing the work report as an invoice attachment and mailing it (Phases 9/10), invoice and payment dashboard tiles (wired in Phases 10/11), any new planner functionality.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): Dompdf 3.1 is the PDF engine for the work report and invoice PDF (Phase 3 spike, `03-SPIKE-PDF.md`); money is bigint minor units plus ISO 4217 currency; time is exact seconds, stored UTC and shown in `Europe/Prague`; rate resolution is task, project, client, global default and billing data lives in Admin-only `project_billing` / `task_billing` (Phases 4-6); task billing type values inherit / hourly / fixed / non-billable; Partner never sees time, rates, prices or finance (Phase 2 `#[AccessRule]` / `#[DeniesPartners]`); queued jobs extend `KokpitJob` and are idempotent, failed-job alert is a synchronous e-mail plus bell notification with throttling (Phase 3 D-10, D-11); the System page has a registered `last_rate_date` health slot to replace (Phase 3 D-13, `HealthSlot::LastRateDate`); the Signal planner (`SignalTask`, "Dnes a zítra" page) already exists.

### CNB rates: conversion, download, backfill (EX-01, EX-02, EX-03)
- **D-01:** Unbilled time is converted to CZK in reports with the CNB rate of the **day of the time entry** (the previous valid rate when that day is a weekend or holiday). Reports for past periods therefore do not change from day to day. Document rates stay snapshots taken when the document is issued (EX-03, later phases).
- **D-02:** The whole daily CNB list is stored (all currencies, about 30), not only currencies used by clients, so adding a client in a new currency needs no historical catch-up. Per-unit amounts (for example 100 units) are stored with the unit amount and handled in the conversion helper.
- **D-03:** CNB publishes the rates once per working day after 14:30 Prague time, valid for that day and the following weekend or holiday (cnb.cz methodology sheet and FAQ). The scheduled download therefore runs after publication (for example 15:00) and, when it fails, repeats hourly until about 18:00. Exact schedule values live in `config/kokpit.php`.
- **D-04:** A final failure of the day triggers the Phase 3 alert (synchronous e-mail plus bell notification, throttled), independent of the queue. Until a new rate arrives the last known rate keeps being used. The System page `last_rate_date` slot becomes real (OK / Warning / Error thresholds are Claude's discretion, documented in `config/kokpit.php`).
- **D-05:** The rates overview is an Admin page with a manual "Download today's rates" action and a "Backfill history" action with a from-to date range. Backfill runs queued, is idempotent (a rerun creates no duplicates) and fills days with no CNB list (weekends, holidays) with the previous valid rate. A conversion helper returns the CZK amount for a currency, amount and date.

### Time report and effective rate (RE-01, RE-02, RE-05)
- **D-06:** One Admin-only report page: filters client, project and period (presets this month, last month, custom), a table that expands client > project > task, a switch for breakdown by day and by user, and a summary at the top for billable vs non-billable and billed vs unbilled. Amounts are shown in client currency and CZK.
- **D-07:** For fixed-price projects the report shows hours, the agreed fixed price and the effective hourly rate; no per-entry amount is computed for them (hourly and fixed logic are not mixed). — **Reversibility:** costly — export columns, PDF and dashboard amounts depend on which entries carry an amount.
- **D-08:** Effective hourly rate = fixed price divided by all worked hours of the whole project (not only the selected period), shown in client currency and CZK, with both the price and the hours visible next to it.

### Exports and work report PDF (RE-03)
- **D-09:** CSV and XLSX export respects the current report filters and contains one row per time entry (date, client, project, task, user, seconds and hours, billable, billed, amount in client currency and CZK) plus an XLSX summary sheet. It is built by a queued job; the finished file is kept on private storage for 7 days (value in `config/kokpit.php`), then deleted. The Admin gets a bell notification with a link; download goes through the app with a permission check (no public URL). Cells are spreadsheet-safe against formula injection.
- **D-10:** The PDF work report is generated with Dompdf in the client's language (`clients.invoice_language`, cs/en) and shows header (supplier, client, period), a table by day and task with hours, and a summary. It contains **no rates, prices or amounts** (it is an attachment; prices belong on the invoice). Multi-page, correct Czech diacritics, repeating header and page numbers as proven in the spike. Phase 8 delivers the generator as a service returning the PDF and a download action; storing it as an invoice attachment and e-mailing it is done by Phases 9/10. Raise the PHP memory limit for long reports as noted in the spike.

### Dashboard (RE-04)
- **D-11:** The Admin dashboard (replacing the empty Phase 2 dashboard) shows: hours today and this week (Monday to Sunday, Europe/Prague, including a running timer) and worked time in the current month; unbilled billable time of the current month per client (hours and amount in client currency and CZK); the nearest deadlines of the first ten open tasks, overdue ones highlighted; a collapsible "plan for tomorrow" block read from the existing Signal planner (`SignalTask` for the next day), collapsed by default with the first three tasks visible and the rest expanded on click (read-only, no new planner functions); and a tile for unpaid invoices that appears when invoicing exists (Phase 10; the unresolved-payments tile follows in Phase 11).
- **D-12:** The Partner dashboard replaces the empty landing page: the Partner sees the list of their own visible projects and current open tasks, with tasks in status `in_review` ("ke kontrole") listed first and visually highlighted as a call to action. It shows no time, rates, prices or finance and uses the existing Partner scope from Phase 5. — **Reversibility:** reversible — it is a page and a query, no stored data.

### Claude's Discretion
Class and file names, table design for rates (one row per currency and date, whether weekend/holiday rows are stored or resolved by lookup), rounding of CZK amounts, behaviour of a conversion with no rate at all, XLSX library (AGPL-compatible), Filament widget structure, health slot thresholds, Czech wording, report query performance (indexes, aggregation in SQL), queue and retry values within D-03.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project and requirements
- `.planning/ROADMAP.md` §Phase 8 — goal and 5 success criteria
- `.planning/REQUIREMENTS.md` — EX-01, EX-02, EX-03, RE-01..RE-05, FND-10, TI-05, TI-08
- `.planning/PROJECT.md` — Constraints (money, time, AGPL compatibility, Partner isolation)

### Prior decisions
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — D-10..D-13 (job base, alerts, System page health slots)
- `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md` — Dompdf decision, memory and font findings
- `.planning/phases/06-time-tracking/06-CONTEXT.md` — D-03, D-06 (billable default, billed locking), TI-08 rate resolution
- `.planning/phases/05-tasks-and-kanban/05-CONTEXT.md` — task billing types, Partner task access

### External
- https://www.cnb.cz/cs/casto-kladene-dotazy/Kurzy-devizoveho-trhu-na-www-strankach-CNB — publication time and API access of CNB rates
- https://www.cnb.cz/docs/ARADY/MET_LIST/kurzy_cs.pdf — methodology sheet (14:30 publication, weekend/holiday validity)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Filament/Pages/Dashboard.php`: empty widget-free dashboard with `#[AccessRule(Audience::PartnerAllowed)]`; replaced/extended by this phase.
- `app/Domain/Operations/Health/HealthSlot.php` (`LastRateDate` case) and `app/Filament/Pages/SystemPage.php`: placeholder to make real.
- `app/Domain/Shared/Money/Money.php`: money value object for minor units and currency.
- `app/Domain/Operations/Jobs/Idempotent.php` and `KokpitJob`: base for the rate download, backfill and export jobs.
- `app/Filament/Pages/TimesheetPage.php`, `app/Domain/Signal/Queries/SignalOverviewReader.php`: existing time and planner readers.
- Task status check constraint already contains `in_review` (`database/migrations/2026_10_10_000100_create_tasks_table.php`).

### Established Patterns
- Fail-closed `#[AccessRule]` on every page, `#[DeniesPartners]` on finance-like data; Czech text in `lang/cs/kokpit.php`; domain logic under `app/Domain/<Area>`.
- UUID v7 keys, DB-enforced constraints, `config/kokpit.php` for thresholds.
- No PDF or spreadsheet library is installed yet (`composer.json`): Dompdf and an XLSX library are added by this phase.

### Integration Points
- Time entries and billing tables (Phase 6) feed report, export and dashboard.
- Phase 10 will reuse the conversion helper, the work report generator and the unpaid-invoice dashboard tile; Phase 11 adds the unresolved-payments tile.

</code_context>

<specifics>
## Specific Ideas

- The owner plans the next day in the existing Signal planner ("Dnes a zítra" in the left menu); the dashboard shows that plan collapsed, with only the top three tasks visible.
- Partner dashboard should call attention to tasks waiting for the Partner's review.

</specifics>

<deferred>
## Deferred Ideas

None — discussion stayed within phase scope. (The Partner dashboard and the Signal plan block were accepted into this phase as small read-only views.)

</deferred>

---

*Phase: 8-Exchange Rates and Reports*
*Context gathered: 2026-10-14*
