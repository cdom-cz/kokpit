---
phase: "06"
slug: time-tracking
status: draft
nyquist_compliant: true
wave_0_complete: false
created: "2026-10-09"
---

# Phase 06 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. The requirement-to-test map lives in `06-RESEARCH.md` § Validation Architecture; the planner refines it into the per-task map below.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5 on PHPUnit 13 (suites Unit / Feature / Arch / Isolation / Concurrency) |
| **Config file** | `phpunit.xml`, `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/TimeTracking tests/Feature/Schema/TimeEntriesTableTest.php tests/Arch tests/Isolation` |
| **Full suite command** | `ddev exec vendor/bin/pest` then `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`, plus `scripts/check-sensitive.sh` |
| **Estimated runtime** | ~30 seconds per task, full suite longer |

---

## Sampling Rate

- **After every task commit:** Run the new test files of that task (each under 30 s; concurrency excluded)
- **After every plan wave:** Run the full suite, pint and phpstan
- **Before `/gsd-verify-work`:** Full suite green, `scripts/check-sensitive.sh` clean
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 06-01-T1 | 01 | 1 | TI-01, TI-03, TI-07 | T-06-01, T-06-02, T-06-03 | Partner reads no entry; StartTimer authorizes first; one running row | feature + isolation + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerActionsTest.php tests/Isolation/CanaryRegistryTest.php tests/Arch/ModelDeclarationTest.php tests/Feature/Schema/SchemaConventionsTest.php tests/Feature/Schema/MorphMapTest.php tests/Arch/QueryEscapeHatchTest.php` | ❌ W0 | ⬜ pending |
| 06-01-T2 | 01 | 1 | TI-08, TI-07 | T-06-03 | Truncated instants, clock-skew clamp, zero-length kept | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerActionsTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-02-T1 | 02 | 2 | TI-01, TI-04 | T-06-04 | Task start derives project and client under lock | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerActionsTest.php` | ❌ W0 | ⬜ pending |
| 06-02-T2 | 02 | 2 | TI-04, TI-07 | T-06-04, T-06-06 | Forged, archived, inconsistent context refused; Partner refused | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerActionsTest.php tests/Feature/TimeTracking/BillableDefaultTest.php` | ❌ W0 | ⬜ pending |
| 06-02-T3 | 02 | 2 | TI-07, TI-05 | T-06-05 | Every constraint by SQLSTATE incl. KP001 and TRUNCATE | schema | `ddev exec vendor/bin/pest tests/Feature/Schema/TimeEntriesTableTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-03-T1 | 03 | 3 | TI-07 | T-06-07, T-06-08 | 8 x 25 parallel starts leave one running row | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/TimerConcurrencyTest.php tests/Feature/TimeTracking/TimerActionsTest.php` | ❌ W0 | ⬜ pending |
| 06-03-T2 | 03 | 3 | TI-07 | T-06-07 | Mutation run sees the race; index backstop holds | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/TimerConcurrencyTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-04-T1 | 04 | 4 | TI-02, TI-03 | T-06-09 | Client-only manual entry, exact seconds | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryActionsTest.php` | ❌ W0 | ⬜ pending |
| 06-04-T2 | 04 | 4 | TI-02, TI-05, TI-07 | T-06-09, T-06-10, T-06-11 | Billed edit/delete refused; context and time rules; overlaps never blocked | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryActionsTest.php` | ❌ W0 | ⬜ pending |
| 06-04-T3 | 04 | 4 | TI-08 | - | H:MM truncation, H:MM:SS, sums from seconds | unit | `ddev exec vendor/bin/pest tests/Unit/TimeTracking/DurationFormatTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-05-T1 | 05 | 5 | TI-05 | T-06-12, T-06-14 | Bulk bill and cancel, one activity row per entry, no description logged | feature + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/BillingLockTest.php tests/Arch/ActivityAllowlistTest.php tests/Feature/Operations/ActivityAllowlistColumnsTest.php tests/Feature/TimeTracking/TimeEntryActionsTest.php` | ❌ W0 | ⬜ pending |
| 06-05-T2 | 05 | 5 | TI-05 | T-06-12 | Eligibility, skipped counts, stale and forged ids | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/BillingLockTest.php` | ❌ W0 | ⬜ pending |
| 06-05-T3 | 05 | 5 | TI-08 | T-06-13 | Rate order task, project, client, default; Partner refused | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/EntryRateResolverTest.php tests/Feature/Localisation/EnumLabelsTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-06-T1 | 06 | 6 | TI-02, TI-03 | T-06-15 | Partner 403 on every time-entries route; not searchable | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryResourceTest.php tests/Isolation/RouteWalkTest.php tests/Arch/PanelRegistryTest.php` | ❌ W0 | ⬜ pending |
| 06-06-T2 | 06 | 6 | TI-02, TI-07 | T-06-16 | Pickers mirror the Action rules; forged state is a field error; Czech order | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/CzechOrderingTest.php tests/Feature/TimeTracking/TimeEntryResourceTest.php` | ❌ W0 | ⬜ pending |
| 06-06-T3 | 06 | 6 | TI-02, TI-04 | T-06-16 | Edit page, overlap warning, billable preset | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryResourceTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-07-T1 | 07 | 7 | TI-05 | T-06-18, T-06-19 | Locked row on every screen; edit URL redirects; bulk actions re-read under lock | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/BillingLockTest.php tests/Feature/TimeTracking/TimeEntryResourceTest.php tests/Feature/Localisation/EnumLabelsTest.php` | ❌ W0 | ⬜ pending |
| 06-07-T2 | 07 | 7 | TI-06 | - | Filters on Prague days, whole-set totals, overlap badge, constant queries | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimeEntryListTest.php` | ❌ W0 | ⬜ pending |
| 06-07-T3 | 07 | 7 | TI-08, TI-05 | T-06-20 | Rate and history Admin-only; delete only unbilled | feature + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/BillingLockTest.php tests/Arch/PanelRegistryTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-08-T1 | 08 | 8 | TI-01 | T-06-21, T-06-22, T-06-23 | Bar only for the Admin; forged mount and update 403; scalar state | feature + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerBarTest.php tests/Arch/LivewireComponentContractTest.php tests/Isolation/PanelAccessTest.php` | ❌ W0 | ⬜ pending |
| 06-08-T2 | 08 | 8 | TI-09, TI-07 | T-06-21 | Long-running state, race and stale toasts | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerBarTest.php` | ❌ W0 | ⬜ pending |
| 06-08-T3 | 08 | 8 | TI-02 | T-06-24 | Running entry completed through UpdateTimeEntry | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimerBarTest.php tests/Arch/LivewireComponentContractTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-09-T1 | 09 | 9 | TI-01 | T-06-25, T-06-26 | Panel only for the Admin; forged mount 403 | feature + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/RecentEntriesPanelTest.php tests/Arch/LivewireComponentContractTest.php` | ❌ W0 | ⬜ pending |
| 06-09-T2 | 09 | 9 | TI-01 | T-06-25 | Own-row preference write, Partner refused | feature + schema | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/RecentEntriesPanelTest.php tests/Feature/TimeTracking/TimerBarTest.php tests/Feature/Schema/SchemaConventionsTest.php` | ❌ W0 | ⬜ pending |
| 06-09-T3 | 09 | 9 | TI-09 | - | Older days, empty state, callout, constant queries | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/RecentEntriesPanelTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-10-T1 | 10 | 10 | TI-01, TI-04 | T-06-28 | One-click start from the Admin task page | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TaskStartAffordancesTest.php tests/Feature/Tasks/TaskResourceTest.php` | ❌ W0 | ⬜ pending |
| 06-10-T2 | 10 | 10 | TI-01 | T-06-27 | Untrusted board card argument checked | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TaskStartAffordancesTest.php tests/Feature/Tasks/TaskBoardTest.php` | ❌ W0 | ⬜ pending |
| 06-10-T3 | 10 | 10 | TI-01 | T-06-28 | No time controls or figures on Partner task surfaces | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TaskStartAffordancesTest.php tests/Isolation/PartnerTaskVisibilityTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-11-T1 | 11 | 11 | TI-06 | T-06-29 | Timesheet Admin-only | feature + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimesheetTest.php tests/Arch/PanelRegistryTest.php` | ❌ W0 | ⬜ pending |
| 06-11-T2 | 11 | 11 | TI-06 | - | Week grid totals | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimesheetTest.php` | ❌ W0 | ⬜ pending |
| 06-11-T3 | 11 | 11 | TI-06 | T-06-30 | DST, midnight, URL fallbacks, constant queries | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/TimesheetTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-12-T1 | 12 | 12 | PR-05 | T-06-31, T-06-32 | Stats Admin-only; panel widget list stays empty | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/ProjectTimeOverviewTest.php tests/Isolation/PanelAccessTest.php tests/Arch/PanelRegistryTest.php` | ❌ W0 | ⬜ pending |
| 06-12-T2 | 12 | 12 | PR-05 | - | Per-task estimate vs actual, Bez úkolu, constant queries | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/ProjectTimeOverviewTest.php` | ❌ W0 | ⬜ pending |
| 06-12-T3 | 12 | 12 | PR-05 | T-06-31 | Project entries tab; Partner 403 | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/ProjectTimeOverviewTest.php tests/Isolation/PartnerProjectVisibilityTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-13-T1 | 13 | 13 | TI-09 | T-06-34, T-06-35 | One bell notice to the owner; job contract and schedule on one server | feature + arch | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/LongRunningTimerTest.php tests/Arch/JobContractTest.php tests/Feature/Operations/ScheduleOnOneServerTest.php tests/Arch/QueryEscapeHatchTest.php` | ❌ W0 | ⬜ pending |
| 06-13-T2 | 13 | 13 | TI-09 | T-06-33, T-06-34, T-06-35 | Exactly once, escaped once, never stops, rollback on failure | feature | `ddev exec vendor/bin/pest tests/Feature/TimeTracking/LongRunningTimerTest.php` then `ddev exec vendor/bin/pest` | ❌ W0 | ⬜ pending |
| 06-14-T1 | 14 | 14 | TI-01..TI-09, PR-05 | T-06-36 | No time, rate or price on any Partner surface; XSS escaped on Admin surfaces | isolation | `ddev exec vendor/bin/pest tests/Isolation` | ❌ W0 | ⬜ pending |
| 06-14-T2 | 14 | 14 | all | T-06-37, T-06-38 | Collation readiness check; documented mechanisms exist | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php tests/Feature/Operations/DeployVerifyCommandTest.php tests/Feature/TimeTracking/CzechOrderingTest.php` | ✅ (edit) | ⬜ pending |
| 06-14-T3 | 14 | 14 | all | T-06-37 | Full gate and sensitive scan | gate | `ddev exec vendor/bin/pest` and `ddev composer check-licenses && bash scripts/tests/test-gitignore.sh && scripts/check-sensitive.sh --all` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Each test file is created by the first task that needs it (no separate scaffold wave); the plan that creates it is named.

- [ ] `tests/Feature/Schema/TimeEntriesTableTest.php` — TI-05, TI-07, TI-08 (plan 06-02)
- [ ] `tests/Concurrency/TimerConcurrencyTest.php` + `tests/Concurrency/timer-worker.php` + `tests/Support/UnlockedTimerLock.php` — TI-07 (plan 06-03)
- [ ] `database/factories/TimeEntryFactory.php` (plan 06-01)
- [ ] `tests/Feature/TimeTracking/*` (plans 06-01 to 06-13) and `tests/Unit/TimeTracking/DurationFormatTest.php` (plan 06-04)
- [ ] `tests/Arch/LivewireComponentContractTest.php` (plan 06-08), `tests/Isolation/TimeLeakTest.php` (plan 06-14)
- [ ] Edits of the existing registries listed in RESEARCH Pitfall 12: `ModelDeclarationTest`, `CanaryRegistryTest`, `CanaryRegistry`, `MorphMap`, `AccessServiceProvider` (06-01); `ActivityAllowlistTest` and activity labels (06-05); `RouteWalkTest` (06-06); `JobContractTest`, `ScheduleOnOneServerTest` (06-13); `RepositoryFilesTest`, CONTRIBUTING (06-14)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Timer bar and recent-entries side panel look and persist across SPA navigation | TI-01 | Visual and SPA behavior | Open the panel, start a timer from a task, navigate between pages, confirm the bar stays and ticks (plan 06-08) |
| Side panel docked at 1440px, overlay at 375px, choice remembered | TI-01 | Layout and responsive behaviour | Plan 06-09 human check |
| Week grid scrolls inside its wrapper at 375px; Czech weekday diacritics | TI-06 | Layout | Plan 06-11 human check |
| Czech copy, contrast of the running state (carried flag F-1), end-to-end walk incl. the forgotten-timer bell | TI-01 to TI-09, PR-05 | Language quality, measured contrast, cross-surface flow | Plan 06-14 human checks |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
