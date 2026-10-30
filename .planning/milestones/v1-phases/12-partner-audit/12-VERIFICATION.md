---
phase: 12-partner-audit
verified: 2026-10-24T09:00:00Z
status: human_needed
score: 7/8 must-haves verified
covered_files:
  - .github/workflows/hygiene.yml
  - .planning/phases/12-partner-audit/12-01-PLAN.md
  - .planning/phases/12-partner-audit/12-01-SUMMARY.md
  - .planning/phases/12-partner-audit/12-02-PLAN.md
  - .planning/phases/12-partner-audit/12-02-SUMMARY.md
  - .planning/phases/12-partner-audit/12-03-PLAN.md
  - .planning/phases/12-partner-audit/12-03-SUMMARY.md
  - .planning/phases/12-partner-audit/12-04-PLAN.md
  - .planning/phases/12-partner-audit/12-04-SUMMARY.md
  - .planning/phases/12-partner-audit/12-05-PLAN.md
  - .planning/phases/12-partner-audit/12-05-SUMMARY.md
  - .planning/phases/12-partner-audit/12-06-PLAN.md
  - .planning/phases/12-partner-audit/12-06-SUMMARY.md
  - .planning/phases/12-partner-audit/12-07-PLAN.md
  - .planning/phases/12-partner-audit/12-07-SUMMARY.md
  - .planning/phases/12-partner-audit/12-08-PLAN.md
  - .planning/phases/12-partner-audit/12-08-SUMMARY.md
  - .planning/phases/12-partner-audit/12-09-PLAN.md
  - .planning/phases/12-partner-audit/12-09-SUMMARY.md
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
covered_digest: "v3:sha256:09d593c52b178d6154293fd51b5832acda299a1c94291770f38c0aacede779bb"
behavior_unverified: 1
overrides_applied: 0
behavior_unverified_items:
  - truth: "The audit suite runs in CI and fails the build when a new screen, route or endpoint is added without an explicit role rule (ROADMAP SC4)"
    test: "Open a pull request for the branch (or push to main), open the 'Hygiene' workflow run, job 'Tests (PostgreSQL 18)', steps 'Pest' and 'Partner audit'"
    expected: "'Pest' runs vendor/bin/pest --exclude-testsuite=Audit and 'Partner audit' runs vendor/bin/pest --testsuite=Audit; both green, the Audit step lists RouteAuditTest, AccessMatrixTest, PartnerActionsTest and ChannelMatrixTest, 'CI Passed' green"
    why_human: "The workflow file, composer scripts and CiParityTest are present and consistent, but a hosted run of the Partner audit step has never been observed: the newest hosted Hygiene run on this branch (2026-10-23T22:37Z, PR 22) predates every Phase 12 commit, and no run exists for the pushed head of origin/base-crm-erp. It needs a PR or push to trigger."
human_verification:
  - test: "Observe the hosted CI run of the 'Partner audit' step (see behavior_unverified_items). Note its duration."
    expected: "Pest and Partner audit steps green; the four Audit test files listed; CI Passed green"
    why_human: "Needs a pull request or push to GitHub and a look at the hosted workflow; local composer ci and CiParityTest prove the configuration, not the hosted run (12-09 SUMMARY, Human review)"
  - test: "Decide the disposition of review warnings WR-01 (policy completeness) and WR-02 (unprobed abilities) before release: fix them in a follow-up plan, or accept them with a written reason and soften the README/CONTRIBUTING claim (IN-05)"
    expected: "12-REVIEW-DISPOSITION.md rows WR-01, WR-02 and IN-05 move from 'open' to fixed or accepted-with-reason"
    why_human: "The current behaviour is airtight (see Truth 2 and the WR analysis below), so this is not a failed truth, but it is a decision about how strong the 'proven' claim of the phase must be; that is the owner's call"
audit_acknowledged:
  milestone: v1
  at: 2026-10-30
  status: human_needed
---

# Phase 12: Partner Audit Verification Report

**Phase Goal:** The Partner view is proven airtight across every screen, route and endpoint before release
**Verified:** 2026-10-24T09:00:00Z
**Status:** human_needed
**Re-verification:** No, initial verification

## Goal Achievement

