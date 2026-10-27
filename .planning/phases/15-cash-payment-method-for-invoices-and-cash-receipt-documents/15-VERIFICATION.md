---
phase: 15-cash-payment-method-for-invoices-and-cash-receipt-documents
verified: 2026-10-27T12:00:00Z
status: passed
score: 13/13 must-haves verified
covered_files:
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-01-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-01-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-02-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-02-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-03-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-03-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-04-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-04-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-05-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-05-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-06-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-06-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-07-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-07-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-08-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-08-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-09-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-09-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-10-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-10-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-11-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-11-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-12-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-12-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-13-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-13-SUMMARY.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-14-PLAN.md
  - .planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-14-SUMMARY.md
  - app/Domain/Invoicing/Actions/CancelCashReceipt.php
  - app/Domain/Invoicing/Actions/DeletePayment.php
  - app/Domain/Invoicing/Actions/IssueCashReceipt.php
  - app/Domain/Invoicing/Actions/RecordPayment.php
  - app/Domain/Invoicing/Actions/UnlockCashReceipt.php
  - app/Domain/Invoicing/Actions/UpdateCashReceipt.php
  - app/Domain/Invoicing/Issuing/DocumentIssuer.php
  - app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php
  - app/Domain/Invoicing/Models/CashReceipt.php
  - app/Domain/Invoicing/Pdf/CashReceiptPdfData.php
  - app/Domain/Invoicing/Policies/CashReceiptPolicy.php
  - app/Domain/Invoicing/Support/DocumentPayment.php
  - app/Filament/Partner/Resources/PartnerInvoiceResource/RelationManagers/PartnerCashReceiptsRelationManager.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
  - app/Http/Controllers/CashReceiptPdfController.php
  - database/migrations/2026_10_27_000100_add_cash_to_document_payment_method.php
  - database/migrations/2026_10_27_000200_create_cash_receipts_table.php
  - resources/views/pdf/cash-receipt.blade.php
covered_digest: "v4:sha256:939b9752f9442ebc5a4afee3a40e4418e04f55df411b8b328e307ad1cc1a15d4"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "Open one generated fictional receipt PDF (cs and en) from the invoice detail"
    expected: "A single-page cash receipt with the supplier signature image, the cancelled band after a cancel, and no payment instructions (no bank box, QR, variable symbol or pay button)"
    why_human: "Visual layout of a PDF cannot be judged by grep or text extraction; the plan (15-09) explicitly defers this check to verification and marks it optional"
  - test: "Decide WR-01: should an unlocked receipt be re-sendable / re-lockable?"
    expected: "Either accept the current behaviour (an unlocked receipt stays open until a follow-up phase adds a resend or an explicit lock) or schedule a follow-up that adds a sanctioned resend of the payment confirmation"
    why_human: "A product decision, not a defect against a written requirement (see the WR-01 ruling below)"
---

# Phase 15: Cash payment method for invoices and cash receipt documents - Verification Report

**Phase Goal:** Invoices can be created with cash as the payment method, and a cash receipt document (Czech: pokladni prijmovy doklad, PPD) can be issued for such invoices. Research must first establish what a cash receipt document is and how it is issued (legal requirements, content, numbering).
**Verified:** 2026-10-27
**Status:** passed (the two human items were closed in 15-UAT.md on 2026-10-27)
**Re-verification:** No - initial verification

## Goal Achievement

The goal is achieved in the codebase. Cash exists as a third document payment method with database-enforced shape, every recorded cash payment issues a numbered, stored, e-mailed, lockable, cancellable receipt, and the Partner sees it read-only under policy and scope. No requirement is FAILED. The status is `human_needed` only because one visual PDF check is deferred to verification by the plan, and one product decision (WR-01) needs the owner.

### Observable Truths

ROADMAP.md lists no separate success criteria for this phase, so the truths are the goal sentence and the twelve requirements P15-01..P15-12 (the roadmap contract).

