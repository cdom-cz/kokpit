---
phase: 14-online-payment-method-and-invoice-currency
verified: 2026-10-23T12:00:00Z
status: gaps_found
score: 7/10 must-haves verified
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
  - app/Domain/Invoicing/Actions/ConvertProforma.php
  - app/Domain/Invoicing/Actions/UpdateIssuedDocument.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Domain/Invoicing/Support/DocumentPayment.php
  - app/Domain/Invoicing/Support/InvoiceCurrencies.php
  - app/Domain/Payments/Support/PaymentLinks.php
  - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
  - database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php
  - database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
covered_digest: "v3:sha256:69a67ff81a110f3abba06a070a6dd22551d1a18f0bd3361c116fe731eca4f662"
behavior_unverified: 0
overrides_applied: 0
gaps:
  - truth: "In the issued editor the Admin may pick any account independently of the currency, and the account is editable on an open issued document (plan 14-10 truth 2; P14-04, P14-06; roadmap goal 'the account can also be chosen separately and independently of the currency')"
    status: failed
    reason: "CR-01 (critical, confirmed in code). EditInvoice::issuedValues() sends bank_account_id only when it differs from filledAccountId() (the stored account). currencyChosen() overwrites the form value with DocumentPayment::preselect($currency). If the Admin changes the currency and then re-picks the originally stored account X, the form value equals the stored value, the key is dropped, and DocumentPayment::forIssued() takes the preselect branch for the changed currency. With no account in the new currency issuedSnapshot() throws bank_account_required on every retry (no workaround: a currency-only save fails the same way); with an account in the new currency X is silently replaced by it. The Action is correct when the id is sent; the page decides wrongly what to send. No existing test covers the re-pick case (grep of IssuedEditorTest and IssuedCurrencyChangeTest)."
    artifacts:
      - path: "app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php"
        issue: "Lines 803-809: the account is dropped from the payload when equal to the stored one, even when the currency changed in the same save"
      - path: "app/Domain/Invoicing/Support/DocumentPayment.php"
        issue: "Lines 244-250 and 351-353: a missing key plus currencyChanged selects the preselect branch and then fails or replaces the account"
    missing:
      - "Send the account whenever it is non-null and either differs from the stored one or the currency changed in the same save (fix proposed in 14-REVIEW.md CR-01)"
      - "Pest case: issued transfer document, currency changed to one without an account, original account re-picked, save succeeds with that account in bank_snapshot and invoice_admin.bank_account_id"
  - truth: "Turning the card switch on needs card payment to be available, on every path that sets it (plan 14-07 truth 5; P14-09)"
    status: partial
    reason: "WR-01 (warning, confirmed in code). DocumentPayment::issuedSwitch() runs the availability guard only when the stored switch is off. An online document always has the stored switch on, so switching it back to transfer with no explicit value derives the switch from the client flag and never checks availability; if the global setting was turned off or Stripe credentials were removed afterwards, the transfer document keeps card_payment_enabled = true, a new link is forced, and CreatePaymentLink fails at Stripe (customer sees 'preparing'). The toggle is disabled while unavailable, so the UI cannot correct it. Also, an online document whose stored switch is off (for example made by ConvertProforma while Stripe was unavailable) gets the switch forced on by an unrelated save regardless of availability."
    artifacts:
      - path: "app/Domain/Invoicing/Support/DocumentPayment.php"
        issue: "Lines 313-327: availability guard skipped when storedMethod was online or stored switch already on"
    missing:
      - "Apply the availability guard to every result that turns the switch on or keeps it on after a method change; a derived value degrades to off, an explicit request throws"
      - "Tests: online to transfer with Stripe removed; unrelated save of an online document whose switch is off"
  - truth: "A paid proforma always converts, and the invoice inherits the proforma's bank account (plan 14-09 truth 1; P14-04)"
    status: partial
    reason: "WR-02 (warning, confirmed by reading the docblock claim against IssueChecks). ConvertProforma::inheritedAccount() returns null when the proforma's account was removed from the settings. Because an account may have any currency (D-06), a proforma that used a different-currency account X then yields a draft with no resolvable account, IssueChecks reports bank_missing, and the whole conversion fails; the Admin cannot pick an account inside the atomic conversion. When another same-currency account exists, the invoice silently prints a different account than the proforma the customer paid to. The plan accepted removal of the account, but its docblock promise that a paid proforma never becomes unconvertible does not hold."
    artifacts:
      - path: "app/Domain/Invoicing/Actions/ConvertProforma.php"
        issue: "Lines 159-168: no fallback to the proforma's bank_snapshot when the account id is no longer configured"
    missing:
      - "Carry the proforma's bank_snapshot for a converted draft whose account cannot be resolved, and skip the bank_missing blocker in that case"
      - "Test: transfer proforma in a currency with no other account, account deleted, conversion succeeds with the proforma's snapshot"
