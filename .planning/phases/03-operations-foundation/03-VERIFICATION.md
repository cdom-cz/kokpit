---
phase: 03-operations-foundation
verified: 2026-10-08T12:00:00Z
status: passed
score: 5/5 must-haves verified
covered_files:
  - .github/workflows/deploy.yml
  - .planning/phases/03-operations-foundation/03-01-PLAN.md
  - .planning/phases/03-operations-foundation/03-01-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-02-PLAN.md
  - .planning/phases/03-operations-foundation/03-02-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-03-PLAN.md
  - .planning/phases/03-operations-foundation/03-03-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-04-PLAN.md
  - .planning/phases/03-operations-foundation/03-04-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-05-PLAN.md
  - .planning/phases/03-operations-foundation/03-05-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-06-PLAN.md
  - .planning/phases/03-operations-foundation/03-06-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-07-PLAN.md
  - .planning/phases/03-operations-foundation/03-07-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-08-PLAN.md
  - .planning/phases/03-operations-foundation/03-08-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-09-PLAN.md
  - .planning/phases/03-operations-foundation/03-09-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-10-PLAN.md
  - .planning/phases/03-operations-foundation/03-10-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-11-PLAN.md
  - .planning/phases/03-operations-foundation/03-11-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-12-PLAN.md
  - .planning/phases/03-operations-foundation/03-12-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-13-PLAN.md
  - .planning/phases/03-operations-foundation/03-13-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-14-PLAN.md
  - .planning/phases/03-operations-foundation/03-14-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-15-PLAN.md
  - .planning/phases/03-operations-foundation/03-15-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-16-PLAN.md
  - .planning/phases/03-operations-foundation/03-16-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-17-PLAN.md
  - .planning/phases/03-operations-foundation/03-17-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-18-PLAN.md
  - .planning/phases/03-operations-foundation/03-18-SUMMARY.md
  - .planning/phases/03-operations-foundation/03-19-PLAN.md
  - .planning/phases/03-operations-foundation/03-19-SUMMARY.md
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Audit/LogsAllowlistedActivity.php
  - app/Domain/Operations/Alerts/AdminAlerter.php
  - app/Domain/Operations/Alerts/ReportFailedJob.php
  - app/Domain/Operations/Jobs/KokpitJob.php
  - app/Domain/Operations/Storage/StorageCheck.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Pages/SystemPage.php
  - config/activitylog.php
  - config/filesystems.php
  - config/queue.php
  - routes/console.php
  - zerops.yml
covered_digest: "v3:sha256:f99c435048a0afc57daf1e9a223aa517e36bbaf173a6079a4679d478fcd67818"
behavior_unverified: 0
overrides_applied: 0
manual_followups:
  - test: "Zerops rehearsal on a throwaway project (checks 1 to 8 of 03-ZEROPS-REHEARSAL.md)"
    expected: "Ordered app, worker, scheduler deploy after approval; PHP extensions present; env references resolve; HTTPS URLs behind the balancer; Valkey eviction policy; heartbeats OK; storage check green on Zerops storage; a failing migration keeps the previous version serving"
    why_human: "Needs a real Zerops project; the repository can only prove the static contract of zerops.yml and the workflow"
  - test: "GitHub settings checklist (CONTRIBUTING.md, Deploy (maintainer, manual))"
    expected: "production environment with required reviewer and no admin bypass, v* tag rule and tag protection, deployment branch and tag policy, ZEROPS_TOKEN as environment secret, service ids as environment variables, native Zerops Git integration disabled"
    why_human: "Repository and organisation settings cannot be read or enforced from code; every item is still an unchecked box"
  - test: "Touch drag of the kanban spike at 375 px (03-SPIKE-KANBAN.md, Open items)"
    expected: "Dragging a card into another column, including an empty column, works on a real or emulated touch device without fighting page scrolling"
    why_human: "Needs a touch device; the build-custom-board decision is conditional on it (fallback is a drag handle, not Flowforge)"
  - test: "Owner confirmation of both spike decisions (Dompdf, custom wire:sort board)"
    expected: "Owner accepts the two decision records before Phase 8 adds the PDF dependency and before Phase 5 is planned"
    why_human: "Decision sign-off is an owner action recorded as an open item in both spike records"
  - test: "Visual check of the Settings, System and Activity pages in the browser"
    expected: "Czech labels, tabs, live numbering previews, status badges and polling render and read correctly for the Admin; the Partner sees none of them"
    why_human: "Layout and wording quality are not covered by the Livewire component tests"
