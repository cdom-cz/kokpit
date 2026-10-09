---
phase: 02-platform-foundation
plan: 11
subsystem: identity
tags: [filament, access-rule, partner-isolation, canary-harness, route-walk, strict-authorization, pest]

requires:
  - phase: 02-platform-foundation
    provides: PartnerContext, PartnerScope, KokpitPolicy, CanaryRecord and the Canary helper (02-10); ProductionConfigGuard and the panel MFA wiring (02-09); config kokpit.canary_harness (02-02); the four package models (02-04)
provides:
  - App\Domain\Shared\Auth\Audience, AccessRule (mandatory class attribute) and AccessRules (fail-closed evaluation, reads the class itself only)
  - Four enforcing traits (page, resource, widget, relation manager) that make the access method follow the declaration; resource and relation manager AND it with the parent policy check
  - App\Filament\Pages\Dashboard (PartnerAllowed, empty state, no widgets) replacing the stock dashboard and its widgets; panel on strictAuthorization with global search off
  - Registry test over the panel and app/Filament with no hand-kept list, sorted failure output
  - Test-only CanaryRecordResource registered only while the harness is on and the class exists; ProductionConfigGuard refuses the harness in production
  - CanaryRegistry (one fixture per PartnerIsolated model) and the Partner A route walk
affects: [02-12, 02-13, phase-04 clients and partner grants, every later phase that adds a Resource, Page, Widget or relation manager, phase-07 Sanctum guard]

actuals:
  tokens: 16000
  tasks: 3
  commits: 4

tech-stack:
  added: []
  patterns:
    - "Every Filament Resource, Page, Widget, cluster and relation manager carries #[AccessRule(Audience, reason)] on the class itself; an enforcing trait turns it into canAccess(), canView() or canViewForRecord(); a class without the attribute is denied, even to the Admin"
    - "Resource and relation manager access is the AND of the declaration and the parent policy check, so strict authorization keeps throwing for a policy-less model"
    - "Registry test collects classes from the panel and from a scan of app/Filament, reports offenders sorted by class name, and proves itself on synthetic undeclared classes"
    - "Canary registry: later phases add one fixture line per new PartnerIsolated model; a test fails for a model without a line"
    - "Test-only panel surface behind config('kokpit.canary_harness') plus class_exists, with a production boot refusal"

key-files:
  created:
    - app/Domain/Shared/Auth/Audience.php
    - app/Domain/Shared/Auth/AccessRule.php
    - app/Domain/Shared/Auth/AccessRules.php
    - app/Filament/Concerns/EnforcesPageAccessRule.php
    - app/Filament/Concerns/EnforcesResourceAccessRule.php
    - app/Filament/Concerns/EnforcesWidgetAccessRule.php
    - app/Filament/Concerns/EnforcesRelationManagerAccessRule.php
    - app/Filament/Pages/Dashboard.php
    - tests/Arch/PanelRegistryTest.php
    - tests/Isolation/PanelAccessTest.php
    - tests/Isolation/CanaryRegistryTest.php
    - tests/Isolation/RouteWalkTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Support/Filament/CanaryRecordResource.php
    - tests/Support/Filament/CanaryRecordResource/Pages/ListCanaryRecords.php
    - tests/Support/Filament/CanaryRecordResource/Pages/ViewCanaryRecord.php
    - tests/Support/Filament/Fixtures/ (AdminOnlyWidget, PartnerAllowedWidget, AdminOnlyRelationManager, PolicyDeniedRelationManager, PolicyDeniedResource, UndeclaredCanaryRecordResource, UndeclaredPage)
  modified:
    - app/Providers/Filament/AdminPanelProvider.php
    - app/Support/ProductionConfigGuard.php
    - lang/cs/kokpit.php
    - tests/Unit/Support/ProductionConfigGuardTest.php
    - tests/Feature/Auth/TwoFactorEnforcementTest.php

