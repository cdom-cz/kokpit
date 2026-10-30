---
phase: 07-rest-api
plan: 12
subsystem: api
tags: [scribe, openapi, zerops, deploy, hygiene, canary, contributing, pest]

requires:
  - phase: 07-rest-api
    provides: "07-02 Scribe docs, ApiDocs support and ApiDocumentationTest; 07-03 api/v1 route walk; 07-07 api activity label; 07-10 idempotency purge; 07-11 batch create"
provides:
  - "Docs generation as the last run.initCommands entry of zerops.yml on every container (no execOnce), guarded by zeropsDocsProblems with six mutation self-checks"
  - "ApiDocumentationHygieneTest: the generated page, spec and intermediate files hold no real host, e-mail, token shape, absolute path or instance env value; scripts/check-sensitive.sh accepts them; theme assets are byte-identical to the package"
  - "A broken route attribute makes scribe:generate exit 2 (tests/Support/scribe-broken-route.php), so a docs failure fails the deploy"
  - "The twelve api/v1 routes pinned, exactly two Idempotency-Key operations, batch documents 201/207/409/422"
  - "API canary line in ApiAccessTest (guest and Partner token: 401 and no canary of two clients), with an Admin control"
  - "CONTRIBUTING REST API conventions, the api activity label, timer API recorded as backlog 999.1"
  - "Public API docs describe validation rules in English with no raw placeholder (closes the broken-windows entry of plan 07-11)"
affects: [phase-verification, 999.1 API Timer, deploy rehearsal]

actuals:
  tokens: 15700
  tasks: 3
  commits: 4

plan_head_before: 03bc886712bf10bdee54f27782ff56a903126fc5
plan_head_after: 8e4ebcdbe46fae088bfc95f72400e1d4b90a8f8b

tech-stack:
  added: []
  patterns:
    - "A deploy-manifest rule is a pure function over the parsed manifest with mutation self-checks (zeropsDocsProblems)"
    - "A scan is proven able to fail by feeding it polluted copies assembled from fragments"
    - "A subprocess test script boots the app, registers one probe route and lets the real command decide the exit status"
    - "Docs-only locale: Scribe::bootstrap switches the app locale to English for scribe:generate only"

key-files:
  created:
    - tests/Feature/Api/ApiDocumentationHygieneTest.php
    - tests/Support/scribe-broken-route.php
    - tests/Support/Probes/BrokenDocsProbeController.php
  modified:
    - zerops.yml
    - tests/Feature/Repo/ZeropsConfigTest.php
    - tests/Support/ApiDocs.php
    - tests/Feature/Api/ApiDocumentationTest.php
    - tests/Isolation/ApiAccessTest.php
    - tests/Feature/Repo/RepositoryFilesTest.php
    - CONTRIBUTING.md
    - config/scribe.php
    - app/Providers/ApiServiceProvider.php

key-decisions:
  - "scribe:generate --no-interaction is the exact last init command, plain: a failing generation fails the deploy after the migration and the Horizon start (owner decision D-17, accepted T-07-55)"
  - "The docs are generated with the app locale switched to English for that command only; runtime API answers stay Czech and the wire contract is untouched"
  - "Scribe auth use_value is the {YOUR_API_TOKEN} placeholder, so Scribe's intermediate files never hold a random Bearer value"
  - "Theme assets are proven byte-identical to vendor/knuckleswtf/scribe/resources instead of scanned for hosts (third-party minified code)"
  - "A bare run of 40 or more letters and digits is a token unless it is a hex digest or the base64 of a JSON object (the example cursor)"

patterns-established:
  - "Instance env values shorter than 4 characters are skipped by the hygiene scan (they would match ordinary words); the rest match on word boundaries"

requirements-completed: [AP-01, AP-02, AP-04, AP-05]

coverage:
  - id: D1
    description: "The deploy generates the docs on every container: scribe:generate --no-interaction is the exact last init command, and every weakening of that placement fails ZeropsConfigTest"
    requirement: AP-05
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/ZeropsConfigTest.php#it generates the API docs as the last init command on every container"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/ZeropsConfigTest.php#it reports every weakening of the manifest"
        status: pass
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it generates with exactly the command the deploy manifest runs last on every container"
        status: pass
    human_judgment: false
  - id: D2
    description: "A run with one broken route attribute exits 2 and names the route, and a normal run afterwards exits 0 without it"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it fails the generation, with exit status 2, when one route attribute is broken, and a normal run afterwards is clean"
        status: pass
    human_judgment: false
  - id: D3
    description: "Generated page, spec and intermediate files contain no real host, e-mail, token shape, absolute path or instance env value, and scripts/check-sensitive.sh accepts them"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationHygieneTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "The twelve api/v1 routes are pinned and documented; exactly two operations carry Idempotency-Key; the batch documents 201, 207, 409 and 422"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it pins the twelve routes of the API, no more and no fewer"
        status: pass
    human_judgment: false
  - id: D5
    description: "A guest and a Partner token get 401 on every api/v1 route and no body holds a canary of either client (Admin control reads the canaries)"
    requirement: AP-01
    verification:
      - kind: integration
        ref: "tests/Isolation/ApiAccessTest.php#with canaries of two clients seeded"
        status: pass
    human_judgment: false
  - id: D6
    description: "CONTRIBUTING documents the REST API conventions and every class and test it names exists"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#it only names Phase 7 classes in CONTRIBUTING.md that exist in app/"
        status: pass
    human_judgment: false
  - id: D7
    description: "Rule sentences in the public docs are English with no raw translation placeholder"
    requirement: AP-05
    verification:
      - kind: integration
        ref: "tests/Feature/Api/ApiDocumentationTest.php#it describes validation rules in English, with no raw translation placeholder left in the page or the spec"
        status: pass
    human_judgment: false
  - id: D8
    description: "The first real Zerops deploy runs the new last init command: the init user can write the Scribe output directories (assumption A10) and the failure-fails-deploy behaviour is as expected"
    verification: []
    human_judgment: true
    rationale: "Needs a real Zerops container; recorded as an open rehearsal item together with the sudo -u zerops question."

duration: 15min
completed: 2026-10-14
status: complete
---

# Phase 7 Plan 12: Publish the API - docs in the deploy, hygiene and isolation proofs, contributor docs Summary

**Every container now generates its own public API docs as the last init command (guarded by a mutation-tested manifest check), the generated output is proven free of secrets and real identifiers, a broken attribute fails the generation with exit 2, guest and Partner get 401 with no canary leaking on all twelve routes, and the untranslated `:Attribute` rule sentences are fixed by generating the docs in English.**

## Performance

- **Duration:** about 15 min by the system clock; the full suite alone takes about 4.5 min
- **Tasks:** 3 (one tracer, two TDD) plus the docs-defect fix requested by the caller
- **Files:** 12 (3 created, 9 modified), 4 code commits

## Accomplishments

