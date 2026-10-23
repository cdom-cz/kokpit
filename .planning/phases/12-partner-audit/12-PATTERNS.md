# Phase 12: Partner Audit - Pattern Map

**Mapped:** 2026-10-24
**Files analyzed:** 17 (new and modified)
**Analogs found:** 15 / 17

All analog paths below were checked with `git ls-files` (tracked). Line numbers are from the current tree.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `tests/Audit/RouteAuditTest.php` | test | request-response | `tests/Isolation/ApiAccessTest.php` + `tests/Isolation/RouteWalkTest.php` | exact (walk + self-test style) |
| `tests/Audit/AccessMatrixTest.php` | test | request-response | `tests/Arch/PanelRegistryTest.php` + `tests/Isolation/RouteWalkTest.php` | role-match |
| `tests/Audit/ChannelMatrixTest.php` | test | CRUD / batch (entity x channel) | `tests/Isolation/CanaryRegistryTest.php` + `tests/Isolation/RouteWalkTest.php` | role-match |
| `tests/Audit/CompletenessTest.php` | test | transform (set diff) | `tests/Arch/PanelRegistryTest.php` | exact |
| `tests/Support/PartnerMatrix.php` | config (declaration) | transform | `tests/Support/AuditDeclaration.php` | role-match |
| `tests/Support/RouteRules.php` | config (declaration) | transform | `tests/Support/AuditDeclaration.php` | role-match |
| `tests/Support/ChannelMatrix.php` | config (declaration) | transform | `tests/Support/CanaryRegistry.php` (`fixtures()`, `needles()`) | role-match |
| `tests/Support/AuditInventory.php` | utility | transform | `tests/Arch/PanelRegistryTest.php` helpers (copy) + `walkedRoutes()` / `apiWalk*` | exact |
| `phpunit.xml` | config | n/a | existing `<testsuite>` entries | exact |
| `tests/Pest.php` | config | n/a | existing `pest()->extend(...)` lines | exact |
| `composer.json` | config | n/a | existing `scripts` block | exact |
| `.github/workflows/hygiene.yml` | config | n/a | the `Pest` step | exact |
| `tests/Feature/Repo/CiParityTest.php` | test | n/a | itself (add a case at line ~81) | exact |
| `bootstrap/app.php` | config | request-response | itself (defect 1: `redirectGuestsTo`) | modify |
| A service provider (explicit binders for `export` / `import`) | provider | request-response | existing `app/Providers/*` | modify, analog not read |
| `CONTRIBUTING.md` Phase 12 bullet (~line 121), `.planning/codebase/TESTING.md`, README | docs | n/a | existing text + `tests/Feature/Repo/RepositoryFilesTest.php` guard | modify |
| Test-only declaration of Notification classes (inside `PartnerMatrix` / `ChannelMatrix`) | config | transform | `NotificationMarkupTest` (not read) | no analog read |

## Pattern Assignments

### `tests/Audit/RouteAuditTest.php` (test, request-response)

**Analog:** `tests/Isolation/ApiAccessTest.php`

**Imports and header comment** (lines 5-24): functional Pest, a requirement-ID block comment, file-level helpers prefixed per file.
```php
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/*
 * D-01, D-16, T-07-56: a route walk over every /api/v1 route. Everything is derived from the
 * route collection, so a route added by a later plan is walked without editing this file ...
 */
```

**Route enumeration, method, path with UUID params** (lines 31-76):
```php
foreach (Route::getRoutes()->getRoutes() as $route) { ... }
function apiWalkPath(LaravelRoute $route): string
{
    return '/'.preg_replace_callback('/\{[^}]+\}/', static fn (): string => (string) Str::uuid(), $route->uri());
}
function apiWalkMethod(LaravelRoute $route): string
{
    return array_values(array_diff($route->methods(), ['HEAD']))[0];
}
```
Note: the research says to use `app('router')->gatherRouteMiddleware($route)` instead of `$route->gatherMiddleware()` (line 52 here), which leaves aliases unresolved.

**Non-vacuity and negative self-test** (lines 78-80, 129-141): a "finds at least one route" test, and a test that plants `Route::get('api/v1/probe-bare', ...)` and expects the checker to report it. Copy for the planted unauthenticated route.
```php
$bare = Route::get('api/v1/probe-bare', static fn (): string => 'x');
expect(apiWalkProblems([$bare]))->toHaveCount(2)
```

**Problems-list checker** (lines 89-123): a pure function that returns `list<string>` of problems, then `expect(...)->toBe([])`. Use the same shape for the inventory check (undeclared route, declared-but-missing route, empty reason).