human_verification:
  - test: "Open the draft editor in the DDEV panel. Switch the payment method between bank transfer and online, toggle the card payment switch, change the currency and pick an account from the list (plan 14-08 tracer human-check)."
    expected: "Online is disabled with the reason when card payment is unavailable; choosing online turns the card toggle on and disables it; going back to transfer sets the toggle to the client flag; a currency change preselects the first account of that currency (empty with the hint when none); any account stays selectable independently of the currency."
    why_human: "Visual and interaction behaviour of live Filament form state (reactive fields, disabled state, helper text) cannot be proven by grep; deferred by plan 14-08."
  - test: "Open an open (issued, not yet sent) invoice in the DDEV panel and use the issued editor fields: method, account, card toggle and currency (plan 14-10 tracer human-check). Include the CR-01 sequence once it is fixed."
    expected: "Method, account and card toggle follow the same rules as the draft editor; the bank section shows the printed account or the account the save will print, and nothing for an online document; a refusal appears under its field."
    why_human: "Visual and interaction behaviour of the issued editor and the account section preview; deferred by plan 14-10."
deferred: []
advisory: []
---

# Phase 14: Online payment method and invoice currency Verification Report

**Phase Goal:** The invoice currency can be changed freely, independent of the payment method (bank transfer or online), the selected bank account, or whether payment is online. The payment method can be switched on the invoice between bank transfer and online (Stripe): bank transfer shows the bank payment details, online shows no bank details, only what is needed to pay via Stripe. When the chosen currency matches a preconfigured bank account, that account is preselected; the account can also be chosen separately and independently of the currency. The chosen currency carries through to the PDF and the Stripe payment.
**Verified:** 2026-10-23
**Status:** gaps_found
**Re-verification:** No, initial verification

## Goal Achievement

The data model, the draft path, the PDF and detail rendering, the Stripe link lifecycle and the currency list are built and wired, and the test suite exercising them passes. The phase goal is not fully achieved: the issued-document editor has a functional dead end on the "account independent of currency" clause (CR-01), and two further paths violate stated must-haves in edge conditions (WR-01, WR-02).

### Observable Truths

