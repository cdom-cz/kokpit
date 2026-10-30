---
phase: 03-operations-foundation
plan: 12
subsystem: ui
tags: [filament, activitylog, audit-trail, relation-manager, access-rule, czech-ui, livewire]

requires:
  - phase: 03-operations-foundation
    provides: "LogsAllowlistedActivity, ActivitySourceLabel, activity_log.source and ActivityProbe (03-10); allowlist rules (03-11); page boot hook pattern (03-04)"
  - phase: 02-platform-foundation
    provides: "Activity model (DeniesPartners, AdminOnlyPolicy), AccessRule/Audience, EnforcesResourceAccessRule, strict authorization, PanelRegistryTest, RouteWalkTest"
provides:
  - "ActivityResource: Admin-only, list-only, read-only global activity overview (Historie zmen) with filters and a stable order"
  - "ActivityPresenter: shared columns, change summaries, subject and causer labels"
  - "Abstract ActivityHistoryRelationManager that later phases subclass in a few lines"
  - "Boot hooks in the relation manager and widget access traits (deny before mount)"
affects: [phase-04-tasks-projects, phase-06-time-entries, phase-10-invoices, 03-14, 03-17]

plan_head_before: 0638de419599d550b729d94ca028b228c4bb45ce
plan_head_after: e57575dc43179919c46aca0dd52f13fd57020d54

actuals:
  tokens: 15700
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Read-only Filament resource: canCreate() false, index page only, no record or bulk actions, causer is the only eager-loaded relation and the subject is never loaded"
    - "Access trait boot hook that asks the #[AccessRule] declaration directly, so a class that overrides its own visibility check cannot widen access; proven with visibility-override fixtures"
    - "Prague day filter: Y-m-d picked days become UTC instants (start of day, start of next day) bound as ISO-8601 strings"

key-files:
  created:
    - app/Filament/Resources/ActivityResource.php
    - app/Filament/Resources/ActivityResource/Pages/ListActivities.php
    - app/Filament/Support/ActivityPresenter.php
    - app/Filament/RelationManagers/ActivityHistoryRelationManager.php
    - tests/Feature/Operations/ActivityViewsTest.php
    - tests/Support/Filament/Fixtures/ProbeActivityHistoryRelationManager.php
    - tests/Support/Filament/Fixtures/VisibleOverrideHistoryRelationManager.php
    - tests/Support/Filament/Fixtures/MountProbeWidget.php
    - tests/Support/Filament/Fixtures/VisibleOverrideWidget.php
  modified:
    - app/Filament/Concerns/EnforcesRelationManagerAccessRule.php
    - app/Filament/Concerns/EnforcesWidgetAccessRule.php
    - app/Filament/Concerns/EnforcesResourceAccessRule.php
    - lang/cs/kokpit.php
    - tests/Isolation/PanelAccessTest.php

key-decisions:
  - "Widget boot hook is abort_unless(AccessRules::allows(static::class) && static::canView(), 403): a superset of the plan's canView() check that also holds when a widget overrides canView(); identical behaviour for a widget that does not"
  - "Values in a change summary: null and empty string as an em dash, booleans as ano/ne, cut at 80 characters plus an ellipsis, entries joined with a semicolon; the arrow is the literal ' -> '"
  - "Short id is the last 8 characters of the subject id (the head of a UUID v7 is a timestamp and would repeat across records created together)"
  - "User filter uses the sentinel value 'none' for rows without a causer; no real user id can equal it"
  - "The overview resource is final and its getNavigationGroup() narrows the return type to string (PHPStan level 8 otherwise reports an unused union type)"

patterns-established:
  - "Attach a history tab: final subclass of ActivityHistoryRelationManager with its own #[AccessRule(Audience::AdminOnly, reason)], listed in the resource getRelations(); the record's model must use LogsAllowlistedActivity"
  - "Translate audit labels per subject alias: kokpit.activity.subjects.{alias} and kokpit.activity.attributes.{alias}.{attribute}, raw name as fallback"

requirements-completed: [FND-08]

