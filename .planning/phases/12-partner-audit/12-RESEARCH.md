# Phase 12: Partner Audit - Research

**Researched:** 2026-10-24
**Domain:** Authorization audit of a Laravel 13 / Filament 5 / Livewire 4 application with a Partner (client) role: declaration-driven Pest test suite over routes, panel classes, API endpoints and Partner-leak channels
**Confidence:** HIGH (everything below was read in the repository or probed in DDEV this session; fixes proposed for the three defects found are `[ASSUMED]` until the planner's tests prove them)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Permission matrix (US-01)**
- **D-01:** The expected Partner behaviour is a standalone declaration file in `tests/Support` (entity or screen x Admin/Partner x action). The audit compares it with the real behaviour (policies, `#[AccessRule]`, routes). It is not derived from the code under test.
- **D-02:** The brief is not in the repository. The matrix is drafted from PROJECT.md, REQUIREMENTS.md and the current code, and the owner confirms it in the plan review. Every difference between the draft and the code is reported as a finding.
- **D-03:** For entities a Partner can see, the matrix declares each action (list, view, create, update, delete) and the allowed fields/columns (covers prices, rates and other secret fields), not only CRUD.

**Route audit (AUD-02)**
- **D-04:** Every route from `Route::getRoutes()` gets a rule, vendor and framework routes included (Livewire, Filament assets, Horizon, docs, health). A route is either authenticated or on the explicit public allowlist with a reason. A new vendor route fails the build until it is justified.
- **D-05:** The audit sends a real anonymous request to every non-public route and expects 401, 403 or a redirect to login, never 200 or 500. It also checks the middleware, so a missing auth rule cannot hide behind another error.
- **D-06:** The public allowlist is a declaration file in `tests/Support`: route name -> authentication means (token, signature, guest-only login) -> reason. Today it holds `pay.show` and `webhooks.stripe` (CONTRIBUTING, Phase 11 hand-over) plus the guest-only auth routes. A public route without an entry fails, and so does an entry without a route. An empty reason is rejected.

**Canary coverage (AUD-01)**
- **D-07:** One matrix over `CanaryRegistry`: every registered entity is walked through every channel (UI, global search, selects, exports, notifications, activity log, downloads, API) by a parametrised test. A channel that does not apply to an entity is declared not-applicable with a reason. An entity without a registry entry fails the build.
- **D-08:** The existing `tests/Isolation` files stay where they are. The matrix is an audit on top of them, with no move and no deletion.
- **D-09:** A leak found by the audit is fixed in this phase. It is not just reported.

**CI gate (success criterion 4)**
- **D-10:** A new Pest testsuite `Audit` (`tests/Audit`) is added to `phpunit.xml`, with its own step in CI and in `composer ci`, so it can be run alone (`vendor/bin/pest --testsuite=Audit`).
- **D-11:** An exception or a new public route is declared by editing the declaration file in `tests/Support`, so it shows in the PR diff and review. No attribute in production code for this purpose.
- **D-12:** No time limit. The audit is complete on every run (every route, entity and channel). Speed is optimised later only if it becomes a problem.

### Claude's Discretion
- File and class names of the declaration files and the audit tests, the shape of the declaration data structures, and how the matrix test is parametrised.
- Which channels are marked not-applicable per entity, provided each has a written reason.

### Deferred Ideas (OUT OF SCOPE)
None - discussion stayed within phase scope.
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| US-01 | Roles Admin and Partner behave as specified in the permission matrix of the brief | Draft Partner matrix in "Architecture Patterns / Pattern 1" built from policies, `#[AccessRule]` and Partner resources; declaration file design; the brief is absent (D-02), so the matrix needs owner sign-off (Open Question 1) |
| AUD-01 | Automated tests prove Partner never sees time, rates, prices, finance, internal comments or other clients' data in UI, search, selects, exports, notifications, activity log and API, for every entity | `CanaryRegistry` has 38 entity fixtures (`fixtures()` keys) and `needles()`; per-channel existing coverage map and the gaps the entity x channel matrix must close (Pattern 3) |
| AUD-02 | Route and screen audit confirms every non-public route is authenticated and every Resource, Page, Widget and API endpoint has an explicit role rule | Full inventory of the 125 routes of the testing environment, anonymous-status probe, the public allowlist, the three defects the probe exposed (Pitfalls 1-3), completeness checks (Pattern 2, 4) |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` [VERIFIED: read this session via the session context].

- Public AGPL-3.0 repository: **fictional data only** in code, tests, fixtures, docs, `.planning/` and commit messages. Use `example.com`, company ID `12345678`, `/Users/example/` paths; build fake values at runtime from fragments (`exampleEmail()`, `Canary::canary()`).
- Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook pre-commit hook (`--no-verify`, `LEFTHOOK=0`).
- Tests run in DDEV (`ddev exec vendor/bin/pest`, `ddev exec vendor/bin/phpstan ...`, pint) because the host has no DB (user memory note).
- Partner must never see measured time, rates, prices or finance, nor another client's data: enforced by Policies and global query scopes, not only UI hiding.
- UUID v7 keys everywhere; FK/unique/check constraints in the DB.
- Everything under `.planning/` is written in English (task rule).
- File changes go through a GSD workflow (`/gsd-execute-phase` for this phase).
- No paid or closed dependencies; this phase needs no new package.

## Summary

Phase 12 is an audit layer, not a product feature. The mechanisms exist: `#[AccessRule]` on every panel class (`PanelRegistryTest`), `KokpitPolicy` default-deny, `PartnerIsolated` models with a fail-closed scope, a 38-entity `CanaryRegistry`, route walks over the panel (`RouteWalkTest`) and `/api/v1` (`ApiAccessTest`), and 24 files in `tests/Isolation`. What is missing is (a) a declaration of the *expected* behaviour that is not derived from the code under test, (b) an inventory that covers **all** 125 routes including vendor ones, (c) an entity x channel matrix with completeness checks in both directions, and (d) a CI gate. All four are plain Pest tests plus two or three declaration classes in `tests/Support`; no new package is needed.

A pre-flight probe in DDEV (anonymous, Partner and Admin requests to every non-panel route, and anonymous requests to every route) found **no Partner leak** but exposed **three robustness defects that the D-05 rule ("never 500") will fail on the first run**, so they must be fixed in this phase (D-09): (1) any `/api/v1` request without `Accept: application/json` that is not authenticated answers **500** `Route [login] not defined` (reproduced over real HTTP), (2) `GET /filament/exports/{export}/download` and `/filament/imports/{import}/failed-rows/download` answer **500** for everybody because the `exports`/`imports` tables do not exist, and (3) several routes have no name, so a "route name -> reason" allowlist (D-06) cannot key them (`/`, `/up`, six Livewire asset routes). The existing `ApiAccessTest` hides defect 1 because it always sends `->json(...)`.

Two framework facts shape the test design and were verified by running them: Pest dataset closures cannot touch the application (a dataset that calls `Route::getRoutes()` fails), so parametrisation must run over the *declaration files* (plain PHP arrays) with the discovered inventory compared inside a normal test; and the Livewire endpoint prefix contains a hash of `APP_KEY` (`livewire-1e75046a`), so route keys must normalise it.

**Primary recommendation:** Build `tests/Audit` as four declaration-driven tests: `RouteAudit` (inventory vs `PublicRoutes`/`RouteRules` declaration, middleware check, anonymous request), `AccessMatrix` (panel classes, API endpoints, Livewire components, Partner abilities vs `PartnerMatrix` declaration), `ChannelMatrix` (entity x channel over `CanaryRegistry`), and `CompletenessTest` (anything discovered but undeclared, or declared but gone, fails). Fix the three defects first; run the suite in a separate `Audit` step in CI and `composer ci`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Expected-behaviour declaration (permission matrix, public allowlist, channel applicability) | Test support (`tests/Support`) | — | D-01/D-06/D-11: independent of production code; edits show in the PR diff |
| Route enumeration and middleware inspection | API / Backend (router) | Test support | `Route::getRoutes()` + `Router::gatherRouteMiddleware()` is the single source of truth for what is reachable |
| Anonymous refusal of non-public routes | API / Backend (middleware: Filament `Authenticate`, Horizon `Authenticate`, `auth:sanctum`, `signed`) | Frontend Server (Filament panel) | Auth middleware sits in the route stack; the audit only observes it |
| Panel class access (Resource, Page, Widget, RM) | Frontend Server (Filament, `#[AccessRule]` + `Enforces*` traits) | API / Backend (policies) | Class attribute decides screen access, policy decides record/ability |
| Record visibility and tenant isolation | Database / Storage (global `PartnerScope` on `PartnerIsolated` models) | API / Backend (policies) | Data-layer scope is the primary guard; policies are the second |
| Leak channels (search, selects, exports, downloads, notifications, activity, API) | API / Backend | Database (scope) | Each channel is a code path that must re-apply the scope; the matrix proves it per entity |
| CI gate | CI (GitHub Actions `hygiene.yml` tests job, `composer ci`) | — | Audit failure must fail "CI Passed" |

## Standard Stack

### Core

No new dependency. Everything the audit needs is installed.

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| pestphp/pest | v5.3.3 [VERIFIED: vendor/composer/installed.json] | Test runner; `--testsuite`, `--exclude-testsuite` verified in `pest --help` this session | Already the project runner |
| pestphp/pest-plugin-laravel | v5.0.1 [VERIFIED: composer show] | `actingAs`, `get`, `json`, `withToken` | Already used by `RouteWalkTest`, `ApiAccessTest` |
| laravel/framework | v13.38.0 [VERIFIED: composer show] | `Route::getRoutes()`, `Router::gatherRouteMiddleware()` (Router.php:844) | Source of the route inventory |
| filament/filament | v5.10.3 [VERIFIED: composer show] | Panel registry (`getResources/getPages/getWidgets/getClusters`), `canAccess()` | Existing registry helpers in `PanelRegistryTest` |
| livewire/livewire | v4.4.8 [VERIFIED: composer show] | `Livewire::test()` for Partner table/search/select checks | Already used in `RouteWalkTest` |
| laravel/sanctum | v4.3.3 [VERIFIED: composer show] | API tokens (`ApiFixtures::token`) | `ApiAccessTest` fixtures |
| laravel/horizon | v5.50.1 [VERIFIED: composer show] | 22 routes with its own `Authenticate` middleware | Must be recognised as an auth middleware |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Tests\Support\Canary` / `CanaryRegistry` / `CanaryRecord` | in repo | Two clients, per-model canary rows, `needles()` | All channel tests |
| `Tests\Support\ApiFixtures` | in repo | Admin/Partner tokens | API channel and API matrix |
| `Tests\Support\DocumentFixtures`, `walkedInvoicePdf()` pattern | in repo | Signed file routes with stored bytes | Download channel |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Declaration files in `tests/Support` (D-01, D-11) | `#[PublicRoute]` attribute in production code | Rejected by D-11 |
| Running the new tests inside `Isolation` | New `Audit` suite | Locked by D-10; `Isolation` files stay (D-08) |
| Pest datasets built from the router | Datasets built from the declaration arrays | Router-based datasets fail (verified); see Pitfall 4 |

**Installation:** none. **Version verification:** versions above read from `vendor/composer/installed.json` / `composer show` in DDEV on 2026-10-24.

## Package Legitimacy Audit

This phase installs no external package. **Packages removed due to [SLOP] verdict:** none. **Packages flagged [SUS]:** none. No `checkpoint:human-verify` install gate is needed.

## Architecture Patterns

### System Architecture Diagram

```
                     DECLARATIONS (tests/Support, edited in PRs: D-01, D-06, D-11)
   +-------------------+   +--------------------+   +----------------------+
   | PartnerMatrix     |   | RouteRules         |   | ChannelMatrix        |
   | screen/class/API  |   | public allowlist + |   | entity x 8 channels: |
   | x Admin/Partner   |   | closed vendor      |   | applies | N/A+reason |
   | x action, fields  |   | routes + reasons   |   |                      |
   +---------+---------+   +---------+----------+   +----------+-----------+
             |                       |                         |
             v                       v                         v
   +------------------------------------------------------------------------+
   | tests/Audit  (Pest suite "Audit", RefreshDatabase, own CI step)        |
   |                                                                        |
   |  CompletenessTest : discovered \ declared = {} AND declared \ found = {}|
   |    discovers: Route::getRoutes() | panel registry + app/Filament scan |
   |               | CanaryRegistry::fixtures() | app/Livewire | Notifications|
   |                                                                        |
   |  RouteAudit       : each route -> allowlist entry OR auth middleware   |
   |                     + real ANONYMOUS request -> 401/403/302(login)     |
   |                                                                        |
   |  AccessMatrix     : each declared class/endpoint as Admin & Partner    |
   |                     (canAccess/canView/Gate abilities/HTTP/API token)  |
   |                                                                        |
   |  ChannelMatrix    : seed 2 clients (CanaryRegistry::seedAll),          |
   |                     act as Partner A, run channel -> output blob,      |
   |                     assert needles(entity, B) absent (and A absent for |
   |                     Admin-only entities)                               |
   +-----------------------------+------------------------------------------+
                                 |
        fail => build red        v
   composer ci --> test, partner-audit, lint, stan, licences
   hygiene.yml "tests" job --> Pest step, "Partner audit" step --> CI Passed
```

### Recommended Project Structure

```
tests/
├── Audit/                       # new suite "Audit" (D-10)
│   ├── RouteAuditTest.php       # AUD-02: inventory, allowlist, middleware, anonymous request
│   ├── AccessMatrixTest.php     # US-01: panel classes, API endpoints, Livewire, Partner abilities, Partner fields
│   ├── ChannelMatrixTest.php    # AUD-01: entity x channel
│   └── CompletenessTest.php     # new screen/route/endpoint/entity/notification without a rule fails
└── Support/
    ├── PartnerMatrix.php        # D-01/D-03 declaration (pure data + small readers)
    ├── RouteRules.php           # D-06 public allowlist + closed routes (+ reasons)
    ├── ChannelMatrix.php        # D-07 applicability per entity and channel (+ reasons)
    └── AuditInventory.php       # discovery helpers (routes, panel classes, livewire components)
```

Helper functions defined in a Pest file are **global** and the whole suite loads in one process, so prefix them per file (the repo already does: `walked*`, `apiWalk*`, `panelRegistry*`) or, better, put discovery code in a Support class. `PanelRegistryTest` defines `panelRegistryUnique`, `panelRegistryPanelClasses`, `panelRegistryAppClasses` and others as file-level functions; calling them from another file works only if that file is loaded, and redefining them is a fatal error. Extract the needed logic into `AuditInventory` (copy, do not move: D-08 spirit).

### Pattern 1: Permission matrix declaration (US-01, D-01, D-03)

**What:** A pure-data class; no `AccessRules::for()` call inside it. Rows are keyed by identifiers that do not depend on production code reading.

**Draft Partner baseline** (to be confirmed by the owner, D-02). Built from `#[AccessRule]` reasons, the policies and the Partner resources read this session:

| Surface | Admin | Partner (with client) | Source read |
|---------|-------|----------------------|-------------|
| `Dashboard` page, route `filament.admin.pages.dashboard` | 200 | 200 (neutral text, Partner widgets only) | `Dashboard.php` `#[AccessRule(PartnerAllowed)]`, `PanelAccessTest` |
| Profile `filament.admin.auth.profile` (`EditProfile`) | yes | yes (own profile, notification switches) | `EditProfile.php` PartnerAllowed |
| `my-projects` list, view (`PartnerProjectResource`) | n/a (Admin uses `projects`) | list, view only; no create/edit/delete | `ProjectPolicy`: `viewAny` true, `view` own client AND `client_visible` |
| `my-tasks` list, view, **create** (`PartnerTaskResource`) | n/a | list, view, create; `canEdit`/`canDelete` false; custom abilities `comment`, `escalate`, `clearEscalation` (assignee only), `editDescription` (planned / to-clarify, not trashed) | `TaskPolicy`, `PartnerTaskResource` |
| `my-invoices` list, view (`PartnerInvoiceResource`) | n/a | list, view of **issued** documents of the own client only; never drafts | `InvoicePolicy::view` requires `InvoiceState::Issued` |
| Task comments | all | non-internal only; may `create` | `TaskCommentPolicy` |
| Documents | all | `view` shared (`visible_to_client`, shareable owner), `attach` to an open own task/own non-internal comment, `delete` own upload on an open task, `update` never | `DocumentPolicy` |
| Signed file routes `files.document`, `files.invoice` | 200 (valid signature) | 200 for own visible file/issued PDF, 404 for foreign | `RouteWalkTest` |
| Signed file route `files.archive` | owner only | 404 always | `RouteWalkTest` |
| All other Resources / Pages / Widgets / RMs | per `#[AccessRule]` | 403 / hidden | `grep '#[AccessRule('` (about 75 classes, 10 of them PartnerAllowed) |
| Plain Livewire components (`TimerBar`, `ControlsTimer`, `RecentEntriesPanel`, `SignalDay`) | mount ok | refused on mount and on every update (`RequiresAdmin`) | CONTRIBUTING Livewire bullet |
| `/api/v1/*` (20 routes, `auth:sanctum` + one ability + throttle) | token with the ability | **401** for a Partner token | `ApiAccessTest`, `routes/api.php` |
| Horizon (`/horizon`, 21 API routes) | Gate `viewHorizon` (Admin, +2FA when enforced) | 403 | `HorizonServiceProvider` |
| Test-only `canary-records` resource (only while `KOKPIT_CANARY_HARNESS=true`) | yes | list, view own | `PanelAccessTest`; declare as `testOnly` |

Partner field declaration (D-03): for every Partner-visible resource declare the **allowed UI fields** (table columns, infolist entries, form fields) and a **forbidden-name list** (rate, price, estimate, billing, internal, cnb, czk, payment, source, cost, budget). Storage-level pins already exist in `PartnerSafeColumnsTest` (columns of `projects`, `tasks`, `task_comments`, `documents`, `invoices`, `invoice_items`); the matrix adds the **presented** subset, read at runtime from the Partner resource's table/infolist/form schema. Note for the owner (Open Question 2): `invoices.subtotal_minor`, `vat_minor`, `total_minor` and `invoice_items.unit_price_minor` are Partner-readable by design (IN-09: the Partner sees the own issued documents). "Prices" in AUD-01 must be read as rates, project prices and estimates, not the amounts printed on the Partner's own invoices.

**Reading the actual behaviour** (not the declaration) per surface type:
- Resource: `Resource::canAccess()`, `canViewAny()`, `canCreate()`, `canEdit($record)`, `canDelete($record)`, `canView($record)` with current panel set (`Filament::setCurrentPanel(Filament::getPanel('admin'))`) and `actingAs`.
- Page / widget / relation manager: `canAccess()`, `canView()`, `canViewForRecord($owner, $page)` [ASSUMED: signatures per Filament 5 base classes; `PanelAccessTest` lines 212-300 show the working calls, copy them].
- Record abilities: `Gate::forUser($user)->allows($ability, $record)` for own visible, own hidden, foreign.
- HTTP: `actingAs($user)->get(route)` status per route (Admin 200 / Partner 403) for pages; record routes need the `walkedUrl()` machinery (record id, signed URLs).
- API: Admin token with exactly the route's ability -> not 401/403; Partner token carrying all abilities -> 401.

### Pattern 2: Route inventory and public allowlist (AUD-02, D-04, D-05, D-06)

**Inventory** (testing environment, `APP_ENV=testing`, `DEBUGBAR_ENABLED=false`, `KOKPIT_CANARY_HARNESS=true`): 125 routes [VERIFIED: `php artisan route:list --json`, DDEV]. Authenticated by middleware: 58 Filament panel routes (`Filament\Http\Middleware\Authenticate`), 20 API routes (`Illuminate\Auth\Middleware\Authenticate:sanctum`), 22 Horizon routes (`Laravel\Horizon\Http\Middleware\Authenticate`). The auth-middleware recogniser must accept all three classes (use `app('router')->gatherRouteMiddleware($route)` which resolves aliases and expands groups; `$route->gatherMiddleware()` as used by `ApiAccessTest` returns unresolved aliases).

**Routes that are not behind an auth middleware** (the allowlist candidates, with the anonymous status observed in the probe):

| Route (name or `METHOD uri`) | Anonymous status observed | Authentication means (D-06 column) | Draft reason |
|-----|-----|-----|-----|
| `GET /` (unnamed, `Route::redirect`, answers every method) | 302 to `/admin` | none (redirect only) | Entry redirect, no data |
| `GET /up` (unnamed) | 200 | none | Health probe |
| `pay.show` `GET /pay/{token}` | 200 | token + throttle | Stable pay URL (Phase 11) |
| `webhooks.stripe` `POST /webhooks/stripe` | 400 | Stripe signature + throttle | Webhook intake (Phase 11) |
| `filament.admin.auth.login` | 200 | guest-only | Login |
| `filament.admin.auth.password-reset.request` | 200 | guest-only, throttled | Forgot-password page (Audience::Guest) |
| `filament.admin.auth.password-reset.reset` | 403 unsigned | signature | Reset link |
| `filament.admin.invitation.accept` | 403 unsigned | signature + throttle | Invitation link (Audience::Guest) |
| `scribe`, `scribe.openapi`, `scribe.postman` | 200 / 200 / 404 | none (public by owner decision, `config/scribe.php` comment) | Public API docs |
| `sanctum.csrf-cookie` | 204 | none | Sanctum SPA cookie route, unused by Kokpit, sets only the XSRF cookie (alternative: `Sanctum::ignoreRoutes()`) |
| `storage.local` GET, `storage.local.upload` PUT (`storage/{path}`) | 403 | signature | Framework local-disk serving (`'serve' => true`) |
| Livewire: `default-livewire.update` POST, `livewire.upload-file` POST, `livewire.preview-file` GET, 6 unnamed asset routes (`livewire.js`, two `.map`, `js/{component}.js`, `css/{component}.css`, `css/{component}.global.css`) | update 404 (empty payload), upload 401, preview 401, assets 200/404 | component-level (persistent middleware, snapshot checksum, signed preview) / none for static assets | Livewire internals |

Not in the testing route list but present in dev: `_debugbar/*` (5 routes). `phpunit.xml` pins `DEBUGBAR_ENABLED=false`; `fruitcake/laravel-debugbar` is a dev dependency, so production never has them [VERIFIED: composer.json require-dev]. The audit must run with the pinned environment; do not allowlist them.

Anonymous-status expectations (D-05): 401, 403 or a redirect whose `Location` ends with `/admin/login`. Observed anonymous statuses for the authenticated groups: Filament routes 302 to `/admin/login`, Horizon 403, API 401 (with `Accept: application/json`), `livewire.preview-file` and `upload-file` 401. Two observations the declaration must handle: (a) 404 is a legitimate "closed" answer for routes that are deliberately dead (Pitfall 2), so give the declaration a second list `closed` (route -> reason -> expected status) instead of widening the D-05 set; (b) the audit must send `Accept: application/json` for nothing: it must send **no** special header, otherwise defect 1 stays hidden (Pitfall 1).

Key by **route name when present, else `METHOD uri`** with the Livewire prefix normalised (`preg_replace('#^livewire-[0-9a-f]{8}#', 'livewire-{hash}', $uri)`). The hash is `substr(hash('sha256', config('app.key').'livewire-endpoint'), 0, 8)` [VERIFIED: vendor/livewire/livewire/src/Mechanisms/HandleRequests/EndpointResolver.php:15], so it differs between machines/CI if `APP_KEY` differs. D-06 says "route name"; eight routes have no name, so name-only keying is not enough (Open Question 3).

Completeness (both directions, D-06): `routes_without_auth_middleware \ allowlist = {}`, `allowlist \ routes = {}`, every entry has a non-empty trimmed reason and a `means` from a closed enum (`token`, `signature`, `guest-only`, `none`, `component`). Keep `CardPaymentLeakTest`'s "exactly two routes in `routes/public.php`" test; the allowlist entries `pay.show` and `webhooks.stripe` must stay consistent with it.

### Pattern 3: Entity x channel matrix (AUD-01, D-07)

`CanaryRegistry::fixtures()` has one fixture per `PartnerIsolated` model (38 entries incl. `CanaryRecord`) and `needles($model, $canary)` returns the strings the Admin must find. `CanaryRegistryTest` already proves model-level visibility and that every `PartnerIsolated` model has a fixture [VERIFIED: ran, 8 tests pass, 2.33 s; each two-client seed is about 0.2 s].

Two-class split of entities (from the policies and `DeniesPartners` usage): **Partner-readable** (Project, Task, TaskComment, Document, Invoice, InvoiceItem, Tag [project tags], CanaryRecord) and **Admin-only** (everything else: Client, Contact, ClientInvitation, ProjectBilling, TaskBilling, TaskChecklistItem, TimeEntry, InvoiceAdmin, InvoiceItemSource, InvoicePayment, PaymentConfirmation, InvoiceEmail, PaymentLink, CardPayment, ExchangeRate, Transaction, TransactionCategory, DocumentArchive, ReportExport, the seven Signal models, Activity, Media, WebhookCall, SettingsProperty). Note `Client` is model-scoped to the own row for a Partner (the existing test expects count 1) while every UI/policy surface is closed: the matrix must declare per channel, not per model.

Eight channels, what already exists and what the matrix adds:

| Channel | Existing coverage (D-08: keep) | Gap the matrix closes | How the channel is driven |
|---------|-------------------------------|------------------------|---------------------------|
| UI | `RouteWalkTest` (all panel GET routes, body must not hold canary B; record routes 403/404), `Livewire::test(ListCanaryRecords)` | Per-entity assertion that no surface renders the entity's needles for Admin-only entities (A and B), and only A for Partner-readable | Partner GET of every Partner-reachable route, collect bodies, assert needles |
| Global search | `CanaryRecordResource::getGlobalSearchResults`, panel `isGlobalSearchResourceOptIn()` | Only Client, Project, Task opt in (all AdminOnly); prove a Partner gets zero results from `Filament::getGlobalSearchProvider()` for every entity's needle and a term common to all canaries | Provider call as Partner A with the `CANARY` prefix |
| Selects | none systematic; one Partner select exists (`PartnerTaskResource::projectOptions()` via `Project::selectable()`) | Every option provider reachable by a Partner returns no foreign/hidden project; Admin selects are unreachable for a Partner (page refused at mount) | Call `PartnerTaskResource::projectOptions()` as Partner A; Livewire mount of every Admin form page as Partner fails |
| Exports | `TimeLeakTest`, `ReportExportResource` AdminOnly, `TimeReportPage` AdminOnly; Filament built-in export routes unused | Declare `ReportExport`, `DocumentArchive`, work-report PDF as Admin-only; Partner request to each producing page/route is refused; the dead Filament export/import routes are closed (Pitfall 2) | HTTP as Partner + `Gate` check on the models |
| Notifications | `NotificationLeakTest` (bell rows of Partner A, mails), `NotificationMarkupTest` (matrix over task notification classes) | Concrete `Notification` classes in `app/Domain/*/Notifications` (13 found) each declared Partner-recipient or Admin-only; a new class without a declaration fails | Reflection scan + declaration; reuse `NotificationLeakTest` flows for Partner-recipient ones |
| Activity log | `ActivityAllowlistTest` (Arch), `ActivityResource` AdminOnly, history RMs AdminOnly, `Activity` model deny-all (CanaryRegistryTest) | For every model in `AuditDeclaration::loggingModels()` a Partner sees zero `Activity` rows and every history RM is refused | Query `Activity` as Partner A after `seedAll`, plus RM `canViewForRecord` |
| Downloads | `RouteWalkTest` file/archive/invoice routes (A ok, B 404, no-client 403/404), `DocumentVisibilityTest` | Declare which entities have a download (Document, DocumentArchive, Invoice PDF, ReportExport); rest N/A | Reuse `walkedUrl()` pattern with signed URLs |
| API | `ApiAccessTest` (401 for Partner token on all routes) | Per-entity: the entities exposed by `/api/v1` (TimeEntry, Client, Project, Task, Transaction, TransactionCategory) get a Partner-token attempt that returns 401 with no canary in the body | Reuse `ApiFixtures::token` |

Not-applicable cells are declarations with a written reason (Claude's discretion), e.g. `SignalWeeklyGoal x Exports`: "no export, planner data is on-screen only". Completeness: `array_keys(CanaryRegistry::fixtures())` must equal the declared entity set, and every entity must have all eight channel keys (applies | n/a + non-empty reason).

**Granularity vs cost.** 38 entities x 8 channels = 304 cases. Seeding two clients costs about 0.4 s, so a per-case re-seed is roughly 2 minutes: acceptable under D-12. If it ever matters, run each channel once per test and assert all entities inside, with the entity in every assertion message (the pattern `CanaryRegistryTest` uses today).

### Pattern 4: Completeness checks (success criterion 4)

The build fails when something new exists without a rule. One check per discoverable kind:

| Discovered from | Must equal | Existing check to keep |
|-----------------|------------|-----------------------|
| `Route::getRoutes()` (all) | authenticated by middleware, or allowlisted, or closed | `ApiAccessTest` middleware shape for `/api/v1` |
| panel registry + `app/Filament` scan | keys of the `PartnerMatrix` class section | `PanelRegistryTest` (`#[AccessRule]` present) |
| `/api/v1` routes | `PartnerMatrix` API section (method + uri + ability) | `apiWalkProblems()` |
| `app/Livewire/**` concrete components | `PartnerMatrix` Livewire section | `LivewireComponentContractTest` |
| `CanaryRegistry::fixtures()` | `ChannelMatrix` entities | `CanaryRegistryTest` |
| Concrete `Notification` subclasses under `app/Domain` | declared Partner-recipient / Admin-only | `NotificationMarkupTest` (task classes only) |
| `AuditDeclaration::loggingModels()` | activity-channel declaration | `ActivityAllowlistTest` |

For each, write a negative self-test that feeds the checker an undeclared item and expects the failure (the repo's convention: `PanelRegistryTest` "reports an undeclared class", `ApiAccessTest` "reports a route without a token guard").

### Anti-Patterns to Avoid

- **Deriving the expectation from the code under test** (D-01): never read `AccessRules::for()` or a policy to build the expected matrix; the declaration is literal data.
- **Pest datasets from the router or the container**: fail at collection time (Pitfall 4).
- **Treating 500 as "refused"**: the existing `RouteWalkTest` accepts `[200, 302, 403, 404]` and never 500, fine, but any new assertion must list the accepted set and exclude 419/500.
- **Naming the composer script `audit`**: collides with Composer's built-in `composer audit`, which the dependencies CI job runs (`composer audit --locked`). Use `partner-audit`.
- **Hand-keeping a list of Livewire/Horizon/Filament routes**: enumerate, then compare with the declaration; a hand list is what the audit exists to replace.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Route inventory | A scan of `routes/*.php` source | `Route::getRoutes()->getRoutes()` and `app('router')->gatherRouteMiddleware($route)` | Vendor routes (Livewire, Filament, Horizon, Scribe, Sanctum, storage) are not in `routes/*.php`; middleware groups must be expanded |
| Panel class discovery | A new directory scanner | Port `panelRegistryPanelClasses()` / `panelRegistryAppClasses()` from `PanelRegistryTest` into a Support class | Already handles clusters, widget configurations, relation groups |
| Two clients with canaries | New fixtures | `Canary::twoClients()`, `CanaryRegistry::seedAll()`, `needles()` | Maintained one line per model |
| Signed file URLs | Manual signing | `DocumentUrl::download/archive`, `InvoicePdfUrl::download` as in `walkedUrl()` | Signature bound to the user id |
| API tokens | Manual token rows | `ApiFixtures::token`, `ApiFixtures::admin` | Used by `ApiAccessTest` |
| Fake values | Literals | `exampleEmail()`, `Canary::canary()`, `FictionalCompanyId` | Hygiene rule; `check-sensitive.sh` |

**Key insight:** The audit is a comparison engine over declarations. Every piece of production behaviour it needs to observe already has a test seam; new production code is limited to fixing what the audit finds.

## Common Pitfalls

### Pitfall 1: Unauthenticated API call without `Accept: application/json` answers 500
**What goes wrong:** `GET /api/v1/clients` with no `Accept` header answers **500** `Route [login] not defined` over real HTTP (curl against `https://kokpit.ddev.site`: 500; with `Accept: application/json`: 401). A session-authenticated user (Admin or Partner) calling the API without the header also gets 500, because the `sanctum` guard rejects them the same way.
**Why it happens:** `ApplicationBuilder::withMiddleware()` installs `redirectGuestsTo(fn () => route('login'))` by default (ApplicationBuilder.php:260-262), and `Illuminate\Auth\Middleware\Authenticate::unauthenticated()` evaluates `$this->redirectTo($request)` when `! $request->expectsJson()` (Authenticate.php:97-104, 117). The app has no route named `login` (Filament's is `filament.admin.auth.login`). The `shouldRenderJsonWhen(fn => $request->is('api/*'))` in `bootstrap/app.php` is consulted only later, by the exception handler, too late.
**How to avoid:** In `bootstrap/app.php` set `$middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'))` (the handler then returns JSON 401 for `api/*` through the existing `shouldRenderJsonWhen`, `Handler.php:856`). [ASSUMED: fix and the resulting status; the planner's test must prove 401 without `Accept`.] Add the regression test to `RouteAudit` (no header) and keep `ApiAccessTest` untouched.
**Warning signs:** `ApiAccessTest` is green (it always calls `->json()`), while a plain `curl` shows 500.

### Pitfall 2: Dead Filament export/import routes answer 500
**What goes wrong:** `GET /filament/exports/{export}/download` and `GET /filament/imports/{import}/failed-rows/download` answer 500 anonymously, as Partner and as Admin (probe in the testing environment; `Schema::hasTable('exports')`, `imports`, `failed_import_rows` are all `false` in DDEV).
**Why it happens:** `vendor/filament/actions/routes/web.php` registers both routes unconditionally under the `filament.actions` group (= `web` only, no auth). Implicit model binding of `Export` / `Import` runs in `SubstituteBindings` before the controller (which would answer 401 for a guest) and hits the missing table. Kokpit uses no Filament export/import action (`grep Exporter|ExportAction|Importer|ImportAction app` found nothing); its exports are `ReportExport` / `DocumentArchive`.
**How to avoid:** Prefer closing the routes: explicit binders `Route::bind('export', fn () => abort(404))` and `Route::bind('import', fn () => abort(404))` in a service provider. `Router::substituteBindings()` applies explicit binders for matching parameter names (Router.php:970-979), before implicit binding. Result: 404 for everybody, no DB hit. Do **not** publish Filament's migrations: they create integer-keyed tables, against the UUID v7 convention. Declare both routes in the `closed` list of `RouteRules` with the reason and the expected status 404. [ASSUMED: binder approach; verify in the plan with the same anonymous request.]
**Warning signs:** `route:list` shows `filament.exports.download` with no auth middleware.

### Pitfall 3: Unnamed routes cannot be keyed by name
**What goes wrong:** 8 routes have no name (`/`, `up`, six Livewire asset routes); the Livewire ones also carry an `APP_KEY`-dependent prefix, so a literal URI key breaks on another machine or in CI.
**How to avoid:** key = name else `METHOD uri` with `livewire-{hash}` normalisation (Pattern 2). Open Question 3 records the deviation from D-06's wording.

### Pitfall 4: Pest datasets cannot use the application
**What goes wrong:** a `->with(function () { ... Route::getRoutes() ... })` dataset made the test FAIL when run in this repo (probe, deleted afterwards) because dataset closures are resolved before the Laravel application exists.
**How to avoid:** build datasets only from the declaration classes (plain arrays) and compare against discovery inside an ordinary `it()`. This also yields one test row per declared route/entity/channel, which is the granularity D-07 asks for. [VERIFIED: probe run this session; FAILED.]

### Pitfall 5: A web-group route can hide a missing auth rule behind CSRF
**What goes wrong:** an anonymous POST/PUT/DELETE to a `web` route answers 419 in a real environment (observed in the dev-environment probe: Horizon POSTs and `livewire/update` answered 419), so "not 200/500" would pass even without any auth rule. In the testing environment CSRF verification is off, so the request reaches the next layer.
**How to avoid:** this is exactly why D-05 pairs the request with the middleware check. Implement both and make the middleware check the primary one for the non-GET routes; treat 419 as a failure in the status set.

### Pitfall 6: Route set depends on the environment
**What goes wrong:** `_debugbar/*` appears only when Debugbar is enabled; Scribe routes depend on `scribe.laravel.add_routes`; canary routes depend on `KOKPIT_CANARY_HARNESS`.
**How to avoid:** the suite runs under `phpunit.xml` (debugbar off, harness on). Declare the canary routes as `testOnly` entries, require them only while the harness is on, and add one test that fails when `config('kokpit.canary_harness')` is false (so the inventory is never run against a different route set unnoticed).

### Pitfall 7: Documentation guards in `RepositoryFilesTest`
**What goes wrong:** `tests/Feature/Repo/RepositoryFilesTest.php` fails when CONTRIBUTING or README name a class, composer script or test that does not exist, and checks the "Phase 12" bullet (`CONTRIBUTING.md` line 121) and the `composer (test|lint|stan|check-licenses|licenses|ci)` pattern.
**How to avoid:** when replacing the Phase 12 bullet by the real description, update the guard in the same change; do not rename `pay.show` / `webhooks.stripe` semantics; the new script name `partner-audit` is not in that regex, which is fine (it would not be validated, so add it to the regex if it is documented).

### Pitfall 8: Double execution in CI
**What goes wrong:** `Audit` registered in `phpunit.xml` is also run by the existing plain `vendor/bin/pest` step, so a separate Audit step runs it twice.
**How to avoid:** change the existing step and the composer `test` script to `--exclude-testsuite=Audit` and add the dedicated step (flag verified in `pest --help`). `CiParityTest` only needs a `vendor/bin/pest` command after the boot step, so both steps satisfy it. See Open Question 4 for the trade-off.

### Pitfall 9: Authenticated state leaks between requests in one test
**What goes wrong:** a loop that mixes anonymous and `actingAs` requests reuses the resolved guard.
**How to avoid:** call `Auth::forgetGuards()` (as `ApiAccessTest` does) and `$this->flushSession()` between users; the pre-flight probe used this pattern successfully.

## Code Examples

### Route inventory with resolved middleware

```php
// Source: Router.php:844 gatherRouteMiddleware(); route:list uses the same resolution.
$router = app('router');

foreach (Route::getRoutes()->getRoutes() as $route) {
    $middleware = array_map('strval', $router->gatherRouteMiddleware($route));
    $authenticated = array_values(array_filter($middleware, static fn (string $m): bool => in_array(
        explode(':', $m)[0],
        [
            \Filament\Http\Middleware\Authenticate::class,
            \Illuminate\Auth\Middleware\Authenticate::class,
            \Laravel\Horizon\Http\Middleware\Authenticate::class,
        ],
        true,
    )));
}
```
Observed middleware class names [VERIFIED: `route:list --json` this session]: `Filament\Http\Middleware\Authenticate` (58), `Illuminate\Auth\Middleware\Authenticate:sanctum` (20), `Laravel\Horizon\Http\Middleware\Authenticate` (22), `Illuminate\Routing\Middleware\ValidateSignature` (5).

### Route key (name else method + uri, Livewire prefix normalised)

```php
function auditRouteKey(\Illuminate\Routing\Route $route): string
{
    if ($route->getName() !== null && $route->getName() !== '') {
        return $route->getName();
    }

    $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
    $uri = preg_replace('#^livewire-[0-9a-f]{8}#', 'livewire-{hash}', $route->uri());

    return $method.' /'.ltrim((string) $uri, '/');
}
```
Caveat: `Route::redirect('/')` answers all methods (`GET|POST|...|QUERY`), so its first non-HEAD method is `GET`; fine for the key. `QUERY` is in that method list [VERIFIED: route:list]; request with `GET`.

### Anonymous request to every non-public route (no special headers)

```php
foreach (auditNonPublicRoutes() as $route) {
    Auth::forgetGuards();
    $response = $this->call(auditMethod($route), auditPath($route)); // UUID for every {param}, as apiWalkPath()

    $status = $response->getStatusCode();
    $location = (string) $response->headers->get('Location');

    expect(in_array($status, [401, 403], true) || ($response->isRedirection() && str_ends_with($location, '/admin/login')))
        ->toBeTrue("{$route->uri()} answered {$status} to an anonymous request");
}
```
Result in the pre-flight probe (dev environment): Filament routes 302 `/admin/login`; Horizon 403; API 401 only with `Accept: application/json` (defect 1).

### Declaration shape (data only)

```php
// tests/Support/RouteRules.php (illustrative; names are Claude's discretion)
final class RouteRules
{
    /** @return array<string, array{means: string, reason: string}> */
    public static function publicRoutes(): array
    {
        return [
            'pay.show' => ['means' => 'token', 'reason' => 'Stable pay URL printed on the PDF and sent by e-mail (Phase 11).'],
            'webhooks.stripe' => ['means' => 'signature', 'reason' => 'Stripe webhook intake, signature verified before any write.'],
            'GET /up' => ['means' => 'none', 'reason' => 'Health probe, no data.'],
            // ... see the table in Pattern 2
        ];
    }

    /** @return array<string, array{status: int, reason: string}> routes deliberately dead */
    public static function closedRoutes(): array
    {
        return [
            'filament.exports.download' => ['status' => 404, 'reason' => 'Kokpit uses no Filament export; the table does not exist.'],
        ];
    }
}
```

### Dataset over a declaration (works), not over the router (fails)

```php
it('refuses the anonymous request to a declared route', function (string $key): void {
    // look the route up by key here, inside the test, where the application exists
})->with(array_map(static fn (string $key): array => [$key], array_keys(RouteRules::publicRoutes())));
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Sanctum route aliases implicit | Sanctum 4 registers no `abilities` alias; the app aliases `CheckAbilities` itself (`bootstrap/app.php`) | Sanctum 4 | Already handled; the matrix reads the alias as `abilities:` |
| Static Livewire URL `/livewire/update` | Hashed prefix `livewire-{hash}` derived from `APP_KEY` | Livewire 4 | Route keys must normalise the prefix |
| Laravel `Authenticate` default redirect | `redirectGuestsTo(route('login'))` set by the application builder | Laravel 11+ | Source of defect 1 |

**Deprecated/outdated:** CONTRIBUTING's Livewire bullet still says `/livewire/update` is the endpoint; it is `livewire-{hash}/update`. Correct it when rewriting the Phase 12 bullet (the `RepositoryFilesTest` guard may match the text; check before editing).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `$middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'))` turns the unauthenticated API 500 into JSON 401 for `api/*` without an `Accept` header | Pitfall 1 | Fix insufficient; try returning `null` for `api/*` or a custom `Authenticate` subclass; the regression test decides |
| A2 | `Route::bind('export'|'import', fn () => abort(404))` closes the two Filament routes without side effects | Pitfall 2 | Filament may resolve `{export}` elsewhere (signed download URLs inside Filament actions that the app never creates); low |
| A3 | Filament 5 method signatures `canViewForRecord`, `canView`, table `getColumns()` / infolist entries accessible from `Livewire::test(...)->instance()` as described | Pattern 1 | The planner reads `PanelAccessTest` lines 212-300 and Filament source; adjust |
| A4 | The draft Partner matrix table equals the owner's brief | Pattern 1 | The brief is not in the repository (D-02); owner confirmation required |
| A5 | `invoices` amounts and `invoice_items.unit_price_minor` shown to the Partner on the own issued documents are intended, not "prices" in the AUD-01 sense | Pattern 1 | If wrong, large redesign of IN-09; confirm with owner |
| A6 | `sanctum.csrf-cookie` is unused by Kokpit (no SPA token flow) | Pattern 2 | If used, the allowlist reason changes, not the entry |
| A7 | The 13 concrete Notification classes found by `ls app/Domain/*/Notifications` are the complete set | Pattern 3 | A scan in the plan finds the exact set; the completeness check covers it |

## Open Questions

1. **Owner confirmation of the draft permission matrix (D-02).**
   - What we know: the brief is absent; the draft in Pattern 1 comes from PROJECT.md, REQUIREMENTS.md (IN-09, TA-01/04/07, KB-03, PR-03/04, DO-01), the policies and `#[AccessRule]` reasons.
   - What's unclear: Partner abilities not stated anywhere (for example Partner `delete` of own uploads, `escalate`), and the exact field lists.
   - Recommendation: the plan review presents the matrix as a one-page table; every code/draft difference becomes a finding with a decision (fix code or amend matrix).

2. **What "prices" means for the Partner's own invoices (A5).**
   - What we know: IN-09 lets the Partner see the own issued documents; `PartnerSafeColumnsTest` pins `subtotal_minor`, `total_minor`, `unit_price_minor` as Partner-readable.
   - Recommendation: keep; word the matrix "no rates, no project/task prices, no estimates, no CNB/CZK conversions, no payments, no drafts, no internal notes".

3. **Allowlist key for unnamed routes (D-06 says "route name").**
   - Recommendation: key = name, else `METHOD uri` with the Livewire hash normalised. Alternative: name the routes (not possible for vendor routes).

4. **CI shape: exclude `Audit` from the main Pest step or run it twice?**
   - Recommendation: exclude (`--exclude-testsuite=Audit`) in `composer test` and the CI Pest step; add `partner-audit` to `composer ci` and a separate CI step. Trade-off: plain `vendor/bin/pest` (the GSD `test_command`) still runs everything, `composer test` does not.

5. **Declared status of the dead Filament export/import routes.** Closed by binder (404) as recommended, or given a table and auth. Recommendation: close.

6. **Is `DO-01` (files, pending checkbox in REQUIREMENTS.md line 115) complete?** The document routes and Partner file rules exist and are walked by `RouteWalkTest`; the checkbox may simply be stale. Not this phase's scope, but the download channel assumes the file rules are final.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV (Docker/OrbStack) | all tests (host has no DB) | yes | DDEV v1.25.4, web container PHP 8.5 | none |
| PostgreSQL | `RefreshDatabase` on `kokpit_test` | yes (DDEV db service) | 18 | none |
| Redis | Horizon probe, queue tests | yes (DDEV service) | running | none |
| Pest | runner | yes | v5.3.3, `--exclude-testsuite` present | none |
| `curl` to `https://kokpit.ddev.site` | manual HTTP verification of defects | yes | — | `Request::create` in tinker (does not reproduce defect 1 without the header-aware path; prefer curl) |

**Missing dependencies with no fallback:** none. **Missing with fallback:** none.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest 5.3.3 on PHPUnit 13 [VERIFIED: installed.json] |
| Config file | `phpunit.xml` (add `<testsuite name="Audit"><directory>tests/Audit</directory></testsuite>`), `tests/Pest.php` (add `pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Audit');`) |
| Quick run command | `ddev exec vendor/bin/pest --testsuite=Audit` |
| Full suite command | `ddev exec vendor/bin/pest` (all suites incl. Audit) or `ddev composer ci` |

### Phase Requirements -> Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| US-01 | Every Resource/Page/Widget/RM/API endpoint/Livewire component behaves per `PartnerMatrix` for Admin and Partner; Partner abilities and presented fields match | integration (HTTP + Gate + Filament) | `ddev exec vendor/bin/pest tests/Audit/AccessMatrixTest.php` | no, Wave 0 |
| US-01 | A class/endpoint/component missing from the matrix, or a matrix row without a class, fails | completeness | `ddev exec vendor/bin/pest tests/Audit/CompletenessTest.php` | no, Wave 0 |
| AUD-02 | Every route is allowlisted/closed or has an auth middleware; every allowlist entry has a route, a means and a non-empty reason | integration | `ddev exec vendor/bin/pest tests/Audit/RouteAuditTest.php --filter=inventory` | no, Wave 0 |
| AUD-02 | Anonymous request to every non-public route is 401/403/redirect-to-login, no header tricks, never 200/419/500 | integration | `ddev exec vendor/bin/pest tests/Audit/RouteAuditTest.php --filter=anonymous` | no, Wave 0 |
| AUD-02 | The audit detects a planted unauthenticated route (negative self-test, `Route::get(...)` inside the test) | unit-style | `... --filter="reports"` | no, Wave 0 |
| AUD-01 | Entity x channel: no foreign canary, no Admin-only canary for a Partner, in UI, search, selects, exports, notifications, activity, downloads, API | integration | `ddev exec vendor/bin/pest tests/Audit/ChannelMatrixTest.php` | no, Wave 0 |
| AUD-01 | Entity without registry/channel declaration fails; N/A without reason fails | completeness | `ddev exec vendor/bin/pest tests/Audit/CompletenessTest.php` | no, Wave 0 |
| Defects | Unauthenticated `/api/v1/*` without `Accept` is 401; Filament export/import routes 404 | integration | `ddev exec vendor/bin/pest tests/Audit/RouteAuditTest.php --filter="defect"` | no, Wave 0 |
| CI gate | CI workflow and `composer ci` contain the Audit step | repo test | extend `tests/Feature/Repo/CiParityTest.php` | file exists, add case |

### Sampling Rate
- **Per task commit:** `ddev exec vendor/bin/pest --testsuite=Audit` plus `ddev exec vendor/bin/pest tests/Feature/Repo` when `phpunit.xml`, `composer.json`, workflow or docs change.
- **Per wave merge:** `ddev exec vendor/bin/pest` (all suites), `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` (PHPStan analyses `app`, `routes`, `database/*` only [VERIFIED: phpstan.neon], so defect fixes in `app`/`bootstrap`... note `bootstrap/` is not analysed).
- **Phase gate:** `ddev composer ci` green, then `scripts/check-sensitive.sh`, then `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Audit/` with the four test files above.
- [ ] `tests/Support/PartnerMatrix.php`, `RouteRules.php`, `ChannelMatrix.php`, `AuditInventory.php`.
- [ ] `phpunit.xml` suite `Audit`; `tests/Pest.php` binding for `Audit`.
- [ ] `composer.json`: scripts `partner-audit`, `test` (with `--exclude-testsuite=Audit`), `ci` (adds `@partner-audit`).
- [ ] `.github/workflows/hygiene.yml`: `Pest` step excludes Audit; new "Partner audit" step in the `tests` job (so the existing services and boot step apply).
- [ ] Defect fixes (D-09): `bootstrap/app.php` redirect, closing the Filament export/import routes.
- [ ] Docs: CONTRIBUTING Phase 12 bullet (line 121) rewritten, README gate sentence, `.planning/codebase/TESTING.md` suite table; adjust `RepositoryFilesTest` guard for the bullet.

## Security Domain

`security_enforcement` is enabled (absent = enabled; `security_asvs_level: 1` in `.planning/config.json`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (route audit asserts auth per route) | Filament `Authenticate`, Sanctum `auth:sanctum`, Horizon gate |
| V3 Session Management | partial (guest-only routes, logout) | Laravel session; out of scope except route rules |
| V4 Access Control | **yes, the core of the phase** | `#[AccessRule]`, `KokpitPolicy`, `PartnerScope`, abilities on tokens |
| V5 Input Validation | no new inputs (audit sends fixed requests) | existing validators |
| V6 Cryptography | no | signed URLs / webhook signature already in place; do not touch |
| V8 Data Protection | yes (no leak in any channel) | scope + policies + `PartnerSafeColumnsTest` |
| V13 API | yes (every endpoint has token, ability, throttle; 401 not 500) | `auth:sanctum`, `abilities:`, `throttle:` |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Missing auth on a new vendor/framework route | Elevation of privilege / Information disclosure | Inventory-vs-allowlist audit (Pattern 2) |
| Auth failure hidden behind another error (419, 500, 404) | Spoofing / Repudiation of control | Middleware check plus strict status set (Pitfall 5) |
| Insecure direct object reference across clients (record ids, file routes) | Information disclosure | `PartnerScope`, policies, `RouteWalkTest` pattern, signed URL bound to user |
| Partner token for the API | Elevation of privilege | `ApiAccessTest` 401; matrix API section |
| Unauthenticated 500 reveals stack trace / paths when `APP_DEBUG=true` | Information disclosure | Fix defects 1 and 2; production runs `APP_DEBUG=false` [ASSUMED] |
| Public docs expose endpoint list | Information disclosure | Accepted by owner decision (`config/scribe.php`); declared in the allowlist with that reason |
| New notification or export reaching a Partner | Information disclosure | Notification and download declarations with completeness checks |

## Sources

### Primary (HIGH confidence)
- Repository files read this session: `12-CONTEXT.md`, `REQUIREMENTS.md`, `.claude/CLAUDE.md`, `phpunit.xml`, `tests/Pest.php`, `tests/TestCase.php`, `tests/Arch/PanelRegistryTest.php`, `tests/Isolation/{RouteWalkTest,ApiAccessTest,CanaryRegistryTest,PartnerSafeColumnsTest}.php`, `tests/Support/{Canary,CanaryRegistry,AuditDeclaration,ModelDeclaration}.php`, `routes/{web,public,api}.php`, `bootstrap/app.php`, `app/Domain/Shared/Auth/*`, `app/Providers/{AccessServiceProvider,HorizonServiceProvider}.php`, `app/Providers/Filament/AdminPanelProvider.php`, the policies, `app/Filament/Partner/Resources/PartnerTaskResource.php`, `.github/workflows/hygiene.yml`, `composer.json`, `phpstan.neon`, `CONTRIBUTING.md` lines 99, 121, `.planning/codebase/TESTING.md`, `11-20-SUMMARY.md`.
- Vendor source read: `vendor/laravel/framework/.../Routing/Router.php` (844, 970-979), `Auth/Middleware/Authenticate.php` (97-120), `Foundation/Configuration/ApplicationBuilder.php` (258-262), `Foundation/Exceptions/Handler.php` (854-860), `vendor/filament/actions/routes/web.php`, `.../DownloadExport.php`, `vendor/livewire/livewire/.../EndpointResolver.php` (15-17).
- Executed in DDEV: `artisan route:list --json` (testing env, 125 routes), anonymous/Partner/Admin request probes (dev and testing), `curl` against `https://kokpit.ddev.site` (defects 1 and 2), `Schema::hasTable` checks, `pest tests/Isolation/CanaryRegistryTest.php` (8 passed, 2.33 s), `pest --help` (`--exclude-testsuite`), a dataset-with-router probe (failed). All probe files were deleted afterwards; `git status` shows only the pre-existing `.planning` modifications.

### Secondary (MEDIUM confidence)
- None.

### Tertiary (LOW confidence)
- Fix proposals A1, A2 (framework behaviour reasoned from the sources above, not yet run end to end).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH, no new packages, versions read from `installed.json`.
- Architecture: HIGH for inventory, allowlist, completeness design (all grounded in code and probes); MEDIUM for the Partner matrix content (brief missing, A4) and for Filament introspection calls (A3).
- Pitfalls: HIGH for 1, 2, 4 (reproduced), MEDIUM for 5-9 (reasoned from source and repo guards).

**Research date:** 2026-10-24
**Valid until:** 2026-11-23 (stable stack; re-run `route:list` if a Filament, Livewire or Horizon minor is bumped, because vendor routes change)
