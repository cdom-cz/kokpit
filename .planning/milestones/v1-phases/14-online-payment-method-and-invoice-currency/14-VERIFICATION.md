---
phase: 14-online-payment-method-and-invoice-currency
verified: 2026-10-23T18:00:00Z
status: human_needed
score: 10/10 must-haves verified
covered_files:
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-01-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-01-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-02-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-02-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-03-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-03-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-04-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-04-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-05-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-05-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-06-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-06-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-07-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-07-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-08-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-08-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-09-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-09-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-10-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-10-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-11-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-11-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-12-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-12-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-13-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-13-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-14-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-14-SUMMARY.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-15-PLAN.md
  - .planning/phases/14-online-payment-method-and-invoice-currency/14-15-SUMMARY.md
  - CONTRIBUTING.md
  - app/Domain/Invoicing/Actions/ConvertProforma.php
  - app/Domain/Invoicing/Issuing/DocumentIssuer.php
  - app/Domain/Invoicing/Issuing/IssueContext.php
  - app/Domain/Invoicing/Support/DocumentPayment.php
  - app/Domain/Invoicing/Support/InvoiceCurrencies.php
  - app/Domain/Invoicing/Support/IssueChecks.php
  - app/Domain/Invoicing/Support/IssuedEditFinisher.php
  - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
  - database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
  - tests/Feature/Invoicing/ConvertProformaTest.php
  - tests/Feature/Invoicing/IssuedEditorTest.php
  - tests/Feature/Invoicing/IssuedHeaderEditTest.php
  - tests/Feature/Payments/LinkLifecycleTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
covered_digest: "v3:sha256:d3a6b1effdaf3807c0473b96142394d0a92f1a78575c6d005e9bb84088d47c75"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 7/10
  gaps_closed:
    - "CR-01: re-picking the stored account after a currency change in the open issued editor (plan 14-12)"
    - "WR-01: the card switch needs card payment to be available on every path that turns it on or keeps it on (plan 14-13)"
    - "WR-02: a paid proforma with a removed account converts and prints the proforma's own bank snapshot (plan 14-14)"
    - "IN-01 and IN-02: documentation items (plan 14-15)"
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Draft editor (plan 14-08 tracer). In the DDEV panel, sign in as Admin and create a draft invoice for a client whose online payment flag is on, with card payment enabled in the settings and at least one CZK and one EUR bank account configured. (1) Open the draft, find the payment method field and switch between 'bank transfer' and 'online'. (2) With online selected, look at the card payment toggle. (3) Switch back to bank transfer and look at the toggle again. (4) Change the currency to EUR and look at the bank account field; then pick a CZK account from the list. (5) Change the currency to a currency with no configured account (for example USD) and look at the bank account field. (6) Turn the global card payment setting off in Settings, reopen the draft and look at the 'online' option."
    expected: "(1) The method switches without error. (2) Online turns the card toggle on and disables it. (3) Back on transfer the toggle takes the client flag and is editable. (4) The EUR change preselects the first EUR account; the CZK account stays selectable independently of the currency. (5) The account field is empty and shows the hint that no account matches; saving is allowed, issuing a transfer document is blocked with the bank_missing reason. (6) Online is disabled with a stated reason."
    why_human: "Reactive Filament form state (disabled state, helper text, preselection after a currency change) is visual and interaction behaviour that no grep or Livewire assertion proves in a real browser; deferred by plan 14-08."
  - test: "Issued editor, CR-01 UI sequence (plans 14-10 and 14-12). In the DDEV panel, with a CZK bank account 'Account A' and no USD account configured, issue a CZK transfer invoice that prints Account A (do not send it, so it stays open). Open it in the edit view. (1) Change the currency to USD (a currency with no account) and, if a confirmation modal appears because of hand-typed prices, confirm it. (2) Look at the bank account field: it should be empty with a hint. (3) Pick Account A again from the dropdown. (4) Look at the 'account used after saving' section. (5) Save. (6) Open the invoice detail and the PDF preview. (7) Repeat on a second open CZK invoice, but with a EUR account 'Account E' also configured: change the currency to EUR, confirm the field preselects Account E, pick Account A again and save. (8) On a third open invoice change only the note and save."
    expected: "(2) Empty with a hint. (4) The section names Account A. (5) The save succeeds with no field error; the currency is USD. (6) The PDF and detail show Account A's bank data, not another account, and the currency USD. (7) The save keeps Account A; Account E is not printed. (8) An unrelated save keeps the printed bank data unchanged. A save that fails with 'bank account required' or prints a different account is a regression of CR-01."
    why_human: "The server-side sequence is proven by two Livewire tests, but the account field's reactive refill after a confirmation modal and the account section preview are interaction behaviour; deferred by plan 14-10 and required by the re-verification brief."
  - test: "Issued editor, method and card switch (plans 14-07, 14-10, 14-13). On an open issued online invoice of a client with online payment on, in the DDEV panel: (1) switch the method to bank transfer while card payment is available, save, and look at the card toggle and the bank section. (2) Switch back to online. (3) Turn the global card payment setting off in Settings, then on another open issued online invoice switch the method to bank transfer and save."
    expected: "(1) Transfer shows the bank section for the chosen account; the toggle follows the client flag; the pay URL stays the same. (2) Online prints no bank data and forces the toggle on. (3) The save succeeds, the card toggle is off, the pay URL answers 'not available' or 'preparing' with no new live link, and the bank data is printed."
    why_human: "Visual state of the toggle and section and the real pay-URL page; the server rules are covered by tests, the rendering is not."