- `zerops.yml` ends `run.initCommands` with `php artisan scribe:generate --no-interaction`, plain, after the migration, the verify and the Horizon start, with a comment recording the accepted consequences and the open A10 item. `zeropsDocsProblems` (registered as `'docs'`) checks exactly one command, in the init commands, exactly that string, no `execOnce`, the last entry, after migrate, verify and the Horizon start; six mutations (removed, `execOnce`, in the build, before Horizon, `|| true`, a second copy) are all reported, and no other manifest check started failing.
- `ApiDocumentationHygieneTest` scans the page, the spec and Scribe's intermediate files for URL hosts outside the allowlist (placeholder host, the hosts in the stock theme's views, plus Scribe's documentation host that appears in a comment of an intermediate file), `.ddev.site`, `localhost`, IPv4 literals, e-mails outside `example.com`, Sanctum-shaped tokens and long token-like runs, `base64:`, Bearer values other than `{YOUR_API_TOKEN}`, absolute paths and the configured app key, database host and password, and S3 key, secret and bucket. A polluted-copy self-check proves each rule can fail. `scripts/check-sensitive.sh` accepts every generated file. The theme assets are proven byte-identical to the package.
- `tests/Support/scribe-broken-route.php` boots the app, registers a probe route with an unreadable `#[ResponseFromFile]`, and runs the real command: exit status 2 and the output names `_docs-probe`; a normal generation afterwards (in a `finally`) exits 0 and the probe is absent from the spec and the page.
- `ApiDocumentationTest` pins the twelve routes, requires an operation for each, exactly two `Idempotency-Key` operations and 201/207/409/422 on the batch.
- `ApiAccessTest` gained the API canary line: canaries of two clients seeded through `CanaryRegistry`, a guest and a Partner token get 401 on every route, no body holds either canary; an Admin token control reads both canaries from the list so the proof is not vacuous.
- `CONTRIBUTING.md`: REST API, API lists and lookups, API documentation and Idempotency conventions; the audit label list is `web, console, job, webhook, api`; the Phase 7 hand-over records the timer API as backlog 999.1. `RepositoryFilesTest` checks that every Phase 7 class and test it names exists.
- Docs defect from plan 07-11 fixed: the Czech validation lines start with `:Attribute`, which Scribe does not replace; `Scribe::bootstrap` in `ApiServiceProvider` switches the locale to English for `scribe:generate` only. The 15 raw placeholders are gone from spec and page, rule sentences read "Must be at least 1. Must not be greater than 200.", API answers stay Czech. The WINDOWS.md entry is marked fixed.

## Task Commits

1. **Task 1 (tracer): docs generation as the last init command, guarded by the manifest test** - `e41f96b` (feat)
2. **Task 2: hygiene scan, broken-route negative, pinned routes and idempotency/batch docs** - `d7b3aa6` (feat)
3. **Task 3: API canary line and contributor documentation** - `f2fa1f7` (feat)
4. **Docs defect (caller request): English rule sentences in the generated docs** - `8e4ebcd` (fix)

**Plan metadata:** committed separately as `docs(07-12): complete publish the API plan`.

## Files Created/Modified

- `zerops.yml` - the new last init command and its comment (owner-approved D-17 change)
- `tests/Feature/Repo/ZeropsConfigTest.php` - `zeropsDocsProblems`, its test and six mutations
- `tests/Support/ApiDocs.php` - `COMMAND`, `generateWithBrokenRoute()`, `generatedFiles()`, `allowedHosts()`
- `tests/Feature/Api/ApiDocumentationHygieneTest.php` - the forbidden-string scan with self-check
- `tests/Feature/Api/ApiDocumentationTest.php` - manifest command, pinned routes, idempotency and batch, broken route, English rule sentences
- `tests/Support/scribe-broken-route.php`, `tests/Support/Probes/BrokenDocsProbeController.php` - the broken-route fixture
- `tests/Isolation/ApiAccessTest.php` - the canary line
- `CONTRIBUTING.md`, `tests/Feature/Repo/RepositoryFilesTest.php` - conventions and the existence check
- `config/scribe.php` - `auth.use_value` is the placeholder
- `app/Providers/ApiServiceProvider.php` - the English-locale hook for generation

## Decisions Made

See key-decisions. The wire contract (07-02 "Confirmed contract") is unchanged: no controller, resource, request or translation line was edited.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The plan says eleven routes, the route table has twelve**
- **Found during:** Task 2
- **Issue:** the must-have text and artifact table say "eleven", but the pinned list the plan itself names (six time-entry routes plus index and show of clients, projects and tasks) is twelve, and so is `route:list`.
- **Fix:** pinned the twelve routes; no route was added or removed.
- **Files modified:** `tests/Feature/Api/ApiDocumentationTest.php`
- **Commit:** `d7b3aa6`

**2. [Rule 2 - Missing critical] Scribe wrote a random Bearer value into its intermediate files**
- **Found during:** Task 2 (hygiene scan)
- **Issue:** with `auth.use_value` empty, Scribe stores a random `Bearer <random>` in `.scribe/endpoints*`. It is not published, but it is a token-shaped value in a generated file and fails the required "no Bearer except the placeholder" rule.
- **Fix:** `auth.use_value` is `{YOUR_API_TOKEN}` (response calls are removed anyway).
- **Files modified:** `config/scribe.php`
- **Commit:** `d7b3aa6`

