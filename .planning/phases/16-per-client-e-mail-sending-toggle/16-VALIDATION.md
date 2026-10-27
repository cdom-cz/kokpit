---
phase: "16"
slug: "per-client-e-mail-sending-toggle"
# status lifecycle: draft (seeded by plan-phase) → validated (set by validate-phase §6)
# audit-milestone §5.5 distinguishes NOT-VALIDATED (draft) from PARTIAL (validated + nyquist_compliant: false) (#2117)
status: draft
nyquist_compliant: true
wave_0_complete: false
created: "2026-10-27"
---

# Phase 16 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3.3 (PHPUnit config phpunit.xml), run in DDEV |
| **Config file** | phpunit.xml, tests/Pest.php |
| **Quick run command** | `ddev exec php -d memory_limit=1G vendor/bin/pest <test file>` |
| **Full suite command** | `ddev exec composer ci` |
| **Estimated runtime** | ~16 seconds |

---

## Sampling Rate

- **After every task commit (worktree):** `php -l` on the task's touched PHP files (host PHP, no shared state; CLAUDE.md: no migrations and no DB tests inside a worktree)
- **After the plan's merge into the HEAD branch (post-merge):** `ddev exec php artisan migrate` when the plan adds a migration (16-01, 16-03, 16-05), then each task's `ddev exec vendor/bin/pest ...` command in task order, then the plan gate (last task)
- **After every plan wave:** Run `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Schema tests/Feature/Clients tests/Feature/Operations tests/Arch tests/Isolation`
- **Before `/gsd-verify-work`:** Full suite must be green (`ddev exec composer ci`, 16-06 Task 3)
- **Max feedback latency:** 16 seconds
- Plans of one wave share the DDEV test database: their Pest commands run one after another, never at the same time

---