coverage:
  - id: D1
    description: "The Admin opens a Czech global activity overview listing every row newest first (ties by id) with Prague time, event, subject type and short id, user or source label and the old to new allowlisted changes"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php#shows the Admin a probe change with its Czech event, subject, source and old to new value"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php#lists rows with the same time by id descending, identically across two renders"
        status: pass
    human_judgment: false
  - id: D2
    description: "The overview filters by event, source, subject type (from the active morph map), user including no user, and a Europe/Prague date range"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php (filter tests: source, event, user, subject type, Prague day)"
        status: pass
    human_judgment: false
  - id: D3
    description: "A reusable read-only history relation manager lists one record's rows only, newest first, with no actions, and a concrete subclass is a few lines"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php#lists only the history of its own record, newest first, with no actions"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner sees none of it: overview 403 for every Partner state, relation manager and widget refused before mount() and before any activity query, no create/edit/view route, Activity stays deny-all, route walk finds no canary leak"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php (refusal, read-only, relation manager, widget and Activity model tests)"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#refuses a Partner before a relation manager or a widget mount() runs, whatever their own visibility check says"
        status: pass
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "The properties payload of a row is never rendered and the subject model is never queried"
    requirement: FND-08
    verification:
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php#never renders the properties payload of a row"
        status: pass
      - kind: integration
        ref: "tests/Feature/Operations/ActivityViewsTest.php#renders a row whose subject is gone and never queries the subject table"
        status: pass
    human_judgment: false
  - id: D6
    description: "The Czech wording of the overview, filters and change summaries reads naturally"
    requirement: FND-08
    verification: []
    human_judgment: true
    rationale: "Label wording is a language-quality judgment no test asserts; tests only prove the keys render"

duration: 10min
completed: 2026-10-08
status: complete
---

# Phase 03 Plan 12: Activity Overview and History Relation Manager Summary

**Admin-only read-only Czech activity overview with event, source, subject, user and Prague-day filters, a reusable history relation manager for later phases, and access-rule boot hooks for relation managers and widgets**

## Performance

- **Duration:** about 10 min
- **Started:** 2026-10-08T03:50:20Z
- **Completed:** 2026-10-08T04:01Z
- **Tasks:** 3
- **Files modified:** 14 (9 created, 5 modified)

## Accomplishments

- `ActivityResource` (AdminOnly, `EnforcesResourceAccessRule`, slug `activity`, Czech label "Historie zmen" in the "Sprava" group): index page only, `canCreate()` false, no record or bulk actions, newest first with an id tie-break. Only the causer is eager-loaded; the subject model is never loaded, so a deleted record renders and no query touches the subject table. Only `attribute_changes` is rendered, never the properties payload.
- Filters: event, source (labels from `ActivitySourceLabel`), subject type (options computed at runtime from the active morph map, models using `LogsAllowlistedActivity`), user (every user plus "Bez uzivatele"), and a date range on Europe/Prague days converted to UTC instants (a row at 23:30 UTC on the 8th is found by the 9th in Prague and not by the 8th).
- `ActivityPresenter`: shared columns (`columns(withSubject)`), `changes()` as `name: old -> new` joined by `; ` (booleans ano/ne, empty as a dash, 80-character cut, translated attribute names with the raw name as fallback), `subjectLabel()` and `causerLabel()` (user name, else source label).
- Abstract `ActivityHistoryRelationManager` on `activitiesAsSubject`: read-only, same columns without the subject, no header, record or bulk actions, Czech title; the docblock shows the few lines a later phase writes (final subclass with its own AdminOnly declaration, listed in `getRelations()`).
- `bootEnforcesRelationManagerAccessRule()` and `bootEnforcesWidgetAccessRule()` abort 403 before `mount()`; the relation manager trait lost its `trait.unused` ignore (the widget trait keeps its own).
- Verification: `ddev composer ci` green (Pest 760 passed, 3247 assertions; Pint; Larastan no errors; licence check 201 packages). The route walk passes with the new route.

## Task Commits

1. **Task 1 (tracer): Czech overview, Partner 403** - `23ea549` (feat)
2. **Task 2 (tdd): filters, ordering, change formatting**
   - RED `482d370` (test) - 9 of 17 tests fail
   - GREEN `d65c293` (feat)