key-decisions:
  - "AccessRules::for() reads the attribute on the named class only (ReflectionClass::getAttributes), so a subclass of a declared class is undeclared and denied; the registry test proves it"
  - "Resource and relation manager traits return AccessRules::allows(static::class) && parent::...: the declaration cannot widen a policy and a policy cannot be skipped"
  - "Panel global search is off (UI-SPEC A-6); the global-search leak check runs on CanaryRecordResource::getGlobalSearchResults() and getGlobalSearchEloquentQuery() directly, which is the path that matters when a later phase turns search on"
  - "ProductionConfigGuard treats anything but false or null as on for canary_harness (fail closed on a truthy string)"
  - "The registry collector lives in PanelRegistryTest.php (the plan's acceptance grep looks there), not in a support class"
  - "Test-only canary Resource is registered through class_exists() on a Tests\\ class name; autoload-dev is absent in production installs and the guard refuses the switch anyway"

patterns-established:
  - "A new panel class is denied until it declares #[AccessRule]; the registry test fails first, then the trait denies at runtime"
  - "Route walk fills only the {record} parameter; any other route parameter fails the walk so a new kind of route is looked at on purpose"
  - "Serialise package-model rows with getAttributes(), not toJson() (Media serialises to nothing useful and a toJson-based check would pass vacuously)"

requirements-completed: [FND-18, FND-06]

coverage:
  - id: D1
    description: "Every Resource, Page, Widget, cluster and relation manager registered in the panel, and every concrete such class under app/Filament, declares #[AccessRule] on itself; the registry test finds offenders without a hand-kept list, reports them sorted by class name, is not vacuous (sees the dashboard through both the registry and the directory scan) and reports an undeclared fixture and a subclass of a declared class"
    requirement: "FND-18"
    verification:
      - kind: unit
        ref: "tests/Arch/PanelRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "The declaration drives behaviour: PartnerAllowed admits an Admin and a Partner with a client only, AdminOnly admits only an Admin, a class without the attribute admits nobody (even the Admin), a non-existent class and a subclass of a declared class are denied; resources and relation managers keep their policy check (declaration AND policy)"
    requirement: "FND-18"
    verification:
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "The stock dashboard and its default widgets are replaced by App\\Filament\\Pages\\Dashboard (PartnerAllowed, no widgets, one empty state with role-specific text); the dashboard answers 200 for an Admin and Partner A, 403 for a Partner without a client and for a user without a role, and a login redirect for a guest; the panel runs with strictAuthorization and without global search"
    requirement: "FND-18"
    verification:
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#opens the dashboard to an Admin, #opens the dashboard to a Partner with a client and shows the neutral text, #refuses the dashboard to a Partner without a client, #refuses the dashboard to a user without a role, #replaces the stock dashboard and its widgets, #runs the panel with strict authorization and without global search"
        status: pass
    human_judgment: false
  - id: D4
    description: "The canary registry has one fixture per PartnerIsolated model (CanaryRecord, Media, Tag, Activity, WebhookCall); a test fails for any PartnerIsolated model without one and reports a synthetic one; for every entry the Admin sees both clients' canaries, Partner A sees no client B canary or id, sees zero rows of every deny-all model and exactly the own row of a client-bound model, and a Partner without a client sees nothing"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Partner A requests every GET panel route (5 walked) and no body, including Livewire snapshots, contains a client B canary or id; the record routes refuse a client B record with 403 or 404 and serve a client A record with 200 and the own canary; the list page shows Partner A's own canary; the Livewire table search for the client B canary shows nothing; global search returns only client A records and its query is already scoped; the Admin sees both so the refusal comes from the scope"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/RouteWalkTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "The application refuses to boot in production when the canary harness switch is on (unit check on the guard, including a truthy string, and a real artisan process in production with the switch on and off); the canary Resource is absent from the route list when the switch is off"
    requirement: "FND-06"
    verification:
      - kind: unit
        ref: "tests/Unit/Support/ProductionConfigGuardTest.php"
        status: pass
      - kind: integration
        ref: "tests/Isolation/PanelAccessTest.php#refuses to start a production process while the canary harness is on, #keeps the canary resource out of the panel while the harness is off"
        status: pass
    human_judgment: false
  - id: D7
    description: "The dashboard empty state, navigation skeleton (one item for both roles, derived from canAccess) and the stock 403/404 views look right in the browser in both colour modes and at mobile width (UI-SPEC Surfaces 5, 6 and 7 backstop items)"
    verification: []
    human_judgment: true
    rationale: "Visual and responsive checks are listed as backstop in the UI-SPEC and no test asserts layout, contrast or Czech diacritics rendering in Inter; the verifier routes this to a human"

