---
phase: "08"
slug: "exchange-rates-and-reports"
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-14"
---

# Phase 8 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Full requirement-to-test map: see `08-RESEARCH.md` section "Validation Architecture".

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5 on PHPUnit 13 (suites Unit, Feature, Arch, Isolation, Concurrency) |
| **Config file** | `phpunit.xml` (PostgreSQL `kokpit_test`, `QUEUE_CONNECTION=sync`) |
| **Quick run command** | `ddev exec vendor/bin/pest --filter=<new test class>` |
| **Full suite command** | `ddev exec vendor/bin/pest` (plus `ddev composer stan`, `lint`, `check-licenses`) |
| **Estimated runtime** | ~300 seconds |

---

## Sampling Rate

- **After every task commit:** Run `ddev exec vendor/bin/pest --filter=<new test class>`
- **After every plan wave:** Run `ddev exec vendor/bin/pest` plus stan and lint
- **Before `/gsd-verify-work`:** Full suite, `check-licenses` and `scripts/check-sensitive.sh` green
- **Max feedback latency:** 60 seconds for the quick run

---

## Per-Task Verification Map

Filled in by the planner from the requirement map in `08-RESEARCH.md` (EX-01..03, RE-01..05).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 08-XX-XX | — | — | EX-01..RE-05 | — | Admin-only access, spreadsheet-safe cells | feature | see RESEARCH | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] New test files for EX-01..03 and RE-01..05 with invented CNB fixtures (no real lists)
- [ ] Deliberate edits of `HealthRegistryTest`, `PanelAccessTest`, `JobContractTest`, `ModelDeclarationTest`, `MorphMap`, policy registrations
- [ ] `composer.json`: `dompdf/dompdf ^3.1`, `openspout/openspout ^4.32`; license alias normalisation in `scripts/check-licenses.php`

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| PDF visual layout and diacritics | RE-03 | Visual judgement | Open generated work report, check Czech characters and page footer |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 60s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