**Guest loop with guard reset** (lines 143-160): `Auth::forgetGuards();` between requests. The anonymous audit must NOT use `->json(...)` (line 145 does, which hides defect 1). Use `$this->call($method, $path)` with no Accept header.

**Analog 2 for status-set assertion:** `tests/Isolation/RouteWalkTest.php` lines 330-346:
```php
expect($response->getStatusCode())->toBeIn([200, 302, 403, 404], "{$url} answered {$response->getStatusCode()}")
```
Copy this message style; the audit's set is 401, 403, or a redirect ending in `/admin/login`. Exclude 419 and 500.

**Pest dataset rule (Pitfall 4):** build `->with(...)` only from the declaration arrays (plain PHP), never from `Route::getRoutes()`.

---

### `tests/Audit/AccessMatrixTest.php` (test, request-response)

**Analog:** `tests/Arch/PanelRegistryTest.php` (panel classes) and `tests/Isolation/RouteWalkTest.php` (HTTP per role, Livewire, walked URLs).

**Imports** (PanelRegistryTest lines 5-24): `AccessRule`, `AccessRules`, `Audience`, `Filament`, `Resource`, `Widget`, `WidgetConfiguration`, `RelationManager`. Do not read `AccessRules::for()` to build the expectation (D-01); it is read only as the actual behaviour.

**Panel set-up** (ApiAccessTest line 232): `Filament::setCurrentPanel(Filament::getPanel('admin'));`

**Actual-behaviour calls for Partner abilities:** see `tests/Feature/.../PanelAccessTest.php` lines 212-300 (named in RESEARCH A3; not read here). Copy the `canAccess()` / `canViewForRecord()` calls from there.

**API section:** copy `apiWalkAbility()` / `apiWalkMiddleware()` and the Partner-token 401 loop (ApiAccessTest lines 150-160):
```php
$partner = Canary::partnerFor(ApiFixtures::client()->id);
$token = ApiFixtures::token($partner, ApiAbility::values());
foreach (apiWalkRoutes() as $route) {
    Auth::forgetGuards();
    $this->withToken($token)->json(apiWalkMethod($route), apiWalkPath($route))->assertUnauthorized();
}
```

**Signed file routes and record routes:** reuse `walkedUrl()` / `walkedRecordId()` (RouteWalkTest lines 96, 281). They are file-level functions in a Pest file, so do not call or redefine them from `tests/Audit`; extract a copy into `AuditInventory` (research Architecture, helper-name warning).

**Partner field checks (D-03):** storage pins live in `tests/Isolation/PartnerSafeColumnsTest.php` (not read); the matrix adds presented-field checks read from the Partner resource schema.

---

### `tests/Audit/ChannelMatrixTest.php` (test, entity x channel)

**Analog:** `tests/Isolation/CanaryRegistryTest.php` (lines 1-110) and `tests/Isolation/ApiAccessTest.php` lines 230-241 (seed set-up).

**Seed set-up** (ApiAccessTest lines 230-246):
```php
beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    CanaryRegistry::prepare();
    [$this->clientA, $this->clientB] = Canary::twoClients();
    $this->canaryA = Canary::canary('client_a');
    $this->canaryB = Canary::canary('client_b');
    CanaryRegistry::seedAll($this->clientA, $this->canaryA);
    CanaryRegistry::seedAll($this->clientB, $this->canaryB);
});
afterEach(function (): void {
    CanaryRegistry::cleanup();
});
```
Note CanaryRegistryTest uses `CanaryRegistry::prepare()` in `beforeEach` and `cleanup()` in `afterEach` (lines 55-61).

**Per-entity needles:** `CanaryRegistry::needles($model, $canary)` (CanaryRegistry.php line 143) returns the strings to look for; the default is `[$canary]`, with overrides for Signal, ExchangeRate, PaymentLink, CardPayment, SignalSetting. Always assert via `needles()`, never the bare canary.

**Entity set:** `array_keys(CanaryRegistry::fixtures())` (line 182) is the entity universe (38). Completeness helper to copy: `modelsWithoutFixture()` and `partnerIsolatedModels()` (CanaryRegistryTest lines 60-90):
```php
$models = array_values(array_filter(ModelDeclaration::appModels(), static fn (string $class): bool => is_subclass_of($class, PartnerIsolated::class)));
$models[] = CanaryRecord::class;
```