3. **Task 3 (tdd): history relation manager and boot hooks**
   - RED `516133a` (test) - 3 of 24 tests fail
   - GREEN `e57575d` (feat)

**Plan metadata:** recorded in the docs commit that follows this summary.

## TDD Gate Compliance

Both TDD tasks have a real `test(03-12)` commit before the `feat(03-12)` commit; no REFACTOR commit was needed. Task 1 is a tracer (written with its tests, one commit). `gsd_run check tdd-red-evidence` does not parse Pest output, so classification was by reading the Pest output (same as plans 03-10 and 03-11).

**RED evidence (semantic assessment):**

- Task 2: filter tests failed with "a table filter with name [source|event|causer|created_at] exists" not satisfied and empty option arrays (filters did not exist); formatting tests failed on string identity (`1`/empty instead of `ano`/dash, no cut, no translation). Green at RED, by design of the tracer: the stable-order test (the tracer already sorts newest first with the id tie-break) and the properties-canary test (the tracer never renders properties).
- Task 3: the history list test failed on the planned assertions (the placeholder relation manager had no columns), and the two "visibility check overridden to pass" tests failed with "Expected 403 but received 200" (no boot hook yet). Green at RED: Partner refusal of the plain fixtures and the widget fixture, see Deviations 2.

**Mutation checks (tests are not vacuous):**

- Task 2: replacing the Prague zone with UTC failed the Prague-day test; dropping `orderByDesc('id')` failed the tie-break test; rendering properties failed the canary test (and three formatting tests); ignoring the "no user" branch failed the user-filter test.
- Task 3: emptying the relation manager hook, emptying the widget hook, ascending order, and a header action on the history each failed exactly the matching test. Every file was restored byte for byte.

## Files Created/Modified

- `app/Filament/Resources/ActivityResource.php` - read-only overview, filters, default sort
- `app/Filament/Resources/ActivityResource/Pages/ListActivities.php` - list page without header actions
- `app/Filament/Support/ActivityPresenter.php` - columns, change formatting, labels
- `app/Filament/RelationManagers/ActivityHistoryRelationManager.php` - abstract read-only history
- `app/Filament/Concerns/EnforcesRelationManagerAccessRule.php`, `EnforcesWidgetAccessRule.php` - boot hooks
- `app/Filament/Concerns/EnforcesResourceAccessRule.php` - stale `trait.unused` ignore removed (first real Resource now uses it)
- `lang/cs/kokpit.php` - `kokpit.activity.*` strings
- `tests/Feature/Operations/ActivityViewsTest.php`, `tests/Isolation/PanelAccessTest.php` - tests
- `tests/Support/Filament/Fixtures/ProbeActivityHistoryRelationManager.php` (plan), `VisibleOverrideHistoryRelationManager.php`, `MountProbeWidget.php`, `VisibleOverrideWidget.php` - fixtures

## Decisions Made

See `key-decisions` above. In short: the widget hook is the AND of the declaration and `canView()`; change values are dash/ano/ne/80-cut; the short id is the last 8 characters; the user filter uses the sentinel `none`.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Extra test fixtures to prove the boot hooks**
- **Found during:** Task 3 (RED authoring)
- **Issue:** Filament 5 already runs its own access check at boot (`CanAuthorizeAccess` for relation managers and widgets, calling `canViewForRecord()` and `canView()`), so a plain AdminOnly fixture is refused for a Partner even without the new hooks and a mutation of the hook would not fail any test. The plan's fixture list had only the probe relation manager, and the existing `AdminOnlyWidget` has no `mount()`.
- **Fix:** Added three fixtures: `VisibleOverrideHistoryRelationManager` and `VisibleOverrideWidget` (their own visibility check is overridden to pass, so only the new hook can refuse a Partner) and `MountProbeWidget` (a `mount()` that records it ran). Mutation checks confirm the hooks are now guarded by tests.
- **Files modified:** `tests/Support/Filament/Fixtures/*` (3 new files)
- **Committed in:** `516133a`