deferred: []
advisory: []
audit_acknowledged:
  milestone: v1
  at: 2026-10-30
  status: human_needed
---

# Phase 14: Online payment method and invoice currency Verification Report

**Phase Goal:** The invoice currency can be changed freely, independent of the payment method (bank transfer or online), the selected bank account, or whether payment is online. The payment method can be switched on the invoice between bank transfer and online (Stripe): bank transfer shows the bank payment details, online shows no bank details, only what is needed to pay via Stripe. When the chosen currency matches a preconfigured bank account, that account is preselected; the account can also be chosen separately and independently of the currency. The chosen currency carries through to the PDF and the Stripe payment.
**Verified:** 2026-10-23
**Status:** human_needed
**Re-verification:** Yes, after gap closure plans 14-12 to 14-15

## Goal Achievement

All three gaps of the first verification (CR-01, WR-01, WR-02) are closed in code and pinned by tests that fail when the fix is removed. No automated gap remains. The status is `human_needed` only because the live Filament editor behaviour (draft and issued) was deferred to a browser check by plans 14-08 and 14-10 and has never been performed; I did not guess it as passed.

### Prior gap closure (read from code, not from SUMMARY.md)

| Gap | Fix found in code | Test evidence | Verdict |
| --- | ----------------- | ------------- | ------- |
| CR-01 (blocker): re-picked stored account dropped after a currency change | `EditInvoice::issuedValues()` lines 807-816 compute `$currencyChanged` from `$data['currency']` vs `$invoice->currency` and send `bank_account_id` when `$account !== filledAccountId()` or (`$currencyChanged && $account !== null`). The unrelated-save rule stays (account equal to the filled one is not sent when the currency is unchanged). `DocumentPayment::forIssued()` takes the sent-account branch (lines 244-250). | `IssuedEditorTest.php` lines 875-909: currency to USD (no account) then re-pick, and currency to EUR (own account) then re-pick; both assert snapshot and `invoice_admin.bank_account_id` are the stored account. Mutation check: reverting the condition to `$account !== $this->filledAccountId()` makes both cases fail (2 failed). Existing case at 844 (unrelated save keeps the printed snapshot) still passes. | CLOSED |
| WR-01: switch on while card payment unavailable | `DocumentPayment::issuedSwitch()` lines 313-337: online branch returns `$storedSwitch \|\| onlineUnavailableReason() === null`; transfer branch guards `$switch && (! $storedSwitch \|\| $storedMethod === Online) && ! available()`, degrading a derived value to false and refusing an explicit `true` as `card_payment_unavailable`. | `LinkLifecycleTest.php` 709 (two causes: setting off, Stripe missing; switch off, link replaced, no new link, pay URL null, token kept), 576 (converted online invoice with switch off stays off on an unrelated save, turns on once available); `IssuedHeaderEditTest.php` 916 (explicit on refused, nothing written). Mutation check: reverting the transfer guard to `! $storedSwitch` only makes the lifecycle cases fail (2 failed). | CLOSED |
| WR-02: paid proforma unconvertible / wrong account | `IssueContext::$bankSnapshot` added; `ConvertProforma::handle()` hands the proforma's `bank_snapshot` (null for online) to the issue; `IssueChecks::blockers()` skips `bank_missing` when a snapshot is given; `DocumentIssuer::bankSnapshot()` prints the inherited snapshot when `DocumentPayment::account($draft)` is null; `inheritedAccount()` returns the stored id unfiltered, so a removed id is never replaced by another account. | `ConvertProformaTest.php` 494 (another account of the currency left: proforma snapshot printed, not the other), 513 (no account of the currency left: converts), 538 (legacy proforma with no id and no account left). Ordinary transfer draft without a resolvable account is still `bank_missing` (the check is skipped only when a snapshot is passed; only `ConvertProforma` passes one). | CLOSED |
| IN-01: online/card-switch tie undocumented | Migration docblock lines 39-42 states the tie is application-level only; CONTRIBUTING "Payment method and bank accounts" bullet repeats it; pinned by `RepositoryFilesTest`. | `RepositoryFilesTest` green | CLOSED |
| IN-02: stale wording | `IssuedEditFinisher::paymentLink()` docblock now says "card switch on" (no "frozen"); `InvoiceCurrencies` docblock says a listed currency needs no account of its own and only a transfer document without a resolvable account is refused as `bank_account_required`; CONTRIBUTING token sentence now reads "with the first issue or, later, on an open issued document" (pinned by `RepositoryFilesTest`, including absence of the old clause). | `RepositoryFilesTest` green | CLOSED |
| IN-03, IN-04 | Out of scope by the orchestrator's ruling (cosmetic retired CNB codes in the currency list; Partner sees no pay path while a link is inactive, by design D-01). | n/a | Accepted, not gaps |

