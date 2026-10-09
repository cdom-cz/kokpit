---
phase: 02-platform-foundation
plan: 10
subsystem: identity
tags: [partner-isolation, global-scope, policies, default-deny, canary-harness, arch-tests, pest]

requires:
  - phase: 02-platform-foundation
    provides: KokpitModel and package subclasses (02-03, 02-04), RoleName, RoleSeeder and kokpit:install (02-09), users.client_id (02-03)
provides:
  - App\Domain\Shared\Auth\PartnerContext (scoped binding, runAsSystem restored in finally)
  - PartnerScope (fail-closed global scope), IsolatesPartners, DeniesPartners, PartnerIsolated, NotPartnerScoped
  - KokpitPolicy (default-deny base with the single Admin rule) and AdminOnlyPolicy
  - AccessServiceProvider (scoped context, AdminOnlyPolicy registered for Media, Tag, Activity, WebhookCall)
  - Isolation declarations on all eight models; arch tests that force the declaration and scan app/ for scope escape hatches
  - Canary harness (CanaryRecord, CanaryRecordPolicy, Canary) and the Isolation Pest suite
affects: [02-11 canary registry and production canary rule, 02-12, 02-13, phase-04 clients and partner grants, phase-07 Sanctum guard, phase-09 documents and tags, phase-11 webhooks]

actuals:
  tokens: 18000
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Default-deny in the data layer: a global scope on every PartnerIsolated model adds WHERE 1 = 0 for every state except Admin, an explicit system run and a Partner with a client"
    - "PartnerContext is bound with scoped(); every console, seeder and job entry point wraps its work in runAsSystem"
    - "Isolation is declared on the class itself: implements PartnerIsolated (IsolatesPartners or DeniesPartners) xor #[NotPartnerScoped(reason: ...)]; an arch test enforces it"
    - "Policies extend KokpitPolicy: before() holds the one Admin rule, every ability denies until a subclass grants it; ability methods take Model $record so subclasses can override without narrowing"
    - "Test-only tenant model whose table is created inside each test; canary strings assembled at runtime"

key-files:
  created:
    - app/Domain/Shared/Auth/PartnerContext.php
    - app/Domain/Shared/Auth/PartnerScope.php
    - app/Domain/Shared/Auth/PartnerIsolated.php
    - app/Domain/Shared/Auth/IsolatesPartners.php
    - app/Domain/Shared/Auth/DeniesPartners.php
    - app/Domain/Shared/Auth/NotPartnerScoped.php
    - app/Domain/Shared/Auth/KokpitPolicy.php
    - app/Domain/Shared/Policies/AdminOnlyPolicy.php
    - app/Providers/AccessServiceProvider.php
    - tests/Support/CanaryRecord.php
    - tests/Support/CanaryRecordPolicy.php
    - tests/Support/Canary.php
    - tests/Support/ModelDeclaration.php
    - tests/Support/EscapeHatchScanner.php
    - tests/Isolation/FailClosedScopeTest.php
    - tests/Isolation/PolicyBaseTest.php
    - tests/Isolation/DeniedModelsTest.php
    - tests/Isolation/SystemRunCommandsTest.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Arch/QueryEscapeHatchTest.php
  modified:
    - bootstrap/providers.php
    - phpunit.xml
    - tests/Pest.php
    - app/Domain/Identity/Models/User.php
    - app/Domain/Identity/Models/Role.php
    - app/Domain/Identity/Models/Permission.php
    - app/Domain/Identity/Models/PersonalAccessToken.php
    - app/Domain/Shared/Models/Media.php
    - app/Domain/Shared/Models/Tag.php
    - app/Domain/Shared/Models/Activity.php
    - app/Domain/Shared/Models/WebhookCall.php
    - app/Domain/Shared/Models/KokpitModel.php
    - app/Console/Commands/InstallCommand.php
    - app/Console/Commands/ResetAdminTwoFactorCommand.php
    - tests/Feature/Schema/PackageModelsTest.php

key-decisions:
  - "The Admin rule lives only in KokpitPolicy::before(); there is no application Gate::before (acceptance grep over app/ is empty)"
  - "PartnerScope resolves PartnerContext on every apply and never caches it; PartnerContext reads the default guard, so Phase 7 must make a Sanctum-authenticated request resolve the same user"
  - "KokpitPolicy ability methods take Model $record (not a concrete model) so a subclass overrides without narrowing the parameter type; CanaryRecordPolicy checks the type itself"
  - "constrainForPartner is typed Builder<covariant Model> so PHPStan level 8 accepts the scope's call and the implementations"
  - "Console work runs inside one runAsSystem for the whole command (handle() delegates to a private method), not around a part of the body"
  - "A Phase 2 test that reads Media, Tag, Activity or WebhookCall without a user runs as the system; the scopes are now real"

