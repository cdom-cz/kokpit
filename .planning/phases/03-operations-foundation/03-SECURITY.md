---
phase: "03"
slug: operations-foundation
status: verified
threats_open: 0
asvs_level: 1
created: "2026-10-08"
---

# Phase 03 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail. Register authored at plan time (19 plans); verified by gsd-security-auditor on 2026-10-08, then T-03-48 closed by a follow-up fix and re-checked (code-level; GitHub and Zerops settings are manual).

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Partner session -> Admin-only surfaces | Settings page, activity overview/history, System page | settings, bank accounts, rates, audit trail, health details |
| Application -> queue worker | Jobs run outside a web session in the system context | job payloads, failure details |
| Application -> mail / database notification | Queue-independent Admin alert for failed jobs | exception class and sanitised first message line |
| Repository -> GitHub Actions -> Zerops | Release/dispatch deploy through the protected production environment | deploy token, build artefact, environment variables |
| Application -> S3-compatible storage | Private bucket, temporary URLs | files, signed URLs, credentials |
| Developer machine -> public repository | Spike code, fixtures, docs | fictional data only (hygiene rule) |

---

## Threat Register

| Threat ID | Category | Component | Severity | Disposition | Mitigation / evidence | Status |
|-----------|----------|-----------|----------|-------------|-----------------------|--------|
| T-03-01 | Information disclosure | QR payload, fixtures, PDF decision record | medium | mitigate | .planning/phases/03-operations-foundation/03-SPIKE-PDF.md:12,42 (account assembled at runtime from fragments, fictional data); `scripts/check-sensitive.sh --all` clean (IBAN mod-97 detector in scripts/lib/scan.awk:69-117); no PDF tracked (`git ls-files` has none) | closed |
| T-03-02 | Tampering | repository tree and application dependencies (PDF spike) | medium | mitigate | spike commits 09e168c and ee043db touch only 03-SPIKE-PDF.md; composer.json/composer.lock changed only by 37c76b7 (03-03) and fadc0d4 (03-17); no package.json in the repo | closed |
| T-03-03 | Elevation of privilege | board move handler, forged Livewire call (spike) | high | mitigate | 03-SPIKE-KANBAN.md:105-107 (forged move of another client card 404, own-client Partner update 403, measured), :197 (ordered guard pattern recorded as Phase 5 consequence); 03-02-SUMMARY.md (mutation run: removing guards failed 2 of 6 tests). Spike-only: no production board code exists in this phase | closed |
| T-03-04 | Tampering | repository tree and dependencies (kanban spike) | medium | mitigate | commits 4e1b408, 3602c59, 5a8ff29 touch only 03-SPIKE-KANBAN.md; composer files unchanged by them (see T-03-02); 03-02-SUMMARY.md self-check | closed |
| T-03-05 | Information disclosure | throwaway PostgreSQL container with trust auth | low | accept | rationale in 03-02-PLAN.md threat model; 03-SPIKE-KANBAN.md:42 (bound to 127.0.0.1, fictional data) and :211 (container stopped and removed) | closed |
| T-03-06 | Information disclosure | settings rows read as Partner (model, package cache) | high | mitigate | app/Domain/Shared/Models/SettingsProperty.php (DeniesPartners, PARTNER_VISIBLE_GROUPS = []); app/Providers/AccessServiceProvider.php (AdminOnlyPolicy registered for SettingsProperty); config/settings.php cache.enabled literal false, no env() call; tests/Support/CanaryRegistry.php SettingsProperty fixture; tests/Feature/Operations/SettingsStorageTest.php (Partner sees 0 rows and MissingSettings; cache-literal test) | closed |
| T-03-07 | Information disclosure | secrets stored in settings payload in plain text | low | accept | no secret-like property in app/Domain/Settings or database/settings (grep); CONTRIBUTING.md:73 requires the package encrypted() list for any future secret setting | closed |
| T-03-08 | Tampering | settings migration under fail-closed scope | medium | mitigate | app/Domain/Settings/SettingsMigration.php (final up() runs migrate() in runAsSystem); all six files in database/settings extend it; SettingsStorageTest 'runs a settings migration in the system context' and 'declares a class extending the system-context base in every file' | closed |
| T-03-09 | Elevation of privilege | SettingsPage::mount() running for a Partner | high | mitigate | app/Filament/Concerns/EnforcesPageAccessRule.php (boot hook abort_unless(AccessRules::allows(static::class) && static::canAccess(), 403); WR-01 fix confirmed); SettingsPage #[AccessRule(Audience::AdminOnly)]; tests/Isolation/PanelAccessTest.php:224-235 (MountProbePage never mounted for a Partner), AccessOverrideMountProbePage fixture; SettingsPageTest:118-141 (403 for three Partner states, no settings query); RouteWalkTest | closed |
| T-03-10 | Tampering | Livewire form state written into settings | medium | mitigate | SettingsPage::form() statePath('data') with a statePath per group; save() calls $this->form->getState() then fillFromFormState() per declared class; ValidatedSettings::fillFromFormState ignores undeclared keys and save() runs Validator::make(...)->validate(); SettingsStorageTest 'ignores keys of a form state that are not declared properties' | closed |
| T-03-11 | Tampering | default hourly rate (rounding, currency) | medium | mitigate | app/Domain/Shared/Money/Money.php fromMajor (RoundingNecessaryException becomes InvalidArgumentException); DefaultsSettings::save() currency tie and regex rule; SettingsGroupsTest:57-107; SettingsPageTest:200-235 | closed |
| T-03-12 | Information disclosure | rates read by a Partner | high | mitigate | inherited, same controls as T-03-06 and T-03-09 (deny-all SettingsProperty scope, Admin-only page with boot-time 403); canary and route-walk tests | closed |
| T-03-13 | Tampering | forced VAT mode or out-of-range due days | medium | mitigate | app/Domain/Settings/Settings/InvoicingSettings.php rules() ('in:non_payer', 'between:0,365') enforced by ValidatedSettings::save(); SettingsGroupsTest:138-190; SettingsPageTest:298-325 | closed |
| T-03-14 | Tampering | partial save across settings groups | medium | mitigate | SettingsPage::save() beginDatabaseTransaction / rollBackDatabaseTransaction / commit, hasDatabaseTransactions() true; SettingsGroupsTest:194 (nothing stored when one group is refused) | closed |
| T-03-15 | Tampering | bank accounts written with a crafted payload | medium | mitigate | BankAccountSettings::rules() (UniqueCurrencies) and save() per-account accountRules (IbanRule, patterns); BankAccount::normalised()/toArray() null hidden fields; BankAccountSettingsTest:256,285,305,325 | closed |
| T-03-16 | Information disclosure | real IBANs or account numbers in tests and docs | medium | mitigate | Iban::compose with runtime fragments (tests/Unit/Settings/IbanTest.php); scanner rules iban and cz-account (scripts/lib/scan.awk:69-117); `scripts/check-sensitive.sh --all` clean | closed |
| T-03-17 | Information disclosure | bank accounts read by a Partner | high | mitigate | inherited, same controls as T-03-06 and T-03-09 | closed |
| T-03-18 | Denial of service | pattern parser on pathological input | medium | mitigate | app/Domain/Settings/Numbering/NumberPattern.php parse(): length cap 32 checked first, one linear scan, no regex over the pattern; tests/Unit/Numbering/NumberPatternTest.php:80-87 (10 000-char input refused as too_long under 50 ms; passed in this audit) | closed |
| T-03-19 | Tampering | preview consuming or duplicating gap-free numbers | high | mitigate | SequenceAllocator::peek() (plain SELECT, no lock, insert or transaction); DocumentNumbering::preview() uses peek, next() asserts a transaction; NumberingTest:70-110,130-166,312-321 (counters unchanged after previews) | closed |
| T-03-20 | Tampering | pattern change rewriting or colliding with issued numbers | medium | mitigate | Declared parts present: scope key follows the reset period (NumberPattern::scopeKey), invoice pattern digits only and 10 characters (invoice_digits, invoice_length). Gap: the scope key ignores pattern shape, so two patterns with the same reset period can write the same string from different counters (e.g. yearly {YY}{NNNN} at 1010 and monthly {YY}{MM}{NN} in October at 10 both give 261010). This is review finding WR-06, deferred to Phase 10 (03-REVIEW-DISPOSITION.md). Disposition is mitigate, so it cannot be treated as accepted residual risk. No invoices exist yet, so nothing can collide today | open — below high threshold (non-blocking) |
| T-03-21 | Tampering | crafted payload on the disabled task pattern or an invalid pattern | medium | mitigate | NumberPatternRule on every pattern field and again in NumberingSettings::rules(); disabled task_pattern field still carries its rule (SettingsPage numberingSection); NumberingPageTest:227 (crafted task pattern refused, stored value unchanged) | closed |
| T-03-22 | Denial of service | live preview re-rendering per keystroke | low | mitigate | SettingsPage::patternInput() ->live(onBlur: true); preview is one peek() SELECT on a pattern of at most 32 characters | closed |
| T-03-23 | Information disclosure | activity_log rows with non-allowlisted or hidden attributes | high | mitigate | app/Domain/Audit/LogsAllowlistedActivity.php (fixed LogOptions: logOnly, logOnlyDirty, dontLogEmptyChanges; throws without a non-empty #[LoggedAttributes]); ActivityLogBehaviourTest:109 (raw JSON of every column searched for canaries), :122, :136 | closed |
| T-03-24 | Repudiation | source label missing or spoofed | low | mitigate | app/Domain/Audit/KokpitLogActivityAction.php sets source from ActivitySource (context only); config/activitylog.php actions.log_activity; migration 2026_10_08_000200 CHECK activity_log_source_check; ActivitySourceTest:162-178 | closed |
| T-03-25 | Information disclosure | later model logging hidden, sensitive or non-existent attributes | high | mitigate | tests/Arch/ActivityAllowlistTest.php + tests/Support/AuditDeclaration.php (sensitive-name denylist, hidden attribute, override, wildcard, path rules; not-vacuous scan guard :44); tests/Feature/Operations/ActivityAllowlistColumnsTest.php (real columns) | closed |
| T-03-26 | Repudiation | audit trail switched off in production | high | mitigate | app/Support/ProductionConfigGuard.php (activitylog.enabled must be strictly true) called from app/Providers/AppServiceProvider.php:26; tests/Unit/Support/ProductionConfigGuardTest.php:68-90 (passed) | closed |
| T-03-27 | Repudiation | activity records pruned by command or schedule | medium | mitigate | app/Domain/Audit/RefusingCleanActivityLogAction.php configured as actions.clean_log; routes/console.php schedules no clean; NoPruningTest:41-69 | closed |
| T-03-28 | Information disclosure | activity overview and history seen by a Partner | high | mitigate | ActivityResource #[AccessRule(AdminOnly)] with EnforcesResourceAccessRule; ActivityHistoryRelationManager with EnforcesRelationManagerAccessRule; Activity model DeniesPartners; AdminOnlyPolicy registered; ActivityViewsTest:105-137,376-455 | closed |
| T-03-29 | Elevation of privilege | relation manager or widget code running for a refused user | high | mitigate | EnforcesRelationManagerAccessRule and EnforcesWidgetAccessRule boot hooks (abort 403 before mount); ActivityViewsTest:376-450 (refused at boot even when visibility was overridden to pass) | closed |
| T-03-30 | Information disclosure | activity properties or non-allowlisted values rendered | medium | mitigate | app/Filament/Support/ActivityPresenter.php renders attribute_changes only; ActivityViewsTest:300 (properties canary never rendered) | closed |
| T-03-31 | Denial of service | loading deleted or heavy subjects per row | low | mitigate | ActivityPresenter never loads the subject; ->with('causer') in ActivityResource and ActivityHistoryRelationManager; ActivityViewsTest:83 (no subject-table query) | closed |
| T-03-32 | Denial of service | alert e-mail flooding | medium | mitigate | AdminAlerter::suppressedSinceLastAlert (Cache::add window, suppressed count); config/kokpit.php alerts.throttle_seconds 900; AdminAlertTest:101-148 | closed |
| T-03-33 | Information disclosure | alert content (payload, trace, long exception message) | high | mitigate | ReportFailedJob builds the alert from job name, queue, attempts, exception class, failed job id and AlertMessageSanitiser::firstLine (WR-02 fix confirmed; hosts, IPs, ports, DSN, quoted values, SQL tails removed) cut to message_max_length 200; AlertMessageSanitiserTest (passed); AdminAlertTest:228,306. See unregistered flag UF-1 | closed |
| T-03-34 | Repudiation | failed job ends silently | high | mitigate | AdminAlerter::deliver uses Notification::sendNow per channel with try/catch; ReportFailedJob::handle catches everything and logs critical; CR-01 fix confirmed: defer($send, always: true) runs on JobAttempted (vendor FoundationServiceProvider:224; Worker.php dispatches JobAttempted in a finally block) after the failed_jobs write, timeout kill delivered inline with MAIL_TIMEOUT bound (config/mail.php:53); FailingJobFlowTest:31-72, AdminAlertTest:274-300 | closed |
| T-03-35 | Tampering | job runs outside the system context | medium | mitigate | app/Domain/Operations/Jobs/KokpitJob.php final middleware() prepends RunsAsSystem; tests/Arch/JobContractTest.php:55 (every dispatchable class extends KokpitJob); QueueContractTest:136 | closed |
| T-03-36 | Repudiation | production running the sync queue | medium | mitigate | ProductionConfigGuard queue.default must be 'redis'; ProductionConfigGuardTest:92-116 (passed) | closed |
| T-03-37 | Tampering | job processed before its transaction commits | medium | mitigate | config/queue.php redis connection 'after_commit' => true; QueueContractTest:110-130 | closed |
| T-03-38 | Information disclosure | System page seen by a Partner | high | mitigate | SystemPage #[AccessRule(AdminOnly)] with EnforcesPageAccessRule; indicators run in a #[Computed] property, never in mount(); SystemPageTest:45-77 (spy indicator proves none runs for a Partner; Livewire 403) | closed |
| T-03-39 | Information disclosure | indicator details exposing connection strings or messages | medium | mitigate | HealthIndicatorRegistry::results() reports $exception::class only; no getMessage() in app/Domain/Operations/Health (grep); SystemPageTest:93; HealthRegistryTest:102 | closed |
| T-03-40 | Denial of service | one failing indicator breaking the page | medium | mitigate | HealthIndicatorRegistry::results() per-indicator try/catch giving Error; HealthRegistryTest:102-123; SystemPageTest:93 | closed |
| T-03-41 | Repudiation | dead worker or scheduler invisible | medium | mitigate | SchedulerHeartbeatIndicator + OldestPendingJobIndicator; routes/console.php schedules kokpit-heartbeat and RecordWorkerHeartbeat every minute; RecordWorkerHeartbeat ShouldBeUnique (WR-04 fix confirmed); HealthIndicatorsTest:118-185,216-360 | closed |
| T-03-42 | Denial of service | heartbeat keys lost on cache flush | low | accept | Cache::forever in app/Domain/Operations/Health/Heartbeats.php; caveat documented in config/kokpit.php:75-77 | closed |
| T-03-43 | Information disclosure | signed URL or credentials printed or logged by the storage check | high | mitigate | app/Domain/Operations/Storage/StorageCheck.php describe() (exception class + AWS error code only), StorageCheckCommand prints step, host, path, error only; StorageCheckTest:131 | closed |
| T-03-44 | Information disclosure | publicly readable bucket or object | high | mitigate | StorageCheck::unsignedRefused() passes only on 401/403 and reports publicly_readable otherwise; StorageCheckTest:96 | closed |
| T-03-45 | Repudiation | silent storage write failure | medium | mitigate | config/filesystems.php:63 's3' 'throw' => true; StorageCheck::filesystem() forces throw; StorageCheckTest:72 (wrong credentials) | closed |
| T-03-46 | Information disclosure | RustFS credentials in the CI workflow | low | accept | .github/workflows/hygiene.yml:138-141 documents the public development default for a job-scoped container; same pair as .env.example:57-58; tests/Feature/Repo/CiParityTest.php | closed |
| T-03-47 | Tampering | script injection through event data in the deploy workflow | high | mitigate | .github/workflows/deploy.yml passes event data only through step env (:42-46,109-117,...); DeployWorkflowTest deployInjectionProblems (no `${{` in run) with mutation self-check; actionlint and zizmor in hygiene.yml workflow-lint job (:65-110) | closed |
| T-03-48 | Elevation of privilege | deploy triggered by another event, prerelease, foreign tag or unreviewed commit | high | mitigate | Fixed by commit 249bd06: deploy.yml refuses a dispatch unless the ref is refs/heads/main or refs/tags/v*, and requires a green "CI Passed" check run for the commit; DeployWorkflowTest runs the real verify steps against refused refs and CI states (see 03-REVIEW-DISPOSITION.md, WR-05). Original audit finding: dispatch from any ref passed verify. GitHub environment policy (required reviewer, branch/tag rule) remains a manual maintainer setting. | closed (fixed after audit) |
| T-03-49 | Information disclosure | deploy token exposure | high | mitigate | deploy.yml: permissions {} (:19), contents read per job, persist-credentials false on both checkouts, token only in the step env of 'Log in to Zerops' (:108-111); DeployWorkflowTest secrets and actions checks (secrets only in deploy job step env); .gitleaks.toml:86-94 rule kokpit-zerops-token. Environment-secret placement is a manual setting (CONTRIBUTING.md:226) | closed |
| T-03-50 | Elevation of privilege | native Zerops Git integration deploying around the approval | high | mitigate | Declared mitigation is the checklist plus documentation test: CONTRIBUTING.md:231 and 03-ZEROPS-REHEARSAL.md prerequisites; RepositoryFilesTest 'documents the manual GitHub and Zerops deploy settings in CONTRIBUTING.md' asserts the 'Git integration' phrase. Setting itself unverifiable from the repository (manual, pending) | closed |
| T-03-51 | Information disclosure | secrets committed in zerops.yml or docs | high | mitigate | zerops.yml holds only ${...} references and non-secret values; ZeropsConfigTest:199-222 (references-only for secret-like keys, no APP_KEY, no base64:); `scripts/check-sensitive.sh --all` clean; gitleaks in CI | closed |
| T-03-52 | Tampering | tampered zcli binary | medium | mitigate | deploy.yml:84-106 ZCLI_VERSION and hard-coded ZCLI_SHA256, `sha256sum --check --strict`; DeployWorkflowTest deployInstallProblems with mutation self-check | closed |
| T-03-53 | Tampering | failed or half-applied migration going live | high | mitigate | zerops.yml: migrate only in the app setup via `zsc execOnce`, readinessCheck `php artisan kokpit:deploy:verify`; app/Console/Commands/DeployVerifyCommand.php refuses pending migrations, unreachable DB and Redis; ZeropsConfigTest:85,143,165; DeployVerifyCommandTest:60-76; backward-compatible migration rule CONTRIBUTING.md:241-242. Rehearsal check 8 pending (manual) | closed |
| T-03-54 | Denial of service | Valkey evicting queue keys | medium | mitigate | CONTRIBUTING.md:235 (volatile-lru or noeviction); RepositoryFilesTest asserts 'maxmemory-policy' and 'volatile-lru'; rehearsal check 5 listed in 03-ZEROPS-REHEARSAL.md (pending, manual) | closed |
| T-03-55 | Spoofing | forged X-Forwarded-* with trustProxies(at: '*') | low | accept | rationale in 03-18-PLAN.md and bootstrap/app.php comment; exposure reduced by WR-07 (only FOR, PORT, PROTO trusted, host ignored); TrustedProxiesTest:53,64; rehearsal check 4 pending | closed |
| T-03-56 | Information disclosure | readiness command printing connection details | low | mitigate | DeployVerifyCommand::check() prints check names and exception class only; DeployVerifyCommandTest:87,107 | closed |
| T-03-57 | Information disclosure | real data in documentation | medium | mitigate | RepositoryFilesTest 'keeps real addresses out of README, SECURITY.md and CONTRIBUTING.md'; `scripts/check-sensitive.sh --all` clean; no e-mail-like string in README.md, SECURITY.md or CONTRIBUTING.md (grep) | closed |
| T-03-58 | Repudiation | documentation drifting from the code | low | mitigate | RepositoryFilesTest 'names every Phase 3 operations mechanism ... and the named class exists' and 'only names Phase 3 classes in CONTRIBUTING.md that exist in app/' | closed |
| T-03-59 | Information disclosure | new tool output committed by accident | low | mitigate | per-phase .gitignore review recorded in 03-19-SUMMARY.md (".gitignore review result": no new tool output, so no new rule and no new test case was required); .gitignore unchanged since Phase 2; `git ls-files` shows no logs, dumps, PDFs or key files | closed |
| T-03-SC | Tampering | package supply chain (all 19 plans) | high for plans 01, 02, 03, 17; low for the rest | mitigate (plans 01, 02, 03, 17); accept (plans 04-16, 18, 19) | Only two packages were added in the phase: spatie/laravel-settings (commit 37c76b7, plan 03-03) and league/flysystem-aws-s3-v3 (commit fadc0d4, plan 03-17); both are rows marked Approved in 03-RESEARCH.md "Package Legitimacy Audit" (:152-153). Spike plans 01 and 02 changed no composer file and ran no npm install (no package.json exists). CI gates present: `composer audit --locked`, `composer validate --strict` and the licence allowlist in .github/workflows/hygiene.yml dependencies job (:226-253), `composer check-licenses` script in composer.json; local run: 206 packages, all licences allowed.  | closed |

*Status: open · closed · open — below high threshold (non-blocking)*
*Severity: critical > high > medium > low — only open threats at or above workflow.security_block_on (high) count toward threats_open*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-03-01 | T-03-05 | Throwaway spike database bound to loopback, fictional data, removed at the end of the spike | plan 03-02 threat model | 2026-10-08 |
| AR-03-02 | T-03-07 | No secret is a setting in this phase; CONTRIBUTING.md requires encrypted() for any future secret setting | plan 03-03 threat model | 2026-10-08 |
| AR-03-03 | T-03-42 | Heartbeats use Cache::forever; a cache flush shows Error for at most one minute and heals itself | plan 03-16 threat model | 2026-10-08 |
| AR-03-04 | T-03-46 | Publicly documented RustFS development default in a job-scoped CI container, identical to .env.example | plan 03-17 threat model | 2026-10-08 |
| AR-03-05 | T-03-55 | App containers reachable only through the Zerops balancer; exposure narrowed to FOR/PORT/PROTO headers by review fix WR-07 | plan 03-18 threat model | 2026-10-08 |
| AR-03-06 | T-03-SC (plans 04-16, 18, 19) | Those plans install no package | plan threat models | 2026-10-08 |

*Non-blocking open threat T-03-20 (pattern change can collide with an already issued number from another pattern with the same reset period) is deferred to Phase 10 (unique constraint on the issued number string); no invoices exist yet.*

---

## Unregistered Flags and Manual Controls

- UF-1 (alert sanitiser input cap): fixed in commit 47d7c86.
- UF-2: controls that exist only as maintainer-side GitHub/Zerops settings and cannot be verified from the repository: production environment with required reviewer and no admin bypass, deployment branch/tag policy and v* tag ruleset, ZEROPS_TOKEN as an environment secret, native Zerops Git integration disabled, Valkey maxmemory-policy, APP_URL and MAIL_* as project variables. Tracked in the CONTRIBUTING.md deploy checklist and 03-ZEROPS-REHEARSAL.md (pending).
- UF-3: code-review items IN-01..IN-04 remain open as informational (see 03-REVIEW-DISPOSITION.md).

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-08 | 60 (59 numbered + T-03-SC) | 58 | 2 (T-03-48 high blocking, T-03-20 medium non-blocking) | gsd-security-auditor |
| 2026-10-08 (after fix 249bd06) | 60 | 59 | 1 (T-03-20 medium, non-blocking, deferred to Phase 10) | orchestrator re-check of the fix evidence |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed (blocking threats at or above high)
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-08 (automated; maintainer-side GitHub/Zerops settings still to be applied manually)