**3. [Rule 1 - Bug] The scan rules as written in the plan flag legitimate output**
- **Found during:** Task 2
- **Issue:** "40 or more alphanumerics" flags the base64 example cursor (fictional JSON) and a hex digest in the vendor print stylesheet; the host rule flags a documentation link Scribe writes into a comment of `.scribe/endpoints/custom.0.yaml` and a library credit in the minified theme script.
- **Fix:** a long run is a token unless it is a hex digest or base64 of a JSON object; `scribe.knuckles.wtf` is allowed (never published); theme assets are proven byte-identical to the package instead of scanned. Each rule keeps a polluted-copy self-check.
- **Files modified:** `tests/Feature/Api/ApiDocumentationHygieneTest.php`, `tests/Support/ApiDocs.php`
- **Commit:** `d7b3aa6`

**4. [Rule 3 - Blocking] `scribe-broken-route.php` printed nothing and its exit status 2 could be mistaken for its own guard**
- **Found during:** Task 2
- **Issue:** `Kernel::call` buffers output and Scribe exits the process itself, so the output was lost.
- **Fix:** the script passes a `ConsoleOutput`; the test also asserts the output names the probe route, so the exit 2 is Scribe's.
- **Files modified:** `tests/Support/scribe-broken-route.php`
- **Commit:** `d7b3aa6`

**5. [Caller request] Docs defect of plan 07-11 fixed in this plan**
- **Issue:** `:Attribute nemůže být delší než 200 znaků.` and similar sentences in the public docs.
- **Fix:** see Accomplishments; commit `8e4ebcd`. Closure rules were rejected because they would change the Czech 422 messages of the wire contract. The WINDOWS.md entry is closed, `deferred-items.md` updated.

**Total deviations:** 4 auto-fixed (1 bug in the plan text, 1 missing hygiene, 1 over-strict scan, 1 blocking) plus the requested docs fix. **Impact:** none on the wire contract or the scope.

## Auth Gates

None.

## Known Stubs

None.

## Deferred / Open Items

- Empty `examples: [[]]` on the batch `entries` property in the OpenAPI spec (from 07-11): cosmetic Scribe rendering, left open in `deferred-items.md`.
- Open rehearsal item (assumption A10): the first Zerops deploy must confirm that the init user can write `.scribe/`, `resources/views/scribe/`, `public/vendor/scribe/` and `storage/app/private/scribe/`, together with the open `sudo -u zerops` question. The generation is the last init command, so a permission failure fails the deploy after the migration (accepted, D-17).
- Residual risk A-CDN (owner choice OD-6b) unchanged: the public docs page loads third-party CDN assets.

## Threat Flags

None. T-07-52 (hygiene scan), T-07-53 (plain last command, mutations, exit 2 proof) and T-07-54 (route walk with canaries) are mitigated as planned; T-07-55 is accepted by the owner.

## Verification

- `ddev exec vendor/bin/pest`: 2907 passed
- `ddev exec vendor/bin/pint --test`, `phpstan analyse`: clean
- `ddev composer check-licenses`: 220 packages, every one allowed
- `scripts/tests/run.sh --quick`: PASS 8, FAIL 0
- `scripts/check-sensitive.sh` and the lefthook hook (gitleaks): clean on all four commits
- `git status` shows no generated docs file; the last init command of `zerops.yml` is `php artisan scribe:generate --no-interaction` and `grep -c scribe:generate zerops.yml` is 1 (the acceptance command with `php -r` could not run under `ddev exec` because the wrapper expands `$m`; the same property is asserted by `ApiDocumentationTest`)

## Self-Check: PASSED

- FOUND: tests/Feature/Api/ApiDocumentationHygieneTest.php, tests/Support/scribe-broken-route.php, tests/Support/Probes/BrokenDocsProbeController.php
- FOUND commits: e41f96b, d7b3aa6, f2fa1f7, 8e4ebcd