### Observable Truths

| #  | Truth | Status | Evidence |
| -- | ----- | ------ | -------- |
| 1  | The currency can be changed freely; choices are one union list (CNB + account currencies + client + current + CZK), no duplicates (P14-05) | VERIFIED | `InvoiceCurrencies` used by both editors; `InvoiceCurrenciesTest` green (regression check, previously verified). |
| 2  | A draft, invoice or proforma has an exclusive payment method `transfer` or `online`, default `transfer`; a credit note has none (P14-01) | VERIFIED | Migration `2026_10_24_000200` (column, kind check, amended issued-fields check, guard, backfill); `DocumentPaymentMethod`; schema tests green. |
| 3  | Transfer prints bank box, variable symbol and QR; online prints none of them, only the pay path, amount and due facts (P14-02) | VERIFIED | `InvoicePdfData` branches on `payment_method`; Partner and Admin detail hide bank block and VS for online; `InvoicePdfTest`, `InvoicePdfVariantsTest`, `InvoiceDetailTest`, `PartnerInvoiceResourceTest` in the passing full suite. |
| 4  | Setting or changing the currency preselects the first account of that currency; any account may be chosen instead, independently of the currency; with no match nothing is preselected and issuing a transfer document is blocked (P14-03, P14-04) | VERIFIED | Draft path unchanged and green; issued path now closed (CR-01 row above). No-match issue block: `IssueChecks` `bank_missing` retained for ordinary drafts. |
| 5  | Method, account and currency are editable on an open issued document and frozen once locked (P14-06) | VERIFIED | `UpdateIssuedDocument` -> `DocumentPayment::forIssued()`; locked refusal via `IssuedDocumentRules`; DB guard opens exactly `payment_method`; `InvoiceLockGuardTest` green; account edit now correct (CR-01). |
| 6  | A method or currency change deactivates the live Stripe link and, when card payment is still offered, queues a new one in the document currency; the pay URL stays the same (P14-07) | VERIFIED | `PaymentLinks::deactivateLive`, `IssuedEditFinisher`, `LinkLifecycleTest` incl. the new unavailable-payment cases (a degraded switch forces no new link). |
| 7  | A currency change is refused once a payment is recorded; method and account changes are still allowed (P14-08) | VERIFIED | `IssuedCurrencyChangeTest` green. |
| 8  | `online` requires card payment to be available and blocks issuing otherwise (P14-09); the card switch is turned on or kept on only while it can work | VERIFIED | `onlineUnavailableReason()`; `forIssued()` refusal of a switch to online; `issuedSwitch()` now guards the reverse direction (WR-01 row above). |
| 9  | The chosen currency carries through to the PDF and to the Stripe payment link | VERIFIED | Link opened in the document currency by `PaymentLinks`; PDF renders from the invoice row; `InvoicePdfTest`, `CreatePaymentLinkTest` green. Unsupported Stripe currencies fail through the Phase 11 path by design (D-08). |
| 10 | Proforma conversion inherits method and account; a paid proforma always converts; link-job race and method-change audit are covered | VERIFIED | Inheritance and reconcile unchanged; WR-02 closed (row above); `ConvertProformaTest` green. |