duration: 11 min
completed: 2026-10-07
status: complete
commits: 4
plan_head_before: 1c38d62515d125186540fa510a40451a2d4b7ff6
plan_head_after: 55366a37950b9337745a1830121e6edc5fe6a5a6
---

# Phase 2 Plan 11: Declared access rules on every panel class and the Partner route walk Summary

**A mandatory `#[AccessRule(Audience, reason)]` on every Filament Resource, Page, Widget, cluster and relation manager that drives the class's own access method (declaration AND policy for resources and relation managers, deny when undeclared), an own empty `Dashboard` replacing the stock one, a panel on strict authorization, a registry test over the panel and `app/Filament`, and a canary registry plus a route walk proving Partner A sees nothing of client B on any page, record route, Livewire table or global search.**

## Performance

- **Duration:** 11 min (2026-10-07T19:45:06Z to 19:56:37Z)
- **Tasks:** 3 of 3
- **Files:** 28 changed (23 created, 5 modified)
- **Suite:** 356 passed (was 309 at the start of the plan), `ddev composer test`, Pint and Larastan level 8 clean, `scripts/check-sensitive.sh` clean on every commit

## Accomplishments

- `Audience` (AdminOnly, PartnerAllowed), `AccessRule` (`Attribute::TARGET_CLASS`) and `AccessRules::for()`/`allows()`. `for()` reflects on the named class only, so attributes are not inherited and a subclass is undeclared; `allows()` is false without the attribute, `isAdmin()` for AdminOnly and `isAdmin() || partnerClientId() !== null` for PartnerAllowed.
- Four traits, one method each: `canAccess()` (page), `canAccess()` AND parent (resource), `canView()` (widget), `canViewForRecord()` AND parent (relation manager). Mutation run: dropping the `AccessRules::allows(...) &&` half from the resource and relation-manager traits made 2 tests fail; removing the page trait from the dashboard made 2 tests fail.
- `App\Filament\Pages\Dashboard`: `#[AccessRule(PartnerAllowed)]`, `getWidgets()` returns `[]`, content is one `EmptyState` (outline inbox icon, heading "Zatím tu nic není", Admin or Partner description from `lang/cs/kokpit.php`). The panel loses `AccountWidget` and `FilamentInfoWidget`, gains `strictAuthorization()` and `globalSearch(false)`.
- `PanelRegistryTest`: collects from `Filament::getPanel('admin')` (resources, pages, widgets incl. configurations, clusters, page and resource configurations, every resource's `getRelations()` with `RelationGroup` flattened) plus a scan of `app/Filament` for concrete subclasses of Resource, Page, Widget, RelationManager and Cluster (Resource pages excluded, they are governed by the Resource and its policy). Failure message lists offenders sorted by class name; self-checks with a synthetic undeclared fixture, a subclass of a declared class and an unsorted input.
- Test-only `CanaryRecordResource` (list and view pages, `secret` as record title, searchable column, no scope removal) registered in `AdminPanelProvider` only when `config('kokpit.canary_harness') === true` and the class exists. `ProductionConfigGuard::check()` (same signature) throws in production unless `kokpit.canary_harness` is false or absent.
- `CanaryRegistry::fixtures()` has five lines: `CanaryRecord`, `Media`, `Tag`, `Activity`, `WebhookCall` (follow-up from 02-04 and 02-10 done). `prepare()`/`cleanup()` build and tear down the canary table, fake disk and probe host.
- `RouteWalkTest` and `CanaryRegistryTest` as described in the coverage block.

### Walked routes

The route walk takes every `filament.admin.*` GET route except `filament.admin.auth.login` (guest-only) and the logout POST. Five routes are walked:

1. `filament.admin.pages.dashboard` (`/admin`)
2. `filament.admin.resources.canary-records.index` (`/admin/canary-records`)
3. `filament.admin.resources.canary-records.view` (`/admin/canary-records/{record}`, requested with a client B id: 403 or 404, and with a client A id: 200)
4. `filament.admin.auth.profile` (`/admin/profile`)
5. `filament.admin.auth.multi-factor-authentication.set-up-required` (`/admin/multi-factor-authentication/set-up`)

The `filament.exports.download` and `filament.imports.failed-rows.download` GET routes are Filament package routes outside the `admin` panel prefix; they are not walked (no export or import exists).

## Tracer gate

Task 1 (tracer) was committed and its `<verify>` re-run on the same tree before the commit: the two named test files plus the full suite (324 passed at that point), Pint and Larastan clean. The mutation run (page trait removed from the dashboard) proved the behaviour tests are not vacuous. Expansion followed.

## Task Commits

1. **Task 1: Tracer, access rule, own dashboard, strict authorization, registry test** - `95266e6` (feat)
2. **Task 2 RED: failing tests for resource, widget and relation manager enforcement and the canary guard** - `f63414b` (test)
3. **Task 2 GREEN: enforcing traits, canary Resource registration, production refusal** - `57f0655` (feat)
4. **Task 3: canary registry, registry test and the route walk** - `55366a3` (test)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

## TDD Gate Compliance

- **Task 2 RED (`f63414b`):** 9 failed, 21 passed, run against temporary permissive stubs of the three traits (resource: parent only; widget: always true; relation manager: parent only), kept out of the commit so the tests failed on their own assertions and not on a missing trait. Failures were assertion failures: "arrays identical" for the widget, AdminOnly relation manager and undeclared-resource matrices, "array contains CanaryRecordResource" (panel registration absent), "exception thrown" (guard unchanged) and "true is false" (production process with the harness on still started). The passes are controls that hold under the stubs (parent policy checks, dashboard behaviour from Task 1). `gsd_run check tdd-red-evidence` was not run: `tdd_mode` is off and Pest console output is not a supported report format (same as earlier plans). The RED commit tree alone does not run (the fixtures use traits that arrive in GREEN), same shape as 02-10's RED.
- **Task 2 GREEN (`57f0655`):** 340 passed after fixing two older production-boot tests (see deviations).
- **Task 3 (`55366a3`):** plan marks it `tdd="true"` but it adds only tests and test support; the behaviour under test (scope, policies, enforcement) already existed from 02-10 and Task 2, so there is no production code to put in a GREEN commit and one `test` commit carries it. RED-equivalent evidence is a mutation run: making `CanaryRecord::constrainForPartner` a no-op failed 5 of 16 tests (the route walk, the own-canary list check, the Livewire table check and two registry checks), and the Admin control test proves the canary strings really are in every registered model. No REFACTOR commits.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] An existing 2FA test used a Partner without a client for `/admin`**
- **Found during:** Task 1 (full suite before the tracer commit)
- **Issue:** `TwoFactorEnforcementTest` "never forces a Partner to set up two-factor authentication" opened `/admin` as a Partner with no `client_id` and expected 200. The new dashboard rule (PartnerAllowed means a Partner with a client) correctly answers 403 for a client-less Partner (FND-18 empty input).
- **Fix:** the test gives the Partner a client; it is about two-factor enforcement, not about the client-less state.
- **Files modified:** `tests/Feature/Auth/TwoFactorEnforcementTest.php`
- **Committed in:** `95266e6`