## Per-Task Verification Map

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 16-01-01 | 01 | 1 | P16-01, P16-02 | T-16-03, T-16-04 | a switch change is audited with old/new value and causer; the gate reads fresh and fails closed | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientResourceTest.php tests/Feature/Invoicing/ClientDocumentEmailsTest.php tests/Arch/ActivityAllowlistTest.php tests/Feature/Operations/ActivityAllowlistColumnsTest.php` | ❌ W0 (ClientDocumentEmailsTest new, created in the task) | ⬜ pending |
| 16-01-02 | 01 | 1 | P16-01 | T-16-01 | an omitted or null key never switches sending off; DB default true, NOT NULL | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientDefaultsTest.php tests/Feature/Schema/ClientTablesTest.php tests/Feature/Clients/ClientResourceTest.php` | ✅ extend | ⬜ pending |
| 16-01-03 | 01 | 1 | P16-01..P16-11 (registration) | — | N/A | docs + plan gate | registration grep loop over `^| P16-NN |` and `^- \[ \] \*\*P16-NN\*\*` + `scripts/check-sensitive.sh .planning/REQUIREMENTS.md`; plan gate `ddev exec vendor/bin/pest tests/Feature/Clients tests/Feature/Schema tests/Feature/Invoicing tests/Feature/Operations tests/Isolation tests/Arch && ddev exec vendor/bin/pint --test && ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | ✅ | ⬜ pending |
| 16-02-01 | 02 | 2 | P16-07, P16-08, P16-10 | T-16-06, T-16-07, T-16-08 | header send hidden, gray note; hand lock audited, no e-mail row; lock token confined to four files | feature + arch | `ddev exec vendor/bin/pest tests/Feature/Invoicing/HandServedDocumentTest.php tests/Arch/InvoiceUnlockPathTest.php tests/Feature/Invoicing/InvoiceDetailTest.php tests/Feature/Schema/InvoiceLockGuardTest.php` | ❌ W0 (HandServedDocumentTest new, created in the task) | ⬜ pending |
| 16-02-02 | 02 | 2 | P16-03, P16-07, P16-08 | T-16-05, T-16-06, T-16-09 | crafted queue call refused with no row and no job; no post-issue dialog; lock refusals (A2); Partner refused; lock vs send one lock row | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/HandServedDocumentTest.php tests/Feature/Invoicing/LockInvoiceTest.php tests/Feature/Invoicing/UnlockInvoiceTest.php tests/Feature/Invoicing/EmailDialogTest.php` | ❌ W0 (LockInvoiceTest new, created in the task) | ⬜ pending |
| 16-02-03 | 02 | 2 | P16-08 | T-16-10 | an edit after hand lock + unlock is audited (A1) | feature + plan gate | `ddev exec vendor/bin/pest tests/Feature/Invoicing/InvoiceEditAuditTest.php tests/Feature/Invoicing/InvoiceDetailTest.php tests/Feature/Invoicing/EditableInvoiceJourneyTest.php tests/Feature/Invoicing/UpdateIssuedDocumentTest.php` | ✅ extend | ⬜ pending |
| 16-03-01 | 03 | 2 | P16-05, P16-06 | T-16-11, T-16-12 | queued-then-off row ends skipped: no transport, no lock, no bell, no retry; CHECK accepts skipped only without sent_at/failure code | feature + schema + unit | `ddev exec vendor/bin/pest tests/Feature/Invoicing/SendInvoiceEmailTest.php tests/Unit/Invoicing/InvoiceEnumsTest.php tests/Feature/Schema/InvoiceAdminTablesTest.php tests/Feature/Invoicing/EmailDialogTest.php tests/Feature/Localisation/EnumLabelsTest.php tests/Arch/JobContractTest.php` | ✅ extend | ⬜ pending |
| 16-03-02 | 03 | 2 | P16-06, P16-07 | T-16-13 | no resend in the list row or Odeslání row for a hand-served client; skipped reads as not sent | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/EmailDialogTest.php tests/Feature/Invoicing/InvoiceListTest.php tests/Feature/Clients/ClientInvoicePanelsTest.php` | ✅ extend | ⬜ pending |
| 16-03-03 | 03 | 2 | P16-06 | T-16-13, T-16-14 | indicator ignores skipped rows and hand-served clients (A3), still alarms for enabled clients | feature + plan gate | `ddev exec vendor/bin/pest tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php tests/Feature/Operations/HealthRegistryTest.php` | ✅ extend | ⬜ pending |
| 16-04-01 | 04 | 3 | P16-09, P16-10 | T-16-15, T-16-16 | Platby tab hand lock audited; receipt lock token confined to three files | feature + arch | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptAdminTest.php tests/Arch/CashReceiptUnlockPathTest.php tests/Arch/InvoiceUnlockPathTest.php tests/Feature/Schema/CashReceiptsTableTest.php` | ✅ extend | ⬜ pending |
| 16-04-02 | 04 | 3 | P16-09, P16-10 | T-16-15, T-16-17, T-16-18 | cancelled/locked refused; Partner refused; unlock needs a reason; later send adds no second lock | feature + plan gate | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptLockTest.php tests/Feature/Invoicing/CashReceiptEditTest.php tests/Feature/Invoicing/CashReceiptDeliveryTest.php` | ✅ extend | ⬜ pending |
| 16-05-01 | 05 | 3 | P16-04 | T-16-20, T-16-21, T-16-22 | payment never blocked; confirmation skipped with address kept, no job; CHECK still refuses non-skipped null and blank recipients | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/PaymentConfirmationAdminTest.php tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php tests/Feature/Invoicing/PaymentModalTest.php` | ✅ extend (rewrite the case at PaymentConfirmationSchemaTest.php:189-197) | ⬜ pending |
| 16-05-02 | 05 | 3 | P16-05, P16-06 | T-16-19 | queued-then-off confirmation skipped, nothing sent, receipt stays open | feature + plan gate | `ddev exec vendor/bin/pest tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php tests/Feature/Invoicing/CashReceiptDeliveryTest.php tests/Arch/JobContractTest.php` | ✅ extend | ⬜ pending |
| 16-06-01 | 06 | 4 | P16-01, P16-03, P16-04, P16-07, P16-08, P16-09 | — | end-to-end hand-served journey | journey | `ddev exec vendor/bin/pest tests/Feature/Invoicing/HandServedClientJourneyTest.php` | ❌ W0 (new, created in the task) | ⬜ pending |
| 16-06-02 | 06 | 4 | P16-11 | T-16-23, T-16-24 | system e-mails unaffected; nothing of the switch or the hand locks reaches a Partner; Partner lock refused | feature + isolation + audit | `ddev exec vendor/bin/pest tests/Feature/Clients/DocumentEmailsScopeTest.php tests/Isolation/InvoiceLeakTest.php && ddev exec composer partner-audit` | ❌ W0 (DocumentEmailsScopeTest new, created in the task) | ⬜ pending |
| 16-06-03 | 06 | 4 | all P16 | T-16-26 | documentation names only what exists | docs + phase gate | `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php` then `ddev exec composer ci` | ✅ extend | ⬜ pending |

