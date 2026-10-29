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
| **Estimated runtime** | ~120 seconds |

---

## Sampling Rate

- **After every task commit (worktree):** `vendor/bin/pint --test` on touched files; PHPStan only if it works without the DB
- **After every plan wave (merged into HEAD branch, DDEV):** quick run command
- **Before `/gsd-verify-work`:** full suite and PHPStan green in DDEV
- **Max feedback latency:** 180 seconds

---

## Per-Task Verification Map

| Ref | Behavior | Test Type | Automated Command | File Exists | Status |
|-----|----------|-----------|-------------------|-------------|--------|
| P17-1 | Checklist section spans full width | feature | `pest tests/Feature/Tasks/TaskChecklistTest.php` | existing, extend | ⬜ pending |
| P17-2 | Description entries carry `fi-prose` | feature | `pest tests/Feature/Tasks/RichTextRenderingTest.php` | ❌ new (17-03 Task 1) | ⬜ pending |
| P17-3 | New task from project view | Livewire | `pest tests/Feature/Projects/ProjectViewActionsTest.php` | ❌ W0 (17-07 Task 1) | ⬜ pending |
| P17-4 | Active-first search ordering | feature | `pest tests/Feature/Search/GlobalSearchTest.php` | existing, extend | ⬜ pending |
| P17-5 | Add time from task | Livewire | `pest tests/Feature/TimeTracking/TaskAddTimeTest.php` | ❌ W0 | ⬜ pending |
| P17-6 | Hidden-on-board flag | feature + isolation | `pest tests/Feature/Tasks/TaskBoardTest.php tests/Isolation/PartnerSafeColumnsTest.php` | existing, extend | ⬜ pending |
| P17-7 | Signal task link | Livewire + schema | `pest tests/Feature/Signal/SignalTaskLinkTest.php` | ❌ W0 | ⬜ pending |
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

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