---

# Phase 3: Operations Foundation Verification Report

**Phase Goal:** The app is operable and deployable: settings, audit trail, background work, health visibility, release deploy and file storage all work and fail loudly rather than silently
**Verified:** 2026-10-08
**Status:** passed (with non-blocking manual follow-ups, listed below and in `manual_followups`)
**Re-verification:** No, initial verification

## Goal Achievement

The verification was done against the code, not the SUMMARY files. In addition the full gate was re-run by the verifier:

- `ddev composer ci`: exit 0. Pint, Larastan, licence check (206 packages, every licence allowed) and 970 Pest tests (4228 assertions) pass.
- `ddev exec vendor/bin/pest --group=s3`: 9 passed (81 assertions) against RustFS.
- `ddev exec php artisan kokpit:storage:check`: all five steps (write, signed read, unsigned read refused, delete, gone) pass.
- `actionlint .github/workflows/deploy.yml` clean; `zizmor --offline .github/workflows` reports no findings (3 suppressed).
- `scripts/check-sensitive.sh` on the phase documents, `CONTRIBUTING.md`, `README.md` and `zerops.yml`: clean.
- No `TBD`, `FIXME` or `XXX` marker, and no `TODO`, `HACK` or `PLACEHOLDER` marker, in the application code, config, database, routes, workflows or `zerops.yml`.

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin edits typed settings in Czech; allowlisted attributes of audited models appear in the activity log, non-allowlisted never do | VERIFIED (mechanism; see note) | Six typed settings groups (`app/Domain/Settings/Settings/{Supplier,Defaults,Invoicing,Numbering,Payment,BankAccount}Settings.php`) with six settings migrations under `database/settings/`. `app/Filament/Pages/SettingsPage.php` has tabs for supplier, bank accounts (Repeater, per currency), invoicing (VAT mode, payment terms, numbering patterns with live previews), defaults (currency, hourly rate) and payments (online-payment toggle), saved in one transaction across all groups, admin-only via `#[AccessRule(Audience::AdminOnly)]` and a boot-time check. Activity log: `LogsAllowlistedActivity` fixes `logOnly(allowlist)`, `logOnlyDirty`, `dontLogEmptyChanges` and throws on a missing or empty `#[LoggedAttributes]`; `config/activitylog.php` routes writes through `KokpitLogActivityAction` (source label) and the pruning command through `RefusingCleanActivityLogAction`. `ActivityLogBehaviourTest` proves create, update, mixed update, delete and no-leak of non-allowlisted or hidden values; `Arch/ActivityAllowlistTest` rejects wildcard, dotted path, JSON path, hidden and sensitive attribute names. |
| 2 | A failing queued job is retried with backoff, lands in failed jobs and raises a queue-independent Admin alert; the System page shows failed jobs, oldest pending job and scheduler heartbeat plus slots for later phases | VERIFIED | `KokpitJob` carries `#[Tries(3)] #[Backoff(10, 60, 300)] #[Timeout(60)]`, `RunsAsSystem` middleware (not droppable, `middleware()` is final). `config/queue.php` default is `redis`. `FailingJobFlowTest` runs a real job on the real Redis queue: 3 attempts, exactly 1 `failed_jobs` row, 1 mail and 1 database notification to the Admin, and asserts the `failed_jobs` row exists before the alert is sent. `OperationalAlert` is neither `ShouldQueue` nor `Queueable`; `AdminAlerter` uses `Notification::sendNow` on `database` and `mail`, throttled through the cache, with `Log::critical` as last resort; delivery failure on every channel leaves the failed-job record intact (tested). `routes/console.php` schedules a scheduler heartbeat and a `RecordWorkerHeartbeat` job every minute. `SystemPage` renders six slots from `HealthIndicatorRegistry`; real indicators exist for failed jobs, oldest pending job and scheduler heartbeat (`HealthIndicatorsTest` covers thresholds, boundaries and a dead worker), `PlaceholderIndicator` fills last rate date, unprocessed webhooks and unsent invoice e-mails with "not available yet" (`HealthRegistryTest`). Partner is refused before any indicator runs (`SystemPageTest`). |
| 3 | A published release or manual dispatch deploys to Zerops through the protected `production` environment; no other trigger deploys; `zerops.yml` has no secrets and covers build, deploy, worker, scheduler and migrations; GitHub settings checklist documented | VERIFIED (static contract; live behaviour is a manual follow-up) | `.github/workflows/deploy.yml`: `on:` is only `release: [published]` and `workflow_dispatch`; `permissions: {}` at top level; `verify` job (no environment, no secret) refuses prereleases, non-`v*` tags, any ref other than `refs/heads/main` or `refs/tags/v*`, commits not ancestors of `origin/main`, and commits without a completed successful `CI Passed` check run; `deploy` job `environment: production`, actions pinned to a commit SHA, `persist-credentials: false`, zcli installed by version with a hard-coded SHA-256, token and service ids only through `env`, pushes app then worker then scheduler. `zerops.yml`: three setups (`app`, `worker`, `scheduler`), `php@8.5` build with `composer install --no-dev`, `zsc execOnce ${ZEROPS_appVersionId} -- php artisan migrate --force` only in `app`, readiness check `php artisan kokpit:deploy:verify` (database, pending migrations, Redis), `/up` health check, worker `queue:work`, scheduler `schedule:work`; only `${...}` references and non-secret literals. `DeployWorkflowTest`, `ZeropsConfigTest` and `DeployVerifyCommandTest` pass in the gate; `CONTRIBUTING.md` "Deploy (maintainer, manual)" lists every manual GitHub and Zerops setting (all boxes unchecked, see follow-ups). |
| 4 | S3-compatible private storage configured by environment variables only; a smoke test uploads a file and fetches it through a temporary URL | VERIFIED | `config/filesystems.php` `s3` disk reads key, secret, region, bucket, endpoint and `AWS_USE_PATH_STYLE_ENDPOINT` from `env()` only and sets `throw => true`. `StorageCheck` (`php artisan kokpit:storage:check`) writes a UUID-named object, reads it back through `temporaryUrl` over HTTP, proves an unsigned read is refused, deletes and confirms it is gone, with cleanup in `finally`. Re-run by the verifier against RustFS: all five steps pass, and `pest --group=s3` passes 9 tests including wrong body, public-object and non-S3-disk failure paths. CI has a RustFS service inside the existing tests job (`hygiene.yml`), `CI Passed` aggregator unchanged. |
| 5 | Spike results are recorded as decisions: PDF engine and kanban library versus custom board | VERIFIED | `.planning/phases/03-operations-foundation/03-SPIKE-PDF.md`: Dompdf chosen by a pre-written decision rule, measured on a 90-row, 4-page report with Czech diacritics on every page, embedded fonts, repeating header, page numbers and a QR that decodes to the exact SPAYD input; consequences for Phases 8 and 10 recorded. `03-SPIKE-KANBAN.md`: custom `wire:sort` board chosen over Flowforge on stated criteria, with the Phase 5 guard pattern and ordering contract; conditional on a touch check. Spike code stayed outside the repository (`git ls-files` shows only the two decision records; `composer.json` carries no PDF or board dependency). Owner confirmation and the touch check remain open items (follow-ups). Queue driver Redis, PostgreSQL 18 and RustFS were already decided and are implemented. |

