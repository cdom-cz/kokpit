---
phase: 12-partner-audit
reviewed: 2026-10-24T00:00:00Z
depth: standard
files_reviewed: 20
files_reviewed_list:
  - .github/workflows/hygiene.yml
  - CONTRIBUTING.md
  - README.md
  - app/Providers/AccessServiceProvider.php
  - bootstrap/app.php
  - composer.json
  - phpunit.xml
  - tests/Audit/AccessMatrixTest.php
  - tests/Audit/ChannelMatrixTest.php
  - tests/Audit/PartnerActionsTest.php
  - tests/Audit/RouteAuditTest.php
  - tests/Feature/Repo/CiParityTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Pest.php
  - tests/Support/ChannelMatrix.php
  - tests/Support/ChannelProbe.php
  - tests/Support/PanelInventory.php
  - tests/Support/PartnerMatrix.php
  - tests/Support/RouteInventory.php
  - tests/Support/RouteRules.php
findings:
  critical: 0
  warning: 5
  info: 6
  total: 11
status: issues_found
---

# Phase 12: Code Review Report

**Reviewed:** 2026-10-24
**Depth:** standard
**Files Reviewed:** 20
**Status:** issues_found

## Summary

The Audit suite was run inside DDEV (`vendor/bin/pest --testsuite=Audit`): 592 tests passed, 1249 assertions, 153 s. The
two production changes (guest redirect in `bootstrap/app.php`, the `export` and `import` route binders in
`AccessServiceProvider`) behave as intended and are covered by `RouteAuditTest` (defects 1 and 2). The hygiene check
(`scripts/check-sensitive.sh`) is clean for every reviewed file, and the CI wiring (`hygiene.yml`, `composer.json`,
`phpunit.xml`, `CiParityTest`) is consistent.

No Critical issue was found. The findings are gaps in what the audit proves, which matters because the suite's purpose is
to be the evidence that a Partner reaches nothing undeclared: two completeness checks are anchored to the wrong set, the
action probe covers only a subset of the abilities a policy can grant, the "forbidden field" pattern misses several money
and billing words, and one channel driver skips routes silently instead of failing loudly. One of the two production
changes carries a latent collision risk (route binders are global by parameter name).

## Warnings

### WR-01: Action matrix completeness is checked against CanaryRegistry, not against the registered policies

**File:** `tests/Audit/PartnerActionsTest.php:401-412` (declaration in `tests/Support/PartnerMatrix.php:361-503`)
**Issue:** `declares every isolated entity exactly once` compares `PartnerMatrix::partnerAbilities()` plus `adminOnlyModels()`
with `CanaryRegistry::fixtures()` only. Models that have a registered policy but are not canary entities have no row and
no test can notice. Verified with `Gate::policies()` against the declared set:
`App\Domain\Identity\Models\User`, `App\Domain\Identity\Models\PersonalAccessToken` and
`App\Domain\Dashboard\Models\DashboardWidgetPreference` have policies (see `AccessServiceProvider.php:106,132,156`) and
appear in neither list (`Role`, `Permission` and `IdempotencyKey` are app models that appear nowhere either). `User` is the
most sensitive of these (account management, role changes). A later change to `UserPolicy` or to
`DashboardWidgetPreferencePolicy::collapse` that widens a Partner grant would pass the audit, although the docs
(README.md:145, CONTRIBUTING.md Phase 12 bullet) say the audit proves no Partner action exists without a declared rule.
**Fix:** Add a completeness test over the policy registry, with an explicit, reasoned exemption list for models that are
covered elsewhere, for example:
```php
it('has a matrix row or a written exemption for every model with a registered policy', function (): void {
    $declared = [...array_keys(PartnerMatrix::partnerAbilities()), ...array_keys(PartnerMatrix::adminOnlyModels()), ...array_keys(PartnerMatrix::policyExemptions())];
    expect(array_values(array_diff(array_keys(Gate::policies()), $declared)))->toBe([]);
});
```
and give `User`, `PersonalAccessToken` and `DashboardWidgetPreference` a row (or an exemption naming the test that covers them).

### WR-02: The action audit probes only five of the abilities a policy can grant