All `ddev exec` commands are post-merge (run on the HEAD branch after the plan's merge); the worktree check of every task is `php -l` on its touched PHP files.

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

Each new test file is created test-first inside the task that needs it, so no separate Wave 0 plan exists:

- [ ] `tests/Feature/Invoicing/ClientDocumentEmailsTest.php` — the gate (P16-01; 16-01 Task 1)
- [ ] `database/factories/ClientFactory.php` — `'document_emails_enabled' => true` and the `withoutDocumentEmails()` state (16-01 Task 2)
- [ ] `tests/Feature/Invoicing/HandServedDocumentTest.php` — detail, gray note, queue refusal, post-issue dialog (P16-03, P16-07; 16-02 Tasks 1-2)
- [ ] `tests/Feature/Invoicing/LockInvoiceTest.php` — manual invoice lock (P16-08; 16-02 Task 2)
- [ ] `tests/Feature/Invoicing/HandServedClientJourneyTest.php` — the end-to-end journey (16-06 Task 1)
- [ ] `tests/Feature/Clients/DocumentEmailsScopeTest.php` — the D-01 scope (P16-11; 16-06 Task 2)
- [ ] Updates of pinned tests: `tests/Arch/InvoiceUnlockPathTest.php` (16-02), `tests/Arch/CashReceiptUnlockPathTest.php` (16-04), `tests/Unit/Invoicing/InvoiceEnumsTest.php` (16-03), the case at `tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php:189-197` (16-05), `tests/Feature/Invoicing/InvoiceEditAuditTest.php` (16-02), the banner texts of `tests/Feature/Invoicing/InvoiceDetailTest.php` (16-02), the hand-over phrases of `tests/Feature/Repo/RepositoryFilesTest.php` (16-06)
- [ ] Framework install: none (Pest 5.3.3 present)

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Visual check of the hand-served surfaces (client heading badge, gray note, no send action, lock button in the open callout, unlock in the locked callout, receipt lock in the Platby row group) | P16-01, P16-07, P16-08, P16-09 | appearance and placement; the behaviour itself is automated (Livewire tests) | `<human-check>` of 16-06 Task 1, at the end of the phase (`workflow.human_verify_mode: end-of-phase`), on the HEAD branch in DDEV with a fictional client |

*Every behaviour above also has automated verification; the manual row covers appearance only.*

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies (16 tasks, each with a worktree `php -l` check and post-merge `ddev exec` commands)
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references (new test files are created test-first in their tasks)
- [x] No watch-mode flags
- [ ] Feedback latency < 16s (per-task commands target single files; the plan gates and `composer ci` are longer by design)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** {pending / approved YYYY-MM-DD}
