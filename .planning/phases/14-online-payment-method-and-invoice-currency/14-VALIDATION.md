---
phase: "14"
slug: "online-payment-method-and-invoice-currency"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-23"
---

# Phase 14 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13.3 |
| **Config file** | `phpunit.xml` (suites Unit, Feature, Arch, Isolation, Concurrency; PostgreSQL `kokpit_test`) |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Schema tests/Feature/Operations/BankAccountSettingsTest.php` |
| **Full suite command** | `ddev exec vendor/bin/pest` plus `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` and `ddev exec vendor/bin/pint --test` |
| **Estimated runtime** | ~120 seconds (quick), longer for full suite |

Tests and static analysis run through DDEV only (the host has no DB).

---

## Sampling Rate

- **After every task commit:** Run the narrowest test file for the task through DDEV
- **After every plan wave:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Schema tests/Isolation tests/Arch`
- **Before `/gsd-verify-work`:** Full suite, phpstan and pint green; `scripts/check-sensitive.sh` clean
- **Max feedback latency:** 120 seconds

---

## Per-Task Verification Map

Task IDs are filled in by the planner from the requirement-to-test map in `14-RESEARCH.md` (§ Validation Architecture). Placeholder requirement IDs P14-01..P14-09 until real IDs are registered.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 14-XX-XX | TBD | TBD | P14-01..09 | — | Partner never sees account id or bank rows for `online` | schema / feature / isolation / arch | see RESEARCH.md map | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Invoicing/InvoiceCurrenciesTest.php` — currency list (P14-05)
- [ ] `tests/Arch/BankAccountByIdTest.php` — no `forCurrency(` / currency-based `bank(` callers
- [ ] `tests/Support/InvoiceFixtures.php` helper — document with chosen method and account, two accounts in one currency
- [ ] Update pinned lists in `InvoiceLockGuardTest`, `PartnerSafeColumnsTest`, `InvoiceAdminTablesTest` (fail by design until migrations land)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Live Stripe link in a non-CZK currency | P14-07 | Needs a real Stripe test account | Issue an online EUR invoice in test mode, open the pay URL, confirm currency and amount |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 120s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
