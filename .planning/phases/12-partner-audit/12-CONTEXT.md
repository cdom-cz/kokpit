# Phase 12: Partner Audit - Context

**Gathered:** 2026-10-24
**Status:** Ready for planning

<domain>
## Phase Boundary

Prove, with an automated suite that runs in CI, that the Partner view leaks nothing and that every route, screen and endpoint has an explicit role rule. Requirements: US-01 (permission matrix), AUD-01 (canary coverage across channels), AUD-02 (route and screen audit).

The mechanisms already exist (Phase 2 default-deny, `#[AccessRule]`, canary harness, 24 files in `tests/Isolation`). This phase adds the audit layer over them and fixes any leak the audit finds. No new product capability.

</domain>

<decisions>
## Implementation Decisions

### Permission matrix (US-01)
- **D-01:** The expected Partner behaviour is a standalone declaration file in `tests/Support` (entity or screen x Admin/Partner x action). The audit compares it with the real behaviour (policies, `#[AccessRule]`, routes). It is not derived from the code under test.
- **D-02:** The brief is not in the repository. The matrix is drafted from PROJECT.md, REQUIREMENTS.md and the current code, and the owner confirms it in the plan review. Every difference between the draft and the code is reported as a finding.
- **D-03:** For entities a Partner can see, the matrix declares each action (list, view, create, update, delete) and the allowed fields/columns (covers prices, rates and other secret fields), not only CRUD.

### Route audit (AUD-02)
- **D-04:** Every route from `Route::getRoutes()` gets a rule, vendor and framework routes included (Livewire, Filament assets, Horizon, docs, health). A route is either authenticated or on the explicit public allowlist with a reason. A new vendor route fails the build until it is justified.
- **D-05:** The audit sends a real anonymous request to every non-public route and expects 401, 403 or a redirect to login, never 200 or 500. It also checks the middleware, so a missing auth rule cannot hide behind another error.
- **D-06:** The public allowlist is a declaration file in `tests/Support`: route name -> authentication means (token, signature, guest-only login) -> reason. Today it holds `pay.show` and `webhooks.stripe` (CONTRIBUTING, Phase 11 hand-over) plus the guest-only auth routes. A public route without an entry fails, and so does an entry without a route. An empty reason is rejected.

### Canary coverage (AUD-01)
- **D-07:** One matrix over `CanaryRegistry`: every registered entity is walked through every channel (UI, global search, selects, exports, notifications, activity log, downloads, API) by a parametrised test. A channel that does not apply to an entity is declared not-applicable with a reason. An entity without a registry entry fails the build.
- **D-08:** The existing `tests/Isolation` files stay where they are. The matrix is an audit on top of them, with no move and no deletion.
- **D-09:** A leak found by the audit is fixed in this phase. It is not just reported.

### CI gate (success criterion 4)
- **D-10:** A new Pest testsuite `Audit` (`tests/Audit`) is added to `phpunit.xml`, with its own step in CI and in `composer ci`, so it can be run alone (`vendor/bin/pest --testsuite=Audit`).
- **D-11:** An exception or a new public route is declared by editing the declaration file in `tests/Support`, so it shows in the PR diff and review. No attribute in production code for this purpose.
- **D-12:** No time limit. The audit is complete on every run (every route, entity and channel). Speed is optimised later only if it becomes a problem.

### Claude's Discretion
- File and class names of the declaration files and the audit tests, the shape of the declaration data structures, and how the matrix test is parametrised.
- Which channels are marked not-applicable per entity, provided each has a written reason.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Requirements and roadmap
- `.planning/ROADMAP.md` (Phase 12 section) - goal and four success criteria
- `.planning/REQUIREMENTS.md` - US-01, AUD-01, AUD-02
- `.planning/PROJECT.md` - Partner security constraint (policies and global scopes, not only UI hiding)

### Hand-overs from earlier phases
- `CONTRIBUTING.md` (the Phase 12 bullet, line ~121) - the two public routes of Phase 11 and the Admin-only tables `payment_links`, `card_payments`
- `.planning/phases/11-stripe-payments/11-20-SUMMARY.md` - Phase 12 hand-over
- `.planning/phases/11-stripe-payments/11-CONTEXT.md`, `.planning/phases/10-invoicing/10-CONTEXT.md` - Partner boundary decisions

### Existing audit infrastructure
- `app/Domain/Shared/Auth/AccessRule.php`, `AccessRules.php`, `Audience.php` - the access declaration of every panel class
- `tests/Arch/PanelRegistryTest.php` - D-03 registry check (Resources, Pages, Widgets, clusters, relation managers)
- `tests/Isolation/RouteWalkTest.php` - D-04 Partner route walk over panel GET routes
- `tests/Isolation/ApiAccessTest.php` - route walk over `/api/v1`
- `tests/Isolation/CanaryRegistryTest.php`, `tests/Support/CanaryRegistry.php`, `tests/Support/Canary.php` - canary harness
- `tests/Isolation/CardPaymentLeakTest.php` - asserts `routes/public.php` holds exactly two routes
- `routes/public.php`, `routes/web.php`, `routes/api.php`
- `.planning/codebase/TESTING.md` - suites, conventions, rules for new tests

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AccessRules` / `#[AccessRule]` / `Audience` (AdminOnly, PartnerAllowed, Guest): the per-class declaration the matrix compares against.
- `CanaryRegistry::prepare()/seedAll()/cleanup()` and `Canary::twoClients()`: seed two clients with distinguishable secrets.
- `PanelRegistryTest` helpers: flatten relation managers and scan `app/Filament`.
- `RouteWalkTest` `walkedRoutes()`: route enumeration to extend.

### Established Patterns
- Pest functional style with a requirement-ID block comment; fictional data only; ability matrices compared with one `expect()` per ability.
- Declaration files in `tests/Support` (`AuditDeclaration`, `ModelDeclaration`) read by Arch tests.
- Suites registered in `phpunit.xml`; `Isolation` already uses `RefreshDatabase`.

### Integration Points
- `phpunit.xml`, `composer.json` (`ci` script), `.github/workflows` application CI job.
- `tests/Pest.php` bindings for the new `Audit` suite.

</code_context>

<specifics>
## Specific Ideas

No specific requirements - open to standard approaches.

</specifics>

<deferred>
## Deferred Ideas

None - discussion stayed within phase scope.

</deferred>

---

*Phase: 12-Partner Audit*
*Context gathered: 2026-10-24*