patterns-established:
  - "Escape-hatch scan works on PHP tokens, so comments and strings never trigger it; the allowlist is a file => reason map, empty"
  - "The system flag is proven inside console commands by recording PartnerContext::isSystem() at the moment a matching SQL statement runs (DB::listen)"

requirements-completed: [FND-06, FND-18]

coverage:
  - id: D1
    description: "A global PartnerScope hides every row from a guest, a Partner without a client, a user with a client but no role and a user with an unknown role; a Partner sees exactly the rows of their one client (list, find, count, exists, or-conditions) and none of another client's canary strings; two Partner accounts of one client see the same rows; the Admin and a system run see all rows"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/FailClosedScopeTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "PartnerContext is a scoped binding; runAsSystem sets the flag for the callback only and restores it when the callback throws, nested and between requests"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/FailClosedScopeTest.php#restores the system flag after a callback that throws, #restores the outer system flag when a nested system run ends, #starts every request with the system flag off"
        status: pass
    human_judgment: false
  - id: D3
    description: "KokpitPolicy denies a guest, a role-less user, an unknown role and a Partner without a client every one of twelve abilities, admits the Admin through its single rule, and gives a Partner only explicit grants (own-client view and the list); a policy overriding nothing and AdminOnlyPolicy deny a Partner everything"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/PolicyBaseTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every concrete model under app/Domain declares its isolation (PartnerIsolated xor NotPartnerScoped with a reason, on the class itself), every PartnerIsolated model resolves a KokpitPolicy, and the declaration check itself reports a model with neither, both, a blank reason, or an inherited-only attribute"
    requirement: "FND-06"
    verification:
      - kind: unit
        ref: "tests/Arch/ModelDeclarationTest.php"
        status: pass
    human_judgment: false
  - id: D5
    description: "Media, Tag, Activity and WebhookCall created through their packages are invisible to a Partner (also through the host relations) and denied by AdminOnlyPolicy, while the Admin sees them; User, Role, Permission and PersonalAccessToken stay readable for authentication and a Partner reaches the panel"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/DeniedModelsTest.php"
        status: pass
    human_judgment: false
  - id: D6
    description: "No file in app/ calls DB::table( or a bare withoutGlobalScopes() outside an (empty) allowlist; the scanner reports both with file and line and ignores comments, strings and calls with arguments"
    requirement: "FND-06"
    verification:
      - kind: unit
        ref: "tests/Arch/QueryEscapeHatchTest.php"
        status: pass
    human_judgment: false
  - id: D7
    description: "kokpit:install and kokpit:admin:reset-2fa run their database work inside PartnerContext::runAsSystem and leave the flag off afterwards"
    requirement: "FND-06"
    verification:
      - kind: integration
        ref: "tests/Isolation/SystemRunCommandsTest.php; tests/Feature/Auth/InstallCommandTest.php; tests/Feature/Auth/ResetTwoFactorCommandTest.php"
        status: pass
    human_judgment: false
  - id: D8
    description: "A Partner account with a null client_id sees zero rows of every PartnerIsolated model and is denied every policy ability (FND-18 empty input); two accounts of one client share rows (FND-18 adjacency)"
    requirement: "FND-18"
    verification:
      - kind: integration
        ref: "tests/Isolation/FailClosedScopeTest.php#shows a Partner without a client no row, #shows two Partner accounts of one client the same rows; tests/Isolation/PolicyBaseTest.php#denies a Partner without a client every ability"
        status: pass
    human_judgment: false

duration: 8 min
completed: 2026-10-07
status: complete
commits: 5
plan_head_before: c0e4820163c08fd0ce397beb0b797c8c6a8e89a9
plan_head_after: 1c38d62515d125186540fa510a40451a2d4b7ff6
---

# Phase 2 Plan 10: Partner default-deny in the data layer Summary

**A request-scoped `PartnerContext` with a throw-safe system escape, a fail-closed `PartnerScope` (Admin and system see all, a Partner with a client is constrained by the model, every other state gets `WHERE 1 = 0`), a deny-all trait for the four Admin-only package models, a `#[NotPartnerScoped(reason)]` opt-out for the four authentication models, and a default-deny `KokpitPolicy` with the one Admin rule, proven on a test-only tenant model with two fictional clients and runtime canary strings, plus architecture tests that force every model to declare its isolation and scan `app/` for scope escape hatches.**

## Performance

- **Duration:** 8 min (2026-10-07T19:32:44Z to 19:41:22Z)
- **Tasks:** 3 of 3
- **Files:** 35 changed (20 created, 15 modified)
- **Suite:** 309 passed (was 262 at the start of the plan), Pint and Larastan level 8 clean, `scripts/check-sensitive.sh` clean on every commit

## Accomplishments