**File:** `tests/Audit/PartnerActionsTest.php:556-568`, `tests/Support/PartnerMatrix.php:349-352`
**Issue:** The Admin-only refusal test asks `viewAny`, `create`, `view`, `update`, `delete` only. `KokpitPolicy` also defines
`deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`, `reorder` and `replicate` (all default-deny today).
`PartnerMatrix::CLASS_ABILITIES` and `RECORD_ABILITIES` have no entry for any of them, so for the Partner-visible entities
(Project, Task, TaskComment, Document, Invoice, InvoiceItem) a future override such as `restore` or `forceDelete` on a
policy that already grants `view` to a Partner is invisible to the suite, and `abilityProblems()` would reject a row
declared for it as "unknown ability". Filament resources call exactly these abilities (bulk delete, restore, replicate).
**Fix:** Derive the probed set from the policy contract rather than a hand-kept list: reflect the public methods of
`KokpitPolicy` (minus `before`) plus any extra public method of the registered policy, and require a declared answer for
each (default `false`). At minimum, add the seven inherited abilities to the Admin-only loop and to `RECORD_ABILITIES` /
`CLASS_ABILITIES` with `false` rows.

### WR-03: FORBIDDEN_FIELD_PATTERN misses common money and billing words

**File:** `tests/Support/PartnerMatrix.php:573`
**Issue:** The guard that forces a written exception for money/measured-time field names does not match `billed_at`,
`billed_seconds` (matches only through `seconds`), `amount`, `fee`, `margin`, `profit`, `revenue`, `discount`, `tax`, `total`,
`remaining`, `hourly`, `invoiced`. Verified with `preg_match`: `billed_at`, `amount`, `fee`, `margin`, `profit`, `revenue`,
`discount`, `tax`, `hourly`, `invoiced`, `total` all return 0. The declaration already shows the inconsistency:
`PartnerInvoiceResource` declares `total`, `remaining`, `amount_due`, `summary_total`, `line_total` without any exception,
while `unit_price` needs one only because it contains `price`. Because the runtime field list must equal the declared list,
a new column still shows up in a diff, but the pattern is the only mechanism that forces the author to justify a finance
word, and it silently lets `billed_at` (a time-entry billing state, TI-07) or `margin` through. Conversely `rate` also
matches `generate`, `separate`, `operator`-style names (`vat_rate` is intended, `separate` is not), so exceptions will
accumulate for harmless names.
**Fix:** Extend the pattern with `billed|bill|amount|fee|margin|profit|revenue|discount|vat|tax|hourly|invoiced|salary` and
anchor `rate` to word boundaries or `_` (`(?<![a-z])rate|rate(?![a-z])`), then give the existing printed-document fields
(`total`, `amount_due`, `remaining`, `summary_*`, `line_total`) explicit entries in `fieldExceptions()`. Add the new words
to the "must match" list in `tests/Audit/PartnerActionsTest.php:457`.

### WR-04: ChannelProbe::apiBlob silently skips API routes it does not know how to fill

**File:** `tests/Support/ChannelProbe.php:353-368`
**Issue:** For a route with path parameters, the driver continues (`else { continue; }` at 366) when the route has more than
one parameter or a parameter that is not in `API_PARAMETER_MODELS`. `walkUrl()` and `walkRecordId()` in the same class throw
`LogicException` for an unknown parameter exactly so that "a new kind of route is looked at on purpose", and
`ChannelMatrixTest` even tests that (`still refuses a route parameter and a resource it does not know`). The API driver
breaks that rule: a future `/api/v1/invoices/{invoice}` route would be absent from every `api` cell and the matrix would stay
green. The per-route 401 check in `AccessMatrixTest` still covers the route with a random UUID, so this is not a leak
today, but the channel matrix claims to walk "every route under api/v1/".
**Fix:** Replace the silent branch with a failure:
```php
} else {
    throw new LogicException("The api channel does not know how to fill the parameters [".implode(', ', $parameters)."] of route [{$route->getName()}]; extend API_PARAMETER_MODELS.");
}
```
(and make the model mismatch `continue` explicit only for parameters that are known).

### WR-05: The `export` / `import` route binders are global by parameter name

**File:** `app/Providers/AccessServiceProvider.php:83-88`
**Issue:** `Route::bind('export', ...)` and `Route::bind('import', ...)` apply to every route in the application that has a
parameter of that name, not only to the two Filament action routes they are meant to close. The domain has a `ReportExport`
model and "report exports" are a named feature; any future route written as `/reports/{export}` or `/imports/{import}`
would answer 404 to everybody before any controller or policy runs, and the cause (a binder registered in an access
provider) is far from the route. Nothing in `RouteAuditTest` guards this: it proves only that the two known routes are 404.
**Fix:** Scope the binder to the Filament routes and leave other routes to the default binding:
```php
foreach (['export', 'import'] as $parameter) {
    Route::bind($parameter, static function (string $value, \Illuminate\Routing\Route $route) {
        abort_if(in_array($route->getName(), ['filament.exports.download', 'filament.imports.failed-rows.download'], true), 404);

        return $value;
    });
}
```
or add an Audit test asserting that no route other than those two has a parameter named `export` or `import`.

