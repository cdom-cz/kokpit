# Phase 6: Time Tracking - Research

**Researched:** 2026-10-09
**Domain:** Laravel 13 / Filament 5.10 / Livewire 4.4 time tracking on PostgreSQL 18 (running timer, DB-enforced consistency, billed locking, timesheet, forgotten-timer notice), Admin-only, with Partner isolation proven by the canary harness
**Confidence:** HIGH for schema design, locking, concurrency and reuse map (code read and PostgreSQL 18.6 behaviour measured in this session); MEDIUM for the Livewire/Filament component mechanics (read from vendor source, not run); items marked `[ASSUMED]` need owner confirmation (see Assumptions Log)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): Partner isolation via `users.client_id`, fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); activity log allowlist, queued mail pattern, `KokpitJob` (Phase 3); rate resolution order task, project, client, global default and billing data in separate Admin-only 1:1 tables `project_billing` / `task_billing` (Phase 4 D-05, D-15; Phase 5 D-12 to D-14); task billing type values inherit / hourly / fixed / non-billable (Phase 5 D-12); per-user notification channel preferences (Phase 5 D-15); UUID v7, `timestamptz`, DB-enforced invariants.

#### Timer UI and behaviour (TI-01)
- **D-01:** The timer lives in the Filament panel top bar (topbar render hook), visible on every panel page: running time, description, stop button. Start from a task takes at most two clicks.
- **D-02:** Starting a timer stops the running one and keeps its entry (auto-stop, no confirmation dialog). Starting from the top bar without a task requires only a client (picked in the bar itself); project, task and description can be filled in later on the running entry. Concurrent starts must never leave two running timers for one user (DB-enforced, see TI-07).

- **D-08:** A hideable right-hand side panel on panel pages shows recent time entries, inspired by the owner's reference screenshots of the previous tool. At the top it holds the detailed timer: while idle a "What are you working on" start field with a start button and `00:00`; while running it shows the task key and title (or the client when there is no task), the description, the elapsed time and a stop button, in a warning/danger colour. Below it, entries are grouped by day (heading "Today" or weekday and date, with the day total on the right); each row shows task key and title (or the client when there is no task), client name and duration in hours and minutes. Not included: a "Log time" link (manual entries are created from the time entries list and the timesheet) and billed check marks. Styling stays within standard Filament components and theme, no custom look. The panel can be shown or hidden with a toggle and the choice is remembered per user; it is Admin-only. The top bar timer (D-01) stays and shows a compact running state (icon and elapsed time, coloured while running), so the timer is visible when the panel is hidden; the panel timer is the detailed view of the same single running timer. There is no pause: only start and stop (TI-07 allows one running timer per user). How many days or entries load, and whether the list scrolls or loads more, is Claude's discretion.

#### Billable default (TI-04)
- **D-03:** `billable` defaults to true. It is pre-set to false only when the resolved task billing type is non-billable (Phase 5 D-12, including inherit from the parent task). Projects get no non-billable value (Phase 4 D-15 stays); a fixed-price project leaves `billable` true, and how fixed-price time is billed is decided in Phase 10. The user can always override the flag on the entry. The roadmap wording "non-billable projects" is read as this rule.

