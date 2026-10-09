---
phase: "5"
slug: "tasks-and-kanban"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
status: validated
nyquist_compliant: true
wave_0_complete: true
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
- **After every plan wave:** Run `ddev exec vendor/bin/pest` (the last task of every plan runs the full suite), then pint and phpstan; `tests/Unit` holds no files, so it is not named
- **Before `/gsd-verify-work`:** Full suite must be green, plus `scripts/check-sensitive.sh`
- **Max feedback latency:** 120 seconds

---

## Per-Task Verification Map

Refined by the planner to the 17 plans (one plan per wave). Every test file is created by the first task that names it (the tracer task of its plan), so no separate Wave 0 plan is needed.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 05-01-01 | 05-01 | 1 | TA-01, TA-02 | T-05-01, T-05-03 | KEY-N from the project counter; Partner reads only own visible tasks | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskActionsTest.php tests/Isolation/CanaryRegistryTest.php tests/Arch/ModelDeclarationTest.php` | ✅ | ✅ green |
| 05-01-02 | 05-01 | 1 | TA-01 | T-05-02 | Forged people rejected; Partner defaults forced; rollback returns the number | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskActionsTest.php` | ✅ | ✅ green |
| 05-02-01 | 05-02 | 2 | TA-02 | T-05-04 | Project key frozen by trigger KP002, Action and form | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskKeyTest.php` | ✅ | ✅ green |
| 05-02-02 | 05-02 | 2 | TA-01 | T-05-01 | Every tasks constraint by SQLSTATE; Partner-safe column pin | schema + isolation | `ddev exec vendor/bin/pest tests/Feature/Schema/TasksTableTest.php tests/Isolation/PartnerSafeColumnsTest.php` | ✅ | ✅ green |
| 05-02-03 | 05-02 | 2 | TA-02 | T-05-03, T-05-05 | 8 x 25 parallel creations give 1..200; unlocked allocator caught | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/TaskNumberConcurrencyTest.php` | ✅ | ✅ green |
| 05-03-01 | 05-03 | 3 | TA-01, TA-02 | T-05-07, T-05-08 | Quick create and /admin/tasks/KEY-N; Partner 403 on every Admin task route | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskResourceTest.php tests/Isolation/RouteWalkTest.php` | ✅ | ✅ green |
| 05-03-02 | 05-03 | 3 | TA-02 | T-05-06 | Global search finds by key for the Admin, nothing for a Partner | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskResourceTest.php` | ✅ | ✅ green |
| 05-03-03 | 05-03 | 3 | TA-05 | — | Filters, inclusive due range, empty state, stable order | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskListFiltersTest.php` | ✅ | ✅ green |
| 05-04-01 | 05-04 | 4 | TA-01 | T-05-11 | Edit through UpdateTask; locked status change; history | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskUpdateTest.php tests/Arch/ActivityAllowlistTest.php` | ✅ | ✅ green |
| 05-04-02 | 05-04 | 4 | TA-01 | T-05-02 | People pickers limited to the allowed set; forged id refused | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskUpdateTest.php` | ✅ | ✅ green |
| 05-04-03 | 05-04 | 4 | TA-01 | T-05-09, T-05-10 | Strict sanitiser on write and render; no attachments | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/RichTextSanitiserTest.php` | ✅ | ✅ green |
| 05-05-01 | 05-05 | 5 | TA-01 | — | Subtask from the task page with the same counter | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/SubtasksTest.php` | ✅ | ✅ green |
| 05-05-02 | 05-05 | 5 | TA-01 | T-05-12 | No sub-subtask, no cross-project parent | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/SubtasksTest.php tests/Feature/Schema/TasksTableTest.php` | ✅ | ✅ green |
| 05-05-03 | 05-05 | 5 | TA-01 | T-05-13 | Archive guard and locked re-append on restore | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskArchiveTest.php` | ✅ | ✅ green |
| 05-06-01 | 05-06 | 6 | TA-03 | T-05-14 | Checklist edit; Partner reads no item | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskChecklistTest.php tests/Isolation/CanaryRegistryTest.php` | ✅ | ✅ green |
| 05-06-02 | 05-06 | 6 | TA-03 | — | Progress without N+1; item constraints | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskChecklistTest.php` | ✅ | ✅ green |
| 05-07-01 | 05-07 | 7 | TA-06 | T-05-15 | task_billing Admin-only; Partner reads nothing | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBillingTest.php tests/Isolation/CanaryRegistryTest.php` | ✅ | ✅ green |
| 05-07-02 | 05-07 | 7 | TA-06 | T-05-16 | Exact money, bounds, fixed price requirement | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBillingTest.php` | ✅ | ✅ green |
| 05-08-01 | 05-08 | 8 | TA-06 | T-05-17 | Effective billing with sources, Admin page only | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBillingResolverTest.php` | ✅ | ✅ green |
| 05-08-02 | 05-08 | 8 | TA-06 | — | Resolution matrix task, parent, project, client | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBillingResolverTest.php` | ✅ | ✅ green |
| 05-08-03 | 05-08 | 8 | TA-06 | T-05-18 | task_billing constraints; billing audit without the note | schema | `ddev exec vendor/bin/pest tests/Feature/Schema/TaskBillingTableTest.php` | ✅ | ✅ green |
| 05-09-01 | 05-09 | 9 | TA-04 | T-05-19, T-05-21 | Internal flag; Partner scope hides internal rows | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskCommentsTest.php tests/Isolation/CanaryRegistryTest.php` | ✅ | ✅ green |
| 05-09-02 | 05-09 | 9 | TA-04 | T-05-20 | Partner forced non-internal (mutation check); append-only | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskCommentsTest.php` | ✅ | ✅ green |
| 05-10-01 | 05-10 | 10 | KB-01, KB-02, KB-03 | T-05-22, T-05-SC | Move persists; one updated event; Partner 403 on the board | feature | `ddev composer check-licenses && ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php` | ✅ | ✅ green |
| 05-10-02 | 05-10 | 10 | KB-02 | T-05-22, T-05-23 | Filter-aware drops; Done cap; 422/404/403 guard order | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php` | ✅ | ✅ green |
| 05-10-03 | 05-10 | 10 | KB-01 | T-05-24 | Card content; constant queries at 200 cards | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php` | ✅ | ✅ green |
| 05-11-01 | 05-11 | 11 | KB-01, KB-02 | T-05-22 | Per-project board keeps the global order | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php tests/Isolation/RouteWalkTest.php` | ✅ | ✅ green |
| 05-11-02 | 05-11 | 11 | KB-01 | T-05-25 | Preview resolves the task through scope and policy | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskBoardTest.php` | ✅ | ✅ green |
| 05-11-03 | 05-11 | 11 | KB-02 | T-05-23, T-05-26 | Two processes move cards cleanly; unlocked double caught | concurrency | `ddev exec vendor/bin/pest tests/Concurrency/TaskBoardConcurrencyTest.php` | ✅ | ✅ green |
| 05-12-01 | 05-12 | 12 | TA-07, KB-03 | T-05-27, T-05-28 | Partner creates in a visible project; route walk my-tasks | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskResourceTest.php tests/Isolation/RouteWalkTest.php` | ✅ | ✅ green |
| 05-12-02 | 05-12 | 12 | KB-03 | T-05-29 | Pinned Partner builders; no edit or move; cross-client 404 | isolation | `ddev exec vendor/bin/pest tests/Isolation/PartnerTaskVisibilityTest.php` | ✅ | ✅ green |
| 05-12-03 | 05-12 | 12 | TA-07, KB-03 | T-05-27 | Partner XSS canary; empty state; visibility switch; order | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskResourceTest.php` | ✅ | ✅ green |
| 05-13-01 | 05-13 | 13 | TA-07, TA-04 | T-05-31 | Partner comments, forced non-internal | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskCommentsTest.php` | ✅ | ✅ green |
| 05-13-02 | 05-13 | 13 | TA-07 | T-05-30, T-05-32, T-05-42 | Escalation with comment; once; the assignee (also a Partner assignee) or the Admin clears; non-assignee Partner refused | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskEscalationTest.php` | ✅ | ✅ green |
| 05-13-03 | 05-13 | 13 | TA-04 | T-05-31 | Partner comment sanitising; task_comments pin; no internal on Partner surfaces | feature + isolation | `ddev exec vendor/bin/pest tests/Feature/Tasks/PartnerTaskCommentsTest.php tests/Isolation/PartnerSafeColumnsTest.php` | ✅ | ✅ green |
| 05-14-01 | 05-14 | 14 | TA-07 | T-05-33 | Preferences on the profile; Partner bell | feature | `ddev exec vendor/bin/pest tests/Feature/Notifications/NotificationPreferencesTest.php` | ✅ | ✅ green |
| 05-14-02 | 05-14 | 14 | TA-07 | T-05-33, T-05-34, T-05-35 | Own-row writes; private bell; JSON CHECK | feature | `ddev exec vendor/bin/pest tests/Feature/Notifications/NotificationPreferencesTest.php` | ✅ | ✅ green |
| 05-15-01 | 05-15 | 15 | TA-07 | T-05-37 | Admin notified of Partner tasks; no-user render | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskNotificationsTest.php` | ✅ | ✅ green |
| 05-15-02 | 05-15 | 15 | TA-07 | T-05-36, T-05-38 | Comment fan-out; internal never to a Partner; preferences narrow | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskNotificationsTest.php` | ✅ | ✅ green |
| 05-16-01 | 05-16 | 16 | TA-07 | T-05-43 | Escalation notifies the assignee (also a Partner assignee), the Admin only as fallback | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskNotificationsTest.php` | ✅ | ✅ green |
| 05-16-02 | 05-16 | 16 | TA-07 | — | Admin changes notify the Partner side once per save | feature | `ddev exec vendor/bin/pest tests/Feature/Tasks/TaskNotificationsTest.php` | ✅ | ✅ green |
| 05-16-03 | 05-16 | 16 | TA-07 | T-05-39, T-05-40, T-05-43 | Canary leak proof over every Partner notification | isolation | `ddev exec vendor/bin/pest tests/Isolation/NotificationLeakTest.php` | ✅ | ✅ green |
| 05-17-01 | 05-17 | 17 | all | T-05-41 | Documented Phase 5 classes exist | feature | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` | ✅ | ✅ green |
| 05-17-02 | 05-17 | 17 | all | T-05-41 | Full phase gate | full | `ddev exec vendor/bin/pest` | ✅ | ✅ green |

*Status: ✅ green · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Each item is created by the plan that first needs it (tracer-first; no separate Wave 0 plan):

- [ ] `tests/Feature/Schema/TasksTableTest.php` (plan 05-02), `tests/Feature/Schema/TaskBillingTableTest.php` (plan 05-08) — SQLSTATE 23505/23514/23503/23001/23P01, deferred exclusion checked with `SET CONSTRAINTS ... IMMEDIATE`, composite-FK rejections
- [ ] `database/factories/TaskFactory.php` (plan 05-01) — fictional titles built at runtime
- [ ] `tests/Concurrency/task-worker.php` (creation mode plan 05-02, move mode plan 05-11) with `UnlockedSequenceAllocator` and `Tests\Support\UnlockedTaskBoard` for the mutation runs
- [ ] Registry edits per model in the model's plan: `Task` (05-01), `TaskChecklistItem` (05-06), `TaskBilling` (05-07), `TaskComment` (05-09); `ActivityAllowlistTest` (05-04, 05-08); `RouteWalkTest` (05-03 `tasks`, 05-12 `my-tasks`); `PartnerSafeColumnsTest` (05-02 `tasks`, 05-13 `task_comments`); `config/kokpit.php` board block (05-10)
- [ ] Notification tests using `Notification::fake()` plus a no-user render of bodies (plans 05-15, 05-16)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Touch drag at 375 px, including drop into an empty column and scroll conflict | KB-02 | No browser automation in the test stack | Open the board on a phone-width viewport, drag a card across columns and into an empty column (human check in plan 05-11) |
| Livewire SPA navigation away from and back to the board | KB-01 | Browser behaviour | Navigate to a task and back, then drag again (plan 05-11) |
| Czech copy review | TA-07 | Wording judgement | Read all new `lang/cs` strings (plan 05-17) |
| Mail rendering in Mailpit | TA-07 | Visual check | Trigger a Partner comment, an escalation to the Admin and an escalation to a Partner assignee, open the mails (plan 05-16) |
| End-to-end walk as Admin and Partner | all | Whole-flow judgement | Plan 05-17 human check |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 120s (per task-file runs 10-95 s; the full suite takes about 165 s and runs per wave, not per task)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** validated 2026-10-09 (all 32 referenced test files exist and pass: 508 tests; full suite 1982 tests green at the phase gate)

## Validation Audit 2026-10-09

| Metric | Count |
|---|---|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |
