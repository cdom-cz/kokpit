---
phase: "5"
slug: "tasks-and-kanban"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-08"
---

# Phase 5 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3.0 on PHPUnit 13 |
| **Config file** | `phpunit.xml`, `tests/Pest.php` (test DB `kokpit_test`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `KOKPIT_CANARY_HARNESS=true`) |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Isolation tests/Arch` |
| **Full suite command** | `ddev exec vendor/bin/pest` (concurrency tests spawn real processes), plus `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| **Estimated runtime** | quick ~60 seconds, full several minutes |

---

## Sampling Rate

- **After every task commit:** Run the new test files of that task with `ddev exec vendor/bin/pest <files>` (each file under 30 s, concurrency files excluded)
- **After every plan wave:** Run `ddev exec vendor/bin/pest tests/Feature tests/Isolation tests/Arch tests/Unit`, then pint and phpstan
- **Before `/gsd-verify-work`:** Full suite must be green, plus `scripts/check-sensitive.sh`
- **Max feedback latency:** 120 seconds

---

## Per-Task Verification Map

Seeded from RESEARCH.md `## Validation Architecture`; the planner and executor refine task IDs.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 5-XX-XX | TBD | TBD | TA-01 | — | Forged people rejected; subtask depth enforced by DB | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskActionsTest.php tests/Feature/Schema/TasksTableTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | TA-02 | — | No gaps or duplicates in parallel; key frozen by trigger | concurrency + feature | `ddev exec vendor/bin/pest tests/Concurrency/TaskNumberConcurrencyTest.php tests/Feature/Tasks/TaskKeyTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | TA-03 | — | Checklist Admin-only | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskChecklistTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | TA-04 | XSS / internal leak | Partner comment forced non-internal; sanitiser canary | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskCommentsTest.php tests/Feature/Tasks/RichTextSanitiserTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | TA-05 | — | Filters by client, project, status, priority, assignee, tag, due date | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskListFiltersTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | TA-06 | Partner price leak | task_billing never loaded for Partner; resolver order | schema + unit | `ddev exec vendor/bin/pest tests/Feature/Schema/TaskBillingTableTest.php tests/Feature/Tasks/TaskBillingResolverTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | TA-07 | Internal comment leak | Partner cannot set status/priority/assignee; internal never notifies Partner | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskResourceTest.php tests/Feature/Tasks/TaskNotificationsTest.php tests/Feature/Tasks/NotificationPreferencesTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | KB-01 | — | Columns by status, Done cap from config | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | KB-02 | Forged move | Move persists status and position; forged status rejected | feature + concurrency | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php tests/Concurrency/TaskBoardConcurrencyTest.php` | ❌ W0 | ⬜ pending |
| 5-XX-XX | TBD | TBD | KB-03 | Cross-client leak | Partner 403 on board; read-only list; canary absent | isolation | `ddev exec vendor/bin/pest tests/Isolation` | partial | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Schema/TasksTableTest.php`, `tests/Feature/Schema/TaskBillingTableTest.php` — SQLSTATE 23505/23514/23503/23P01, deferred commit-time exclusion, composite-FK rejections
- [ ] `database/factories/TaskFactory.php` — fictional titles built at runtime
- [ ] `tests/Concurrency/` task worker for creation and moves, plus no-lock doubles for mutation runs
- [ ] Registry edits (MorphMap, AccessServiceProvider, ModelDeclarationTest, ActivityAllowlistTest, CanaryRegistryTest, CanaryRegistry fixtures, RouteWalkTest, PartnerSafeColumnsTest, lang/cs, config/kokpit.php)
- [ ] Notification tests using `Notification::fake()` plus a no-user render of bodies

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Touch drag at 375 px, including drop into an empty column and scroll conflict | KB-02 | No browser automation in the test stack | Open the board on a phone-width viewport, drag a card across columns and into an empty column |
| Livewire SPA navigation away from and back to the board | KB-01 | Browser behaviour | Navigate to a task and back, then drag again |
| Czech copy review | TA-07 | Wording judgement | Read all new `lang/cs` strings |
| Mail rendering in Mailpit | TA-07 | Visual check | Trigger Partner comment and escalation, open the mails |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