| #  | Truth | Status | Evidence |
| -- | ----- | ------ | -------- |
| 0  | Research established what a PPD is, how it is issued, legal content and numbering (goal precondition) | VERIFIED | `15-RESEARCH.md` exists; its findings are reflected in the code: Sec. 11 Act 563/1991 content in `CashReceiptPdfData`, own series in `DocumentKind::CashReceipt` |
| 1  | P15-01: `cash` is a third document method; credit note has none; draft default stays `transfer` | VERIFIED | `DocumentPaymentMethod::Cash` enum case; migration `2026_10_27_000100` widens `invoices_payment_method_check` to `('transfer','online','cash')` and leaves the credit-note kind check untouched; `DocumentPayment::forDraft` defaults to `Transfer`; `DocumentPaymentTest`, `DraftEditorTest` pass |
| 2  | P15-02: cash prints no bank box/QR/VS/pay button and stores no bank snapshot, enforced by a DB check | VERIFIED | `invoices_issued_fields_check` now `(payment_method IN ('online','cash')) = (bank_snapshot IS NULL)`; `InvoicePdfData` cash branch; `printsBankDetails()` false for cash; `CashReceiptsTableTest`, `InvoicePdfVariantsTest`, `IssueDocumentTest` pass |
| 3  | P15-03: cash excludes card: switch off and locked, no Stripe link, pay URL shows the info page | VERIFIED | `DocumentPayment` refuses an explicit `card_payment_enabled=true` for cash (`card_payment_cash`), `DocumentIssuer::writeAdminRow` forces switch false and no token for cash; pay URL of a kept token resolves to `PayPageOutcome::Preparing` (existing information page, per D-03); `CardSwitchTest`, `LinkLifecycleTest` pass |
| 4  | P15-04: method chosen in draft, changeable while the issued document is open; change to cash deactivates the live Stripe link | VERIFIED | `DocumentPayment::forIssued` + `UpdateIssuedDocument` (deactivation on `method_changed`); EditInvoice helpers for cash; `UpdateIssuedDocumentTest`, `LinkLifecycleTest` pass |
| 5  | P15-05: every recorded cash payment (any document method, invoice or proforma) issues exactly one PPD in the payment transaction; carried copies issue none | VERIFIED | `RecordPayment::recordLocked()` calls `IssueCashReceipt` when `$facts->method === PaymentMethod::Cash`, inside the transaction; `carry()` does not call it; unique `invoice_payment_id` plus INSERT guard in DB; `CashReceiptIssueTest`, `ConvertProformaTest` pass |
| 6  | P15-06: own gap-free series, configurable pattern, no VS, no QR | VERIFIED | `DocumentKind::CashReceipt` (default `PPD{YYYY}{NNNN}`), `NumberingSettings::cash_receipt_pattern` with `NumberPatternRule`, settings migration; allocated by `DocumentNumbering::next` inside the payment transaction (a PDF failure rolls the number back); `CashReceiptNumberingConcurrencyTest`, `NumberingTest`, `VariableSymbolTest` pass |
| 7  | P15-07: editable until the successful confirmation send locks it; Admin unlocks with a recorded reason; number, kind, amount, currency never change | VERIFIED (see WR-01) | Row guard `kokpit_guard_cash_receipt_row()` freezes number, amount, currency, invoice, client, language, appearance and allows edits only while open; `markSent()` locks; `UnlockCashReceipt` needs a 10-500 char reason and the transaction-local setting, audited; `CashReceiptLockTest`, `CashReceiptEditTest`, `CashReceiptUnlockPathTest` pass. The written clauses all hold; the relock-after-unlock loop has no resend path, see ruling below |
| 8  | P15-08: PDF carries Sec. 11 content, is built from snapshots in the client's language, is stored, and regenerated on edit | VERIFIED | `CashReceiptPdfData` reads the receipt row only (title and number, issue date, date received, payer and receiver, amount, purpose, paid document, signature record); `StoreCashReceiptPdf` stores it as an owned document; `UpdateCashReceipt` regenerates; `CashReceiptPdfTest` (text extracted from the stored PDF, cs and en, "reads no invoices row") passes |
| 9  | P15-09: PDF attached to the payment confirmation e-mail; the successful send locks the PPD | VERIFIED | `SendPaymentConfirmation` attaches the stored PDF (`attachment_missing` failure when gone), `markSent()` sets `lock_state = locked` under the receipt row lock; `CashReceiptDeliveryTest`, `PaymentConfirmationMailTest` pass |
| 10 | P15-10: a payment with a PPD cannot be deleted before the PPD is cancelled with a reason; the number is never reused | VERIFIED | `DeletePayment` refuses with `payment_has_receipt`; DB `cash_receipts_payment_link_check` (`invoice_payment_id IS NOT NULL OR cancelled_at IS NOT NULL`) with `ON DELETE SET NULL` is the backstop; guard blocks DELETE and TRUNCATE of receipts; unique `number`; `DeletePaymentActionTest`, `CashReceiptCancelTest` pass |
| 11 | P15-11: Admin sees, downloads, edits, cancels PPDs in the invoice detail; Partner sees and downloads own non-draft PPDs read-only by policy and scope | VERIFIED | `InvoicePaymentsRelationManager` actions (download, cancel, edit, unlock, regenerate); `PartnerCashReceiptsRelationManager` (number, date, amount, state only); `CashReceipt` is `PartnerIsolated` with scope (own client, issued document, client not archived); `CashReceiptPolicy` denies every write; signed route `CashReceiptPdfController` checks user binding, UUID, scoped lookup, policy, owner; registered in canary, route-walk, safe-columns and audit matrices; `CashReceiptPartnerTest`, `CanaryRegistryTest`, `PartnerSafeColumnsTest`, `RouteWalkTest`, `PartnerInvoiceResourceTest` pass |
| 12 | P15-12: a cancelled PPD keeps number and PDF, is marked cancelled and is audited | VERIFIED | `CancelCashReceipt` sets `cancelled_at` (never cleared, guard), regenerates the PDF with the cancelled band, writes `CashReceiptAudit`; `CashReceiptCancelTest`, `CashReceiptPdfTest` (cancelled band) pass |

**Score:** 13/13 truths verified (0 present, behavior-unverified). Every behavior-dependent truth (locking, cancel/delete ordering, numbering under concurrency) has a passing behavioral test.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `database/migrations/2026_10_27_000100_add_cash_to_document_payment_method.php` | CHECKs widened for cash | VERIFIED | Substantive, with a guarded `down()` |
| `database/migrations/2026_10_27_000200_create_cash_receipts_table.php` | Single-table `cash_receipts`, composite FKs, CHECKs, row guard, PDF-link guard, truncate guard | VERIFIED | Matches the owner's single-table decision of 2026-10-27 |
| `app/Domain/Invoicing/Actions/{Issue,Cancel,Update,Unlock,Regenerate}CashReceipt.php`, `StoreCashReceiptPdf.php` | Receipt lifecycle | VERIFIED | All wired (RecordPayment, relation manager) |
| `app/Domain/Invoicing/Pdf/CashReceiptPdf*.php`, `ClosedDompdf.php`, `PdfParts.php`, `resources/views/pdf/cash-receipt.blade.php` | PDF from the receipt row | VERIFIED | `PdfEngineTest` pins the closed engine options |
| `app/Domain/Invoicing/Models/CashReceipt.php`, `Policies/CashReceiptPolicy.php` | Partner-isolated model and policy | VERIFIED | Registered in `AccessServiceProvider` and `MorphMap` |
| `app/Http/Controllers/CashReceiptPdfController.php` | Signed, scoped download | VERIFIED | Route in `AdminPanelProvider` |
| `lang/{cs,en}/cash_receipt_pdf.php`, `lang/cs/cash_receipts.php` | Texts | VERIFIED | Key parity pinned by a test |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `RecordPayment::recordLocked` | `IssueCashReceipt` | direct call for `PaymentMethod::Cash`, inside the payment transaction | WIRED | `carry()` deliberately has no call (D-07) |
| `IssueCashReceipt` | `StoreCashReceiptPdf` / `DocumentNumbering` | same transaction, PDF last | WIRED | A render failure rolls back payment and number |
| `RecordPayment` | `QueuePaymentConfirmation` -> `SendPaymentConfirmation` | queued confirmation attaches the stored PDF, `markSent()` locks | WIRED | Receipt row lock taken in `markSent()` |
| `DeletePayment` | `CashReceipt` | refuses while valid; DB check as backstop | WIRED | |
| `InvoicePaymentsRelationManager` | Cancel/Update/Unlock/Regenerate actions and signed download route | `runReceiptAction()` | WIRED | |
| `PartnerInvoiceResource` | `PartnerCashReceiptsRelationManager` | `getRelations()` | WIRED | |
| `DocumentOwnerType::CashReceipt` | `DeleteDocument` | refuses a receipt PDF without the sanctioned flag | WIRED | |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Receipt PDF | all printed values | the `cash_receipts` row and its three snapshots (copied once from the locked invoice and payment at issue) | Yes (stored PDF text read back in `CashReceiptPdfTest`) | FLOWING |
| Admin payments tab | `InvoicePayment::cashReceipt` | DB relation | Yes | FLOWING |
| Partner tab | scoped `CashReceipt` query | DB with `PartnerScope` | Yes | FLOWING |
| Confirmation e-mail | receipt attachment | stored media object of the receipt document | Yes (`attachment_missing` if gone) | FLOWING |

### Behavioral Spot-Checks

Run in DDEV, targeted files only (the full gate is reported green by the orchestrator: pest 7008 passed, 3 skipped).

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Receipt issue, cancel, lock, delivery, Partner, schema, unlock-path arch, PDF | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceipt{Issue,Cancel,Lock,Delivery,Partner,Pdf}Test.php tests/Feature/Schema/CashReceiptsTableTest.php tests/Arch/CashReceiptUnlockPathTest.php` | 99 passed (550 assertions) | PASS |
| Admin, edit, model, concurrent numbering, payment deletion, document payment, issued edit, link lifecycle, card switch, confirmation mail, conversion, issue, PDF engine, numbering | `ddev exec vendor/bin/pest` over 14 files | 331 passed (1662 assertions) | PASS |
| Isolation and Partner surfaces | `ddev exec vendor/bin/pest tests/Isolation/{CanaryRegistry,PartnerSafeColumns,RouteWalk}Test.php tests/Arch/ModelDeclarationTest.php tests/Feature/Invoicing/PartnerInvoiceResourceTest.php` | 66 passed (1098 assertions) | PASS |

### Probe Execution

Step 7c: SKIPPED. No probe scripts are declared by the phase plans (no `probe-*.sh` references).

### Requirements Coverage

All twelve IDs are declared in PLAN frontmatter, defined in REQUIREMENTS.md (lines 168-179) and mapped to Phase 15 in its traceability table (lines 341-352). No orphaned requirement. REQUIREMENTS.md still shows the checkboxes and traceability status as `Pending`/unchecked; updating them is a bookkeeping step for the orchestrator, not a code gap.

| Requirement | Source Plan(s) | Status | Evidence |
| ----------- | -------------- | ------ | -------- |
| P15-01 | 15-01 | SATISFIED | Truth 1 |
| P15-02 | 15-01, 15-04 | SATISFIED | Truth 2 |
| P15-03 | 15-07 | SATISFIED | Truth 3 |
| P15-04 | 15-07 | SATISFIED | Truth 4 |
| P15-05 | 15-02, 15-08 | SATISFIED | Truth 5 |
| P15-06 | 15-05, 15-08 | SATISFIED | Truth 6 |
| P15-07 | 15-02, 15-10, 15-13, 15-14 | SATISFIED | Truth 7 (WR-01 ruling below) |
| P15-08 | 15-03, 15-09, 15-13 | SATISFIED | Truth 8 |
| P15-09 | 15-10 | SATISFIED | Truth 9 |
| P15-10 | 15-02, 15-12, 15-14 | SATISFIED | Truth 10 |
| P15-11 | 15-06, 15-11, 15-14 | SATISFIED | Truth 11 |
| P15-12 | 15-02, 15-09, 15-11, 15-12, 15-14 | SATISFIED | Truth 12 |

### Anti-Patterns Found

None blocking. No `TBD`, `FIXME`, `XXX`, `TODO` or `HACK` in any app, database, lang or resource file changed in the phase range. No stubs: the artifacts are substantive and exercised by tests.

### Code Review Findings (15-REVIEW.md) - effect on the must-haves

The review has 0 critical, 4 warnings, 3 info; all are open in 15-REVIEW-DISPOSITION.md. I confirmed WR-01, WR-02, WR-03 and WR-04 against the code. None makes a must-have untrue.

| Id | Confirmed in code | Does it falsify a requirement? | Ruling |
| -- | ----------------- | ------------------------------ | ------ |
| WR-01 | Yes: only `SendPaymentConfirmation::markSent()` sets `lock_state = locked`; there is one confirmation row per payment and no resend path in `QueuePaymentConfirmation`, `RecordPaymentAction` or the payments tab | No (see below) | Product gap, nice-to-have; owner decision requested |
| WR-02 | Yes: `runReceiptAction()` catches only `AuthorizationException` and `ValidationException`; a render/storage failure on cancel or edit surfaces as an unhandled error (the transaction rolls back, nothing is lost) | No | Robustness; fix in a follow-up |
| WR-03 | Yes: `CashReceiptPdfData` always passes the "not a VAT payer" note | No, latent | `IssueChecks` blocks issuing with `vat_payer_unsupported`, so no payer document exists; must be fixed before VAT-payer support lands |
| WR-04 | Yes: the regenerate action is visible only when `pdf_document_id === null`, while the action also covers a lost stored object | No | Edge-case repair UX; P15-08 (stored, regenerated on edit) holds in the normal lifecycle |

**WR-01 ruling.** P15-07 states three things: the PPD is editable until the successful confirmation send locks it (verified: `markSent()` locks, the guard then refuses edits), Admin can unlock it with a recorded reason (verified: `UnlockCashReceipt`, reason 10-500 characters, audited, only path allowed by the DB guard), and number, kind, amount and currency never change (verified: row guard). P15-09 requires that the successful send locks the PPD (verified). Neither requirement states that an unlocked receipt must be re-sendable or automatically re-locked; CONTEXT D-10 says "follows the invoice rules of Phase 10.1" and D-12 says the confirmation send is "the send that locks the PPD", but no requirement or decision mandates a resend of an already-sent confirmation. The "relock on next send" sentence exists only in the `UnlockCashReceipt` docblock and in an assumption in RESEARCH (line 196), not in a stated requirement. WR-01 is therefore a nice-to-have lifecycle gap, not a contradiction of P15-07 or P15-09. It is, however, a real weakness against the spirit of the project immutability rule (an unlocked accounting document stays editable indefinitely and the corrected PDF never reaches the client). I classify it as a WARNING with a requested owner decision, and recommend a follow-up (sanctioned resend of the confirmation that re-attaches the current PDF and re-locks through `markSent()`, or an explicit "lock" action). It should not block the phase.

### Human Verification Required

#### 1. Visual check of the generated receipt PDF

**Test:** In the invoice detail of a fictional cash-paid invoice, download the receipt PDF (Czech and English client) and one cancelled receipt.
**Expected:** A single-page receipt with the supplier signature image, correct party blocks and amount, the red cancelled band after a cancel, and no bank box, QR, variable symbol or pay button.
**Why human:** Visual layout; planned in 15-09 as an optional check at verification.

#### 2. Owner decision on WR-01

**Test:** Decide whether to accept the current lifecycle (an unlocked receipt is relocked only by a send that has no trigger today) or schedule a follow-up for a resend/relock path.
**Expected:** A recorded decision (accept, or add a follow-up plan or phase).
**Why human:** A product decision, not a defect against a written requirement.

### Hygiene

`scripts/check-sensitive.sh` over every file changed in the phase range (194ad47..HEAD, excluding the shard timing file) and all phase planning documents reports clean. A grep over the added lines found no real-looking e-mail address and no personal absolute path. No real-looking data noticed; fixtures use fictional values.

### Gaps Summary

No gaps. All twelve requirements are implemented, wired, data-backed and covered by passing behavioral tests (496 targeted tests re-run in DDEV, green). Open items are advisory: WR-01 (owner decision), WR-02 to WR-04 and IN-01 to IN-03 (follow-up hardening), the optional visual PDF check, and the REQUIREMENTS.md checkbox/traceability bookkeeping.

---

_Verified: 2026-10-27_
_Verifier: Claude (gsd-verifier)_