The audit suite exists, is substantive, and passes against the real codebase: `ddev exec vendor/bin/pest --testsuite=Audit` run by this verifier: 592 passed, 1249 assertions, 154 s, exit 0. `CiParityTest` and `RepositoryFilesTest`: 56 passed. `scripts/check-sensitive.sh` clean on the Audit files, the three declaration files and the phase documents. `routes/public.php` and `tests/Isolation` are unchanged since 58c5e93 (D-08). No TBD/FIXME/XXX marker in any file the phase touched.

### Observable Truths

| #   | Truth | Status | Evidence |
| --- | ----- | ------ | -------- |
| 1 | SC1: an automated matrix test enumerates every Resource, Page, Widget, relation manager and API endpoint against Admin and Partner and confirms each behaves as the permission matrix specifies | VERIFIED | `tests/Audit/AccessMatrixTest.php` (385 lines): "declares every panel class and only existing ones" (discovery in `PanelInventory`, literal rows in `PartnerMatrix::panelClasses()`), "declares every /api/v1 route and only existing ones", "demands exactly the declared ability on {0}" (Admin token with only that ability is not refused, Partner token with every ability gets 401), "declares every Livewire component", "lets the Admin mount and refuses a Partner", Horizon 403, "behaves for the Admin and for a Partner as {0} declares", "answers 200 or 403 on every Resource index and panel Page". Each has a planted-difference self-test and a non-vacuity test. "keeps the matrix literal" rejects a matrix source that reads the access rules (D-01). All pass in the verifier's run. |
| 2 | SC1/US-01: the action each role may take (list, view, create, update, delete, custom abilities) per record state is declared and matches the policies | VERIFIED (with warnings WR-01, WR-02) | `PartnerActionsTest` builds real record states (own visible, own hidden, foreign, own upload) and asserts the declared Partner answer and an allowed Admin for every row of `PartnerMatrix::abilityRows()`; every Admin-only CanaryRegistry entity is refused five abilities with non-vacuous rows; runtime fields of every Partner surface equal the declared field list. Code check of all policies extending `KokpitPolicy`: none overrides `deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`, `reorder` or `replicate`, so they default-deny today (`KokpitPolicy.php`). `UserPolicy` grants nothing; `DashboardWidgetPreferencePolicy::collapse` grants only Partner widgets and is covered by `tests/Feature/Dashboard/CollapsibleWidgetsTest.php`; both models are classified by `tests/Arch/ModelDeclarationTest.php`. The residual gap is that the proof does not cover future widening (see Warnings). |
| 3 | SC2/AUD-01: canary tests prove a Partner never sees time, rates, prices, finance, internal comments or another client's data in UI, global search, selects, exports, notifications, activity log, downloads and API, for every entity | VERIFIED | `ChannelMatrix` gives every `CanaryRegistry` entity a cell for each of the eight channels; "declares every CanaryRegistry entity and only those", "declares every PartnerIsolated model", "drives every channel of the matrix" (`ChannelProbe::DRIVEN` equals `ChannelMatrix::CHANNELS`); "keeps the canaries of the cell out of Partner A's channel" walks entity x channel with client B needles and ids; each channel has a control showing it reaches real data (search, API, activity, selects, UI, downloads, exports, notifications). Notification and mail classes under `app/` each need a declared audience. All pass. |
| 4 | SC3/AUD-02: every non-public route requires authentication, the public routes are an explicit allowlist, and an anonymous request to any other route is refused | VERIFIED | `RouteAuditTest.php` + `RouteInventory`/`RouteRules`: every route (vendor included) has a recognised auth middleware (exact match, not prefix) or exactly one `RouteRules` entry; ghost, stale, double and reasonless entries fail; anonymous request with no header to every non-declared route is 401/403/login redirect; closed routes answer their declared status; no public route answers 500. Two production defects found and fixed with regression tests: guest redirect (`bootstrap/app.php:54` `redirectGuestsTo`) and the Filament export/import download routes closed with 404 (`AccessServiceProvider.php:83-88`). |
| 5 | SC4: the audit suite runs in CI and fails the build when a new screen, route or endpoint is added without an explicit role rule | PRESENT_BEHAVIOR_UNVERIFIED | Present and wired: `phpunit.xml` has the `Audit` testsuite, `tests/Pest.php` binds it to `RefreshDatabase`, `composer.json` has `partner-audit` and `ci` runs it, `.github/workflows/hygiene.yml` runs `Pest` with `--exclude-testsuite=Audit` then a `Partner audit` step inside the `tests` job, and `ci-passed` requires every gated job (`needs: [scan, workflow-lint, tests, static-analysis, dependencies]`, all must be `success`). `CiParityTest` passes. "Fails the build" is demonstrated locally by the planted-difference self-tests, but the hosted run has not been observed (see Human Verification). |
| 6 | Owner sign-off: the owner reviewed the whole matrix and the amendments are recorded (D-02, 12-08) | VERIFIED | `12-MATRIX-REVIEW.md` "Owner decision": Status approved, Date 2026-10-24, items F-6, F-11, F-12, F-14, F-20, F-23 and open questions 1 to 6 answered, nothing deferred; the confirmation line appears in the docblocks of `tests/Support/PartnerMatrix.php:152`, `RouteRules.php:19`, `ChannelMatrix.php:90`. The approval is also stated in the verification request. |
| 7 | Hand-over: later phases are told which declaration to edit, and the text is guarded by a test (12-09) | VERIFIED (with warning IN-05) | `CONTRIBUTING.md` Phase 12 bullet and composer table, `README.md` gate paragraph, `RepositoryFilesTest` "hands over the Partner audit: the Phase 12 bullet names only what exists" passes (56 tests green). The documentation states the audit "proves" that a Partner reaches nothing undeclared, which is stronger than WR-01..WR-04 allow (IN-05). |
| 8 | The full gate is green locally and the planning baselines are untouched (12-09 D3) | VERIFIED | The Audit suite reproduces (592 passed). Claimed `ddev composer ci` result (6878 passed, Pint, PHPStan, licences) was not re-run in full by this verifier; the Audit suite, repository and CI-parity tests were re-run. `git diff --quiet 58c5e93 -- routes/public.php tests/Isolation` succeeds. |