#### Manual entries, overlaps and timesheet (TI-02, TI-03, TI-06)
- **D-04:** Overlapping entries of one user are allowed. There is no DB exclusion constraint. The entry form and the timesheet show a non-blocking warning on overlap.
- **D-05:** Timesheet has a daily view (list of the day's entries with a total) and a weekly view (grid of rows client / project / task by days Monday to Sunday, with totals per row and per day, week switching). Durations show in hours and minutes; storage is exact seconds (TI-08).

#### Billed locking and forgotten timer (TI-05, TI-09)
- **D-06:** Entries are marked billed manually through a bulk action "Mark as billed" and unlocked through "Cancel billing" (both with confirmation). Unlocking is only possible through that action, not by editing a locked entry. Both actions are written to the activity log. Phase 10 invoicing uses the same billed state.
- **D-07:** A timer running longer than a threshold (default 12 h, value in `config/kokpit.php`) is flagged: the top bar timer changes to a warning state and the user gets a one-time database (bell) notification from a scheduled job. No e-mail by default and the timer is never stopped automatically.

### Claude's Discretion
Class and file names, storage of the running timer (for example a time entry without an end plus partial unique index per user), DB check constraints for client / project / task agreement and end after start, how the rate and amount are resolved and snapshotted at billing time, duration display format details, top bar widget and quick-start layout, filters and columns of the entry list, bulk action wording and Czech labels, structure of the PR-05 project time overview, exact bell notification wording.

### Deferred Ideas (OUT OF SCOPE)
- Calendar-style timeline view of the week: not chosen, possible later enhancement.
- E-mail for a forgotten timer: the notification preference infrastructure exists (Phase 5 D-15), adding an e-mail channel is left for later.

Also out of scope (CONTEXT domain): API endpoints for time and timer (Phase 7), exchange rates and reports/exports (Phase 8), automatic billing from time and the rate/amount snapshot written by invoicing (Phase 10). A Partner sees no time, rates or prices anywhere.

Approved UI contract: `06-UI-SPEC.md` (status approved). It is treated as locked in the same way as the decisions above; where this research finds a conflict inside it, the conflict is listed under Open Questions, not silently resolved.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TI-01 | Timer visible throughout the app, startable from a task in at most two clicks | Persisted topbar region + `GLOBAL_SEARCH_AFTER` hook, Livewire timer component with Admin `boot` guard, one-click affordances on task page / list / board via the same `StartTimer` Action (Architecture Patterns 1, 5, 6) |
| TI-02 | Manual creation and editing of entries | `TimeEntryResource` over `CreateTimeEntry` / `UpdateTimeEntry` Actions, Prague-time pickers, truncation to whole seconds (Patterns 3, 7; Pitfalls 2, 7) |
| TI-03 | Entry may have only a client; client always required | `client_id NOT NULL`, nullable `project_id` / `task_id`, composite FKs skip on NULL (measured, Pattern 2) |
| TI-04 | Billable defaults true, pre-set false for non-billable; overridable | `TaskBillingResolver` + `EffectiveBilling::isBillable()` called by the Actions when `billable` is not supplied (Pattern 4) |
| TI-05 | Billed manually / in bulk, locked until billing cancelled | `billing_state` + `Immutability::guardTriggerSql` with `duration_seconds` in the mutable list (measured), `MarkEntriesBilled` / `CancelEntriesBilling` Actions, Resource `canEdit` / `canDelete` (Pattern 8; Pitfalls 1, 4) |
| TI-06 | Daily / weekly timesheet with totals | Prague-day grouping, UTC range predicates, array-records week grid, one aggregate query per view (Pattern 9; Pitfalls 8, 9) |
| TI-07 | DB-enforced consistency, one running timer per user, start stops the running one | Composite FKs, CHECKs, partial unique index, per-user advisory lock in `StartTimer`, concurrency harness with mutation run (Patterns 2, 3, 10) |
| TI-08 | Exact seconds; rate order task, project, client, global default; snapshot on billing | `timestamptz(0)` + stored `duration_seconds`, `TimeEntryRateResolver` over `TaskBillingResolver`; the snapshot itself is Phase 10 (ROADMAP mapping note, quoted below) |
| TI-09 | Forgotten long-running timer flagged | `kokpit.time.long_running_hours`, state computed on render, `NotifyLongRunningTimers` `KokpitJob` with a claim column (Pattern 11) |
| PR-05 | Admin-only project detail: tasks, estimate vs actual, billed vs unbilled | `StatsOverviewWidget` + two relation managers on `ViewProject`, SQL aggregates, estimate inheritance decision (Pattern 12; Open Question 1, 2) |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` (read this session).

- Stack fixed: PHP 8.5, current stable Laravel and Filament (SPA mode), PostgreSQL, Sanctum, queue; licence AGPL-3.0, no paid or closed packages. This phase needs **no new Composer or npm package**.
- Partner must never see measured time, rates, prices or finance, nor another client's data - enforced by Policies and global query scopes, not only UI hiding.
- UUID v7 keys everywhere; FK, unique, partial indexes and check constraints enforced in the DB.
- Issued invoices and billed time entries are immutable.
- **Fictional data only** in code, tests, fixtures, docs, `.planning/` and commit messages: `example.com` addresses, placeholder company ID `12345678`, `/Users/example/` paths, test fakes assembled at runtime from fragments. Run `scripts/check-sensitive.sh` before every commit; never bypass the lefthook hook.
- Work starts through a GSD command; repository edits outside a GSD workflow only on explicit request.
- Czech via `lang/cs`; code, tests and docs in English (planning docs English).

## Summary

The phase is a single new table (`time_entries`) with a heavy constraint set, a handful of domain Actions, one Resource, one Page, two relation managers, a stats widget, two plain Livewire components in the persisted top bar and at `LAYOUT_END`, and one scheduled `KokpitJob`. The repository already contains every primitive needed: `Immutability` guard triggers, `TaskBillingResolver`, `Project::selectable()`, the activity allowlist, the `KokpitJob` contract, the Task-notification escaping rules, the advisory-lock plus real-process concurrency harness, and the canary/route-walk isolation harness. No dependency is added.

Measured in this session on the dev PostgreSQL 18.6, and decisive for the design: (1) `timestamptz(0)` **rounds** a fractional second instead of truncating, so every write must truncate in PHP; (2) a **stored generated** `duration_seconds` column is `NULL` in `NEW` inside a `BEFORE UPDATE` trigger, which makes the generic `kokpit_guard_frozen_row()` refuse every update of a billed row, including the legitimate unlock, unless the column is listed as mutable; (3) the default database collation sorts Czech names wrongly (`Čapek` first, `Chalupa` before `Cyril`) while `COLLATE "cs-CZ-x-icu"` is correct - the "Všichni klienti" list is the first sorted text of the application, so CONTRIBUTING makes this phase define and test the Czech collation mechanism; (4) composite foreign keys with `MATCH SIMPLE` skip the check when `project_id` is NULL, so a task without a project needs its own CHECK.

The ROADMAP mapping note puts the TI-08 "snapshot of rate and amount on billing" and the automatic TI-05 billing in Phase 10 (quoted below). Phase 6 therefore resolves and displays the effective rate and stores **no money** on a time entry; it only guarantees that a billed entry is frozen so Phase 10 can attach its snapshot columns later (that migration must extend the guard's mutable list).

**Primary recommendation:** Build `time_entries` first with all constraints, the guard trigger (mutable list `billing_state, billed_at, duration_seconds`) and a schema test by SQLSTATE; put every state change in a domain Action (`StartTimer` takes a per-user advisory lock, then stops the running entry and inserts the new one, with the partial unique index as the backstop and a real-process concurrency test with a mutation run); keep the Livewire timer components thin, Admin-guarded in a `boot` hook on every request, and register every new model/page/job in the existing architecture and canary tests.

**Roadmap quote (verified):** [VERIFIED: .planning/ROADMAP.md:14] "TI-05 automatic billing and the TI-08 rate/amount snapshot on billing are exercised in Phase 10 (IN-02, IN-03)."

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| One running timer per user, start stops the previous | Database / Storage (partial unique index) | API / Backend (`StartTimer`, advisory lock) | The index is the authority; the Action avoids the failure path in the normal case |
| client / project / task agreement, end not before start, billed freeze | Database / Storage (composite FKs, CHECKs, trigger) | API / Backend (Actions translate SQLSTATE to field errors) | Brief demands DB enforcement; forms must never offer a rejected combination |
| Exact seconds, duration | Database / Storage (`timestamptz(0)`, stored `duration_seconds`) | API / Backend (truncate on write) | Exactness by construction; PHP only truncates |
| Effective rate, billable default | API / Backend (`TimeEntryRateResolver`, `TaskBillingResolver`) | - | Rate data is Admin-only; resolver refuses a Partner |
| Ticking clock | Browser / Client (Alpine from stored start instant) | Frontend Server (Livewire re-reads state: events + 60 s visible poll) | Server row is the only state; no per-second requests |
| Timer bar and side panel markup | Frontend Server (Livewire components via render hooks) | Browser / Client (Alpine) | Hooks return nothing for non-Admin; each action re-checks Admin |
| Time entries list, forms, bulk billing | Frontend Server (Filament Resource) | API / Backend (Actions) | Standard Filament; business rules stay in Actions |
| Timesheet day/week, project overview aggregates | Database / Storage (SQL aggregates by Prague day) | Frontend Server (Filament table / widget) | Constant query count; Prague bucketing must happen in SQL |
| Forgotten-timer notice | API / Backend (scheduled `KokpitJob`, claim column) | Frontend Server (bar state computed on render; stock bell) | Idempotent, never stops the timer |
| Partner invisibility | Database / Storage + API (model `DeniesPartners`, `AdminOnlyPolicy`, `#[AccessRule]`) | Frontend Server (hooks render nothing) | Enforced below the UI, proven by canary and route walk |

## Standard Stack

### Core (all already installed; no new package)

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| filament/filament | v5.10.0 | Resource, Page, Widget, RelationManager, Table (incl. `records()` array source), Schema `Callout` / `EmptyState`, Actions | Project panel framework [VERIFIED: composer.lock:1524-1525 `"name": "filament/filament"`, `"version": "v5.10.0"`] |
| livewire/livewire | v4.4.7 | Timer bar and side panel components; `boot` lifecycle hook runs on every request | [VERIFIED: composer.lock:4022-4023]; hook behaviour [VERIFIED: vendor/livewire/livewire/src/Features/SupportLifecycleHooks/SupportLifecycleHooks.php:29-30 and 45-46, `$this->callHook('boot'); $this->callTraitHook('boot');` in both `mount()` and `hydrate()`] |
| laravel/framework | ^13.17 | Scheduler, Notifications, DB, `trans_choice` | [VERIFIED: composer.json require] |
| spatie/laravel-activitylog | ^5.1 | History of entry changes via `LogsAllowlistedActivity` | Existing wrapper |
| spatie/laravel-settings | ^3.9 | `DefaultsSettings::default_hourly_rate` (global default rate) | Existing |
| brick/money, `App\Domain\Shared\Money\Money` | ~0.15.2 | Rate display; `Money::forDuration` exists for Phase 10 | Existing |

### Supporting (existing project code to reuse, not packages)

| Component | Path | Use |
|-----------|------|-----|
| `Immutability::guardTriggerSql()` | `app/Domain/Shared/Database/Immutability.php:40` | Billed freeze trigger |
| `TaskBillingResolver`, `EffectiveBilling`, `BillingSource` | `app/Domain/Tasks/Billing/` | Billable default, task-level rate |
| `Project::scopeSelectable()` | `app/Domain/Projects/Models/Project.php:123` | Project picker (CONTRIBUTING mandates it for time entries) |
| `KokpitJob` + `#[Idempotent]` | `app/Domain/Operations/Jobs/` | Forgotten-timer job |
| `ActivityHistoryRelationManager` | `app/Filament/RelationManagers/` | "Historie změn" tab |
| `RethrowsDomainValidation`, `EnforcesResourceAccessRule`, `EnforcesPageAccessRule`, `EnforcesWidgetAccessRule`, `EnforcesRelationManagerAccessRule` | `app/Filament/Concerns/` | Access-rule wiring |
| `PartnerContext`, `AccessRules` | `app/Domain/Shared/Auth/` | Admin re-check in Livewire |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Per-user advisory lock + partial unique index | Index only, catch `23505` and ask the user to retry | Index-only makes every race a user-visible error; the lock serialises the common case, the index stays as the proof. Keep both |
| Stored generated `duration_seconds` (+ mutable-list entry) | No stored duration, compute `EXTRACT(EPOCH ...)` in queries | Stored column is indexable/sortable/summable and simple in `Sum`; the cost is one entry in the guard's mutable list (measured working). Chosen |
| Array-records Filament table for the week grid | Eloquent grouped query | `Table::records()` exists; a `GROUP BY` query breaks Filament's `paginate()` count and record keys |
| `<style>` block in the Blade view | Vite/Tailwind build, `FilamentAsset` CSS | No build exists and UI-SPEC forbids adding one; custom utility classes are absent from the precompiled Filament CSS |
| Exclusion constraint for overlaps | Application-level overlap warning | Locked: D-04 forbids the constraint |

**Installation:** none. `composer.json` and `package.json` are unchanged by this phase.

**Version verification:** versions above are the locked ones from `composer.lock`; nothing is added, so no `composer require` / registry lookup applies.

## Package Legitimacy Audit

No external package is installed by this phase. Slopcheck / `package-legitimacy check` was therefore not run. Rows: none.

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

If a later plan proposes any package (for example a duration or chart library), it must go through the Package Legitimacy Gate first; the planner should treat such a proposal as out of scope for this research.

## Architecture Patterns

### System Architecture Diagram

```
 Admin browser (Filament panel, SPA mode)
 +----------------------------------------------------------------------------+
 | persisted topbar region (x-persist, NOT re-rendered on navigation)         |
 |   [TimerBar Livewire] --events: timer-started/stopped/time-entry-saved-->   |
 |      Alpine ticks from started_at; wire:poll.visible.60s re-reads state     |
 |   [panel toggle] ----------------------------+                              |
 | page content (re-rendered)                   |   LAYOUT_END (re-rendered)   |
 |   Task page / Task list / Board card         |   [RecentEntriesPanel]       |
 |   Time entries list / forms / Timesheet      |   timer block + 7-day groups |
 |   Project view: stats widget + 2 tabs        |                              |
 +----------------+-----------------------------+---------------+--------------+
                  | Livewire actions (public endpoint /livewire/update)
                  v         every request: boot hook -> abort_unless(Admin)
        +-------------------------------+
        | Domain Actions (App\Domain\   |   Filament forms/bulk actions call the
        |   TimeTracking\Actions)       |   same Actions; Phase 7 API will too
        |  StartTimer  StopTimer        |
        |  Create/Update/DeleteEntry    |--- BillableDefault --> TaskBillingResolver
        |  MarkEntriesBilled            |--- OverlapFinder (read only, warning)
        |  CancelEntriesBilling         |--- TimeEntryRateResolver --> DefaultsSettings
        +---------------+---------------+
                        | one transaction; advisory lock per user (timer)
                        v
 +----------------------------------------------------------------------------+
 | PostgreSQL 18: time_entries                                                 |
 |  FK (project_id,client_id)->projects   FK (task_id,project_id)->tasks      |
 |  CHECK task needs project | end >= start | billed => finished AND billable |
 |  UNIQUE INDEX (user_id) WHERE ended_at IS NULL   <- one running timer       |
 |  TRIGGER guard: billed rows frozen (mutable: billing_state, billed_at,      |
 |                 duration_seconds, updated_at); TRUNCATE guard               |
 +----------------------------------------------------------------------------+
        ^                                         ^
        | scheduler (every 5 min, onOneServer)    | read models (SQL aggregates by
        | NotifyLongRunningTimers (KokpitJob,     | Prague day / project / task)
        |  system context) --claim row--> bell    |  Timesheet day+week, PR-05
```

### Recommended Project Structure

```
app/Domain/TimeTracking/
├── Actions/            StartTimer, StopTimer, CreateTimeEntry, UpdateTimeEntry, DeleteTimeEntry,
│                       MarkEntriesBilled, CancelEntriesBilling
├── Billing/            TimeEntryRateResolver, EntryRate (rate + source), BillableDefault
├── Enums/              BillingState (unbilled|billed)  +  BillingBadge (derived: 3 display states)
├── Jobs/               NotifyLongRunningTimers (extends KokpitJob, #[Idempotent])
├── Models/             TimeEntry (+ Project::timeEntries(), Task::timeEntries(), Client::timeEntries())
├── Notifications/      LongRunningTimerNotification
├── Queries/            OverlapFinder, TimesheetQuery (day, week), ProjectTimeSummary
├── Support/            DurationFormat (H:MM / H:MM:SS), TimerClock (truncated "now")
└── TimeEntryInput.php  (parsing/validation helpers, as TaskInput)
app/Livewire/TimeTracking/   TimerBar, RecentEntriesPanel  + shared trait RequiresAdmin (boot guard)
app/Filament/Resources/TimeEntryResource(+Pages, +RelationManagers\TimeEntryHistoryRelationManager)
app/Filament/Pages/TimesheetPage.php   resources/views/filament/pages/timesheet.blade.php
app/Filament/Widgets/ProjectTimeStats.php
app/Filament/Resources/ProjectResource/RelationManagers/{ProjectTasksTimeRelationManager,ProjectTimeEntriesRelationManager}.php
resources/views/livewire/time-tracking/{timer-bar,recent-entries-panel}.blade.php
database/migrations/2026_10_11_*  (composite keys, time_entries, users panel preference)
database/factories/TimeEntryFactory.php
```

Directory placement mirrors `app/Domain/Tasks/` (Actions, Billing, Models, Notifications, Policies). `ModelDeclaration::appModels()` scans `app/Domain/**/Models/`, so the model must live there to be enforced.

### Pattern 1: Admin-only Livewire components in persisted and layout hooks

**What:** Two class-based Livewire components outside the Filament registry. Registration in `AdminPanelProvider` (or a dedicated provider) with `Panel::renderHook()`:

```php
// Source: vendor/filament/filament/src/Panel/Concerns/HasRenderHooks.php:18
// public function renderHook(string $name, Closure $hook, string | array | null $scopes = null): static
$panel->renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER, fn (): string => app(PartnerContext::class)->isAdmin()
    ? Blade::render('@livewire(\App\Livewire\TimeTracking\TimerBar::class)') : '');
$panel->renderHook(PanelsRenderHook::LAYOUT_END, fn (): string => app(PartnerContext::class)->isAdmin()
    ? Blade::render('@livewire(\App\Livewire\TimeTracking\RecentEntriesPanel::class)') : '');
```

**Why it works (verified):**
- `GLOBAL_SEARCH_AFTER` is rendered inside the `x-persist` block of the topbar: [VERIFIED: vendor/filament/filament/resources/views/livewire/topbar.blade.php] - the block starts `x-persist="topbar.end.panel-{{ filament()->getId() }}"` and contains `{{ FilamentView::renderHook(PanelsRenderHook::GLOBAL_SEARCH_AFTER) }}` unconditionally (outside the `isGlobalSearchEnabled` branch), directly before the database notifications bell.
- `LAYOUT_END` is a sibling inside `.fi-layout`: [VERIFIED: vendor/filament/filament/resources/views/components/layout/index.blade.php:129 `{{ FilamentView::renderHook(PanelsRenderHook::LAYOUT_END, scopes: $renderHookScopes) }}` inside `<div class="fi-layout">`]; the compiled CSS makes `.fi-layout` a flex row: [VERIFIED: public/css/filament/filament/app.css `.fi-layout{width:100%;height:100%;display:flex;overflow-x:clip}`].
- The constants exist: [VERIFIED: vendor/filament/filament/src/View/PanelsRenderHook.php:37 `const GLOBAL_SEARCH_AFTER = 'panels::global-search.after';` and :49 `const LAYOUT_END = 'panels::layout.end';`].

**Admin guard on every request:** use a trait with a Livewire trait-boot hook (runs in both `mount()` and `hydrate()`, i.e. before any action method of a forged update request), the same idea as `EnforcesPageAccessRule::bootEnforcesPageAccessRule`:

```php
trait RequiresAdmin
{
    public function bootRequiresAdmin(): void
    {
        abort_unless(app(PartnerContext::class)->isAdmin(), 403);
    }
}
```

Keep public properties to scalars (ids, ISO strings, booleans); never a model, rate or price (Livewire snapshots are Partner leakage surfaces).

**Refresh model (SPA):** the bar is persisted, so it is NOT re-rendered on navigation. It must refresh on `#[On('timer-started')]`, `#[On('timer-stopped')]`, `#[On('time-entry-saved')]` and `wire:poll.visible.60s`. Components that change the timer from pages (`ViewTask`, `ListTasks`, board) call `$this->dispatch('timer-started')` after the Action. Alpine clock: `x-data` ticking once per second from the stored UTC start instant with a server-now offset, cleaned in `destroy()`; no global `setInterval` [CITED: .planning/research/PITFALLS.md Pitfall 2].

**Actions inside the components** (the "Doplnit záznam" modal): implement `Filament\Actions\Contracts\HasActions` and `Filament\Schemas\Contracts\HasSchemas`, `use InteractsWithActions` (which itself uses `InteractsWithSchemas`) and render `<x-filament-actions::modals />` in the component view [VERIFIED: vendor/filament/actions/src/Concerns/InteractsWithActions.php imports `Filament\Schemas\Concerns\InteractsWithSchemas` and `Filament\Schemas\Contracts\HasSchemas`].

### Pattern 2: `time_entries` schema (design proposal; constraint behaviour measured)

```sql
-- migration 2026_10_11_000100 (composite keys the FKs need; additive, no column change)
ALTER TABLE projects ADD CONSTRAINT projects_id_client_unique UNIQUE (id, client_id);
ALTER TABLE tasks    ADD CONSTRAINT tasks_id_project_unique  UNIQUE (id, project_id);

-- migration 2026_10_11_000200
CREATE TABLE time_entries (
  id uuid PRIMARY KEY DEFAULT uuidv7(),
  user_id    uuid NOT NULL REFERENCES users(id)   ON DELETE RESTRICT,
  client_id  uuid NOT NULL REFERENCES clients(id) ON DELETE RESTRICT,
  project_id uuid NULL,
  task_id    uuid NULL,
  description varchar(1000) NULL,
  started_at timestamptz(0) NOT NULL,
  ended_at   timestamptz(0) NULL,                       -- NULL = running
  duration_seconds integer GENERATED ALWAYS AS
     (CASE WHEN ended_at IS NULL THEN NULL
           ELSE (EXTRACT(EPOCH FROM (ended_at - started_at)))::integer END) STORED,
  billable boolean NOT NULL DEFAULT true,
  billing_state varchar(16) NOT NULL DEFAULT 'unbilled',
  billed_at timestamptz(0) NULL,
  long_running_notified_at timestamptz(0) NULL,
  created_at timestamptz, updated_at timestamptz,
  FOREIGN KEY (project_id, client_id) REFERENCES projects (id, client_id) ON DELETE RESTRICT,
  FOREIGN KEY (task_id, project_id)   REFERENCES tasks (id, project_id)   ON DELETE RESTRICT,
  CONSTRAINT time_entries_task_needs_project_check CHECK (task_id IS NULL OR project_id IS NOT NULL),
  CONSTRAINT time_entries_end_check   CHECK (ended_at IS NULL OR ended_at >= started_at),
  CONSTRAINT time_entries_state_check CHECK (billing_state IN ('unbilled', 'billed')),
  CONSTRAINT time_entries_billed_at_check CHECK ((billing_state = 'billed') = (billed_at IS NOT NULL)),
  CONSTRAINT time_entries_billed_finished_check CHECK (billing_state = 'unbilled' OR (ended_at IS NOT NULL AND billable))
);
CREATE UNIQUE INDEX time_entries_one_running_per_user ON time_entries (user_id) WHERE ended_at IS NULL;
CREATE INDEX time_entries_user_started_index ON time_entries (user_id, started_at);
CREATE INDEX time_entries_project_started_index ON time_entries (project_id, started_at);
CREATE INDEX time_entries_client_started_index ON time_entries (client_id, started_at);
CREATE INDEX time_entries_task_id_index ON time_entries (task_id) WHERE task_id IS NOT NULL;
CREATE INDEX time_entries_unbilled_index ON time_entries (project_id, started_at)
  WHERE billing_state = 'unbilled' AND billable AND ended_at IS NOT NULL;
-- guard (Immutability::guardTriggerSql + truncateGuardSql, installed by migration 2026_10_07_000200)
--   guardTriggerSql('time_entries', 'billing_state', 'unbilled', ['billing_state','billed_at','duration_seconds'])
```

In Laravel use `$table->timestampTz('started_at', 0)`, `->uuid()` / `foreignUuid()` for plain keys and `DB::statement` for the composite FKs, generated column, partial index and checks (the same split as `create_tasks_table`). Schema rules R1-R9 are enforced for every table by `tests/Feature/Schema/SchemaConventionsTest.php` (all `*_id` uuid, `timestamptz`, `uuidv7()` default) - the proposal complies.

**Measured on PostgreSQL 18.6 (scratch temp tables in the dev database):**
- A client-only row is accepted; a project of another client is refused with the foreign-key error; a task of another project is refused; a task without a project is accepted by the FKs (MATCH SIMPLE skips when a column is NULL) and is caught only by `time_entries_task_needs_project_check`; a second running row for the same user hits the partial unique index; a zero-length finished row (`ended_at = started_at`) is accepted; a billed running row violates the check.
- `timestamptz(0)` **rounds**: inserting `10:00:00.700` stored `10:00:01`, `11:25:30.200` stored `11:25:30`, and `duration_seconds` was `5129`.
- The generic guard with mutable list `billing_state,billed_at,duration_seconds,updated_at`: billing a row passes, editing `started_at` or `ended_at` of a billed row is refused with the guard error (SQLSTATE `KP001` by construction of the function), the unlock (`billing_state='unbilled', billed_at=NULL`) passes, and a normal edit after the unlock recomputes `duration_seconds` (`7200` after +1 h).
- Without `duration_seconds` in the mutable list the unlock was **refused** (see Pitfall 1).

Existing facts the proposal relies on:

[VERIFIED: database/migrations/2026_10_10_000100_create_tasks_table.php:89] `DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_ident_unique UNIQUE (id, project_id, depth)');` - this is NOT `(id, project_id)`, so the FK target needs the new `tasks_id_project_unique`.
[VERIFIED: database/migrations/2026_10_09_000300_create_projects_table.php:50] `$table->index(['client_id', 'client_visible'], 'projects_client_visible_index');` - the table has no `(id, client_id)` unique key, so `projects_id_client_unique` is new.
[VERIFIED: app/Domain/Tasks/Actions/UpdateTask.php / UpdateProject.php] a project's client never changes (`client_immutable` error) and `tasks.project_id` is not updated by `UpdateTask`, so the composite FKs never block a normal edit; the default `NO ACTION` would refuse such a change anyway.

### Pattern 3: `StartTimer` / `StopTimer` Actions

```php
// design skeleton (all names are proposals)
DB::transaction(function () use ($actor, $input) {
    DB::select('select pg_advisory_xact_lock(hashtextextended(?, 0))', ['kokpit:timer:'.$actor->getKey()]);
    $now = CarbonImmutable::now()->startOfSecond();            // read AFTER the lock, truncated
    // re-read and validate client / project (selectable) / task (not archived) FOR SHARE
    $running = TimeEntry::query()->where('user_id', $actor->getKey())->whereNull('ended_at')->lockForUpdate()->first();
    $stopped = null;
    if ($running !== null) {
        $running->forceFill(['ended_at' => $now->max($running->started_at)])->save();   // clamp: clock skew between containers
        $stopped = $running;
    }
    $entry = (new TimeEntry($contentAttributes))->forceFill([
        'user_id' => $actor->getKey(), 'client_id' => ..., 'project_id' => ..., 'task_id' => ...,
        'started_at' => $now, 'billable' => $input->billable ?? BillableDefault::for($task),
    ]);
    $entry->save();
    return [$entry, $stopped];
});   // catch UniqueConstraintViolationException (23505 on time_entries_one_running_per_user) -> typed "race lost" error
```

Design rules:
- The advisory lock key follows the existing `TaskBoard::LOCK_KEY` style [VERIFIED: app/Domain/Tasks/Board/TaskBoard.php `select pg_advisory_xact_lock(hashtextextended(?, 0))`]; per-user key so different users never wait on each other. Lock order: timer lock first, then rows. `ArchiveTask` takes the board lock then a task row lock and never the timer lock, so there is no cycle.
- The loser of a race with the lock simply serialises behind the winner and stops the winner's new entry (still exactly one timer). The UI-SPEC "race lost" toast therefore appears only if the lock were missing or an unrelated writer interferes; the unique index is the proof, the mutation run (below) is how the test shows it.
- `StopTimer(User, ?string $expectedEntryId)`: `lockForUpdate` the user's running row; none (or id mismatch) -> typed no-op ("Žádný časovač neběží."); otherwise `ended_at = max(now, started_at)`. A stop is idempotent and never overwrites an `ended_at` [CITED: .planning/research/PITFALLS.md Pitfall 12].
- Always truncate with `startOfSecond()` before writing (Pitfall 2) and take the clock after the lock.
- Attributes `user_id`, `client_id`, `project_id`, `task_id`, `started_at`, `ended_at`, `billing_state`, `billed_at` are NOT fillable (set with `forceFill` in Actions) - the same convention as `Task`.

### Pattern 4: Billable default and effective rate

- **Billable default (D-03):** `BillableDefault::for(?Task $task): bool` = `$task === null || app(TaskBillingResolver::class)->resolve($task)->isBillable()`. The Action applies it only when the caller passes `billable = null`; an explicit value always wins (Phase 7 API gets the same rule). The "touched toggle" behaviour of the form is UI-only state.
  [VERIFIED: app/Domain/Tasks/Billing/EffectiveBilling.php:38] `return $this->type !== 'non_billable';` (inside `isBillable()`); a subtask under a non-billable parent resolves non-billable through the resolver's parent level [VERIFIED: TaskBillingResolver `type()` checks `$own`, then `$parent`, then the project type].
  The resolver refuses a Partner: [VERIFIED: TaskBillingResolver.php] `if (! $this->context->isAdmin() && ! $this->context->isSystem()) {` ... `throw new AuthorizationException('The effective billing is available to the Admin only.');`.
- **Effective rate (TI-08):** a new `TimeEntryRateResolver::resolve(TimeEntry): EntryRate` covering all three entry shapes, because `TaskBillingResolver` takes a `Task` only:
  - task set -> `TaskBillingResolver->resolve($task)` -> `hourlyRate` + `hourlyRateSource` (task, parent task, project, client);
  - project only -> `project.billing.hourly_rate`, else client rate;
  - client only -> client rate;
  - nothing found -> `DefaultsSettings::default_hourly_rate` source `default` (defensive floor: the client column is NOT NULL, see below, so this level is reachable only in a unit test or a corrupted row).
  [VERIFIED: database/migrations/2026_10_09_000100_create_clients_table.php:42] `$table->bigInteger('hourly_rate_minor');` (no `->nullable()`), and [VERIFIED: app/Domain/Settings/Settings/DefaultsSettings.php:27] `public Money $default_hourly_rate;`.
  The currency of the default must equal the client's currency before it is returned; otherwise return null and show `—` (a rate in another currency would price work in the wrong money - the settings class already guards the same risk on save).
  Source labels: existing `BillingSource` has exactly [VERIFIED: app/Domain/Tasks/Billing/BillingSource.php:15-18] `Task = 'task'`, `ParentTask = 'parent_task'`, `Project = 'project'`, `Client = 'client'` with labels "Tento úkol / Nadřazený úkol / Projekt / Klient" [VERIFIED: lang/cs/enums.php:77-82]; the UI-SPEC copy wants "úkol / projekt / klient / výchozí nastavení" -> see Open Question 4.
- **Estimate (PR-05):** the resolver inherits the estimate literally (task, parent, project) - see Open Question 1.

### Pattern 5: One start affordance, many surfaces

All of `ViewTask` / `EditTask` header action, `ListTasks` row action, board card button, bar dropdown and side panel call the same `StartTimer` (task start passes `task_id` only; the Action derives project and client from the locked task row so a forged client/project cannot be combined). Task surfaces dispatch `timer-started` afterwards. The board card button is a plain `<button wire:click="startTimer('{{ $card['id'] }}')">` inside the existing `wire:sort:ignore` wrapper [VERIFIED: resources/views/filament/pages/task-board.blade.php, the header cluster is `<div wire:sort:ignore ...>`]; the `startTimer` method is added to `ManagesTaskBoard` and treats its argument as untrusted (UUID check, scoped lookup, `Gate::authorize('view')`), exactly like `previewData()` [VERIFIED: app/Filament/Concerns/ManagesTaskBoard.php docblock "The task argument of the preview action is untrusted the same way: scoped lookup, then the view Gate"]. The page already carries `EnforcesPageAccessRule`, whose boot hook refuses a Partner before the action runs.

### Pattern 6: Entry Resource, pages and the billed lock

`TimeEntryResource` follows `TaskResource`: `#[AccessRule(Audience::AdminOnly, ...)]`, `use EnforcesResourceAccessRule`, slug `time-entries`, navigation sort 40, no global search (the panel opts in per resource [VERIFIED: app/Providers/Filament/AdminPanelProvider.php `->globalSearchResourceOptIn()`], so omit `$isGloballySearchable`).

**The billed lock cannot live in the policy.** `KokpitPolicy::before()` returns `true` for the Admin for every ability: [VERIFIED: app/Domain/Shared/Auth/KokpitPolicy.php:31-33] `if ($user->hasRole(RoleName::Admin->value)) { return true; }`. A `TimeEntryPolicy::update()` that returns false for a billed row is never consulted for the Admin. The lock is therefore enforced in three layers: `TimeEntryResource::canEdit()` / `canDelete()` overrides (the pattern of `TaskResource::canEdit` for archived tasks), the `UpdateTimeEntry` / `DeleteTimeEntry` Actions re-reading the row `FOR UPDATE` and refusing a billed one with the Czech message, and the DB trigger (KP001 translated to the same message).

`EditTimeEntry` must redirect a billed entry to the view page **before** `parent::mount()` (Filament's own `authorizeAccess()` would otherwise answer 403 once `canEdit` is false).

Register `Gate::policy(TimeEntry::class, AdminOnlyPolicy::class)` in `AccessServiceProvider` (same line style as `TaskBilling`).

### Pattern 7: Entry form (cascade, overlap warning, Prague time)

- Pickers: client (non-archived), project (`Project::query()->selectable()` plus `client_id` filter), task (active tasks of the chosen project, or of the client's selectable projects when no project). Cascade rules as specified in UI-SPEC Surface E; server re-validation lives in the Action (a forged combination is a field error keyed `client_id` / `project_id` / `task_id`, translated into the `data.` form path by `RethrowsDomainValidation::withFormErrors`).
- `DateTimePicker::make('started_at')->seconds()` [VERIFIED: vendor/filament/forms/src/Components/DateTimePicker.php:542 `public function seconds(bool | Closure $condition = true): static`]. The panel timezone is Prague and storage is UTC [VERIFIED: app/Providers/LocalisationServiceProvider.php `FilamentTimezone::set('Europe/Prague');` and config/app.php `'timezone' => 'UTC'`]. The picker has no sub-second precision, but the Action still calls `startOfSecond()`.
- Overlap warning: live `Callout` (`Filament\Schemas\Components\Callout` exists [VERIFIED: vendor/filament/schemas/src/Components/Callout.php]) driven by `OverlapFinder` (read only, never blocks).
- Description limit 1000: `varchar(1000)` plus `maxLength(1000)` and the Action check (UI-SPEC U-15).

### Pattern 8: Billing actions

`MarkEntriesBilled(User $actor, list<string> $ids)` in one transaction: select the ids `FOR UPDATE` ordered by id (stable lock order), keep eligible rows (finished, billable, `unbilled`), `forceFill(['billing_state' => 'billed', 'billed_at' => $now])->save()` **per model** so each entry writes one activity row (the allowlist wrapper only logs model saves [VERIFIED: app/Domain/Audit/LogsAllowlistedActivity.php docblock "Attributes change only through model saves: a bulk query update raises no model event and therefore writes no row."]). Return counts (eligible, skipped by reason) for the toasts. `CancelEntriesBilling` is the exact inverse (a pure `billing_state`/`billed_at` flip, which the trigger allows). The bulk action closures must re-read the ids under the lock and never trust the possibly stale `Collection` of models; Filament selection spans the current page only (stock).
Phase 10 will add `invoice_item_id` and snapshot columns; **that migration must re-create the trigger with the extended mutable list** (the list is compared by name, see Immutability docblock "a column renamed in a guarded table becomes frozen, so such a migration must re-create the trigger").

### Pattern 9: Timesheet and aggregates

- Day bucket: `(started_at AT TIME ZONE 'Europe/Prague')::date` in the `GROUP BY`/`SELECT`, but **filter with UTC range predicates** computed in PHP from the Prague day boundaries (`CarbonImmutable::parse($date, 'Europe/Prague')->startOfDay()->utc()` and the next day's start) so the `(user_id, started_at)` index is used and 23/25-hour DST days are right [CITED: .planning/research/PITFALLS.md Pitfall 11]. Entries spanning midnight belong to the start day [CITED: .planning/research/ARCHITECTURE.md Pattern 6]; UI-SPEC lists the "Datum (date of the start)" column.
- Elapsed seconds for a running entry: bind the PHP clock (`:now`), never SQL `now()`: PostgreSQL's `now()` ignores Carbon's `travelTo`, which would make time-dependent tests flaky. Define one query scope, for example `scopeWithElapsedSeconds($q, CarbonInterface $now)` selecting `COALESCE(duration_seconds, GREATEST(0, EXTRACT(EPOCH FROM (?::timestamptz - started_at))::int)) AS elapsed_seconds`.
- **List footer total over the whole filtered set:** Filament wraps the filtered query as a subquery and sums the column over it, not over the page: [VERIFIED: vendor/filament/tables/src/Columns/Summarizers/Summarizer.php:151 `->table($query->toBase(), $asName)` then `:168 return $this->summarize($query, $attribute);`, and `Sum::summarize()` is `$query->sum($attribute)`]. Selecting `elapsed_seconds` in the list query and attaching `Sum::make()` with a duration formatter gives the total; the billable/non-billable split uses a second `Sum` with `modifyQueryUsing`.
- **Overlap flag per row, constant queries:** one `EXISTS` (and one `LIMIT 1` sub-select for the tooltip label) in the select list:
  `EXISTS (SELECT 1 FROM time_entries o WHERE o.user_id = time_entries.user_id AND o.id <> time_entries.id AND o.started_at < COALESCE(time_entries.ended_at, 'infinity') AND COALESCE(o.ended_at, 'infinity') > time_entries.started_at)`.
  A zero-length entry overlaps nothing (strict inequalities) - state this in the test.
- **Week grid:** `Table::records(Closure)` exists [VERIFIED: vendor/filament/tables/src/Table/Concerns/HasRecords.php:40 `public function records(?Closure $dataSource): static`] and accepts an array/collection of arrays that each need a unique `key` entry (`getTableRecordKey` throws without it: [VERIFIED: vendor/filament/tables/src/Concerns/HasRecords.php:249-253]). Use one grouped query (client, project, task, Prague day, sum, overlap flag) plus one name lookup, pivot to seven day columns in PHP, build the footer row from the same collection. Query count is independent of the number of entries.
- Czech list ordering: client names in the quick-start select use the mechanism of Pitfall 3.

### Pattern 10: Concurrency test design (copy the Phase 5 harness)

Reuse `tests/Concurrency/task-worker.php` conventions: no `RefreshDatabase`, real worker processes behind a shared barrier, refuse non-`_test` databases, clean up in FK order, final assertion that nothing remains.
- Locked run: N=8 workers x K=25 `StartTimer` calls for ONE user -> exactly one running row for the user, `COUNT(*) = N*K`, every other row finished, no worker failure, no overlap gap (each `ended_at` equals the next `started_at` or is clamped).
- Mutation run: a test double `UnlockedStartTimer` (or a flag that skips the advisory lock) plus a widened window; the expected outcome is **still at most one running row** (the partial index) and `>0` race-lost errors - this proves the DB backstop and that the harness can fail [CITED: tests/Concurrency/TaskNumberConcurrencyTest.php mutation test "detects the defect when the allocator has no row lock"].
- Add the new job class to the JobContractTest expected list (below).

### Pattern 11: Forgotten timer

- Config: add `'time' => ['long_running_hours' => 12]` to `config/kokpit.php` beside `'board'` [VERIFIED: config/kokpit.php:115 `'board' => [`]; the bar, the panel callout and the job read this one value.
- Bar/panel state is **computed on every render and poll** from `started_at` and the PHP clock; no stored flag.
- Job `NotifyLongRunningTimers extends KokpitJob` with `#[Idempotent(how: '...')]`. Natural key = the entry id: inside one transaction run an atomic claim `UPDATE time_entries SET long_running_notified_at = :now WHERE id = :id AND ended_at IS NULL AND long_running_notified_at IS NULL RETURNING ...` (query-builder update on purpose: no activity row for an internal marker) and, only for a claimed row, send a **synchronous** database-channel notification in the same transaction, so claim and notice commit or roll back together (a queued notification could be lost after the claim). Skip a deactivated owner (`users.deactivated_at` exists [VERIFIED: database/migrations/2026_10_09_000200_add_client_fk_and_deactivation_to_users_table.php:26 `$table->timestampTz('deactivated_at')->nullable();`]).
- Notification: copy the Task notification rules: scalars only in the constructor, `toDatabase()` builds `FilamentNotification::make()->title()->body()->actions([...])->getDatabaseMessage()`, every value escaped once with `e()` [VERIFIED: app/Domain/Tasks/Notifications/TaskNotification.php `TARGET_BELL => array_map(static fn (string $value): string => e($value), $values)`]. Body carries the client name and duration only; the URL is built at dispatch time (`TimeEntryResource::getUrl('view', ...)`).
- Schedule in `routes/console.php`: `Schedule::job(new NotifyLongRunningTimers)->everyFiveMinutes()->name('kokpit-long-running-timers')->onOneServer();` The existing `ScheduleOnOneServerTest` fails any event without `onOneServer` [VERIFIED: tests/Feature/Operations/ScheduleOnOneServerTest.php `if ($event->onOneServer) { continue; }`].

### Pattern 12: Project overview (PR-05)

- `ViewProject::getHeaderWidgets()` with a `StatsOverviewWidget` subclass declaring `#[AccessRule(AdminOnly)]` + `EnforcesWidgetAccessRule`; resource pages pass the record to widgets [VERIFIED: vendor/filament/filament/src/Resources/Pages/Concerns/InteractsWithRecord.php:123-126 `getWidgetData()` returning `'record' => $this->getRecord()`].
- Aggregates (one query per widget/tab): `worked = SUM(elapsed)`, `billed = SUM(elapsed) FILTER (WHERE billing_state='billed')`, `unbilled = SUM(elapsed) FILTER (WHERE billable AND billing_state='unbilled')`, `nonbillable = SUM(elapsed) FILTER (WHERE NOT billable)`; identity `worked = billed + unbilled + nonbillable` holds because billed implies billable (CHECK). Including a running entry at its elapsed time matches UI-SPEC U-16 (see Open Question 3 for the "Nevyfakturováno" side).
- "Úkoly a čas" tab: a `RelationManager` always builds on a relationship query [VERIFIED: vendor/filament/filament/src/Resources/RelationManagers/RelationManager.php `makeTable()` uses `makeBaseRelationshipTable()`], so a fixed synthetic last row "Bez úkolu" cannot be a record of that table. Open Question 2 lists the options; the task rows themselves use correlated sub-selects over `time_entries` and a left join to `task_billing` (own and parent) - **not** the resolver per row (query count constant), and the relation must include archived tasks (`withoutGlobalScopes([SoftDeletingScope::class])`) because archived tasks keep their time.

### Anti-Patterns to Avoid

- **Check-then-insert for the running timer** without a lock and index (double click, two tabs, API): use the lock and the partial unique index.
- **A policy method for the billed lock** (the Admin bypasses policies in `before()`).
- **`DATE(started_at)` or a UTC date** for timesheet days.
- **SQL `now()` in tested aggregates.**
- **Money on a time entry** in this phase (TI-08 snapshot is Phase 10); **per-entry rounding** of amounts [CITED: .planning/research/ARCHITECTURE.md Pattern 4 and `Money::forDurations` docblock "rounded once"].
- **Storing UI state in the Livewire snapshot** (models, rates).
- **Tailwind arbitrary classes** in new Blade views (not in the precompiled CSS).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Billed freeze | Eloquent observers / UI hiding | `Immutability::guardTriggerSql()` + `truncateGuardSql()` | Writer-independent (raw SQL, console, packages); already tested by `ImmutabilityPilotTest` |
| Billable default / task rate chain | Re-implementing task -> parent -> project -> client | `TaskBillingResolver` | Single source; Phase 10 reads the same |
| Project picker rule | `whereNull('deleted_at')` + client check by hand | `Project::query()->selectable()` | CONTRIBUTING mandates it |
| Job idempotence, system context, retries | Custom job base | `KokpitJob` + `#[Idempotent]` | Enforced by `JobContractTest` |
| Bell payload and escaping | String concatenation | `FilamentNotification::make()->...->getDatabaseMessage()` + `e()` once | 05-19 escape rule |
| Activity history | Own history table | `LogsAllowlistedActivity` + `ActivityHistoryRelationManager` subclass | Allowlist architecture tests |
| Czech plural of "záznam" | `:count záznamů` literal | `trans_choice` | Laravel has the Czech rule [VERIFIED: vendor/laravel/framework/src/Illuminate/Translation/MessageSelector.php:359-362 `case 'cs': case 'cs_CZ': case 'sk':`] so 1 / 2-4 / 5+ forms are correct |
| Footer totals over the filtered set | Computing in PHP over the page | Filament `Sum` summarizer over an `elapsed_seconds` select | Whole filtered set, one query |
| Money arithmetic | Float rate x hours | `Money::forDuration` / `forDurations` (Phase 10) | Single rounding point |
| Czech-sorted text | `ORDER BY name` | `ORDER BY name COLLATE "cs-CZ-x-icu"` (one helper + test) | Measured wrong with default collation |

**Key insight:** nearly every hard part (immutability, locking, jobs, isolation, escaping) already has a reviewed implementation in the repository; the phase's risk is in the seams (generated column vs guard trigger, SPA persistence, array-records table, Prague day maths), not in new technology.

## Common Pitfalls

### Pitfall 1: A stored generated column breaks the generic guard trigger
**What goes wrong:** with `duration_seconds ... GENERATED ALWAYS AS (...) STORED` and the guard mutable list `billing_state, billed_at, updated_at`, every update of a billed row (even the unlock) is refused with the immutability error.
**Why it happens:** [VERIFIED: measured on PostgreSQL 18.6] inside a `BEFORE UPDATE` row trigger `to_jsonb(NEW) ->> 'duration_seconds'` is `NULL` (generated values are computed after BEFORE triggers) while `OLD` holds `3600`; `kokpit_guard_frozen_row()` compares `to_jsonb(NEW) - allowed` with `to_jsonb(OLD) - allowed`, so the generated column always differs. (A `VIRTUAL` generated column showed `NULL` on both sides, but cannot be indexed.)
**How to avoid:** put `duration_seconds` in the mutable list. Measured: the unlock then passes, edits of `started_at` / `ended_at` are still refused, and the duration recomputes after unlock. Pin this with a schema test (billed edit refused, unlock allowed, edit after unlock changes duration).
**Warning signs:** "row ... is immutable" on the Cancel billing action although only `billing_state` changed.

### Pitfall 2: `timestamptz(0)` rounds, it does not truncate
**What goes wrong:** a value with 700 ms is stored one second later; a start taken at `10:00:00.7` and a stop at `11:25:30.2` give 5129 s, not 5129.5 / 5130 - inconsistent with "exact seconds" and with the second a user saw.
**How to avoid:** every write path (`StartTimer`, `StopTimer`, forms, Phase 7 API) goes through one clock helper returning `CarbonImmutable::now()->startOfSecond()` and truncates incoming values the same way. Test with fractional input around `.5`.

### Pitfall 3: Default collation sorts Czech wrongly
**What goes wrong:** [VERIFIED: measured] `ORDER BY n` returned `Čapek, Chalupa, Cyril, Dvořák, Hora`; `ORDER BY n COLLATE "cs-CZ-x-icu"` returned `Cyril, Čapek, Dvořák, Hora, Chalupa, Řezník, Sova, Šebesta, Zima` (correct Czech: `ch` after `h`).
**Why it matters:** [VERIFIED: CONTRIBUTING.md:71] "Text that is sorted for people (names, titles) uses a Czech collation instead of the database default; no sorted text column exists yet, so the first one defines the mechanism and adds the test." The "Všichni klienti" option group is that first column.
**How to avoid:** one reusable helper (for example a `Builder` macro or a small `CzechCollation::orderBy($query, 'name')`) used by the quick start, panel, filters and pickers, with a Feature test like the measurement above. Availability on the production database is `[ASSUMED]` (A4): the dev server lists `cs-CZ-x-icu` in `pg_collation`; add it to `deploy:verify`-style checks or fail loudly.

### Pitfall 4: Admin bypasses policies
See Pattern 6. Test: an Admin `update`/`delete` attempt on a billed entry through the Livewire edit page, the Action called directly, and a raw `UPDATE` (KP001) must all be refused.

### Pitfall 5: Soft-deleted parents return `null`
`TimeEntry::task()` / `project()` / `client()` use `SoftDeletes` targets; an archived task, project or client makes `$entry->task` null and the bar/panel/list would show a blank or throw. Define the three relations with `->withTrashed()` (and `Project`/`Client` `withoutGlobalScopes` where needed) and test an entry whose task, project and client were archived after the timer started. The `TaskResource` route binding already opens archived tasks [VERIFIED: TaskResource::getRecordRouteBindingEloquentQuery uses `withoutGlobalScopes([SoftDeletingScope::class])`]. Decide (and test) that archiving does not stop a running timer.

### Pitfall 6: SPA persistence and role switches
The bar sits in `x-persist`, so it is not re-rendered by navigation, and a login as another role in the same tab may keep persisted DOM [CITED: .planning/research/PITFALLS.md Pitfall 2]. Requirements: the hook returns `''` for non-Admins, login/logout stay full reloads (Filament default), the component never relies on page re-render, and a test renders a Partner page and asserts no timer string. Every Livewire action must also pass the `boot` guard.

### Pitfall 7: Prague day maths and DST
Fall-back is **2026-10-25** (days away from today, 2026-10-09), spring-forward 2027-03-28, fall-back 2027-10-31 [CITED: .planning/research/PITFALLS.md Pitfall 11]. A local time may not exist or occur twice; durations come from UTC instants. Fixtures: 23:30-00:30 Prague entry (start-day attribution), an entry on each DST day, month/week boundary, `Carbon::setTestNow` / `travelTo`. A 25-hour day must not be summed as 24 h.

### Pitfall 8: Clock skew across containers
Production runs several containers with their own clocks [CITED: routes/console.php comment "The production crontab runs schedule:run on every container"]. `StartTimer` stops the previous entry at `max(now, previous.started_at)` or the `ended_at >= started_at` CHECK fails; after taking the advisory lock, read the clock once and use the same instant for stop and start.

### Pitfall 9: Array-records table and grouped queries
`GROUP BY` queries break Filament pagination counts and record keys; use `records()` with a unique `key` per row for the week grid (Pattern 9). The grid is read-only: no row actions.

### Pitfall 10: Filament `RelationManager` cannot host a synthetic row
See Pattern 12 / Open Question 2.

### Pitfall 11: Custom CSS without a build
The panel ships precompiled CSS only (`public/css/filament/filament/app.css`); there is no `package.json` / Vite. Utilities such as `xl:w-80` or arbitrary values are absent unless Filament's own views use them. The side panel needs a 320 px column, a ring, sticky positioning and an `xl` breakpoint overlay: write a scoped `<style>` block (media query `min-width: 80rem`) in the component's Blade view, or inline styles as Phase 5 did [VERIFIED: resources/views/filament/pages/task-board.blade.php uses `style="..."` throughout]. Use only the spacing tokens of UI-SPEC (0.25, 0.5, 1, 1.5, 2 rem).

### Pitfall 12: Existing tests that must be edited (not just new ones)
Each of these fails by design until the new class is registered (they are the "Wave 0" edits):
- `tests/Arch/ModelDeclarationTest.php` - the explicit `appModels()` list.
- `tests/Isolation/CanaryRegistryTest.php` - `partnerIsolatedModels()` expected list, and `tests/Support/CanaryRegistry.php` - one fixture line per `PartnerIsolated` model (a `DeniesPartners` model must carry the canary in a text column: `description`).
- `tests/Isolation/RouteWalkTest.php` - `walkedResourceMap()` needs `'time-entries' => ['partner' => false, ...]`; the walk throws `LogicException` for an unknown resource slug [VERIFIED: tests/Isolation/RouteWalkTest.php `walkedRecordId` throws "does not know the resource"]. The Timesheet page has no `{record}` parameter, so it is walked automatically.
- `tests/Arch/ActivityAllowlistTest.php:` the explicit list `[Client::class, Contact::class, Project::class, ProjectBilling::class, Task::class, TaskBilling::class]` gains `TimeEntry::class` (`ActivityAllowlistColumnsTest` then verifies real columns).
- `tests/Arch/JobContractTest.php:26` `const EXPECTED_APPLICATION_JOBS = [RecordWorkerHeartbeat::class];` gains `NotifyLongRunningTimers::class`.
- `app/Domain/Shared/Database/MorphMap.php` - add `'time_entry' => TimeEntry::class` (alias snake_case, enforced by R7).
- `app/Providers/AccessServiceProvider.php` - policy registration.
- `lang/cs/kokpit.php` activity `subjects.time_entry` and `attributes.time_entry.*` labels (ActivityPresenter falls back to the raw alias if missing, but `ContactsTest` shows the project's habit of asserting the labels exist).
- `lang/cs/enums.php` - labels for each new `HasLabel` enum (`EnumLabelsTest` scans `app/`), and a `default` source label if `BillingSource` is extended.
- `tests/Feature/Repo/RepositoryFilesTest.php` + `CONTRIBUTING.md` - the Phase 6 hand-over bullet must stay present (`**Phase 6 (`) and any class it names must exist.
- `PartnerSafeColumnsTest` is **not** touched: `time_entries` is Admin-only (`DeniesPartners`), no Partner-readable table gains a column. The two composite `UNIQUE` constraints on `projects` / `tasks` add no column.

## Code Examples

### Czech collation ordering (measured)
```sql
-- Source: measured on the dev PostgreSQL 18.6, scratch query
SELECT n FROM (VALUES ('Dvořák'),('Čapek'),('Cyril'),('Chalupa'),('Hora')) t(n) ORDER BY n COLLATE "cs-CZ-x-icu";
-- Cyril, Čapek, Dvořák, Hora, Chalupa
```

### Guard trigger for the entries table
```php
// Source: app/Domain/Shared/Database/Immutability.php:40 (signature) - arguments are this phase's design
DB::unprepared(Immutability::guardTriggerSql(
    'time_entries', 'billing_state', 'unbilled', ['billing_state', 'billed_at', 'duration_seconds'],
));
DB::unprepared(Immutability::truncateGuardSql('time_entries'));
```
The table name is 12 characters, within `TABLE_MAX_LENGTH = 48` [VERIFIED: Immutability.php:35].

### SQLSTATE assertions in the schema test
```php
// Source pattern: tests/Feature/Schema/TasksTableTest.php + tests/Support/RawSql.php (expectSqlState / expectAllowed)
RawSql::expectSqlState('23514', fn () => timeEntryInsert(['task_id' => $taskId, 'project_id' => null]));   // task needs project
RawSql::expectSqlState('23514', fn () => timeEntryInsert(['ended_at' => $before]));                        // end before start
RawSql::expectSqlState('23503', fn () => timeEntryInsert(['client_id' => $otherClient, 'project_id' => $p])); // project of another client
RawSql::expectSqlState('23505', fn () => timeEntryInsert(['user_id' => $runner, 'ended_at' => null]));        // second running timer
RawSql::expectSqlState('KP001', fn () => DB::update('update time_entries set started_at = started_at + interval \'1 second\' where id = ?', [$billedId]));
RawSql::expectAllowed(fn () => DB::update("update time_entries set billing_state = 'unbilled', billed_at = null where id = ?", [$billedId]));
```
`23503` for the FK case follows Laravel/PDO reporting of the PostgreSQL foreign-key violation (the existing pilot test documents it: "a referenced parent delete is refused with 23503 (foreign_key_violation)").

### Livewire admin guard trait + arch check
```php
// Source: pattern of app/Filament/Concerns/EnforcesPageAccessRule.php (boot hook before mount)
trait RequiresAdmin { public function bootRequiresAdmin(): void { abort_unless(app(PartnerContext::class)->isAdmin(), 403); } }
// Arch test: every concrete class under app/Livewire uses RequiresAdmin (no AccessRule registry covers them).
```

### Week-grid data source
```php
// Source: vendor/filament/tables/src/Table/Concerns/HasRecords.php:40 (records()) and Concerns/HasRecords.php:249 (key required)
$table->records(fn (): array => $this->weekRows())   // each row array contains a unique 'key'
      ->paginated(false);
```

### Prague day boundaries (UTC range predicates)
```php
$from = CarbonImmutable::parse($date, 'Europe/Prague')->startOfDay()->utc();
$to   = $from->setTimezone('Europe/Prague')->addDay()->startOfDay()->utc();   // 23/25 h on DST days, never +86400 s
$q->where('started_at', '>=', $from)->where('started_at', '<', $to);
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Filament v3 `Form`/`Table` signatures | Filament 5 `Schema`, `Table::records()` array sources, `Callout`, `EmptyState` | Filament 4/5 | Use v5 APIs verified in vendor; old snippets do not compile [CITED: .planning/research/PITFALLS.md Pitfall 2] |
| Livewire 3 `mount`-only guards | Livewire 4 trait/class `boot` hook runs on mount and hydrate | current | Admin guard in `boot`, as the project already does for pages |
| Native/stored duration columns assumed trigger-safe | PostgreSQL generated columns are NULL in BEFORE triggers | PG 12+ behaviour, measured on 18.6 | Mutable-list entry required |
| Exclusion constraints for overlaps | Warning only | project decision D-04 | No `btree_gist` dependency |

**Deprecated/outdated:** none relevant; no deprecated API is recommended.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | DB CHECK is `ended_at >= started_at` (zero-length allowed) while forms/API require `ended_at > started_at`. Rationale: a start followed by another start within the same second auto-stops the first at the same instant; a strict DB check would make that start fail. ROADMAP wording ("end is before the start") matches `>=`; the research sketch in ARCHITECTURE.md used `>` | Pattern 2, 3 | If the owner wants strict `>` everywhere, `StartTimer` must delete or reject zero-length auto-stops and the concurrency test changes |
| A2 | The task rows of the PR-05 tab compare time only with an estimate whose source is the task or its parent task; an estimate that comes from the project is shown on the stats row, not repeated on every task (the UI-SPEC says "resolved estimate", CONTRIBUTING says Phase 6 decides) | Pattern 12, Open Q 1 | If wrong, each task row shows the project estimate and "Zbývá" is misleading |
| A3 | A currently running billable entry counts in "Nevyfakturováno" of the project overview (the identity worked = billed + unbilled + non-billable then holds); bulk billing still skips running entries | Pattern 12 | Dashboard numbers differ by the running entry |
| A4 | The `cs-CZ-x-icu` collation exists on the production PostgreSQL (it exists on the dev server) | Pitfall 3 | The collation helper errors in production; needs a deploy check or fallback |
| A5 | The week grid and day view attribute an entry spanning midnight to its start day | Pattern 9 | Day totals differ for night work; documented behaviour must be told to the owner |
| A6 | One Admin: the panel, bar and timesheet are scoped to the signed-in user (`user_id`), the all-entries list shows every user's entries | Pattern 6 | With several Admin accounts the list may need a user filter |
| A7 | Livewire components live in `app/Livewire/TimeTracking` and are covered by a new architecture test (the AccessRule registry scans `app/Filament` only) | Pattern 1 | A forgotten guard on a future component leaks time to a Partner |
| A8 | Side panel and bar styles are a scoped `<style>` block / inline styles in Blade (no asset registration) | Pitfall 11 | If the owner wants a registered CSS file, `filament:assets` joins the deploy |
| A9 | The forgotten-timer notice is a synchronous database-channel Laravel notification sent in the same transaction as the claim UPDATE on `time_entries.long_running_notified_at` | Pattern 11 | A queued variant risks a lost notice after the claim |
| A10 | Source labels in the entry "Platná sazba" use dedicated `kokpit.time.rate_source.*` keys with the UI-SPEC wording (úkol / projekt / klient / výchozí nastavení) rather than the existing `enums.billing_source.*` labels | Pattern 4, Open Q 4 | Minor wording drift between task page and entry page |
| A11 | The side-panel open/closed preference is a nullable boolean column on `users` (name proposal `time_panel_open`; NULL = default by viewport), written only by the Admin's own component | Pattern 1 | If a general UI-preferences store is wanted, a jsonb column replaces it |
| A12 | No cap on duration and no ban on future times in manual entries (not specified upstream) | Pattern 7 | A typo could create a very long or future entry; add a warning if the owner wants one |
| A13 | Archiving a task, project or client does not stop a running timer; the bar keeps showing it with archived relations loaded via `withTrashed()` | Pitfall 5 | If the owner wants a block or auto-stop, ArchiveTask/ArchiveClient change |

## Open Questions

1. **Estimate inheritance on task rows (PR-05).**
   - What we know: `TaskBillingResolver` inherits the estimate literally task -> parent -> project [VERIFIED: its docblock "The estimate inherits literally like the other fields; whether Phase 6 compares time against an inherited estimate is left to Phase 6 (research A7)"]; UI-SPEC column "Odhad" says "the task's resolved estimate".
   - What's unclear: literal inheritance would print the project estimate on every task without its own.
   - Recommendation: show estimate only when the source is the task or its parent task (A2); the project estimate stays in the stats row. Confirm before planning the relation manager.
2. **Fixed "Bez úkolu" last row in a relation manager.**
   - What we know: a relation manager table is relationship-backed; `Table::records()` works on Pages/Livewire tables but not as the base of a relation manager.
   - What's unclear: whether UI-SPEC's "one fixed last row" must be a table row.
   - Recommendation: (a) show "Bez úkolu" as a first-class line in the table header/`contentFooter` plus include it in the footer sums; or (b) host the tab as a custom Livewire/`TableWidget` with `records()`. Prefer (a) and record the deviation in the plan; the numbers are the contract.
3. **Does a running entry count as "Nevyfakturováno"?** (A3) UI-SPEC says "billable, unbilled time" and U-16 only decides "Odpracováno". Recommendation: yes (core value: no unbilled time slips through).
4. **Rate source labels.** Existing task page labels differ from the UI-SPEC copy (A10). Recommendation: new `kokpit.time.rate_source.*` keys; do not change the task page.
5. **Phase 10 snapshot columns.** Phase 6 stores no money. Phase 10's migration must add the snapshot columns and re-create the guard with an extended mutable list; note this in the CONTRIBUTING hand-over bullet.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV project (web, db, redis) | tests, migrations | yes | DDEV v1.25.4, PHP 8.5 web container | - |
| PostgreSQL | all schema work | yes | 18.6 (`postgres:18` service); native `uuidv7()` works | - |
| `cs-CZ-x-icu` collation | Czech ordering | yes (dev) | listed in `pg_collation` | verify on production (A4) |
| Redis | queue/scheduler lock in prod | yes (dev) | redis 7 | - |
| Horizon worker + scheduler daemons | forgotten-timer job in dev | yes (DDEV `web_extra_daemons`) | - | run `ddev artisan schedule:run` manually in checks |
| Node/Vite | - | not used | - | Phase must not add a build (UI-SPEC) |
| Test command | validation | yes | `ddev exec vendor/bin/pest` [VERIFIED: .planning/config.json `"test_command": "ddev exec vendor/bin/pest"`] | - |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** none.

Probe note: SQL probes in this research ran on temp tables only (nothing persisted in the dev database).

## Validation Architecture

> `workflow.nyquist_validation` is `true` [VERIFIED: .planning/config.json].

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 5 on PHPUnit 13 (`pestphp/pest ^5.3`), suites Unit / Feature / Arch / Isolation / Concurrency [VERIFIED: composer.json, phpunit.xml] |
| Config file | `phpunit.xml`, `tests/Pest.php` (Feature and Isolation use `RefreshDatabase` on `kokpit_test`; Concurrency commits real rows and cleans up) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/TimeTracking tests/Feature/Schema/TimeEntriesTableTest.php tests/Arch tests/Isolation` |
| Full suite command | `ddev exec vendor/bin/pest` then `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, plus `scripts/check-sensitive.sh` |

### Phase Requirements -> Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TI-07 | Every constraint by SQLSTATE: end before start (23514), task without project (23514), project/client and task/project mismatch (23503), second running timer (23505), billed row frozen (KP001), unlock allowed, zero-length allowed, billed running refused, TRUNCATE guard | schema | `ddev exec vendor/bin/pest tests/Feature/Schema/TimeEntriesTableTest.php` | Wave 0 |
| TI-07 | Parallel starts for one user leave exactly one running row; mutation run still leaves at most one and shows race errors | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/TimerConcurrencyTest.php` | Wave 0 |
| TI-01, TI-07 | Start stops the previous in one transaction at the same second; stale stop is a no-op; archived/forged context refused; client-only start | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerActionsTest.php` | Wave 0 |
| TI-01 | Bar and panel render for Admin only, refresh on events, forged Partner Livewire call = 403 with no write, state computed from stored start | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerBarTest.php tests/Feature/TimeTracking/RecentEntriesPanelTest.php` | Wave 0 |
| TI-01 | Task page / list / board start affordances call the Action and show the toast | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TaskStartAffordancesTest.php` | Wave 0 |
| TI-02, TI-03 | Create/update through Actions; client-only entry valid; mismatched combos field errors; fractional seconds truncated; DST-day entry stored from UTC | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryActionsTest.php` | Wave 0 |
| TI-02 | Resource list/create/edit/view pages, filters, footer total over the whole filtered set, empty state | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryResourceTest.php` | Wave 0 |
| TI-04 | Billable default true; false for non-billable task incl. subtask under non-billable parent; fixed-price project stays true; explicit override wins | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/BillableDefaultTest.php` | Wave 0 |
| TI-05 | Mark billed / cancel billing (eligibility, skipped counts, one activity row per entry, edit/delete refused for billed at Action, Resource and DB level) | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/BillingLockTest.php` | Wave 0 |
| TI-08 | Rate resolution task > project > client > default for task/project/client-only entries; default-currency guard; exact seconds | feature + unit | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/EntryRateResolverTest.php tests/Unit/TimeTracking` | Wave 0 |
| TI-06 | Day/week totals, Prague bucketing, 23:30-00:30 entry, DST fixtures (2026-10-25, 2027-03-28), overlap flag incl. 3 overlapping, running entry counted, constant query count | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimesheetTest.php` | Wave 0 |
| TI-09 | Threshold from config, job claims once, second run changes nothing, no notice when stopped/under threshold, payload escaped, schedule registered with `onOneServer`, timer never stopped | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/LongRunningTimerTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Arch/JobContractTest.php` | Wave 0 (+ edit existing) |
| PR-05 | Stats, estimate vs actual (incl. 0 estimate, none), billed vs unbilled, task rows with archived tasks, "Bez úkolu", Admin only | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/ProjectTimeOverviewTest.php` | Wave 0 |
| Isolation | Canary fixture per client; Partner sees zero `time_entries`; route walk 403 on `time-entries`; Partner-rendered HTML contains none of the forbidden strings (Copywriting Contract "Never on a Partner surface"); no time string in Partner notifications/bell | isolation | `ddev exec vendor/bin/pest tests/Isolation` | edit existing + new `TimeLeakTest.php` |
| Arch | Model declared (`DeniesPartners`), allowlist list updated, every `app/Livewire` class guarded, enum labels translated, morph alias | arch | `ddev exec vendor/bin/pest tests/Arch tests/Feature/Localisation` | edit existing + new `LivewireComponentContractTest.php` |
| Cross | Czech collation helper orders `Cyril, Čapek, Dvořák, Hora, Chalupa` | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/CzechOrderingTest.php` | Wave 0 |
| Cross | Duration formatting `H:MM` truncates (never rounds up), `H:MM:SS`, sums formed from seconds first | unit | `ddev exec vendor/bin/pest tests/Unit/TimeTracking/DurationFormatTest.php` | Wave 0 |

### Sampling Rate
- **Per task commit:** the new test files of that task via `ddev exec vendor/bin/pest <files>` (each < 30 s; concurrency excluded).
- **Per wave merge:** `ddev exec vendor/bin/pest` (the last task of every plan runs the full suite), pint and phpstan.
- **Phase gate:** full suite green, `ddev composer ci` green, `scripts/check-sensitive.sh` clean, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Schema/TimeEntriesTableTest.php` (+ helper `timeEntryInsert()`), covers TI-07/TI-05/TI-08
- [ ] `tests/Concurrency/TimerConcurrencyTest.php` and `tests/Concurrency/timer-worker.php` (copy of the task-worker conventions, `_test` database guard, barrier, FK-order cleanup)
- [ ] `database/factories/TimeEntryFactory.php` (fictional descriptions assembled at runtime)
- [ ] `tests/Feature/TimeTracking/*` files above, `tests/Unit/TimeTracking/DurationFormatTest.php`
- [ ] `tests/Arch/LivewireComponentContractTest.php`, `tests/Isolation/TimeLeakTest.php`
- [ ] Edits of the existing registries listed in Pitfall 12
- [ ] Framework install: none

## Security Domain

> `security_enforcement` is on (ASVS level 1) [VERIFIED: .planning/config.json `"security_enforcement": true`, `"security_asvs_level": 1`].

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no change | existing Filament login + enforced Admin 2FA; the new components rely on the signed-in session |
| V3 Session Management | yes (indirectly) | persisted topbar must not survive a role/user change: full-reload login/logout (Filament default), hook re-evaluated per request |
| V4 Access Control | yes | `#[AccessRule(AdminOnly)]` on every Filament class, `DeniesPartners` + `AdminOnlyPolicy` on the model, `RequiresAdmin` boot guard on every Livewire component (public `/livewire/update` endpoints), billed lock in Action + Resource + trigger, route-walk and canary tests |
| V5 Input Validation | yes | UUID checks on every id argument from the browser, enum/state whitelisting, description length (varchar 1000 + rule), date parsing and second truncation in `TimeEntryInput`, composite FKs as the last line; never trust Livewire public properties |
| V6 Cryptography | no | none introduced |
| V7 Error handling / logging | yes | DB errors translated to Czech field errors (no SQL text to the user); allowlisted activity log (description excluded) |
| V8 Data protection | yes | tracked time and rates never in Partner HTML, snapshots, bell, activity view or search (canary + leak test) |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Partner forges a Livewire call to start/stop/read the timer | Elevation of Privilege, Info Disclosure | `bootRequiresAdmin` (runs on hydrate); hooks render nothing for non-Admin; test the forged call returns 403 and writes nothing |
| Forged client/project/task ids in a form or action argument | Tampering | Action re-derives context under lock; composite FKs; field errors; `forceFill` for ids |
| Editing a billed entry via a bulk action, the API (Phase 7) or raw SQL | Tampering | Guard trigger (KP001) plus Action re-check; test all three writers |
| XSS through description, client/task names in the bell or tooltip | Tampering | Blade `{{ }}` escaping, `e()` once for bell values (05-19 rule), no `{!! !!}` in new views; XSS canary string in description |
| Time or rates leaking via Livewire snapshot, notification, global search, activity log | Info Disclosure | Scalar-only component state; entries not searchable (opt-in search); notification body = client + duration only; Activity is Admin-only |
| Double submit / race producing two running timers or a double bill | Tampering | Advisory lock + partial unique index; `FOR UPDATE` in billing Actions; confirmations |
| GET side effects with SPA prefetch | Tampering | Start/stop are Livewire actions, never links; prefetch left off |
| Enumeration via error messages | Info Disclosure | Neutral field errors for unavailable context (as `project_unavailable` in `CreateTask`) |

## Sources

### Primary (HIGH confidence)
- Repository code read this session: `app/Domain/Shared/Database/Immutability.php`, `database/migrations/2026_10_07_000200_create_kokpit_guard_frozen_row_function.php`, `2026_10_10_000100_create_tasks_table.php`, `2026_10_09_000300_create_projects_table.php`, `2026_10_09_000100_create_clients_table.php`, `2026_10_10_000400_create_task_billing_table.php`, `app/Domain/Tasks/Billing/*`, `app/Domain/Shared/Auth/*`, `app/Domain/Operations/Jobs/*`, `app/Domain/Tasks/Notifications/TaskNotification.php`, `app/Domain/Tasks/Board/TaskBoard.php`, `app/Domain/Settings/Settings/DefaultsSettings.php`, `app/Filament/Resources/TaskResource.php`, `.../ViewTask.php`, `.../ViewProject.php`, `ManagesTaskBoard.php`, `task-board.blade.php`, `AdminPanelProvider.php`, `routes/console.php`, `config/kokpit.php`, test harness files under `tests/` (Canary, CanaryRegistry, RouteWalk, concurrency worker, schema and arch tests), `CONTRIBUTING.md`
- Vendor source: `vendor/filament/filament` v5.10.0 (topbar view, layout view, `PanelsRenderHook`, `HasRenderHooks`, `InteractsWithRecord`, `RelationManager`), `vendor/filament/tables` (`Summarizer`, `Sum`, `HasRecords`), `vendor/filament/forms` (`DateTimePicker::seconds`), `vendor/filament/schemas` (`Callout`), `vendor/filament/actions` (`InteractsWithActions`), `vendor/livewire/livewire` v4.4.7 (`SupportLifecycleHooks`), `vendor/laravel/framework` (`MessageSelector`), `public/css/filament/filament/app.css`
- PostgreSQL 18.6 measurements on temp tables (rounding of `timestamptz(0)`, generated column in BEFORE trigger, composite FK + CHECK behaviour, guard trigger with mutable list, Czech collation order)
- `.planning/ROADMAP.md`, `.planning/REQUIREMENTS.md`, `06-CONTEXT.md`, `06-UI-SPEC.md`, `.claude/CLAUDE.md`, `.planning/config.json`

### Secondary (MEDIUM confidence)
- `.planning/research/ARCHITECTURE.md` (Pattern 6 schema sketch, Pattern 9 persistent timer), `.planning/research/PITFALLS.md` (Pitfalls 2, 11, 12), `.planning/research/STACK.md` - project research from earlier phases; the schema sketch is superseded where this document differs (nullable project, billing_state, generated-column guard entry)

### Tertiary (LOW confidence)
- None used. External web documentation was not needed: every Filament/Livewire/Laravel claim above was checked against the installed vendor source. The research-plan / research-store seam was not used for that reason.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - no new dependency; versions from `composer.lock`
- Architecture (schema, locking, guard, concurrency): HIGH - behaviour measured on the project's PostgreSQL version
- Livewire/Filament component mechanics (persisted hook, `records()`, relation manager limits): MEDIUM - read from vendor source, not executed
- Pitfalls: HIGH for the measured four, MEDIUM for SPA/CSS items
- UI-copy conflicts and product decisions: flagged in Assumptions/Open Questions

**Research date:** 2026-10-09
**Valid until:** 2026-11-08 (30 days; re-check if Filament or Livewire minor versions change, and before 2026-10-25 for the DST fixtures)