| #  | Truth | Status | Evidence |
| -- | ----- | ------ | -------- |
| 1  | The currency can be changed freely, independent of method, account and payment mode; the choices are one union list (CNB + account currencies + client + current + CZK), no duplicates (P14-05) | VERIFIED | `app/Domain/Invoicing/Support/InvoiceCurrencies.php` exists and is used by the editors; `InvoiceCurrenciesTest` passed in my spot run (84 tests across 5 files, 0 failures). IN-03 (retired CNB codes still offered) is cosmetic. |
| 2  | A draft, invoice or proforma has an exclusive payment method `transfer` or `online`, default `transfer`; a credit note has none (P14-01) | VERIFIED | Migration `2026_10_24_000200_add_payment_method_to_invoices.php` adds the column with kind check, amended `invoices_issued_fields_check`, guard function and backfill; `DocumentPaymentMethod` enum; the reviewer found the migration, guard, checks and `down()` consistent. |
| 3  | Transfer prints bank box, variable symbol and QR; online prints none of them, only the pay button/link, amount and due facts (P14-02) | VERIFIED | `InvoicePdfData.php` lines 267-277 branch on `payment_method === Online` (no bank rows, `online_payment` fact) and line 332 for transfer; Partner and Admin detail hide bank block and variable symbol for online (`InvoiceColumns`, `PartnerInvoiceResourceTest`, `InvoiceDetailTest`); reviewer found no bank data leaking through any other surface. |
| 4  | Setting or changing the currency preselects the first account of that currency; any account may be chosen instead, independently of the currency; with no match nothing is preselected and issuing a transfer document is blocked (P14-03, P14-04) | FAILED | Draft path verified (`DocumentPayment::preselect()` line 89, `forDraft()` line 134, `IssueChecks` bank_missing, `DraftEditorTest`). Issued editor path broken by CR-01: re-picking the stored account after a currency change is dropped from the payload and the save fails with `bank_account_required` or silently swaps the account. See gap 1. |
| 5  | Method, account and currency are editable on an open issued document and frozen once locked (P14-06) | PARTIAL (FAILED for account) | Method and currency changes go through `UpdateIssuedDocument` -> `DocumentPayment::forIssued()`; locked documents refused with the `document` field error (`IssuedDocumentRules`, `InvoiceLockGuardTest`); DB guard `kokpit_guard_invoice_row()` opens exactly `payment_method`. The account edit in the open editor is broken for the CR-01 sequence. Counted as failed with truth 4's gap. |
| 6  | A method or currency change deactivates the live Stripe link and, when card payment is still offered, queues a new one in the document currency; the pay URL stays the same (P14-07) | VERIFIED | `PaymentLinks::deactivateLive()` and `IssuedEditFinisher::finish(..., forceNewLink)`; `PayToken` set once; deactivation inside the caller's transaction under the row lock, Stripe calls `afterCommit` (reviewer confirmed); `LinkLifecycleTest`, `CardSwitchTest` pass. Edge case WR-01 (availability) tracked in gap 2. |
| 7  | A currency change is refused once a payment is recorded; method and account changes are still allowed (P14-08) | VERIFIED | Covered by `IssuedCurrencyChangeTest` (passed in my spot run) and plan 14-07 rule D-11. |
| 8  | `online` requires card payment to be available (global setting on, Stripe configured, client flag on) and blocks issuing otherwise (P14-09) | VERIFIED (with WR-01 caveat) | `DocumentPayment::onlineUnavailableReason()` (line 114); `forIssued()` line 224 refuses a switch to online when unavailable; `DocumentPaymentTest` and `DraftEditorTest` cases "blocks the issue of an online draft once card payment is unavailable" pass for settings_off, stripe_missing, client_off. The reverse direction (transfer switch kept on while unavailable) is gap 2. |
| 9  | The chosen currency carries through to the PDF and to the Stripe payment link | VERIFIED | Link is opened in the document currency by `PaymentLinks` after `currency` change (`amount_changed` reason, new pending link in new currency); PDF renders from the invoice row; `InvoicePdfTest`, `InvoicePdfVariantsTest`, `CreatePaymentLinkTest` in the passing suite. Unsupported Stripe currencies fail through the Phase 11 path by design (D-08). |
| 10 | Proforma conversion inherits method and account; link job race and method-change audit are covered (plan 14-09) | PARTIAL | Inheritance, reconcile-after-carried-payments, link-job race and audit history (`InvoiceAudit`, no account id or token in history) are in place and tested. WR-02: removed-account edge makes a paid proforma unconvertible or prints a different account. See gap 3. Counted as verified for the core path; gap 3 recorded as a warning-level partial. |

**Score:** 7/10 truths verified (truths 4, 5 and the CR-01 clause counted as failed; WR-01 and WR-02 recorded as partial gaps on truths 8 and 10, which keep VERIFIED/PARTIAL status for their main behavior).

### Architecture truth (plan 14-10 truth 4)

| Check | Result |
| ----- | ------ |
| `BankAccountSettings::forCurrency()` and `InvoiceSnapshot::bank()` deleted; no application lookup of an account by currency except `DocumentPayment::preselect()` | VERIFIED. `grep -rn "forCurrency\|function bank(" app` returns nothing; `tests/Arch/BankAccountByIdTest.php` passes. |

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php` | Stable UUID v7 ids for bank accounts | VERIFIED | Present; `BankAccountSettingsTest` in the green suite |
| `database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php` | `invoices.payment_method` with guard, checks, backfill | VERIFIED | Present; reviewer found it consistent |
| `database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php` | Account id and open card switch on `invoice_admin` | VERIFIED | Present (IN-01: online/card-switch tie is application-level only) |
| `app/Domain/Invoicing/Support/DocumentPayment.php` | `forDraft`, `forIssued`, `preselect`, `onlineUnavailableReason`, `saveAdmin` | VERIFIED (defects WR-01, CR-01 path) | Substantive and wired from Actions and editors |
| `app/Domain/Payments/Support/PaymentLinks.php` | `deactivateLive` | VERIFIED | Wired from `UpdateIssuedDocument` via `IssuedEditFinisher` |
| `app/Domain/Invoicing/Support/InvoiceCurrencies.php` | Union currency list | VERIFIED | Used by draft and issued editors |
| `app/Domain/Invoicing/Actions/UpdateIssuedDocument.php` | Keys `payment_method`, `bank_account_id`, `card_payment_enabled` | VERIFIED | Calls `DocumentPayment::forIssued` |
| `app/Domain/Invoicing/Actions/ConvertProforma.php` | Inherit method and account | VERIFIED (defect WR-02) | `inheritedAccount()` lines 159-168 |
| `app/Domain/Invoicing/Pdf/InvoicePdfData.php` | Payment box by method | VERIFIED | Lines 267-277, 332, 367 |
| `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php` | Issued and draft editor fields | STUB-LIKE for one path | Fields exist and are wired, but `issuedValues()` lines 803-809 drops the account (CR-01) |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `UpdateIssuedDocument` | `PaymentLinks` | `IssuedEditFinisher::finish(forceNewLink)` | WIRED | Link deactivated and reopened inside transaction, jobs afterCommit |
| `EditInvoice::issuedValues` | `UpdateIssuedDocument` / `DocumentPayment::forIssued` | `bank_account_id` payload key | PARTIAL | Key omitted in the CR-01 sequence, causing the wrong branch |
| `ConvertProforma` | `DocumentPayment` | `inheritedAccount()` | PARTIAL | No snapshot fallback (WR-02) |
| `InvoicePdfData` | `invoices.payment_method` | enum comparison | WIRED | |
| Partner views | `invoices.payment_method` only | `PartnerSafeColumnsTest` pins the new column | WIRED | Bank id, switch and token stay on `invoice_admin` |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| PDF payment box | `bank_snapshot` / `payment_method` | `invoices` row written by `DocumentIssuer` / `forIssued` | Yes (snapshot copied from settings by id, never from the request) | FLOWING |
| Issued editor account field | `bank_account_id` | `invoice_admin.bank_account_id` via `filledAccountId()` | Yes, but the write path drops it in the CR-01 sequence | PARTIAL |
| Pay URL | `pay_token` | set once by `PayToken::generate()` via `saveAdmin` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Arch rule: no currency lookups, account by id | `ddev exec vendor/bin/pest tests/Arch/BankAccountByIdTest.php` | passed | PASS |
| Document payment rules, currency list, issued currency change, card switch | `ddev exec vendor/bin/pest` on `DocumentPaymentTest`, `InvoiceCurrenciesTest`, `IssuedCurrencyChangeTest`, `CardSwitchTest`, `BankAccountByIdTest` | 84 passed (401 assertions) | PASS |
| Full suite (regression gate, run by the orchestrator after every wave, not re-run by me) | `ddev exec vendor/bin/pest` | 6768 passed, 3 skipped (orchestrator-reported) | PASS (reported) |
| CR-01 re-pick sequence | none exists; read `EditInvoice.php` 803-809 and `DocumentPayment.php` 244-250, 351-353 | Defect present by reading; no test covers it | FAIL |

### Probe Execution

Step 7c: SKIPPED. The phase declares no `probe-*.sh` scripts.

### Requirements Coverage

All nine IDs P14-01..P14-09 are declared across the 11 plans' `requirements:` frontmatter and are mapped to Phase 14 in REQUIREMENTS.md (all marked Complete). No orphaned requirements.

| Requirement | Source Plans | Description | Status | Evidence |
| ----------- | ------------ | ----------- | ------ | -------- |
| P14-01 | 14-02, 14-04, 14-06, 14-07, 14-08, 14-09, 14-11... | Exclusive payment method, default transfer; credit note none | SATISFIED | Truth 2 |
| P14-02 | 14-05, 14-06 | Transfer prints bank box/VS/QR; online prints none | SATISFIED | Truth 3 |
| P14-03 | 14-01, 14-09, 14-11 | Several accounts per currency; stable ids; document stores account separately | SATISFIED | Settings migration, `invoice_admin.bank_account_id`, arch test |
| P14-04 | 14-04, 14-07, 14-08, 14-09, 14-10 | Currency preselects first account; any account choosable; no match blocks transfer issue | BLOCKED in issued editor | Draft path satisfied; CR-01 breaks the issued editor; WR-02 touches conversion |
| P14-05 | 14-03, 14-08, 14-10 | Union currency list | SATISFIED | Truth 1 |
| P14-06 | 14-02, 14-07, 14-10, 14-11 | Method, account, currency editable on open issued, frozen when locked | PARTIALLY SATISFIED | Account edit hits CR-01 |
| P14-07 | 14-07, 14-09, 14-11 | Method/currency change deactivates live link, queues new one, pay URL stable | SATISFIED | Truth 6 (WR-01 edge case noted) |
| P14-08 | 14-07, 14-09 | Currency change refused after payment; method/account allowed | SATISFIED | Truth 7 |
| P14-09 | 14-04, 14-08, 14-10 | Online requires card availability, blocks issuing otherwise | SATISFIED for online; WR-01 for the reverse switch | Truth 8 |

### Code Review Findings (14-REVIEW.md) weighed against the must-haves

| ID | Severity | Violates a must-have? | Disposition |
| -- | -------- | --------------------- | ----------- |
| CR-01 | Critical | Yes: plan 14-10 truth 2, P14-04/P14-06, roadmap goal clause on independent account choice | Gap 1, BLOCKER; confirmed in code |
| WR-01 | Warning | Yes (edge): plan 14-07 truth 5 (turning the switch on needs availability) | Gap 2, partial; confirmed in code |
| WR-02 | Warning | Yes (edge): plan 14-09 truth 1 docblock promise of always-convertible paid proforma | Gap 3, partial; confirmed by reading |
| IN-01 | Info | No | No DB tie between online method and card switch; application-level only. Document in migration docblock |
| IN-02 | Info | No | Stale wording in `IssuedEditFinisher.php:101`, `InvoiceCurrencies.php:25`, CONTRIBUTING Card payments entry (token "set once with the first issue") |
| IN-03 | Info | No | Currency list includes retired CNB codes |
| IN-04 | Info | No | Online document gives a Partner no pay path while the link is inactive; by design (D-01) |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| `EditInvoice.php` | 803-809 | Payload filtered by comparison with the stored value, ignoring a same-save currency change | Blocker | CR-01 |
| `DocumentPayment.php` | 313-327 | Availability guard conditioned on `! $storedSwitch` | Warning | WR-01 |
| `ConvertProforma.php` | 159-168 | Silent null on a removed account, no snapshot fallback | Warning | WR-02 |

No TBD/FIXME/XXX debt markers were checked beyond the reviewer's scan (the review reported clean hygiene and `scripts/check-sensitive.sh` clean). PHPStan has one known pre-existing error (`ResetAdminTwoFactorCommand.php:39`, logged in `deferred-items.md`, not touched by this phase).

### Human Verification Required

Recorded as `human_verification` in the frontmatter. These are deferred by plans 14-08 and 14-10 and are needed in addition to closing the gaps (the overall status stays `gaps_found` because a gap takes precedence).

1. **Draft editor payment method, account and card toggle** (plan 14-08 tracer). Test: in the DDEV panel switch method, toggle card payment, change currency, pick an account. Expected: online disabled with a reason when unavailable; online forces the toggle on and disabled; back to transfer resets to the client flag; currency change preselects the first matching account, empty with the hint when none; any account selectable. Why human: reactive Filament form state and disabled/helper-text rendering.
2. **Issued editor payment fields** (plan 14-10 tracer). Test: in the DDEV panel edit an open issued document's method, account, toggle and currency, including the CR-01 sequence after the fix. Expected: same rules as the draft editor; account section shows printed or to-be-printed account, nothing for online; refusals appear under their field. Why human: visual and interaction behaviour.

### Gaps Summary

One blocker and two partial warnings stop the phase from being marked passed.

1. **CR-01 (blocker)**: In the open issued editor, after a currency change the Admin cannot keep the originally stored bank account by re-picking it. The page omits the account from the payload because it equals the stored value, and the Action then preselects by the new currency: either the save fails permanently with `bank_account_required` (no account in the new currency) or X is silently replaced. This contradicts "the account can also be chosen separately and independently of the currency" for issued documents and plan 14-10 truth 2. Fix is small (send the account when the currency changed in the same save) plus one regression test.
2. **WR-01**: transfer documents can retain or be forced into an enabled card switch while card payment is unavailable (online to transfer; unrelated save of an online document with switch off), producing a pay URL whose link can never be created.
3. **WR-02**: a paid proforma whose account was removed from the settings may fail to convert (no same-currency account) or the invoice prints a different account than the proforma did.

Recommended next step: `/gsd-plan-phase 14 --gaps` to close CR-01, WR-01 and WR-02, then re-verify and run the two deferred DDEV visual checks. IN-01 to IN-04 can be folded into the same closure plan as low-effort doc fixes.

---

_Verified: 2026-10-23_
_Verifier: Claude (gsd-verifier)_
