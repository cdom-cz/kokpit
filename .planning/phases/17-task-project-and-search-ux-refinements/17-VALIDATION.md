---
phase: "17"
slug: "task-project-and-search-ux-refinements"
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-29"
---

# Phase 17 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13.3 (+ Livewire/Filament testing) |
| **Config file** | `phpunit.xml`, `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Feature/Signal tests/Feature/Search` |
| **Full suite command** | `ddev exec vendor/bin/pest --exclude-testsuite=Audit` plus `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| **Estimated runtime** | ~120 seconds for the full run (an estimate, not a measurement: the runtime of each plan gate and of the phase gate is measured at its first execution and recorded in the plan SUMMARY) |

---

## Sampling Rate

- **After every task commit (worktree):** the task's `php -l` loop and grep checks (host PHP, no shared state); `vendor/bin/pint --test` on touched files where the worktree has `vendor/`; PHPStan only if it works without the DB
- **Per task (merged into HEAD branch, DDEV):** only the named-file Pest command of the task's `<verify>` (single files or a narrow named group, no directory-wide suite, no Pint, no PHPStan)
- **After the last task of each plan (merged into HEAD branch, DDEV):** the plan gate of the plan's `<verification>` section, once
- **After every plan wave (merged into HEAD branch, DDEV):** quick run command
- **Before `/gsd-verify-work`:** the phase gate of 17-08 (`composer ci`), green in DDEV
- **Max feedback latency:** 180 seconds

---

## Gate Commands (plan and phase gates, not per-task)

Directory-wide Pest suites, Pint, PHPStan and `composer ci` run only in these gates, each once, after the named plan's last task is merged into the HEAD branch (and after `ddev exec php artisan migrate` where the plan adds a migration). They are expected to exceed the 30-second per-task feedback target; the full run is estimated at about 120 s and each gate's real runtime is measured at its first execution and recorded in the plan SUMMARY.

| Gate | Plan | Command |
|------|------|---------|
| plan gate | 17-01 | `ddev exec php artisan migrate`, then `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Feature/Schema tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| plan gate | 17-02 | `ddev exec vendor/bin/pest tests/Feature/Documents tests/Isolation/DocumentVisibilityTest.php tests/Isolation/DocumentFinanceLeakTest.php tests/Feature/Tasks/PartnerTaskCommentsTest.php tests/Feature/Tasks/TaskCommentsTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| plan gate | 17-03 | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Isolation/PartnerTaskVisibilityTest.php && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| plan gate | 17-04 | `ddev exec php artisan migrate`, then `ddev exec vendor/bin/pest tests/Feature/Signal tests/Feature/Schema tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| plan gate | 17-05 | `ddev exec vendor/bin/pest tests/Feature/Search tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/TaskChecklistTest.php tests/Feature/Projects tests/Isolation && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| plan gate | 17-06 | `ddev exec vendor/bin/pest tests/Feature/Signal tests/Feature/TimeTracking tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| plan gate | 17-07 | `ddev exec vendor/bin/pest tests/Feature/Projects tests/Feature/Tasks tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |
| phase gate | 17-08 | `ddev exec php artisan migrate`, `ddev exec vendor/bin/pest --exclude-testsuite=Audit --update-shards`, then `ddev exec composer ci` |
| wave check | every wave | the quick run command above |

Plans of one wave share the DDEV test database, so their gates run one after another.

---

## Per-Task Verification Map

| Ref | Behavior | Test Type | Automated Command | File Exists | Status |
|-----|----------|-----------|-------------------|-------------|--------|
| P17-1 | Checklist section spans full width | feature | `pest tests/Feature/Tasks/TaskChecklistTest.php` | existing, extend (17-05 Task 3) | ⬜ pending |
| P17-2 | Description entries carry `fi-prose` | feature | `pest tests/Feature/Tasks/RichTextRenderingTest.php` | ❌ new (17-03 Task 1) | ⬜ pending |
| P17-3 | New task from project view | Livewire | `pest tests/Feature/Projects/ProjectViewActionsTest.php` | ❌ W0 (17-07 Task 1) | ⬜ pending |
| P17-4 | Active-first search ordering | feature | `pest tests/Feature/Search/GlobalSearchTest.php` | existing, extend | ⬜ pending |
| P17-5 | Add time from task | Livewire | `pest tests/Feature/TimeTracking/TaskAddTimeTest.php` | ❌ W0 | ⬜ pending |
| P17-6 | Hidden-on-board flag | feature + isolation | `pest tests/Feature/Tasks/TaskBoardTest.php tests/Isolation/PartnerSafeColumnsTest.php` | existing, extend | ⬜ pending |
| P17-7 | Signal task link (text is the pick-time snapshot; a rename between pick and save does not change it) | Livewire + schema | `pest tests/Feature/Signal/SignalTaskLinkTest.php` | ❌ W0 | ⬜ pending |
| P17-8 | Lightbox and new-window files | feature + isolation | `pest tests/Feature/Documents tests/Isolation/DocumentVisibilityTest.php` | existing, extend | ⬜ pending |
| P17-9 | Project suggestions exclude Done | feature | `pest tests/Feature/Tasks/TaskResourceTest.php tests/Feature/Tasks/PartnerTaskResourceTest.php` | existing, extend | ⬜ pending |
| P17-10 | Project status change from view | Livewire | `pest tests/Feature/Projects/ProjectViewActionsTest.php` | ❌ W0 (17-07 Task 1) | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Signal/SignalTaskLinkTest.php` — P17-7
- [ ] `tests/Feature/TimeTracking/TaskAddTimeTest.php` — P17-5
- [ ] `tests/Feature/Projects/ProjectViewActionsTest.php` — P17-3, P17-10
- [ ] `tests/Feature/Tasks/RichTextRenderingTest.php` — P17-2
- [ ] Update pinned `tasks` column list in `tests/Isolation/PartnerSafeColumnsTest.php`

---

## Manual-Only Verifications

| Behavior | Ref | Why Manual | Test Instructions |
|----------|-----|------------|-------------------|
| Lightbox and `target=_blank` in browsers | P17-8 | Browser behaviour | Open an image and a PDF from a task in Chrome, Safari and Firefox; the PDF opens in a new tab with no popup prompt; after more than 120 s on the open page the PDF tab answers 403 until a reload (accepted, 17-CONTEXT.md "Signed PDF link exception") |
| Prose styling light/dark | P17-2 | Visual | Open a task with lists, headings, a table |
| Type-ahead usability | P17-7 | Interaction | Pick, clear with the cross, save in Signal |
| Add-time picker defaults | P17-5 | Timezone display | On the task view, "Přidat čas": the start picker shows one hour before now and the end picker now, in the panel timezone (Europe/Prague) |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