**2. [Rule 3 - Blocking] Two 02-09 production-boot tests broke when the guard learned the canary rule**
- **Found during:** Task 2 (full suite after GREEN)
- **Issue:** "boots the application provider in production with enforcement on" and "refuses to start a real production process with enforcement off and starts with it on" expect a clean production boot, but the test suite runs with the canary harness on (phpunit.xml), so the new rule refuses it. The tests were describing a production configuration that is not one.
- **Fix:** the first sets `kokpit.canary_harness` to false; the second passes `KOKPIT_CANARY_HARNESS=false` to the child process.
- **Files modified:** `tests/Feature/Auth/TwoFactorEnforcementTest.php`
- **Committed in:** `57f0655`

**3. [Rule 1 - Bug] A registry check built on `toJson()` and `toContain($needle, $message)` would have passed vacuously**
- **Found during:** Task 3 (Admin control test)
- **Issue:** `Collection::toJson()` of `Media` rows came out as `[]`, and Pest's `toContain` takes more needles, not a message, so the first draft asserted the wrong things. The Admin control test (both canaries must be visible to the Admin in every model) caught it before the negative checks could pass for the wrong reason.
- **Fix:** rows are serialised from `getAttributes()` and every containment check uses `str_contains` with a message.
- **Files modified:** `tests/Isolation/CanaryRegistryTest.php`
- **Committed in:** `55366a3`

### Plan adaptations (not defects)

**4. [Adaptation] Collector inside the test file.** The plan's acceptance grep looks for `getRelations` and `getWidgets` in `tests/Arch/PanelRegistryTest.php`, so the collector functions live there (prefixed `panelRegistry...`) instead of a support class.

**5. [Adaptation] `@phpstan-ignore trait.unused` on three traits.** `EnforcesResourceAccessRule`, `EnforcesWidgetAccessRule` and `EnforcesRelationManagerAccessRule` are used only by test fixtures, which Larastan does not analyse (same situation as `IsolatesPartners` in 02-10). Each carries the ignore with a reason; remove each when the first real class uses it (Phase 4).

**6. [Adaptation] Extra fixtures and tests.** Added `PolicyDeniedResource` and `PolicyDeniedRelationManager` (declaration says yes, policy says no) to prove the AND, a Partner-without-client pass over the walk, a Livewire table search check and a scoped-query assertion for global search (the policy also filters the result URL, so the result list alone would not prove the scope). `AdminPanelProvider::panel()` now delegates to a private `configure()` so the canary registration reads separately.

**7. [Adaptation] Global search off in the panel.** UI-SPEC A-6 turns panel global search off; the plan's global-search truth is verified on the Resource's own `getGlobalSearchResults()` and `getGlobalSearchEloquentQuery()`, which is the path a later phase enables.

**Total deviations:** 2 blocking or bug fixes in older tests, 1 own-test bug caught by a control, 4 adaptations
**Impact on plan:** none on scope; every plan truth is covered by a passing test.

## UI-SPEC application

- **Surface 5 (dashboard):** applied. `App\Filament\Pages\Dashboard`, declared PartnerAllowed, no widgets, one centered `EmptyState` with the outline inbox icon, heading "Zatím tu nic není", the exact Admin and Partner descriptions from the Copywriting Contract (strings in `lang/cs/kokpit.php`), no call-to-action button.
- **Surface 6 (403/404):** no custom views (A-8). Behaviour verified by tests: out-of-scope record routes answer 404 for a Partner (the scope returns no row, so existence is not confirmed) and an undeclared or denied page answers 403; guests are redirected to login, not 403.
- **Surface 7 (navigation):** the item list derives from `canAccess()`, which is now the declaration, so navigation and authorization cannot disagree. Phase 2 content is the single "Nástěnka" item (stock title, A-7).
- **A-6:** `globalSearch(false)` set; database notifications are off by default (no `databaseNotifications()` call exists).
- **A-3 (Indigo primary colour): not applied.** It is not part of this plan; `AdminPanelProvider` still holds `Color::Amber`. The UI-SPEC says the colour change belongs elsewhere, so it stays open for the plan that owns the panel theme (likely 02-12 or 02-13).
- **Contradictions with the plan or CONTEXT.md:** none that needed a decision other than the global search note (adaptation 7).

