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

Task IDs filled in by the planner from the requirement-to-test map in `14-RESEARCH.md` (§ Validation Architecture). Requirement IDs P14-01..P14-09 are registered in REQUIREMENTS.md. Every command runs through DDEV (`ddev exec vendor/bin/pest ...`).

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 14-01-01 | 01 | 1 | P14-03 | T-14-01, T-14-11 | account id round-trips the Repeater; the snapshot shape never carries the id | feature (Livewire) | `pest tests/Feature/Operations/BankAccountSettingsTest.php tests/Feature/Operations/SettingsPageTest.php` | ✅ extend (Wave 0 case added) | ⬜ pending |
| 14-01-02 | 01 | 1 | P14-03 | T-14-02 | ids written only on save and by the settings migration, never on read | feature | `pest tests/Feature/Operations/BankAccountSettingsTest.php tests/Feature/Operations/SettingsPageTest.php tests/Feature/Operations/SettingsStorageTest.php` | ✅ extend | ⬜ pending |
| 14-02-01 | 02 | 1 | P14-01, P14-06 | T-14-04, T-14-05, T-14-07 | method open while open, refused when locked; online has no bank snapshot | schema | `pest tests/Feature/Schema/InvoiceTablesTest.php tests/Feature/Schema/InvoiceLockGuardTest.php tests/Isolation/PartnerSafeColumnsTest.php tests/Arch/InvoiceUnlockPathTest.php tests/Feature/Localisation/EnumLabelsTest.php` | ✅ extend (pins updated on purpose) | ⬜ pending |
| 14-02-02 | 02 | 1 | P14-06 | T-14-06, T-14-08 | token set once; switch, token and account frozen by the lock | schema | `pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Isolation tests/Arch` | ✅ rewrite (InvoiceAdminTablesTest, CardSwitchTest) | ⬜ pending |
| 14-03-01 | 03 | 1 | P14-05 | T-14-09 | — | feature | `pest tests/Feature/Invoicing/InvoiceCurrenciesTest.php tests/Feature/Invoicing/CreateDraftModalTest.php` | ❌ W0 (InvoiceCurrenciesTest created first) | ⬜ pending |
| 14-03-02 | 03 | 1 | P14-05 | T-14-09 | issued write path accepts nothing outside the list | feature | `pest tests/Feature/Invoicing` | ✅ extend | ⬜ pending |
| 14-04-01 | 04 | 2 | P14-01, P14-09 | T-14-12, T-14-15 | snapshot copied from settings, id-free | feature | `pest tests/Feature/Invoicing/DocumentPaymentTest.php tests/Feature/Invoicing/DraftLifecycleTest.php` | ❌ W0 (DocumentPaymentTest and InvoiceFixtures helpers created first) | ⬜ pending |
| 14-04-02 | 04 | 2 | P14-04 | T-14-12 | forged or vanished account never printed | feature | `pest tests/Feature/Invoicing` | ✅ extend | ⬜ pending |
| 14-04-03 | 04 | 2 | P14-09 | T-14-13, T-14-14 | online refused and issue blocked when card payment unavailable | feature | `pest tests/Feature/Invoicing tests/Feature/Payments tests/Isolation` | ✅ extend | ⬜ pending |
| 14-05-01 | 05 | 2 | P14-02 | T-14-17 | online PDF prints no bank data, variable symbol or QR | feature | `pest tests/Feature/Invoicing/InvoicePdfVariantsTest.php tests/Feature/Invoicing/InvoicePdfTest.php` | ✅ extend | ⬜ pending |
| 14-05-02 | 05 | 2 | P14-02 | T-14-16, T-14-18 | pay URL only while switch on AND token | feature | `pest tests/Feature/Payments tests/Feature/Invoicing tests/Isolation` | ✅ extend | ⬜ pending |
| 14-06-01 | 06 | 2 | P14-01, P14-02 | T-14-19 | Partner sees the method, no bank rows or variable symbol for online | feature | `pest tests/Feature/Invoicing/PartnerInvoiceResourceTest.php` | ✅ extend | ⬜ pending |
| 14-06-02 | 06 | 2 | P14-01 | T-14-19, T-14-20 | Partner never sees the account id or the token | isolation | `pest tests/Feature/Invoicing tests/Isolation` | ✅ extend (InvoiceLeakTest) | ⬜ pending |
| 14-07-01 | 07 | 3 | P14-07 | T-14-22, T-14-23 | one live link after a method change; token unchanged | feature | `pest tests/Feature/Payments/LinkLifecycleTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php` | ✅ extend | ⬜ pending |
| 14-07-02 | 07 | 3 | P14-06, P14-08 | T-14-21, T-14-24 | locked refuses method, account, switch; forged keys ignored | feature | `pest tests/Feature/Invoicing tests/Feature/Payments` | ✅ rewrite (IssuedHeaderEditTest bank describe) | ⬜ pending |
| 14-07-03 | 07 | 3 | P14-04, P14-07 | T-14-25 | currency change opens the link only after commit | feature | `pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Schema tests/Isolation` | ✅ rewrite (IssuedCurrencyChangeTest) | ⬜ pending |
| 14-08-01 | 08 | 3 | P14-01, P14-04 | T-14-26 | a crafted online value is refused by the Action | feature (Livewire) + end-of-phase human check | `pest tests/Feature/Invoicing/DraftEditorTest.php` | ✅ extend | ⬜ pending |
| 14-08-02 | 08 | 3 | P14-05, P14-09 | T-14-27, T-14-28 | account list only on the Admin editor | feature | `pest tests/Feature/Invoicing tests/Feature/Payments tests/Isolation` | ✅ extend | ⬜ pending |
| 14-09-01 | 09 | 4 | P14-01 | T-14-29 | converted paid invoice has no live link | feature | `pest tests/Feature/Invoicing/ConvertProformaTest.php tests/Feature/Invoicing/ConvertProformaActionTest.php tests/Feature/Payments/CardSwitchTest.php` | ✅ extend | ⬜ pending |
| 14-09-02 | 09 | 4 | P14-07 | T-14-30 | a change during the Stripe call leaves no stale active link | feature | `pest tests/Feature/Invoicing tests/Feature/Payments` | ✅ extend (CreatePaymentLinkTest) | ⬜ pending |
| 14-09-03 | 09 | 4 | P14-08 | T-14-31, T-14-32 | history holds method and switch, never token or account id | feature | `pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Operations/ActivityAllowlistColumnsTest.php tests/Arch tests/Isolation` | ✅ extend | ⬜ pending |
| 14-10-01 | 10 | 5 | P14-04, P14-06 | T-14-33, T-14-34 | the issued editor is advisory; unrelated saves keep the printed account | feature (Livewire) + end-of-phase human check | `pest tests/Feature/Invoicing/IssuedEditorTest.php tests/Feature/Invoicing/IssuedHeaderEditTest.php` | ✅ extend | ⬜ pending |
| 14-10-02 | 10 | 5 | P14-03 | T-14-35 | no currency-to-account lookup outside the preselection | arch | `pest tests/Arch tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Operations tests/Feature/Schema tests/Isolation` | ❌ created in this task (BankAccountByIdTest) | ⬜ pending |
| 14-11-01 | 11 | 6 | P14-03, P14-07 | T-14-37 | documentation names only what exists | repo | `pest tests/Feature/Repo/RepositoryFilesTest.php` | ✅ extend | ⬜ pending |
| 14-11-02 | 11 | 6 | P14-01..P14-09 | T-14-36, T-14-38 | full gate, development database migrated | full suite | `ddev exec vendor/bin/pest` plus Pint, PHPStan, `ddev composer check-licenses`, `php artisan migrate` | ✅ | ⬜ pending |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Invoicing/InvoiceCurrenciesTest.php` — currency list (P14-05) — plan 14-03 Task 1, first step
- [ ] `tests/Arch/BankAccountByIdTest.php` — no currency-based account lookup outside `DocumentPayment::preselect()` — plan 14-10 Task 2 (it can only pass once the last caller moved, so it is written RED-first in that task)
- [ ] `tests/Support/InvoiceFixtures.php` helpers `account()`, `configureAccounts()`, `accountId()`, `online()` — plan 14-04 Task 1, first step
- [ ] Pinned lists in `InvoiceLockGuardTest`, `PartnerSafeColumnsTest`, `InvoiceAdminTablesTest`, `InvoiceUnlockPathTest` updated on purpose — plan 14-02, with the migrations
- [ ] Filament Repeater `Hidden('id')` Livewire round-trip test "keeps the id of an existing account through a Livewire save of the settings form" — plan 14-01 Task 1, first step (owner answer OA-6)

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