**Score:** 7/8 truths verified (1 present, behavior-unverified)

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `phpunit.xml` | Audit testsuite | VERIFIED | `<testsuite name="Audit">` on `tests/Audit` |
| `tests/Pest.php` | TestCase + RefreshDatabase for Audit | VERIFIED | line 24 `->in('Audit')` |
| `tests/Support/RouteRules.php` | public and closed declarations | VERIFIED | 128 lines, 22 public, 2 closed entries, each with a reason |
| `tests/Support/RouteInventory.php` | discovery, keys, auth recogniser, checker | VERIFIED | 159 lines, used by 3 audit files |
| `tests/Audit/RouteAuditTest.php` | inventory audit and defect tests | VERIFIED | 291 lines, substantive |
| `tests/Support/PartnerMatrix.php`, `PanelInventory.php` | literal screen, endpoint, action and field matrix | VERIFIED | 786 and 375 lines, used by AccessMatrixTest and PartnerActionsTest |
| `tests/Audit/AccessMatrixTest.php`, `PartnerActionsTest.php` | per-row checks with completeness both ways | VERIFIED | 385 and 593 lines |
| `tests/Support/ChannelMatrix.php`, `ChannelProbe.php`, `tests/Audit/ChannelMatrixTest.php` | entity x channel matrix and drivers | VERIFIED | 327, 1514, 434 lines; all eight channels driven |
| `composer.json`, `.github/workflows/hygiene.yml` | `partner-audit` script, CI step | VERIFIED | present; hosted run unobserved |
| `CONTRIBUTING.md`, `README.md`, `.planning/PROJECT.md`, `RepositoryFilesTest` | hand-over text and guard | VERIFIED | guard test passes |
| `12-MATRIX-REVIEW.md` | owner review and decision | VERIFIED | decision section dated 2026-10-24 |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `RouteAuditTest` | `RouteRules` | `RouteInventory::problems(..., RouteRules::publicRoutes(), ...)` | WIRED | line 25 |
| `RouteInventory` | Router | `gatherRouteMiddleware` | WIRED | resolves aliases and groups |
| `bootstrap/app.php` | `Authenticate` middleware | `redirectGuestsTo` | WIRED | line 54, defect 1 test passes |
| `AccessServiceProvider` | Filament export/import routes | `Route::bind('export'/'import')` | WIRED | defect 2 test passes (see WR-05) |
| `AccessMatrixTest` | `PartnerMatrix` | datasets from `panelClasses()`, `apiEndpoints()`, `livewireComponents()` | WIRED | |
| `PartnerActionsTest` | `PartnerMatrix`, `CanaryRegistry` | `abilityRows()`, `CanaryRegistry::fixtures()` | WIRED | |
| `ChannelMatrixTest` | `ChannelMatrix`, `ChannelProbe`, `CanaryRegistry` | `cells()`, `DRIVEN`, `fixtures()` | WIRED | |
| `hygiene.yml` | `phpunit.xml` | `vendor/bin/pest --testsuite=Audit` | WIRED | hosted run unobserved |
| `RepositoryFilesTest` | `CONTRIBUTING.md` | `repoContributingBullet('Phase 12.')` | WIRED | |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| `ChannelProbe` channel blobs | response bodies per channel | real HTTP/Livewire requests against seeded two-client canaries | Yes, each channel has a non-vacuity control | FLOWING |
| `PartnerActionsTest` scene | records per state | domain Actions and canary fixtures inside the test transaction | Yes, "builds every declared record state" | FLOWING |
| `PanelInventory` | panel classes | `Filament::getPanel('admin')` registries plus a scan of `app/Filament` | Yes, "does not pass vacuously" | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Whole Audit suite | `ddev exec vendor/bin/pest --testsuite=Audit` | 592 passed (1249 assertions), 154 s, exit 0 | PASS |
| CI parity and documentation guards | `ddev exec vendor/bin/pest tests/Feature/Repo/CiParityTest.php tests/Feature/Repo/RepositoryFilesTest.php` | 56 passed | PASS |
| Hygiene on the phase files | `scripts/check-sensitive.sh <audit files + phase docs>` | clean | PASS |
| Isolation and public routes untouched | `git diff --quiet 58c5e93 -- routes/public.php tests/Isolation` | exit 0 | PASS |