## Issues Encountered

None beyond the deviations above.

## Known Stubs

None. `CanaryRecordResource` and the fixtures are test-only by design and never registered outside the harness.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: package-gate-callback (still open from 02-10) | vendor spatie/laravel-permission | The permission package's `Gate::before` grants an ability when the user holds a permission named like it, and it runs before `KokpitPolicy` and therefore before the Resource policy check that `EnforcesResourceAccessRule` ANDs in. No permissions exist today (roles only). Left visible for the owner as asked; `config/permission.php` was not changed. Decide before the first permission is added. |
| threat_flag: auth-pages-outside-registry | `AdminPanelProvider` (`->profile()`, MFA set-up-required page) | Filament's own profile and MFA set-up pages are registered by the panel configuration, not through `getPages()`, so the registry test does not govern them and they carry no `#[AccessRule]`. They show only the signed-in user's own data and the route walk covers them (client B data never appears; a client-less Partner can still open the profile). Revisit if a later phase puts anything beyond the user's own data on them. |
| threat_flag: test-class-reference-in-app | `app/Providers/Filament/AdminPanelProvider.php` | The provider names `Tests\Support\Filament\CanaryRecordResource` behind `config('kokpit.canary_harness') === true && class_exists(...)`. It cannot load in a no-dev production install and `ProductionConfigGuard` refuses the switch in production, but it is a production file naming a test class; acceptable for the plan's D-04 design, to be revisited if the harness is ever packaged differently. |

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- Every later phase adds a panel class together with `#[AccessRule]` and the matching trait; the registry test and the runtime denial both force it. A new `PartnerIsolated` model needs one line in `CanaryRegistry::fixtures()`.
- Phase 4 opens `Tag` and `Media` to Partners deliberately; its registry fixtures already exist and will then be checked as client-bound models (own row visible, other client's invisible).
- When the first real Resource, Widget and relation manager arrive, remove the three `@phpstan-ignore trait.unused` comments.
- 02-12 and 02-13 can rely on a green suite (356 passed), clean Pint and Larastan level 8.
- Open for the owner: the spatie `Gate::before` item (threat flag above) and the Indigo colour change (A-3).

## Self-Check: PASSED

- Created files exist (checked with `[ -f ]`): the three auth types, four traits, `Dashboard`, the four test files, `CanaryRegistry`, `CanaryRecordResource` with both pages and the seven fixtures.
- Commits `95266e6`, `f63414b`, `57f0655`, `55366a3` are ancestors of HEAD; `git rev-list --count 1c38d62..HEAD` is 4.
- Acceptance criteria re-run and passing for all three tasks: `strictAuthorization()` and `Dashboard::class` in the provider, no `AccountWidget` match, `#[AccessRule(` in the dashboard and in `CanaryRecordResource`, `getRelations` and `getWidgets` in `PanelRegistryTest`, `parent::canAccess` and `parent::canViewForRecord` in the traits, `config('kokpit.canary_harness')` in the provider and `canary_harness` in the guard, `CanaryRecord::class`, `Media::class` and `WebhookCall::class` in the registry, `getRoutes` and `getGlobalSearchResults` in the route walk.
- `ddev composer test` (356 passed), Pint `--test` and Larastan level 8 clean; `scripts/check-sensitive.sh` clean on each commit.
