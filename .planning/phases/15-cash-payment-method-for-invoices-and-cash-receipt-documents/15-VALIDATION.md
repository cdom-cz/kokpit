---
phase: "15"
slug: cash-payment-method-for-invoices-and-cash-receipt-documents
status: draft
nyquist_compliant: false
wave_0_complete: false
created: "2026-10-27"
---

# Phase 15 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest ^5.3 (run through DDEV; the host has no DB) |
| **Config file** | `phpunit.xml`, `tests/Pest.php`; CI shard timings in `tests/.pest/shards.json` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptIssueTest.php` |
| **Full suite command** | `ddev exec composer test`, plus `composer partner-audit`, `composer lint`, `composer stan` |
| **Estimated runtime** | quick ~20 s, wave ~3 min, full suite sharded in CI |

---

## Sampling Rate

- **After every task commit:** run the single new or extended test file for the task via `ddev exec`
- **After every plan wave:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Schema tests/Arch tests/Isolation`
- **Before `/gsd-verify-work`:** full `composer ci` green
- **Max feedback latency:** 180 seconds

---

## Per-Task Verification Map

| Req | Behavior | Test Type | Automated Command | File Exists | Status |
|-----|----------|-----------|-------------------|-------------|--------|
| P15-01 | Cash accepted in draft and issued edit; credit note refuses a method; CHECK rejects other values | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/DocumentPaymentTest.php` | extend | ⬜ pending |
| P15-02 | Cash PDF, e-mail and detail print no bank data; DB CHECK ties method to bank snapshot | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Schema/InvoiceLockGuardTest.php` | extend | ⬜ pending |
| P15-03/04 | Card switch off and locked for cash; live link deactivated on change to cash; no link at issue | feature | `ddev exec vendor/bin/pest tests/Feature/Payments/LinkLifecycleTest.php` | extend | ⬜ pending |
| P15-05 | Cash payment on invoice and proforma issues one PPD in the same transaction; transfer and carry issue none; rollback frees the number | feature + concurrency | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptIssueTest.php tests/Concurrency/CashReceiptNumberingConcurrencyTest.php` | ❌ W0 | ⬜ pending |
| P15-06 | Pattern validation, preview, gap-free numbering | unit + concurrency | `ddev exec vendor/bin/pest tests/Unit/Numbering/NumberPatternTest.php tests/Feature/Operations/NumberingTest.php` | extend | ⬜ pending |
| P15-07 | Editable while open; locked after confirmation send; unlock with reason; frozen columns refused with KP001 | schema + feature | `ddev exec vendor/bin/pest tests/Feature/Schema/CashReceiptsTableTest.php tests/Feature/Invoicing/CashReceiptLockTest.php` | ❌ W0 | ⬜ pending |
| P15-08/09 | PDF built from snapshots; attached to the confirmation mail; client without e-mail sends nothing | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptPdfTest.php` | ❌ W0 | ⬜ pending |
| P15-10 | DeletePayment refuses until the PPD is cancelled; cancelled row survives; raw SQL delete of an active PPD payment fails | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/DeletePaymentActionTest.php` | extend | ⬜ pending |
| P15-11 | Partner sees only own PPDs, foreign id is 404, no payment or rate leakage | isolation | `ddev exec vendor/bin/pest tests/Isolation` | extend | ⬜ pending |
| P15-12 | Cancel with reason, STORNO PDF, audit rows, no reason text in the activity log | feature + arch | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptCancelTest.php tests/Arch/ActivityAllowlistTest.php` | ❌ W0 | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Schema/CashReceiptsTableTest.php` — constraints, guard, FK behaviour (P15-05..10)
- [ ] `tests/Feature/Invoicing/CashReceiptIssueTest.php`, `CashReceiptLockTest.php`, `CashReceiptPdfTest.php`, `CashReceiptCancelTest.php`
- [ ] `tests/Concurrency/CashReceiptNumberingConcurrencyTest.php` (pattern of `InvoiceIssueConcurrencyTest`)
- [ ] `tests/Arch/CashReceiptUnlockPathTest.php` (the unlock literal stays out of other files)
- [ ] Cash payment fixtures in `tests/Support/InvoiceFixtures.php` (fictional data only)
- [ ] Update enumerating tests: `ModelDeclarationTest`, `ActivityAllowlistTest`, `MorphMapTest`, `PartnerSafeColumnsTest`, `RouteWalkTest`, `InvoiceEnumsTest`, `NumberingPageTest`

---

## Manual-Only Verifications

All phase behaviors have automated verification. Visual check of the generated PPD PDF is optional during `/gsd-verify-work`.

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 180 s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