**Score:** 5/5 truths verified (0 present but behavior-unverified)

Note on truth 1: the ROADMAP wording names tasks, projects, invoices and time entries, but those models do not exist yet (Phases 4, 5, 6 and 10 create them). Plan 03-11 states this explicitly (the list of logging application models is empty in this phase). What Phase 3 owes, and delivers, is the fail-closed allowlist mechanism, proven on a probe model, plus the architecture test that every future logging model must pass. Each of those phases must add `LogsAllowlistedActivity` and `#[LoggedAttributes]` to its model; the architecture test then guards the allowlist but does not by itself force a model to log. Recorded as a planning follow-up, not a gap.

### Behavior-dependent truths

The ordering and cleanup invariants have behavioral tests that were run in the gate rather than being inferred from presence: failed-job row written before the alert (`FailingJobFlowTest`), job dispatched in a transaction invisible until commit and never pushed on rollback (`QueueContractTest`), settings reads in a worker without a user (`QueueContractTest`), storage object removed on every failure path (`StorageCheckTest`), one-transaction rollback of the settings save (`SettingsPageTest`), heartbeat job uniqueness so a dead worker shows as a growing age (`HealthIndicatorsTest`). No truth is left PRESENT_BEHAVIOR_UNVERIFIED.

### Deferred Items

None. Items below are open review findings and later-phase wiring, not unmet must-haves.

| # | Item | Addressed In | Evidence |
|---|------|--------------|----------|
| 1 | Last rate date, unprocessed webhooks, unsent invoice e-mails indicators | Phases 8, 11, 10 | ROADMAP overview: "wired in Phases 8, 10 and 11"; Phase 3 SC 2 only requires the slots |
| 2 | Unique constraint on the issued-number string (review WR-06) | Phase 10 | `03-REVIEW-DISPOSITION.md`; nothing to collide with until invoices exist |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Settings/Settings/*.php`, `database/settings/*.php` | Six typed settings groups with data-layer validation | VERIFIED | Substantive, wired into `SettingsPage::SETTINGS`; Money, IBAN, currency and number-pattern rules in `app/Domain/Settings/` |
| `app/Filament/Pages/SettingsPage.php` | Admin-only Czech settings page | VERIFIED | Five tabs, one transaction, boot-time access rule |
| `app/Domain/Audit/*` | Allowlist trait, attribute, source label, refusing clean action | VERIFIED | Wired through `config/activitylog.php` |
| `app/Filament/Resources/ActivityResource.php`, `app/Filament/RelationManagers/ActivityHistoryRelationManager.php` | Admin activity overview and reusable history | VERIFIED | `ActivityViewsTest` (read-only, filters, Partner refused) |
| `app/Domain/Operations/Jobs/*` | Job base, idempotence declaration, system-context middleware, worker heartbeat | VERIFIED | `Arch/JobContractTest` and `QueueContractTest` |
| `app/Domain/Operations/Alerts/*` | Queue-independent throttled alert with sanitiser | VERIFIED | `AdminAlertTest`, `FailingJobFlowTest` |
| `app/Domain/Operations/Health/*`, `app/Filament/Pages/SystemPage.php` | Six-slot registry and System page | VERIFIED | Three real indicators, three placeholders |
| `app/Domain/Operations/Storage/StorageCheck.php`, `app/Console/Commands/StorageCheckCommand.php` | Storage smoke test command | VERIFIED | Run live by the verifier |
| `zerops.yml`, `.github/workflows/deploy.yml`, `app/Console/Commands/DeployVerifyCommand.php` | Deploy manifest, protected workflow, readiness gate | VERIFIED | Static contract tested; live behaviour pending rehearsal |
| `03-SPIKE-PDF.md`, `03-SPIKE-KANBAN.md` | Decision records | VERIFIED | Decisions present, sign-off pending |
| `CONTRIBUTING.md`, `README.md` | Operations and deploy documentation | VERIFIED | Worker, scheduler, System page, alert mail dependency, storage check, manual settings checklist |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `SettingsPage` | settings classes | `self::SETTINGS` loop in `mount()` and `save()` | WIRED | Same loop reads and writes every group |
| Model events | `activity_log` | `LogsAllowlistedActivity` to `KokpitLogActivityAction` | WIRED | Config binds the action; source label set on every row (`ActivitySourceTest`) |
| `JobFailed` | Admin alert | `ReportFailedJob` to `AdminAlerter` | WIRED | Registered in `OperationsServiceProvider`; deferred until after the `failed_jobs` write (timeout case sent inline, documented) |
| `routes/console.php` | System page | `Heartbeats` cache keys read by the indicators | WIRED | `HealthIndicatorsTest` |
| `SystemPage` | `HealthIndicatorRegistry` | `#[Computed] results()` | WIRED | Indicators run at render, after the boot-time Partner refusal |
| `deploy.yml` `deploy` job | `zerops.yml` | `zcli service push --setup app|worker|scheduler` | WIRED | Setup names match |
| `zerops.yml` readiness check | `DeployVerifyCommand` | `php artisan kokpit:deploy:verify` | WIRED | Command registered, tested |
| `hygiene.yml` `CI Passed` | `deploy.yml` verify step | check-run name filter | WIRED (unproven live) | Never contacted the GitHub API; first rehearsal run confirms the answer shape (fails closed) |
| `s3` disk | `StorageCheck` | `Storage::build` with `throw => true` | WIRED | Passes against RustFS |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| `SystemPage` failed-jobs row | failed jobs count | `app('queue.failer')->count()` | Yes (real `failed_jobs` table) | FLOWING |
| `SystemPage` oldest-pending row | job age | Redis queue record creation time | Yes (Redis ZSET; reports Not available on a non-Redis connection, never Ok) | FLOWING |
| `SystemPage` scheduler row | last heartbeat | cache key written by the scheduled closure | Yes | FLOWING |
| `SystemPage` rate, webhook, e-mail rows | none | `PlaceholderIndicator` | Intentional "not available yet" slots for Phases 8, 10, 11 | STATIC (by design) |
| `SettingsPage` forms | form state | `app($class)->toFormState()` over `settings` table | Yes | FLOWING |
| `ActivityResource` | rows | `activity_log` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full gate | `ddev composer ci` | exit 0; 970 tests, 4228 assertions | PASS |
| S3 group against RustFS | `ddev exec vendor/bin/pest --group=s3` | 9 passed | PASS |
| Storage smoke test | `ddev exec php artisan kokpit:storage:check` | five of five steps pass | PASS |
| Workflow lint | `actionlint .github/workflows/deploy.yml`; `zizmor --offline .github/workflows` | clean; no findings | PASS |
| Sensitive-content scan | `scripts/check-sensitive.sh` on phase docs, README, CONTRIBUTING, `zerops.yml` | clean | PASS |

### Probe Execution

Step 7c: SKIPPED. No probe scripts are declared by the phase plans (`scripts/*/tests/probe-*.sh` does not exist; the shell tests under `scripts/tests/` belong to Phase 1 and were not touched by Phase 3).

### Requirements Coverage

Every requirement ID of the phase appears in at least one PLAN `requirements:` field and in REQUIREMENTS.md (marked Complete, mapped to Phase 3). No requirement maps to Phase 3 without a claiming plan, so there are no orphans.

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| FND-07 | 03-03 to 03-09, 03-19 | Typed settings (supplier, bank accounts per currency, VAT mode, default rate and currency, payment terms, numbering patterns, online-payment toggle) | SATISFIED | Truth 1. VAT mode is limited to `non_payer`, which matches REQUIREMENTS (VAT-payer mode is PLT-01, out of the milestone; IN-08 prepares the model) |
| FND-08 | 03-10, 03-11, 03-12, 03-19 | Activity log with explicit attribute allowlist | SATISFIED (mechanism) | Truth 1 and note; audited models are added by their own phases |
| FND-09 | 03-13, 03-14, 03-16, 03-19 | Redis queue, worker and scheduler; job base with retries, backoff, idempotence; visible failure with queue-independent alert | SATISFIED | Truth 2 |
| FND-10 | 03-15, 03-16, 03-19 | Admin System page with six indicators | SATISFIED | Truth 2; three live, three slots by design (ROADMAP overview) |
| FND-15 | 03-18 | `zerops.yml` and protected deploy workflow, manual settings checklist | SATISFIED (static); live rehearsal and GitHub settings pending | Truth 3 |
| FND-16 | 03-17, 03-19 | S3-compatible private storage by environment, smoke test with temporary URL | SATISFIED | Truth 4 |
| FND-19 | 03-01, 03-02 | PDF and kanban spikes recorded as decisions | SATISFIED | Truth 5; owner sign-off pending |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| (none) | - | Debt markers (`TBD`, `FIXME`, `XXX`) in phase code | none | Scan found none |
| `app/Domain/Operations/Health/Indicators/PlaceholderIndicator.php` | - | Static "not available yet" result | Info | Intentional slot for Phases 8, 10, 11; success criterion 2 asks for exactly this |
| `app/Domain/Operations/Alerts/AdminAlerter.php` | - | Throttle window is claimed before delivery; if every channel fails, further alerts of that class are suppressed for the window (review IN-02, open) | Warning | The failure is still in `failed_jobs`, the log (`Log::critical`) and on the System page; only repeat alerts are lost. No fallback recipient when no Admin exists (logged critical) |

### Open review findings (from 03-REVIEW-DISPOSITION.md)

The critical finding (CR-01) and the warnings WR-01 to WR-05, WR-07 and WR-08 are fixed and tests exist. Verified in the code: the deferred alert (`defer(..., always: true)` in `ReportFailedJob`), the ref guard and CI gate in `deploy.yml`. Open and not blocking any success criterion:

- WR-06 deferred to Phase 10 (unique constraint on issued numbers).
- IN-01 supplier rules hardening, IN-02 fallback alert recipient and throttle release, IN-03 single business time zone definition, IN-04 readiness check should cover the session connection and a falsy Redis ping.

### Human Verification Required (non-blocking follow-ups)

No success criterion depends on these for confirmation from code and tests; they confirm behaviour that only exists outside the repository. Status stays `passed`, as agreed with the orchestrator.

#### 1. Zerops rehearsal

**Test:** Work through checks 1 to 8 of `.planning/phases/03-operations-foundation/03-ZEROPS-REHEARSAL.md` on a throwaway Zerops project.
**Expected:** Ordered deploy after approval, required PHP extensions (`redis`, `pdo_pgsql`, `intl`, `bcmath`, `gd`, `zip`), resolved `${db_*}`, `${redis_*}`, `${storage_*}` references, HTTPS URLs behind the balancer, safe Valkey eviction policy, both heartbeats OK, green storage check, and a failing migration leaving the previous version serving.
**Why human:** Needs a real Zerops project. Open risk from this verification: the exact timing of `deploy.readinessCheck` relative to `initCommands` and the assumed reference names are unproven until then.

#### 2. GitHub settings checklist

**Test:** Apply and tick the items under "Deploy (maintainer, manual)" in `CONTRIBUTING.md`.
**Expected:** `production` environment with required reviewer and no admin bypass; `v*` tag rule and protection; deployment branch and tag policy; `ZEROPS_TOKEN` as an environment secret; three service ids as environment variables; native Zerops Git integration off.
**Why human:** Settings of the repository and organisation cannot be read or enforced from code. The workflow's `verify` checks are defence in depth only (the file of the dispatched ref is what runs); the environment policy is the real boundary.

#### 3. Kanban touch check

**Test:** Drag cards, including into an empty column, on a real or emulated touch device at 375 px.
**Expected:** Works without fighting page scrolling.
**Why human:** Needs a touch device; the custom-board decision is conditional on it.

#### 4. Owner sign-off of the spike decisions

**Test:** Review `03-SPIKE-PDF.md` and `03-SPIKE-KANBAN.md`.
**Expected:** Owner confirms Dompdf and the custom board before Phase 8 adds the dependency and before Phase 5 is planned.
**Why human:** Recorded as an open item in both records.

#### 5. Visual check of the admin pages

**Test:** Open Settings, System and Activity as Admin, then as Partner.
**Expected:** Czech wording and layout read correctly, previews and badges render, polling refreshes, the Partner gets 403.
**Why human:** Visual quality is outside what the component tests assert.

### Gaps Summary

No gaps. All five success criteria are met by code that exists, is wired and passes its tests, and the full gate and the S3 group were re-run by the verifier. The remaining items are manual controls that the phase plans themselves classify as non-blocking (Zerops rehearsal, GitHub settings, touch check, owner sign-off, visual check), plus open informational review findings and one planning note for later phases: tasks, projects, invoices and time entries must adopt the activity allowlist when they are created (FND-08 mechanism is in place).

---

_Verified: 2026-10-08_
_Verifier: Claude (gsd-verifier)_
