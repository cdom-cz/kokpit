---
phase: "07"
slug: "rest-api"
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-14"
---

# Phase 7 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13 |
| **Config file** | `phpunit.xml`, `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Api` |
| **Full suite command** | `ddev exec vendor/bin/pest` |
| **Estimated runtime** | ~120 seconds |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec vendor/bin/pest tests/Feature/Api` (plus the touched arch/isolation file)
- **After every plan wave:** Run `ddev exec vendor/bin/pest`, `ddev exec vendor/bin/pint --test` and `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- **Before `/gsd-verify-work`:** Full suite green, `composer check-licenses` green, `scripts/check-sensitive.sh` green
- **Max feedback latency:** 180 seconds

---

## Per-Task Verification Map

Filled by the planner from the "Phase Requirements to Test Map" in `07-RESEARCH.md` (AP-01, AP-02, AP-04, AP-05).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 07-01-01 | 01 | 1 | AP-01 | — | Invalid, revoked or Partner token gets 401 | feature | `ddev exec vendor/bin/pest tests/Feature/Api/AuthenticationTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Api/*` — stubs for AP-01, AP-02, AP-04, AP-05
- [ ] `tests/Support/ApiToken.php` — shared fixture (Admin, token with chosen abilities, entries)
- [ ] `tests/Isolation/ApiAccessTest.php` and a canary line for API access
- [ ] `tests/Concurrency/IdempotencyConcurrencyTest.php` plus a worker script
- [ ] Update exact-list tests: `ModelDeclarationTest`, `JobContractTest`, `ScheduleOnOneServerTest`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Token screen look and feel | AP-01 | Visual contract in `07-UI-SPEC.md` | Open the token screen as Admin, create and revoke a token |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