### Probe Execution

Step 7c: SKIPPED. The phase declares no `scripts/*/tests/probe-*.sh`; its evidence is the Pest Audit suite, run above.

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| US-01 | 12-03, 12-04, 12-08, 12-09 | Roles Admin and Partner behave as specified in the permission matrix | SATISFIED | Truths 1, 2, 6; owner-approved matrix. WR-01/WR-02 limit the proof to the currently declared set. |
| AUD-01 | 12-04, 12-05, 12-06, 12-07, 12-09 | Tests prove a Partner never sees time, rates, prices, finance, internal comments or other clients' data in UI, search, selects, exports, notifications, activity log and API, for every entity | SATISFIED | Truth 3 (entity x channel matrix, 592 tests green) |
| AUD-02 | 12-01, 12-02, 12-03, 12-09 | Route and screen audit confirms every non-public route is authenticated and every Resource, Page, Widget and API endpoint has an explicit role rule | SATISFIED | Truths 1 and 4 |

Every ID in the PLAN frontmatter (US-01, AUD-01, AUD-02) appears in REQUIREMENTS.md (lines 45, 168, 169; traceability lines 244, 326, 327, all "Phase 12"). No requirement maps to Phase 12 in REQUIREMENTS.md without a plan claiming it: no ORPHANED requirement.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| (all phase files) | - | TBD / FIXME / XXX | none found | - |
| `tests/Audit/AccessMatrixTest.php` | 338 | `markTestSkipped` for the test-only row when the canary harness is off | Info | The harness is asserted on by `RouteAuditTest` "runs against the pinned test environment"; no silent skip in the pinned environment |
| `tests/Support/ChannelProbe.php` | 366 | `else { continue; }` for unknown API route parameters | Warning (WR-04) | Not a leak today; the route would still fail `AccessMatrixTest` "declares every /api/v1 route", so the build fails anyway; only the channel walk would skip it |

### Review Warnings Weighed Against the Goal

12-REVIEW.md found 0 critical, 5 warning, 6 info; 12-REVIEW-DISPOSITION.md lists all 11 as `open`. None of the five is a current leak; the question is how firmly the word "proven" is earned.