**Score:** 10/10 truths verified, 0 present-but-behavior-unverified.

Residual notes (no gap, not blocking):
- A legacy proforma that names no account id while some account of its currency is still configured converts with the first account of that currency (`DocumentPayment::account()` legacy fallback), which may differ from the account the legacy proforma printed. This is a pre-existing legacy-data edge outside the plan 14-14 truth (that truth covers a removed id and a legacy proforma with no account left) and is documented in the `ConvertProforma` docblock.
- The converted invoice keeps the removed account's id in `invoice_admin.bank_account_id` (a dangling id by plan ruling R-14-14-4). The issued editor already tolerates a stored id that is no longer configured (T-14-34, covered by the existing unrelated-save test).

### Architecture truth (plan 14-10 truth 4)

| Check | Result |
| ----- | ------ |
| No application lookup of an account by currency except `DocumentPayment::preselect()` and the documented legacy fallback of `account()` | VERIFIED. `tests/Arch/BankAccountByIdTest.php` passes (part of the 297-test run below). |

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php` | Issued editor sends the account when the currency changed | VERIFIED | Lines 807-816 |
| `app/Domain/Invoicing/Support/DocumentPayment.php` | `issuedSwitch()` availability guard on every on-path | VERIFIED | Lines 313-337 |
| `app/Domain/Invoicing/Issuing/IssueContext.php` | optional `bankSnapshot` | VERIFIED | Constructor property, fourth argument |
| `app/Domain/Invoicing/Support/IssueChecks.php` | `bank_missing` skipped with a snapshot to copy | VERIFIED | `blockers()` / `assertIssuable()` take the snapshot |
| `app/Domain/Invoicing/Issuing/DocumentIssuer.php` | snapshot fallback | VERIFIED | `bankSnapshot($draft, $inherited)` |
| `app/Domain/Invoicing/Actions/ConvertProforma.php` | hands the proforma snapshot over | VERIFIED | `new IssueContext($actor, today, null, $snapshot)` |
| `CONTRIBUTING.md`, `RepositoryFilesTest.php`, migration docblock, `IssuedEditFinisher`, `InvoiceCurrencies` docblocks | IN-01 / IN-02 | VERIFIED | Wording present and pinned |
| Earlier artifacts (migrations, settings ids, `InvoicePdfData`, `PaymentLinks`, `UpdateIssuedDocument`, `InvoiceCurrencies`) | unchanged from the first verification | VERIFIED | Regression: all related tests green |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `EditInvoice::issuedValues` | `UpdateIssuedDocument` / `DocumentPayment::forIssued` | `bank_account_id` payload key | WIRED | Sent on a same-save currency change with an account chosen |
| `DocumentPayment::issuedSwitch` | `UpdateIssuedDocument` lines 204-213 | `card_payment_enabled` / `card_changed` | WIRED | A degraded switch deactivates the live link and forces no new one (mutation-tested) |
| `ConvertProforma::handle` | `DocumentIssuer::issue` -> `IssueChecks` and `bankSnapshot()` | `IssueContext::$bankSnapshot` | WIRED | Only conversion passes a snapshot |
| `InvoicePdfData` | `invoices.payment_method` | enum comparison | WIRED | |
| Partner views | `invoices.payment_method` only | `PartnerSafeColumnsTest` | WIRED | |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Issued editor account field -> save | `bank_account_id` | form state -> `issuedValues()` -> `invoice_admin.bank_account_id` and `invoices.bank_snapshot` copied from settings by id | Yes (tests assert both columns after the CR-01 sequences) | FLOWING |
| Converted invoice bank box | `bank_snapshot` | proforma `bank_snapshot` via `IssueContext` | Yes (tests assert equality with the proforma snapshot) | FLOWING |
| PDF payment box | `bank_snapshot` / `payment_method` | `invoices` row | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Gap-closure suites and arch rules | `ddev exec vendor/bin/pest` on `IssuedEditorTest`, `ConvertProformaTest`, `LinkLifecycleTest`, `IssuedHeaderEditTest`, `RepositoryFilesTest`, `DocumentPaymentTest`, `tests/Arch` | 297 passed (2684 assertions) | PASS |
| CR-01 test discriminates | revert the `issuedValues()` condition, run `--filter="picks the stored account again"`, restore with `git checkout` | 2 failed | PASS (fix is load-bearing; file restored, `git status` clean for `app/`) |
| WR-01 test discriminates | revert the transfer guard, run `--filter="while card payment is unavailable"`, restore | 2 failed | PASS (file restored) |
| Static analysis | `ddev exec vendor/bin/phpstan analyse` | 1 error: the known pre-existing `ResetAdminTwoFactorCommand.php:39`, logged in `deferred-items.md`, untouched by this phase | PASS (known) |
| Code style | `ddev exec vendor/bin/pint --test app tests` | PASS, 1072 files | PASS |
| Full suite | `ddev exec vendor/bin/pest` | 6775 passed (executor-reported just before this verification, not re-run by me) | PASS (reported) |

### Probe Execution

Step 7c: SKIPPED. The phase declares no `probe-*.sh` scripts.

### Requirements Coverage

All nine IDs P14-01..P14-09 are declared across the 15 plans' `requirements:` frontmatter and mapped to Phase 14 in REQUIREMENTS.md. No orphaned requirements.

| Requirement | Status | Evidence |
| ----------- | ------ | -------- |
| P14-01 | SATISFIED | Truth 2 |
| P14-02 | SATISFIED | Truth 3 |
| P14-03 | SATISFIED | Settings ids, `invoice_admin.bank_account_id`, arch test, converted invoice keeps the account id |
| P14-04 | SATISFIED | Truth 4 (draft, issued editor after CR-01, conversion after WR-02) |
| P14-05 | SATISFIED | Truth 1 |
| P14-06 | SATISFIED | Truth 5 |
| P14-07 | SATISFIED | Truth 6 |
| P14-08 | SATISFIED | Truth 7 |
| P14-09 | SATISFIED | Truth 8 (both directions of the card switch) |

REQUIREMENTS.md shows all nine as `[x]` / Complete. Automated evidence supports it; closing the phase still waits on the human checks below.

### Anti-Patterns Found

None blocking. Debt-marker scan (`TBD|FIXME|XXX`) over the changed app files returned nothing. `scripts/check-sensitive.sh` clean.

### Human Verification Required

Three browser checks, detailed with exact steps in the `human_verification` frontmatter:

1. **Draft editor payment method, account and card toggle** (plan 14-08 tracer), including the unavailable-online and no-match-currency states.
2. **Issued editor CR-01 UI sequence** (plans 14-10 and 14-12): currency change, re-pick of the stored account, preview section, save, PDF and detail.
3. **Issued editor method and card switch** (plans 14-07, 14-10, 14-13): transfer/online round trip and online to transfer while card payment is switched off.

### Gaps Summary

No gaps. CR-01, WR-01, WR-02 and the doc items IN-01 and IN-02 are closed in code and tests, with two mutation checks showing the new tests fail without the fixes. The phase is ready to be marked complete once the three browser checks are run and pass.

---

_Verified: 2026-10-23_
_Verifier: Claude (gsd-verifier)_