## Info

### IN-01: ChannelProbe resolves closed routes by name although RouteRules allows unnamed keys

**File:** `tests/Support/ChannelProbe.php:1271-1274`
**Issue:** `RouteRules` documents that a key is the route name "or `METHOD /uri`", and `RouteAuditTest::auditRouteByKey()`
resolves both forms. `exportSegments()` uses `Route::getRoutes()->getByName($key)`, so an unnamed closed route added to
`RouteRules::closedRoutes()` would throw "is not registered" in the channel matrix although it exists.
**Fix:** Reuse the key-based lookup (move `auditRouteByKey` into `RouteInventory::byKey()`) in both places.

### IN-02: RouteInventory fails with a TypeError on a closure middleware and probes only the first HTTP verb

**File:** `tests/Support/RouteInventory.php:90` and `:72`
**Issue:** `array_map('strval', app('router')->gatherRouteMiddleware($route))` throws "Object of class Closure could not be
converted to string" when a route (own or from a package) carries a closure middleware, which Laravel resolves unchanged;
the whole inventory then errors instead of reporting the route. Separately, `method()` returns only the first non-HEAD verb
and the unauthenticated-refusal test requests only that one; a `Route::match`/`any` route is declared and tested under its
first verb only (the app already has one: `GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS|QUERY /`, public by declaration).
**Fix:** Map middleware with `is_string($m) ? $m : '[closure]'`; consider requesting every verb of a non-declared route in the
anonymous-refusal test.

### IN-03: The "Partner audit" CI step is skipped when the Pest step fails

**File:** `.github/workflows/hygiene.yml:194-200`
**Issue:** The comment says the suite runs separately "so its failure is visible by name", but a step after a failed step is
skipped by default, so a red `Pest` step hides whether the audit also fails (and vice versa for `composer ci`, which stops at
the first failing script). `CiParityTest` pins the step order but not the condition.
**Fix:** Add `if: ${{ !cancelled() }}` to the `Partner audit` step (and keep the `ci-passed` aggregation as is).

### IN-04: Dead data and duplicated directory scanners in the support classes

**File:** `tests/Support/ChannelProbe.php:1079-1093,470-491`, `tests/Support/PanelInventory.php:94,336`, `tests/Audit/ChannelMatrixTest.php:134-155`
**Issue:** `walkResourceMap()` builds a `partner` flag per resource (documented as "who may open its record routes") that no
code reads. The app-directory-to-class scan is written four times (`PanelInventory::livewireComponents`,
`PanelInventory::appFilamentClasses`, `ChannelProbe::historyManagers`, `auditChannelNotificationClasses`), and
`PanelInventory::livewireComponents()` lacks the `is_dir` guard the sibling method has. `PanelInventory` also copies logic from
`tests/Arch/PanelRegistryTest.php` by design, which will drift.
**Fix:** Drop the unused flag; extract one `ClassScanner::under(string $dir, string $namespace)` helper.

### IN-05: Documentation overstates what the audit proves

**File:** `README.md:145`, `CONTRIBUTING.md` (Phase 12 bullet)
**Issue:** The text says the audit "proves that a Partner reaches no route, screen, endpoint, action, field or leak channel
without a declared rule". Given WR-01 to WR-04 (models outside CanaryRegistry, abilities outside the five probed, fields
outside the pattern, API routes outside the known parameters), and that `default-livewire.update` is declared public on the
strength of a reason string rather than a test, the claim is stronger than the suite.
**Fix:** Soften to "checks that every route, screen, endpoint, ... has a declared rule" or close the gaps above first.

### IN-06: `composer partner-audit` may hit Composer's 300 s process timeout

**File:** `composer.json:68-69,73-79`
**Issue:** The suite takes 153 s on a fast local machine (`ChannelMatrixTest` seeds the full two-client scene in `beforeEach`
even for its pure self-tests). Composer kills a script after `process-timeout` (default 300 s); a slower laptop running
`ddev composer ci` can fail with a timeout that looks like a test failure. CI is unaffected because it calls `pest` directly.
**Fix:** Run the script as `["Composer\\Config::disableProcessTimeout", "vendor/bin/pest --testsuite=Audit"]`, and move the
`ChannelProbe::seed()` call out of the global `beforeEach` into the tests that need it.

---

_Reviewed: 2026-10-24_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