**2. [Rule 2 - Missing critical] Widget hook also asks the declaration directly**
- **Found during:** Task 3
- **Issue:** The plan's `abort_unless(static::canView(), 403)` duplicates Filament's own boot check exactly and adds nothing; a widget overriding `canView()` would pass both.
- **Fix:** `abort_unless(AccessRules::allows(static::class) && static::canView(), 403)`. Same result for a widget that does not override `canView()`.
- **Files modified:** `app/Filament/Concerns/EnforcesWidgetAccessRule.php`
- **Committed in:** `e57575d`

**3. [Rule 3 - Blocking] PHPStan return type and stale ignore**
- **Found during:** Task 1 and Task 2 (verification)
- **Issue:** (a) `getNavigationGroup(): string|UnitEnum|null` on a final class reported as unused union members; (b) `is_string($class)` on a class-string array value reported as always true; (c) the `trait.unused` ignore on `EnforcesResourceAccessRule` was stale once the first real Resource used the trait.
- **Fix:** Narrowed the return type to `string`, dropped the redundant check, removed the stale ignore comment (outside the plan's file list, comment only).
- **Files modified:** `app/Filament/Resources/ActivityResource.php`, `app/Filament/Concerns/EnforcesResourceAccessRule.php`
- **Committed in:** `23ea549`, `d65c293`

**4. [Rule 1 - Bug] Test authoring fixes (not product code)**
- A second request in one test reused Filament's cached navigation, so the Partner navigation check was split into its own test; the widget fixture was based on `StatsOverviewWidget` because `filament-widgets::widget` is not a real view.
- **Committed in:** `23ea549`, `516133a`

---

**Total deviations:** 4 (2 blocking, 1 missing critical, 1 test bug). No change of scope or of the planned contract.

## Issues Encountered

- `ddev exec` occasionally saw a stale mount right after a file write ("test file not found"); a short wait before re-running resolved it (known from earlier plans).
- Finding for the owner and later plans: Pitfall 1 was already closed for relation managers and widgets by Filament 5 itself (`CanAuthorizeAccess::bootCanAuthorizeAccess`); the new hooks are defence in depth that cannot be widened by an override, not the only line.

## Known Stubs

None.

## Threat Flags

None. T-03-28 to T-03-31 are mitigated as planned (AdminOnly declaration plus policy plus boot hooks, no properties rendering, subject never loaded). No new network or file surface beyond the planned overview route, which the route walk covers.

## User Setup Required

None - no external service configuration required.

## Manual follow-up

- Review the Czech wording of `kokpit.activity.*` (label "Historie zmen" in the "Sprava" group, filter labels, "Bez uzivatele", ano/ne); it is placeholder-quality discretionary wording.
- The subject-type options and attribute names need translations per model (`kokpit.activity.subjects.{alias}`, `kokpit.activity.attributes.{alias}.{attribute}`) when the first real logging model arrives; until then the raw alias and attribute names show.

## Next Phase Readiness

- Phase 4 and later: a record gets a history tab with a final subclass of `ActivityHistoryRelationManager` (own `#[AccessRule]`), after its model uses `LogsAllowlistedActivity` with `#[LoggedAttributes]` and is added to the expected list in `tests/Arch/ActivityAllowlistTest.php`.
- FND-08 work is done in 03-10 (allowlist and source), 03-11 (rules and no pruning) and 03-12 (views). The requirement stays unchecked in REQUIREMENTS.md because `requirements.ready-ids` blocks it: plan 03-19 also declares FND-08 and has no summary yet.

## Self-Check: PASSED

- Created files exist: all 9 files under key-files.created found on disk.
- Commits `23ea549`, `482d370`, `d65c293`, `516133a`, `e57575d` are ancestors of HEAD; `git rev-list --count` from the ledger base gives 5.
- Acceptance criteria of all three tasks re-run: all pass (grep checks, `trait.unused` count 0, `'properties'` count 0, subject never eager-loaded, RouteWalkTest green).
- `ddev composer ci` green at the last code commit: Pest 760 passed, Pint, Larastan no errors, licence check 201 packages.

---
*Phase: 03-operations-foundation*
*Completed: 2026-10-08*
