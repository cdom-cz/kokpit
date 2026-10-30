# Phase 6: Time Tracking - Context

**Gathered:** 2026-10-09
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin tracks exact time against clients, projects and tasks with an always-visible timer or manual entries, and always knows what is billable, billed and unbilled. Delivers TI-01 to TI-09 and PR-05 (Admin-only project time overview: tasks, estimate vs actual, billed vs unbilled).

Not in this phase: API endpoints for time and timer (Phase 7), exchange rates and reports/exports (Phase 8), automatic billing from time and rate/amount snapshot written by invoicing (Phase 10). A Partner sees no time, rates or prices anywhere.

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): Partner isolation via `users.client_id`, fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); activity log allowlist, queued mail pattern, `KokpitJob` (Phase 3); rate resolution order task, project, client, global default and billing data in separate Admin-only 1:1 tables `project_billing` / `task_billing` (Phase 4 D-05, D-15; Phase 5 D-12 to D-14); task billing type values inherit / hourly / fixed / non-billable (Phase 5 D-12); per-user notification channel preferences (Phase 5 D-15); UUID v7, `timestamptz`, DB-enforced invariants.

### Timer UI and behaviour (TI-01)
- **D-01:** The timer lives in the Filament panel top bar (topbar render hook), visible on every panel page: running time, description, stop button. Start from a task takes at most two clicks.
- **D-02:** Starting a timer stops the running one and keeps its entry (auto-stop, no confirmation dialog). Starting from the top bar without a task requires only a client (picked in the bar itself); project, task and description can be filled in later on the running entry. Concurrent starts must never leave two running timers for one user (DB-enforced, see TI-07).

- **D-08:** A hideable right-hand side panel on panel pages shows recent time entries, inspired by the owner's reference screenshots of the previous tool. At the top it holds the detailed timer: while idle a "What are you working on" start field with a start button and `00:00`; while running it shows the task key and title (or the client when there is no task), the description, the elapsed time and a stop button, in a warning/danger colour. Below it, entries are grouped by day (heading "Today" or weekday and date, with the day total on the right); each row shows task key and title (or the client when there is no task), client name and duration in hours and minutes. Not included: a "Log time" link (manual entries are created from the time entries list and the timesheet) and billed check marks. Styling stays within standard Filament components and theme, no custom look. The panel can be shown or hidden with a toggle and the choice is remembered per user; it is Admin-only. The top bar timer (D-01) stays and shows a compact running state (icon and elapsed time, coloured while running), so the timer is visible when the panel is hidden; the panel timer is the detailed view of the same single running timer. There is no pause: only start and stop (TI-07 allows one running timer per user). How many days or entries load, and whether the list scrolls or loads more, is Claude's discretion.

### Billable default (TI-04)
- **D-03:** `billable` defaults to true. It is pre-set to false only when the resolved task billing type is non-billable (Phase 5 D-12, including inherit from the parent task). Projects get no non-billable value (Phase 4 D-15 stays); a fixed-price project leaves `billable` true, and how fixed-price time is billed is decided in Phase 10. The user can always override the flag on the entry. The roadmap wording "non-billable projects" is read as this rule.

### Manual entries, overlaps and timesheet (TI-02, TI-03, TI-06)
- **D-04:** Overlapping entries of one user are allowed. There is no DB exclusion constraint. The entry form and the timesheet show a non-blocking warning on overlap.
- **D-05:** Timesheet has a daily view (list of the day's entries with a total) and a weekly view (grid of rows client / project / task by days Monday to Sunday, with totals per row and per day, week switching). Durations show in hours and minutes; storage is exact seconds (TI-08).

### Billed locking and forgotten timer (TI-05, TI-09)
- **D-06:** Entries are marked billed manually through a bulk action "Mark as billed" and unlocked through "Cancel billing" (both with confirmation). Unlocking is only possible through that action, not by editing a locked entry. Both actions are written to the activity log. Phase 10 invoicing uses the same billed state.
- **D-07:** A timer running longer than a threshold (default 12 h, value in `config/kokpit.php`) is flagged: the top bar timer changes to a warning state and the user gets a one-time database (bell) notification from a scheduled job. No e-mail by default and the timer is never stopped automatically.

### Claude's Discretion
Class and file names, storage of the running timer (for example a time entry without an end plus partial unique index per user), DB check constraints for client / project / task agreement and end after start, how the rate and amount are resolved and snapshotted at billing time, duration display format details, top bar widget and quick-start layout, filters and columns of the entry list, bulk action wording and Czech labels, structure of the PR-05 project time overview, exact bell notification wording.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Planning
- `.planning/ROADMAP.md` — Phase 6 goal and five success criteria
- `.planning/REQUIREMENTS.md` — TI-01 to TI-09, PR-05
- `.planning/PROJECT.md` — constraints (Partner never sees time, rates or finance; immutability of billed entries; UUID v7; DB-enforced integrity)
- `.planning/phases/02-platform-foundation/02-CONTEXT.md` — Partner scope, access rules
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — activity log allowlist, queued mail and notification pattern, `KokpitJob`
- `.planning/phases/04-clients-and-projects/04-CONTEXT.md` — `project_billing` (D-05), billing type enum (D-15), Partner project view (D-07)
- `.planning/phases/05-tasks-and-kanban/05-CONTEXT.md` — `task_billing` and billing type (D-12 to D-14), notification preferences (D-15)

### Research
- `.planning/research/PITFALLS.md` — Partner leakage surfaces
- `.planning/research/STACK.md` — package versions

### Repository rules
- `.claude/CLAUDE.md` — fictional data only
- `scripts/check-sensitive.sh` — must pass on every commit

No external ADRs.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Shared/Auth/` (`PartnerScope`, `IsolatesPartners`, `DeniesPartners`, `AccessRule`, `KokpitPolicy`): isolation primitives; time entries are Admin-only.
- `project_billing` and `task_billing` models: source of billing type and rate overrides for TI-04 and TI-08.
- `app/Domain/Audit/LogsAllowlistedActivity.php`: activity log for billed / unbilled actions.
- `app/Domain/Operations/Alerts`, `Jobs/KokpitJob`: scheduled job and notification pattern for the forgotten timer.
- `app/Filament/Resources/ProjectResource`, `TaskResource`, `ClientResource`: Resource and access rule patterns.

### Established Patterns
- Every Filament class declares `#[AccessRule]`; every model declares Partner scope or `#[NotPartnerScoped]`; architecture tests enforce both.
- UUID v7, `timestamptz`, DB constraints (CHECK, partial and unique indexes) for invariants.
- Canary harness (two fictional clients) gets a line for new models; time entries must be proven invisible to a Partner.
- Czech via `lang/cs`; code, tests and docs in English.

### Integration Points
- Timer start action on the task detail page and board card; top bar render hook in the Filament panel.
- Phase 7 exposes the same rules through the API; Phase 8 builds reports on time entries; Phase 10 reads unbilled entries and snapshots rate and amount.

</code_context>

<specifics>
## Specific Ideas

- The timer must be usable from the top bar even without a task: only the client is mandatory.
- Overlapping work is real; the system warns but never blocks saving.

</specifics>

<deferred>
## Deferred Ideas

- Calendar-style timeline view of the week: not chosen, possible later enhancement.
- E-mail for a forgotten timer: the notification preference infrastructure exists (Phase 5 D-15), adding an e-mail channel is left for later.

</deferred>

---

*Phase: 6-Time Tracking*
*Context gathered: 2026-10-09*