**Channel drivers to copy from RouteWalkTest:**
- UI body walk: lines 330-346 (Partner GET of every route, body must not contain the foreign canary or client id).
- Livewire table plus search: lines 408-418 (`Livewire::test(...)->assertCanNotSeeTableRecords(...)->searchTable(...)`).
- Global search: lines 420-441 (`getGlobalSearchResults('CANARY')`, result titles equal only the own canary; Admin control sees both).
- Downloads: lines 449-560 (file, archive, invoice PDF routes with signed URLs).
- Admin control (so a refusal is not vacuous): ApiAccessTest lines 247-253 and RouteWalkTest line 384.
- Row visibility of the model layer: `visibleRows()` (CanaryRegistryTest ~lines 92-100).

**Query-in-closure caveat:** run model queries inside `app(PartnerContext::class)->runAsSystem(fn () => ...)` when an Admin-side id is needed (ApiAccessTest lines 255-258).

---

### `tests/Audit/CompletenessTest.php` (test, transform)

**Analog:** `tests/Arch/PanelRegistryTest.php`

**Structure to copy:** discovered set versus declared set, both directions, plus negative self-tests (lines 205-262):
```php
it('declares an access rule on every class the panel registers and every concrete class under app/Filament', ...);
it('does not pass vacuously: the registry and the directory scan both see the dashboard', ...);
it('reports an undeclared class', ...);
it('sorts the offenders by class name whatever the input order', ...);
```
Also copy the "sorted offenders" convention (`panelRegistryUnique`, lines 34-40) so failure messages are stable.

**Class discovery:** `panelRegistryPanelClasses()` (lines 72-104) uses `Filament::getPanel('admin')` with `getResources()`, `getPages()`, `getClusters()`, `getWidgets()` (unwrap `WidgetConfiguration`), `getPageConfigurations()`, `getResourceConfigurations()`, then relation managers via `$class::getRelations()`. Also `panelRegistryAppClasses()` (line 161) for the `app/Filament` scan. Copy into `AuditInventory`; do not move them (D-08 spirit; file-level functions would be redefined and fatal).

**Logging models:** `AuditDeclaration::loggingModels()` (`tests/Support/AuditDeclaration.php` line 40) feeds the activity-channel completeness.

---

### `tests/Support/PartnerMatrix.php`, `RouteRules.php`, `ChannelMatrix.php` (declaration files)

**Analog:** `tests/Support/AuditDeclaration.php`

**Header and shape** (lines 1-30):
```php
declare(strict_types=1);

namespace Tests\Support;

/**
 * Reads the activity log allowlist declaration ... (D-06).
 */
final class AuditDeclaration
{
    private const string SENSITIVE_NAME = '/^(password|secret|token|remember_token)$|.../i';

    /** @return list<class-string<Model>> */
    public static function loggingModels(?string $directory = null, string $namespace = ''): array
```
Conventions to copy: `final class`, `declare(strict_types=1)`, namespace `Tests\Support`, typed `private const string`, docblock array shapes, static pure functions returning `list<...>` / `array<string, array{...}>`, and a `problems(...)` method that returns a list of strings (empty when fine). Put the "empty reason rejected" and "closed enum for `means`" validation in a `problems()` method on `RouteRules`.

**RouteRules content seed:** CONTEXT D-06 and RESEARCH Pattern 2 table. Keys are the route name, else `METHOD uri` with `livewire-{hash}` normalised. Entries `pay.show` (`routes/public.php` line 25-27, token) and `webhooks.stripe` (line 14, signature). Keep consistent with `tests/Isolation/CardPaymentLeakTest.php`, which asserts exactly two routes in `routes/public.php`.

**ChannelMatrix pattern:** shaped like `CanaryRegistry::fixtures()` (a keyed array, one entry per model class) but data-only: `Model::class => ['ui' => ..., 'search' => ..., ... 'api' => ...]` where each cell is `applies` or `['na' => 'reason']`.

---

### `tests/Support/AuditInventory.php` (utility, transform)

**Analog:** `walkedRoutes()` (RouteWalkTest lines 65-85), `apiWalkRoutes()` (ApiAccessTest lines 31-42), `panelRegistry*` (PanelRegistryTest). Combine into one static class. Route key function from RESEARCH "Code Examples" (name else `METHOD uri`, Livewire hash normalised). Middleware via `app('router')->gatherRouteMiddleware($route)`.

---

### `phpunit.xml`, `tests/Pest.php`

**phpunit.xml** (lines 8-22): add after `Isolation`:
```xml
<testsuite name="Audit">
    <directory>tests/Audit</directory>
</testsuite>
```
(`Concurrency` uses `suffix="Test.php"`; the others do not.)

**tests/Pest.php** (lines 14-17): add one line and extend the docblock comment above it:
```php
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Isolation');
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Audit');
```

