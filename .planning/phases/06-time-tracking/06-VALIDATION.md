---
phase: "06"
slug: time-tracking
status: draft
nyquist_compliant: false
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
| (filled by the planner) | | | | | | | | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Schema/TimeEntriesTableTest.php` — TI-05, TI-07, TI-08
- [ ] `tests/Concurrency/TimerConcurrencyTest.php` + `tests/Concurrency/timer-worker.php` — TI-07
- [ ] `database/factories/TimeEntryFactory.php`
- [ ] `tests/Feature/TimeTracking/*` and `tests/Unit/TimeTracking/DurationFormatTest.php`
- [ ] `tests/Arch/LivewireComponentContractTest.php`, `tests/Isolation/TimeLeakTest.php`
- [ ] Edits of the existing registries listed in RESEARCH Pitfall 12

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Timer bar and recent-entries side panel look and persist across SPA navigation | TI-01 | Visual and SPA behavior | Open the panel, start a timer from a task, navigate between pages, confirm the bar stays and ticks |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
