---
gsd_state_version: "1.0"
current_phase: 03
current_phase_name: Operations Foundation
status: executing
stopped_at: Completed 03-09-PLAN.md
last_updated: "2026-10-08T03:31:19.428Z"
last_activity: 2026-10-08
last_activity_desc: Phase 03 execution started
state_head: bcfb705ebfc00b0f71cfca05ed55237f5348c512
progress:
  total_phases: 12
  completed_phases: 2
  total_plans: 43
  completed_plans: 33
  percent: 17
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-10-06)

**Core value:** Tracked time turns into an issued, payable invoice in one pass, with no unbilled time or unpaid invoice ever slipping through unnoticed.
**Current focus:** Phase 03 — Operations Foundation

## Current Position

Phase: 03 (Operations Foundation) — EXECUTING
Plan: 10 of 19
Status: Ready to execute
Last activity: 2026-10-08 — Phase 03 execution started

Progress: [██░░░░░░░░] 17%

## Performance Metrics

**Velocity:**
- Total plans completed: 24
- Average duration: - min
- Total execution time: 0.0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| 01 | 11 | - | - |
| 02 | 13 | - | - |

**Recent Trend:**
- Last 5 plans: -
- Trend: -

*Updated after each plan completion*
**Per-Plan Metrics:**

| Plan | Duration | Tasks | Files |
|------|----------|-------|-------|
| Phase 01 P01 | 7 min | 2 tasks | 10 files |
| Phase 01 P02 | 7 min | 3 tasks | 4 files |
| Phase 01 P03 | 10 min | 2 tasks | 6 files |
| Phase 01 P04 | 5 min | 3 tasks | 5 files |
| Phase 01 P05 | 4 min | 2 tasks | 7 files |
| Phase 01 P06 | 10 min | 3 tasks | 2 files |
| Phase 01 P07 | 5 min | 2 tasks | 4 files |
| Phase 01 P08 | 5 min | 2 tasks | 7 files |
| Phase 01 P09 | 4 min | 2 tasks | 3 files |
| Phase 01 P10 | 11min | 3 tasks | 5 files |
| Phase 01 P11 | 5 min | 3 tasks | 5 files |
| Phase 02 P01 | 4 min | 3 tasks | 51 files |
| Phase 02 P02 | 15 min | 3 tasks | 44 files |
| Phase 02 P03 | 5 min | 2 tasks | 23 files |
| Phase 02 P04 | 11 min | 3 tasks | 24 files |
| Phase 02 P05 | 5 min | 2 tasks | 8 files |
| Phase 02 P06 | 6 min | 3 tasks | 8 files |
| Phase 02 P07 | 3 min | 2 tasks | 5 files |
| Phase 02 P08 | 10 min | 2 tasks | 19 files |
| Phase 02 P09 | 6 min | 3 tasks | 18 files |
| Phase 02 P10 | 8 min | 3 tasks | 35 files |
| Phase 02 P11 | 11 min | 3 tasks | 28 files |
| Phase 02 P12 | 10 min | 2 tasks | 9 files |
| Phase 02 P13 | 7 min | 2 tasks | 5 files |
| Phase 03 P01 | 25 min | 2 tasks | 1 files |
| Phase 03 P02 | 37 min | 3 tasks | 1 files |
| Phase 03 P03 | 8 min | 2 tasks | 14 files |
| Phase 03 P04 | 8 min | 2 tasks | 9 files |
| Phase 03 P05 | 12min | 2 tasks | 11 files |
| Phase 03 P06 | 6 min | 2 tasks | 12 files |
| Phase 03 P07 | 7 min | 2 tasks | 14 files |
| Phase 03 P08 | 9 min | 2 tasks | 14 files |
| Phase 03 P09 | 25 min | 2 tasks | 3 files |

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- [Roadmap]: Planning docs stay uncommitted (`commit_docs: false`) until Phase 1 hygiene tooling exists and passes
- [Roadmap]: Hygiene is Phase 1; Foundation is split into Phase 2 (platform, conventions, isolation harness) and Phase 3 (operations, deploy, storage, spikes)
- [Roadmap]: Stack fixed by owner: PostgreSQL 18, Redis (queue, cache, sessions), RustFS (S3-compatible storage), DDEV for development
- [Roadmap]: Invoicing (Phase 10) and Stripe (Phase 11) stay separate; Stripe only extends the single payment-apply entry point
- [Roadmap]: Files on projects/tasks/comments are built once in Phase 9 (Documents) with Partner visibility rules
- [Phase 01]: Missing-lefthook test installs the hook with a private lefthook copy then deletes it (generated hook bakes in installer binary path, so reducing PATH alone does not simulate absence)
- [Phase 01]: Scanner test libs isolate temp repos from developer git config (GIT_CONFIG_GLOBAL=/dev/null, GIT_CONFIG_NOSYSTEM=1); assert_out_* use fixed-string grep
- [Phase 01]: 01-02: company-id exemptions are path-scoped (.planning/ for 20YYNNNN invoice examples); only 12345678 and 00000000 are global
- [Phase 01]: 01-02: run-in-ubuntu.sh mounts the repository read-only so the container never writes to the host checkout
- [Phase 01]: 01-03: denylist grep uses -a so GNU grep under C.UTF-8 cannot drop hits on invalid UTF-8; in-repo denylist check compares pwd -P directories (sibling name prefix allowed)
- [Phase 01]: 01-03: a path containing a denylist term is printed as the finding path; the term list and line text are never printed
- [Phase 01]: 01-04: test-docs.sh exposes docs_problems <root> so the doc checks are mutation-tested; .gitignore uses .claude/* then !.claude/CLAUDE.md (ignoring .claude/ itself would kill the negation)
- [Phase 01]: 01-05: gitleaks kokpit-home-path allowlist mirrors the shell scanner (adds ddev, Shared); --ignore-gitleaks-allow in hook and CI; only 'gitleaks git' used
- [Phase 01]: Owner chose rewrite-untrack: commit 5554573 dropped from unpushed history, .planning/codebase/ untracked and ignored, on-disk copy sanitised; mapping in 01-06-SUMMARY.md
- [Phase 01]: 01-07: NUL content is scanned twice (as a space and removed) instead of refused; merge commits use --diff-merges=first-parent; the empty tree is hashed without -w so --all stays read-only
- [Phase 01]: Attribute guard is a scanner rule on .gitattributes rows skipped for history rows (line field carries @sha7); rejects -diff, binary and filter=, allows text=auto, eol, diff=<driver>, -text, export-ignore
- [Phase 01]: CI gitleaks step passes --log-opts="--all --diff-merges=first-parent --text --no-textconv"; the hook command is unchanged because gitleaks --pre-commit takes no diff options; the test suite asserts the exact workflow string
- [Phase 01]: 01-09: denylist folds both term list and scanned text with scripts/lib/fold.awk (locale-free), instead of iconv or case-insensitive-only docs
- [Phase 01]: 01-10: Windows home paths are covered in both layers; a drive-letter path under Users is decided by the Windows rule only, and kokpit-home-path allowlists the four system names on the whole match
- [Phase 01]: 01-10: kokpit-home-path and kokpit-windows-home-path capture the name segment as the secret so the default global allowlist cannot drop path-shaped findings; planning invoice exemption narrowed to ^20[23][0-9]{5}$
- [Phase 01]: 01-11: CONTRIBUTING names the five D-05 exempt files exactly; code-owner review stays an unticked owner decision in the settings checklist; IN-01, IN-02, IN-04, IN-05 and IN-06 stay open with stated reasons
- [Phase 01]: UAT test 3 owner policy: the main ruleset does not require code-owner review (solo maintainer cannot approve their own pull request; CODEOWNERS stays a review request, revisit when a second maintainer joins); commit_docs stays off until the scanner run over .planning/ with the real local denylist is clean (UAT test 4), then it may be switched on
- [Phase 02]: 02-01: composer.lock allowlist decision path-scoped; two entries (email, public-ip) scoped to ^composer\.lock$ in scripts/sensitive-allowlist.txt — Owner chose option id path-scoped at the blocking-human checkpoint
- [Phase 02]: 02-01: SPDX licence decision agpl-only; composer.json license is AGPL-3.0-only (plan 02-13 must assert the same value) — Owner chose option id agpl-only; matches the plain GNU AGPL v3 text in LICENSE
- [Phase 02]: 02-01: storage and bootstrap/cache are content-ignored (wildcard, re-include child dir, content-ignore, re-include .gitignore) so a fresh clone gets every placeholder — Directory ignores made composer install fail on a fresh clone
- [Phase 02]: 02-01: config/queue.php keeps only sync and redis connections (default redis); skeleton pao, agent files, README, Node build files and welcome view are not imported — Collisions fixed at the source instead of widening the allowlist
- [Phase 02]: Pint applies declare_strict_types to the whole tree; strict types everywhere for a money and access-control codebase
- [Phase 02]: Optional no-default env() keys of framework configs are documented as commented placeholders in .env.example; only KOKPIT_ADMIN_PASSWORD is allowlisted as unread by config
- [Phase 02]: Database cache store removed from config/cache.php; cache and session default to Redis
- [Phase 02]: Plan 02-03: exempt map holds only migrations.id, failed_jobs.id, sessions.id, password_reset_tokens.email; jobs, job_batches, cache and cache_locks tables dropped (A-OQ1)
- [Phase 02]: Plan 02-03: Role and Permission are HasUuids subclasses registered in config/permission.php; morph aliases live only in MorphMap::MAP, enforced by ModelConventionsServiceProvider; lazy-loading prevention stays off (A-STRICT)
- [Phase 02]: Plan 02-03: schema rules R1-R8 are pure functions with self-checks in tests/Support (PgSchema, ModelRules); 02-04 adds package models to packageModelRegistry() and MorphMap::MAP plus R9
- [Phase 02]: Str::createUuidsUsing(Uuid::uuid7()) makes every framework-generated uuid version 7 (notification ids use Str::uuid(), which is v4)
- [Phase 02]: Final Phase 2 morph map has 8 aliases: user, role, permission, personal_access_token, media, tag, activity, webhook_call; rule R9 checks every stored morph type against it
- [Phase 02]: webhook-client add_attachments upgrade migration not kept; the create stub already has attachments; WEBHOOK_CLIENT_SECRET documented in .env.example
- [Phase 02]: Money::fromExactMinor(string, string) is the single HALF_UP rounding point; forDurations and convert hand it exact rational or decimal strings, so no Brick type is in a public signature and Brick is imported only in App\Domain\Shared\Money
- [Phase 02]: Whole seconds are the duration unit and a negative duration is rejected; Brick failures surface as InvalidArgumentException and integer overflow as OverflowException
- [Phase 02]: Pest arch confinement of vendor namespaces must name full namespaces (Brick\Math, Brick\Money): expect(Brick) passed vacuously with a violating class present
- [Phase 02]: Money column convention: <name>_minor bigint plus <name>_currency char(3) with CHECK ~ ^[A-Z]{3}$, rates numeric(20,10) with decimal:10 cast, MoneyCast over both columns
- [Phase 02]: 02-06: number_sequences row contract is next-value: next_value is the NEXT number to hand out (CHECK next_value >= 1), key format kind:qualifier — Owner decision at the Task 1 checkpoint; importers write the obvious next number, no 0 sentinel
- [Phase 02]: 02-06: SequenceAllocator locks the counter row with SELECT FOR UPDATE inside the caller's transaction, refuses to run outside one, year lives in the key (Europe/Prague), never resets — D-11/D-12; proven by 8x25 parallel processes and a mutation run without the lock (about 61 of 200 rows survive)
- [Phase 02]: 02-07: kokpit_guard_frozen_row() trigger function (SQLSTATE KP001) plus kokpit_refuse_truncate() installed by migration; Immutability builds trigger DDL from validated identifiers only (table names capped at 48 characters) — Database is the only writer-independent place to freeze issued records; proven on a test-only pilot table including a mutation check
- [Phase 02]: 02-07: foreign keys that must answer 23503 use NO ACTION; ON DELETE RESTRICT answers 23001 in PostgreSQL — Plan paired RESTRICT with 23503; found at the RED run, pilot uses NO ACTION and a test pins the 23001 behaviour
- [Phase 02]: Plan 02-08: Filament picker defaults set via default*DisplayFormat on DateTimePicker (covers DatePicker and TimePicker, with and without seconds) instead of displayFormat()
- [Phase 02]: Plan 02-08: generated lang/cs PHP files excluded from Pint by exact path; phpunit.xml pins APP_LOCALE=cs, APP_FALLBACK_LOCALE=en, APP_FAKER_LOCALE=cs_CZ; ICU 76.1 emits U+00A0 as grouping space
- [Phase 02]: Plan 02-09: kokpit:install refuses a second Admin twice (role-agnostic pre-check before any prompt, authoritative check under pg_advisory_xact_lock after findOrCreate of both roles); e-mails lower-cased on install and looked up case-insensitively on reset
- [Phase 02]: Plan 02-09: Admin-only 2FA enforcement is the per-request EnsureAdminHasTwoFactor middleware registered via multiFactorAuthenticationRequiredMiddlewareName; ProductionConfigGuard refuses production unless the setting is boolean true (unit, provider and subprocess tests)
- [Phase 02]: Plan 02-09: Admin role label stays Administrátor per plan although UI-SPEC says Správce; A-1 gap reported (no password reset for a forgotten Admin password); 02-10 must wrap install and reset commands in the system context once PartnerContext exists
- [Phase 02]: Plan 02-10: Partner default-deny lives in the data layer: scoped PartnerContext, fail-closed PartnerScope (WHERE 1 = 0 for every state except Admin, system run and a Partner with a client), DeniesPartners on Media, Tag, Activity, WebhookCall, #[NotPartnerScoped(reason)] on User, Role, Permission, PersonalAccessToken — D-02; arch test forces every model to declare; every console entry point wraps in runAsSystem
- [Phase 02]: Plan 02-10: Admin rule only in KokpitPolicy::before (no app Gate::before); permission package registers its own Gate::before, so no permission named like an ability may be introduced without a decision (threat flag) — Decide before the first permission is added, see 02-10 Threat Flags
- [Phase 02]: Plan 02-11: every Filament Resource, Page, Widget, cluster and relation manager declares #[AccessRule(Audience, reason)] on the class itself; the declaration drives canAccess/canView/canViewForRecord (resources and relation managers AND it with the policy), an undeclared class is denied even to the Admin, and a registry test over the panel and app/Filament fails on any missing declaration (D-03) — Filament pages and widgets default to visible and strict authorization covers Resources only
- [Phase 02]: Plan 02-11: panel runs strictAuthorization with global search off (UI-SPEC A-6); canary Resource is registered only while kokpit.canary_harness is on and the class exists, and ProductionConfigGuard refuses the switch in production (D-04); canary registry has one fixture per PartnerIsolated model and the route walk proves Partner A sees nothing of client B — D-04 canary harness, T-02-41 to T-02-45
- [Phase 02]: Plan 02-11: open items for the owner: spatie Gate::before still runs before KokpitPolicy (no permission named like an ability may be added without a decision); UI-SPEC A-3 Indigo primary colour not applied (provider still Amber); remove three phpstan-ignore trait.unused comments when the first real Resource, Widget and relation manager arrive — Carried forward from 02-10 and UI-SPEC
- [Phase 02]: 02-12: CI composer script is named check-licenses, because Composer skips a script named licenses in favour of the native command
- [Phase 02]: 02-12: CI service images use version tags (postgres:18, redis:7), not digests; ci-passed.needs lists every job and test-workflow.sh enforces it
- [Phase 02]: 02-13: README and RepositoryFilesTest assert exactly the owner's licence id AGPL-3.0-only; docs say composer check-licenses (02-12 rename); a test fails when a documented command or composer script does not exist
- [Phase 02]: 02-13: Gate::before limit of the permission package and the missing Admin password recovery are documented (CONTRIBUTING, README), not fixed
- [Phase 03]: Dompdf chosen as PDF engine for Phase 8 work report and Phase 10 invoice PDF (passed every criterion; Chromium 3x slower, 6x larger, needs Node/Chromium in container); owner confirms before Phase 8 adds the dependency
- [Phase 03]: LGPL-2.1 SPDX alias of dompdf/dompdf fails scripts/check-licenses.php; maintainer decision (normalise to LGPL-2.1-only in a reviewed commit) needed in the phase that adds Dompdf
- [Phase 03]: Custom Livewire wire:sort board chosen over Flowforge for the Phase 5 kanban (D-15): passes persistence, model event, isolation and concurrency criteria; Flowforge has no policy hook and needs a Filament theme and Node build — Measured: no lock corrupts a column in every round; one advisory lock per move kept 60 of 60 rounds clean; column row locks deadlocked in 12 of 70 empty-column rounds. Conditional on a human touch check at 375 px (fallback is a drag handle).
- [Phase 03]: 03-03: SettingsProperty stays on DeniesPartners with an empty PARTNER_VISIBLE_GROUPS allowlist; settings cache is a literal false; SettingsMigration::up() is final and runs migrate() in runAsSystem — A cached read bypasses the Partner scope; package migrator add/update read through the scoped model and see no rows without the system context; a later Partner-visible group must fail the canary test until chosen on purpose
- [Phase 03]: SupplierSettings is no longer final so a test double can subclass and be bound in the container
- [Phase 03]: SettingsPage overrides hasDatabaseTransactions() to true because the Filament transaction helpers are no-ops unless the panel enables them
- [Phase 03]: ValidatedSettings::fillFromFormState() normalises empty text by property nullability and ignores undeclared keys
- [Phase 03]: 03-05: Money::fromMajor refuses excess decimals via BigDecimal::toScale default no-rounding (RoundingNecessaryException mapped to InvalidArgumentException); fromExactMinor stays the single rounding point
- [Phase 03]: 03-05: DefaultsSettings::save() enforces rate currency equals default_currency (error on default_hourly_rate); form shows the rate as text with a decimal comma so the data-layer regex also rejects negatives
- [Phase 03]: 03-05: data-layer rules shared with Filament fields must be ValidationRule classes, not bare closures (Filament injects closure parameters)
- [Phase 03]: 03-06: InvoicingSettings refuses every VAT mode but non_payer and due days outside 0..365 at the data layer; the form shows the payer option disabled
- [Phase 03]: 03-06: enum settings properties must carry no docblock without @var (the cast factory loses the type); Filament enum selects return the case in form state
- [Phase 03]: 03-07: own IBAN validation (32-country length table plus mod-97) behind IbanRule, no library; per-format account rules shared by form and save() via BankAccountSettings::fieldRules
- [Phase 03]: 03-08: pattern drives allocator scope key (kind:YYYY, kind:YYYY-MM, kind:all); task number is fixed KEY-N from task:<project id> without reading settings; two year tokens are duplicate_token; NumberPatternRule carries the 32-character cap with a Czech reason
- [Phase 03]: 03-08: SequenceAllocator::peek() is the only preview path (plain SELECT, no lock, no insert); Phase 10 open items: digits-only proforma and credit-note numbers, unique constraint on the issued number string
- [Phase 03]: 03-09: numbering reset warning compares against the stored settings row (not the in-memory settings object); task pattern field is disabled but still validated so a crafted payload gets the Czech task_fixed error

### Pending Todos

None yet.

### Blockers/Concerns

Product decisions needed before the owning phase is planned:

- [Phase 4]: One Partner account per client, or many-to-many (users to clients)
- [Phase 6]: Policy for overlapping time entries of one user; SPA timer behaviour
- [Phase 8]: Which date selects the CNB rate (issue date, tax-point date or work date)
- [Phase 9]: Partner document visibility default (hidden unless shared)
- [Phase 10]: Credit-note effect on billed entries and sign convention; proforma series and variable symbol

Research flags (run research before planning): Phase 3 (Zerops specifics, PDF and kanban spikes, PHP 8.5 package compatibility, Filament 5 behaviours), Phase 5 (only if the kanban spike fails), Phase 6, Phase 7 (OpenAPI coverage), Phase 10 (Czech invoicing details), Phase 11 (Payment Link API shape, event matrix). Researcher drift: STACK.md is authoritative on package versions.

Housekeeping: `.planning/codebase/` was committed before hygiene tooling and is unreliable; review it in Phase 1 before pushing anything public.

### Quick Tasks Completed

| # | Description | Date | Commit | Directory |
|---|-------------|------|--------|-----------|
| 261008-28i | Remove the hosted product name from all tracked documentation | 2026-10-08 | e943790 | [261008-28i-remove-the-product-name-from-all-tracked](./quick/261008-28i-remove-the-product-name-from-all-tracked/) |

## Deferred Items

Items acknowledged and deferred at milestone close, most recent first:

| Category | Item | Status | Deferred At | Milestone |
|----------|------|--------|-------------|-----------|
| *(none)* | | | | |

## Session Continuity

Last session: 2026-10-08T03:31:19.346Z
Stopped at: Completed 03-09-PLAN.md
Resume file: None