---

### `composer.json`, `.github/workflows/hygiene.yml`, `CiParityTest`

**composer.json** (lines 68-77): current `"test": "vendor/bin/pest"` and `"ci": ["@test", "@lint", "@stan", "@check-licenses"]`. Change to `"test": "vendor/bin/pest --exclude-testsuite=Audit"`, add `"partner-audit": "vendor/bin/pest --testsuite=Audit"`, and `@partner-audit` into `ci`. Do not name it `audit` (collides with `composer audit`).

**hygiene.yml** (lines 194-195):
```yaml
      - name: Pest
        run: vendor/bin/pest
```
Change to `vendor/bin/pest --exclude-testsuite=Audit` and add a following step `Partner audit` running `vendor/bin/pest --testsuite=Audit` in the same job (boot step and services apply).

**CiParityTest** (`tests/Feature/Repo/CiParityTest.php` lines 81-98): the "boots the tests job from .env.example before Pest runs" case finds the first command containing `vendor/bin/pest` and asserts the boot step precedes it. Add a case that the workflow and `composer ci` contain the Audit step.

---

### `bootstrap/app.php` (defect 1) and Filament export/import routes (defect 2)

No close analog in test code; modify in place. Defect 1: add `$middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'))` (RESEARCH Pitfall 1, assumption A1) next to the existing `shouldRenderJsonWhen(fn => $request->is('api/*'))`. Defect 2: `Route::bind('export', fn () => abort(404))` and `Route::bind('import', ...)` in a provider (A2). Both must be proven by a failing-first test in `RouteAuditTest` that sends the request with no Accept header.

---

## Shared Patterns

### Fictional data only
**Source:** `tests/Pest.php` lines 25-30, `Tests\Support\Canary::canary()`
**Apply to:** every new test and declaration
```php
function exampleEmail(string $local = ''): string
{
    return ($local !== '' ? $local : 'user'.Str::lower(Str::random(8))).'@'.implode('.', ['example', 'com']);
}
```
Use `Canary::canary('client_a')` for markers; reasons in declaration files must contain no real hostnames or names.

### Guard reset between requests
**Source:** `ApiAccessTest.php` lines 154-158
**Apply to:** every loop that mixes anonymous, Partner and Admin requests (`Auth::forgetGuards()`; also `$this->flushSession()`).

### Partner and Admin actors
**Source:** `Canary::partnerFor($clientId)`, `Canary::admin()`, `Canary::twoClients()`, `ApiFixtures::token($user, $abilities)`, `ApiFixtures::admin()` (used in ApiAccessTest and RouteWalkTest).

### Self-tests so the audit cannot pass blindly
**Source:** `PanelRegistryTest` lines 211-262 and `ApiAccessTest` lines 78-141
**Apply to:** each audit check (non-vacuity assertion plus a planted offender that must be reported).

### Pest file-level helper naming
**Source:** prefixes `walked*`, `apiWalk*`, `panelRegistry*`
**Apply to:** any helper left in a `tests/Audit` file (prefix `audit...`), because all files load in one process and a redeclared function is a fatal error. Prefer `AuditInventory` static methods.

### Requirement-ID block comment
**Source:** ApiAccessTest lines 18-24, PanelRegistryTest lines 26-30. Start each audit file with US-01 / AUD-01 / AUD-02 and the D-xx decisions it implements.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| Notification-class declaration (13 classes under `app/Domain/*/Notifications`) | config | transform | Only task notifications are covered today (`NotificationMarkupTest`, not read); the planner should read it and `NotificationLeakTest` before writing the declaration |
| Explicit route binders in a service provider | provider | request-response | No existing `Route::bind` use was inspected; planner should read `app/Providers/*` and pick the provider |

## Metadata

**Analog search scope:** `tests/Isolation`, `tests/Arch`, `tests/Support`, `tests/Pest.php`, `phpunit.xml`, `composer.json`, `.github/workflows/hygiene.yml`, `routes/public.php`
**Files read:** ApiAccessTest (full), AuditDeclaration (1-70), RouteWalkTest (60-95, 330-442 sections), PanelRegistryTest (1-100), CanaryRegistryTest (1-110), CanaryRegistry (selected), composer.json, hygiene.yml, phpunit.xml, Pest.php, routes/public.php
**Not read (named for the planner):** PanelAccessTest lines 212-300, PartnerSafeColumnsTest, NotificationLeakTest, NotificationMarkupTest, RepositoryFilesTest, CardPaymentLeakTest
**Pattern extraction date:** 2026-10-24
