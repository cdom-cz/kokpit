---
phase: "10"
slug: "invoicing"
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-16"
---

# Phase 10 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest ^5.3 on PHPUnit ^13.3 (suites Unit, Feature, Arch, Isolation, Concurrency) |
| **Config file** | `phpunit.xml`, `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Invoicing --stop-on-failure` |
| **Full suite command** | `ddev exec vendor/bin/pest` then `ddev exec vendor/bin/pint --test`, `ddev exec composer stan`, `ddev exec composer check-licenses` |
| **Estimated runtime** | ~300 seconds |

---

## Sampling Rate

- **After every task commit:** Run the narrow test file(s) of the task; add `ddev exec vendor/bin/pest tests/Arch` when a model, job, resource or widget was added
- **After every plan wave:** Run `ddev exec vendor/bin/pest --testsuite=Feature,Isolation,Arch` and `ddev exec composer stan`; Concurrency suite on waves touching issuing or reservation
- **Before `/gsd-verify-work`:** Full suite must be green, plus `scripts/check-sensitive.sh`
- **Max feedback latency:** 300 seconds

---

## Per-Task Verification Map

Populated by the planner from the requirement-to-test map in `10-RESEARCH.md` (section Validation Architecture). Each task's `<automated>` command is the source of truth.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 10-XX-XX | — | — | IN-01..IN-10, CL-03, FI-03 | see PLAN threat models | see RESEARCH | feature / schema / concurrency / isolation / arch | `ddev exec vendor/bin/pest <file>` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Support/InvoiceFixtures.php` — builders for draft, issued, paid proforma, credit note, foreign-currency invoice (fictional data only)
- [ ] `tests/Concurrency/invoice-worker.php` + `InvoiceIssueConcurrencyTest.php` + `ReservationConcurrencyTest.php` with mutation twins
- [ ] `tests/Support/PdfText.php` (Ghostscript txtwrite, skips when absent) and `QrDecode.php`
- [ ] Canary registry lines for `Invoice` and `InvoiceItem` (PartnerIsolated) and the Admin-only models
- [ ] Update `tests/Arch/ModelDeclarationTest.php`, `tests/Arch/ActivityAllowlistTest.php`, `MorphMap::MAP`, `DocumentOwnerType::Invoice`
- [ ] Extend `tests/Feature/Repo/RepositoryFilesTest.php` and `CONTRIBUTING.md` hand-over

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Visual PDF layout at 3 sizes | IN-04 | Visual judgement | Open generated invoice PDFs with 1, 15 and 60 lines; check layout, QR placement, diacritics |
| Draft table at 375 px | IN-02 | Responsive visual check | Open the draft editor at phone width; no horizontal page scroll |
| Contrast of accent buttons | IN-04 | Visual judgement | Check button text contrast with the configured accent colour |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 300s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