- `PartnerContext` (final, `scoped()` in `AccessServiceProvider`): `user()`, `isAdmin()`, `partnerClientId()` (non-null only for role partner with a non-empty `client_id`), `isSystem()`, `runAsSystem()` with the previous flag restored in `finally`.
- `PartnerScope` resolves the context on every apply. Mutation run: replacing the `whereRaw('1 = 0')` by a no-op made 7 of 17 tracer tests fail, so the matrix is not vacuous.
- `DeniesPartners` (uses `IsolatesPartners`, constraint `WHERE 1 = 0`) on `Media`, `Tag`, `Activity`, `WebhookCall`, each registered with `AdminOnlyPolicy`; `#[NotPartnerScoped]` with the plan's reasons on `User`, `Role`, `Permission`, `PersonalAccessToken`.
- `KokpitPolicy::before()` returns false for a guest and for anyone who is neither Admin nor a Partner with a client, true for the Admin, null for a valid Partner; all twelve abilities deny.
- `ModelDeclarationTest` lists the eight models (so the scan cannot pass vacuously), checks the declaration on the class itself (attributes are not inherited), and checks that every `PartnerIsolated` model including `CanaryRecord` resolves a `KokpitPolicy`. `QueryEscapeHatchTest` scans `app/` by PHP tokens; a temporary `DB::table` file made it fail with file and line.
- `kokpit:install` and `kokpit:admin:reset-2fa` (follow-up from 02-09) run as one system run; a `DB::listen` test records the flag at the moment of the `insert into "users"` and the `select`/`update` on users.
- `#[UsePolicy]` was available in the installed Laravel (`Illuminate\Database\Eloquent\Attributes\UsePolicy`) and is used for `CanaryRecord`; the four package models use `Gate::policy()` in `AccessServiceProvider::boot()` because they extend vendor classes.

## Tracer gate

Task 1 (tracer) was committed and its `<verify>` re-run on the committed tree (auto mode active): Isolation suite 17 passed, Pint and Larastan clean, full suite 279 passed. Expansion followed.

## Task Commits

1. **Task 1: Tracer, fail-closed Partner scope and request-scoped access context** - `510d187` (feat)
2. **Task 2 RED: failing tests for the default-deny policy base** - `de459ff` (test)
3. **Task 2 GREEN: KokpitPolicy, AdminOnlyPolicy, canary policy** - `70b7c37` (feat)
4. **Task 3 RED: failing tests for model declarations, deny-all package models and the system run** - `2da53a7` (test)
5. **Task 3 GREEN: declarations, deny-all package models, policies registered, commands as system** - `1c38d62` (feat)

**Plan metadata:** none; `commit_docs` is false and `.planning/` is untracked (intentional skip, `skipped_commit_docs_false`).

## TDD Gate Compliance

Tasks 2 and 3 followed RED then GREEN with separate commits; there are no REFACTOR commits (nothing to clean up). `gsd_run check tdd-red-evidence` was not run: `tdd_mode` is off and Pest console output is not a supported report format (same as earlier plans).

