---
phase: "12"
slug: partner-audit
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-24"
---

# Phase 12 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3.3 on PHPUnit 13 |
| **Config file** | `phpunit.xml` (add `Audit` suite), `tests/Pest.php` (bind `Audit` to TestCase + RefreshDatabase) |
| **Quick run command** | `ddev exec vendor/bin/pest --testsuite=Audit` |
| **Full suite command** | `ddev composer ci` |
| **Estimated runtime** | ~120 seconds (Audit suite) |

---

## Sampling Rate

- **After every task commit:** `ddev exec vendor/bin/pest --testsuite=Audit` (plus `tests/Feature/Repo` when `phpunit.xml`, `composer.json`, workflow or docs change)
- **After every plan wave:** `ddev exec vendor/bin/pest`, `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- **Before `/gsd-verify-work`:** `ddev composer ci` green, then `scripts/check-sensitive.sh`
- **Max feedback latency:** 150 seconds

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 12-xx | TBD | TBD | US-01 | — | Every Resource/Page/Widget/relation manager/API endpoint behaves per PartnerMatrix for Admin and Partner | integration | `ddev exec vendor/bin/pest tests/Audit/AccessMatrixTest.php` | ❌ W0 | ⬜ pending |
| 12-xx | TBD | TBD | US-01 | — | Class or endpoint missing from the matrix, or matrix row without a class, fails | completeness | `ddev exec vendor/bin/pest tests/Audit/CompletenessTest.php` | ❌ W0 | ⬜ pending |
| 12-xx | TBD | TBD | AUD-02 | — | Every route is allowlisted, closed or behind auth; anonymous request refused, never 200/419/500 | integration | `ddev exec vendor/bin/pest tests/Audit/RouteAuditTest.php` | ❌ W0 | ⬜ pending |
| 12-xx | TBD | TBD | AUD-01 | — | No foreign or Admin-only canary visible to a Partner in any channel | integration | `ddev exec vendor/bin/pest tests/Audit/ChannelMatrixTest.php` | ❌ W0 | ⬜ pending |
| 12-xx | TBD | TBD | AUD-02 | — | Unauthenticated `/api/v1/*` without Accept is 401; Filament export/import routes 404 | integration | `ddev exec vendor/bin/pest tests/Audit/RouteAuditTest.php --filter=defect` | ❌ W0 | ⬜ pending |
| 12-xx | TBD | TBD | AUD-02 | — | CI workflow and `composer ci` contain the Audit step | repo test | `ddev exec vendor/bin/pest tests/Feature/Repo/CiParityTest.php` | ✅ add case | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Audit/` — AccessMatrixTest, CompletenessTest, RouteAuditTest, ChannelMatrixTest
- [ ] `tests/Support/` — PartnerMatrix, RouteRules, ChannelMatrix, AuditInventory
- [ ] `phpunit.xml` suite `Audit`; `tests/Pest.php` binding
- [ ] `composer.json` scripts `partner-audit`, `test` (exclude Audit), `ci`
- [ ] `.github/workflows/hygiene.yml` separate "Partner audit" step

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Owner sign-off of the Partner matrix | US-01 | The brief is absent (D-02); only the owner can confirm what a Partner may see | Review `PartnerMatrix` content and approve |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 150s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