| ID | Verified against code | Effect on the goal |
| -- | --------------------- | ------------------ |
| WR-01 | Confirmed. `PartnerActionsTest` "declares every isolated entity exactly once" compares only with `CanaryRegistry::fixtures()` (lines 401-412). `User` (`UserPolicy`), `DashboardWidgetPreference` (`DashboardWidgetPreferencePolicy`) and `PersonalAccessToken` have registered policies (`AccessServiceProvider.php:106,132,156`) and no row. Today `UserPolicy` grants nothing and the dashboard policy grants only Partner widgets, and other tests cover them. | No truth fails. A future widening of those policies would not be caught by the Audit suite, which weakens the "no Partner action without a declared rule" claim of the docs. WARNING, human decision. |
| WR-02 | Confirmed. The Admin-only loop asks `viewAny`, `create`, `view`, `update`, `delete` only (lines 556-568); `deleteAny`, `restore`, `restoreAny`, `forceDelete`, `forceDeleteAny`, `reorder`, `replicate` are not declarable in `PartnerMatrix`. No policy overrides them today (checked), so they default-deny. | Same class: forward-looking gap, not a present leak. WARNING, human decision. |
| WR-03 | Pattern misses names such as `billed_at`, `amount`, `fee`. The declared field lists still must equal the runtime lists, so a new field shows in a diff. | Weakens a convenience guard, not the proof. WARNING (advisory). |
| WR-04 | Confirmed (`ChannelProbe.php:366`). Mitigated by the API route completeness test. | Advisory. |
| WR-05 | Confirmed: `Route::bind('export'/'import')` is global by parameter name; the only routes with these parameters today are the two Filament routes. | Latent collision risk for a later report-export route; no effect on the Partner view. Advisory, but worth fixing before Phase 8/9 routes use that name. |
| IN-03 | The `Partner audit` step is skipped when `Pest` fails; the `tests` job still fails, so `CI Passed` is red. | Cosmetic; SC4 holds. |
| IN-05 | The documentation claim is stronger than the suite (see WR-01..WR-04). | Decide with WR-01/WR-02: close the gaps or soften the text. |

### Human Verification Required

#### 1. Hosted CI run of the "Partner audit" step

**Test:** Open a pull request for `base-crm-erp` (or push to `main`) and open the latest "Hygiene" run, job "Tests (PostgreSQL 18)", steps "Pest" and "Partner audit".
**Expected:** "Pest" runs `vendor/bin/pest --exclude-testsuite=Audit`, "Partner audit" runs `vendor/bin/pest --testsuite=Audit`; both green, the four Audit test files listed, "CI Passed" green. Note the duration.
**Why human:** This is a genuine human-only item. The Phase 12 commits are on `origin/base-crm-erp` but no pull request is open and no hosted run exists for that head (newest hosted Hygiene run on the branch is PR 22 from before Phase 12). Triggering one means opening a PR or pushing to `main`, and the verifier does not do that.

#### 2. Disposition of WR-01 and WR-02 (and IN-05)

**Test:** Decide whether to close the policy-registry completeness gap (WR-01) and the unprobed-abilities gap (WR-02) in a follow-up plan before release, or to accept them and soften the README/CONTRIBUTING claim.
**Expected:** The three rows in `12-REVIEW-DISPOSITION.md` move from `open` to fixed or accepted with a reason.
**Why human:** The behaviour is airtight today, so no truth fails; whether "proven" must also cover future policy changes is a scope decision for the owner.

### Gaps Summary

No must-have truth failed. The Partner view is covered for every screen, route and endpoint by a passing, substantive, non-vacuous Audit suite (592 tests re-run by the verifier), the two defects it found were fixed with regression tests, the matrix is owner-approved, and the hand-over documentation is guarded by a test. The phase is not marked `passed` for two reasons: the hosted CI run of the Partner audit step, which closes ROADMAP SC4 end to end, has never been observed and needs a human with a pull request; and five code-review warnings are still `open`, two of which (WR-01, WR-02) limit the audit to the currently declared policy set and abilities and make the documentation's "proves" claim stronger than the suite. Neither is a present leak.

---

_Verified: 2026-10-24T09:00:00Z_
_Verifier: Claude (gsd-verifier)_