- **Task 2 RED (`de459ff`):** 8 failed, 2 passed against temporary permissive stubs of `KokpitPolicy`, `AdminOnlyPolicy` and `CanaryRecordPolicy` (every ability true, `before()` returning null; kept out of the commit so the tests failed on their own assertions, not on a missing class). Semantic assessment: every failure was "denied expected, allowed given" or a wrong Partner grant. The two passes are negative controls (a guest is denied because the stub's ability methods require a `User`; the Admin is allowed by the permissive stub). One RED test, "registers no global Gate::before callback", was wrong and was removed before the RED commit (see deviations).
- **Task 3 RED (`2da53a7`):** 10 failed, 41 passed. Failures: the declaration test listing the eight undeclared models, `Gate::getPolicyFor()` returning null for the four package models, `Failed asserting that 1 is identical to 0` (a Partner saw the media row), the Admin install flag recorded as `[false]` instead of `[true]`. The passes are controls that already hold: scanner self-tests (the scanner is test support), the authentication models being readable, the panel opening for a Partner, and `QueryEscapeHatchTest` over a clean `app/` (a guard, proven by the temporary violating file).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Existing package schema tests read the now-closed models as a guest**
- **Found during:** Task 3 (first full run after GREEN)
- **Issue:** four tests in `PackageModelsTest` (media, tag, tag cascade, webhook call) read `Media`, `Tag` or `WebhookCall` without a user; the scopes return nothing for a guest by design.
- **Fix:** the Eloquent reads and package calls run inside `PartnerContext::runAsSystem`; raw `DB::table` assertions stay as they were.
- **Files modified:** `tests/Feature/Schema/PackageModelsTest.php`
- **Committed in:** `1c38d62`

**2. [Rule 1 - Bug] Planned "no Gate::before" test could not pass**
- **Found during:** Task 2 RED
- **Issue:** a test asserting that the Gate has no `before` callback fails because the permission package registers its own (`PermissionRegistrar`). The plan's rule is about application code.
- **Fix:** dropped that test; the acceptance grep `grep -rn 'Gate::before' app` (empty, and the docblock wording avoids the literal) is the check. See Threat Flags for the consequence.
- **Files modified:** `tests/Isolation/PolicyBaseTest.php`
- **Committed in:** `de459ff`

### Plan adaptations (not defects)

**3. [Adaptation] `Builder<covariant Model>` and `Model $record`.** PHPStan level 8 rejects `Builder<Model>` against the scope's `Builder<covariant Model>`, so the interface, `DeniesPartners` and `CanaryRecord` use `Builder<covariant Model>`. `KokpitPolicy` ability methods take `Model $record` because PHP forbids a subclass narrowing the parameter type; `CanaryRecordPolicy::view()` checks `instanceof CanaryRecord`.

**4. [Adaptation] Task 1 needed a temporary PHPStan ignore.** `IsolatesPartners` was used only by the test-only `CanaryRecord` (not analysed), so `trait.unused` failed Larastan in Task 1. A `@phpstan-ignore trait.unused` was added in `510d187` and removed in `1c38d62` once `DeniesPartners` uses the trait.

**5. [Adaptation] Extra test files and support classes.** Plan action 4 allowed "a new isolation test" for the package probes; added `tests/Isolation/DeniedModelsTest.php` and `tests/Isolation/SystemRunCommandsTest.php`, plus support classes `tests/Support/ModelDeclaration.php` and `tests/Support/EscapeHatchScanner.php` (neither in `files_modified`). `CanaryRecord::constrainForPartner` qualifies the column (`qualifyColumn`) so a join cannot make it ambiguous. The `KokpitModel` docblock now describes the declaration rule instead of promising it.

**6. [Adaptation] Whole-command system run.** The plan said "wrap the transaction body"; both commands run their entire body (including prompts and the early Admin pre-check) in one system run, because the pre-check and the reset lookup also query users.

**Total deviations:** 1 blocking fix, 1 wrong planned test, 4 adaptations
**Impact on plan:** none on scope; every plan truth is covered by a passing test.

## Issues Encountered

None beyond the deviations above.

## Known Stubs

None.

## Threat Flags

| Flag | File | Description |
|------|------|-------------|
| threat_flag: package-gate-callback | vendor spatie/laravel-permission (registered through `PermissionRegistrar`) | The permission package registers a `Gate::before` that grants an ability when the user holds a permission named like it. The application has no permissions today (roles only), so nothing can be granted this way; if a later phase introduces permissions, a permission named like an ability would bypass `KokpitPolicy` for a Partner. Decide before adding any permission (also consider `register_permission_check_method` in `config/permission.php`). |

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

- 02-11 can register `CanaryRecord` and one fixture per real `PartnerIsolated` model in the canary registry; today the real `PartnerIsolated` models are the four deny-all package models (their data-layer denial is already proven in `DeniedModelsTest`).
- Phase 4 opens `Tag` and `Media` to Partners by replacing `DeniesPartners` with a client-bound `constrainForPartner` and a policy with explicit grants; the arch tests force a `KokpitPolicy` for each.
- Phase 7 owns the open edge from the plan: `PartnerContext` reads the default guard, so a request authenticated on the Sanctum guard must resolve the same user or the scope fails closed (safe, but it locks API Partners out).
- The Admin-only role checks load the `roles` relation once per user instance; code that changes a signed-in user's roles in the same request must reload the user.

## Self-Check: PASSED

- Created files exist (20 created files checked with `[ -f ]`, including the eight `Auth` and `Policies` types, the provider, the five support classes and the six test files).
- Commits `510d187`, `de459ff`, `70b7c37`, `2da53a7`, `1c38d62` are ancestors of HEAD; `git rev-list --count c0e4820..HEAD` is 5.
- Acceptance criteria re-run and passing for all three tasks: `whereRaw('1 = 0')`, `scoped(PartnerContext::class`, `AccessServiceProvider` in `bootstrap/providers.php`, `finally`, `tests/Isolation` in `phpunit.xml`, `abstract class KokpitPolicy` with `forceDeleteAny` and `replicate`, `extends KokpitPolicy` in both policies, empty `grep -rn 'Gate::before' app`, `use DeniesPartners` in the four package models, `NotPartnerScoped` in the four identity models, `runAsSystem` in the install command, `AdminOnlyPolicy` in the provider.
- `ddev composer test` (309 passed), `ddev composer lint` and `ddev composer stan` clean; `scripts/check-sensitive.sh` clean on each commit.
