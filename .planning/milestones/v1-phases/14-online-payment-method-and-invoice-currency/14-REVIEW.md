---
phase: 14-online-payment-method-and-invoice-currency
reviewed: 2026-10-23T00:00:00Z
depth: standard
files_reviewed: 73
files_reviewed_list:
  - CONTRIBUTING.md
  - app/Domain/Invoicing/Actions/ConvertProforma.php
  - app/Domain/Invoicing/Actions/CreateDraft.php
  - app/Domain/Invoicing/Actions/UpdateDraft.php
  - app/Domain/Invoicing/Actions/UpdateIssuedDocument.php
  - app/Domain/Invoicing/Enums/DocumentPaymentMethod.php
  - app/Domain/Invoicing/Issuing/DocumentIssuer.php
  - app/Domain/Invoicing/Models/Invoice.php
  - app/Domain/Invoicing/Models/InvoiceAdmin.php
  - app/Domain/Invoicing/Pdf/InvoicePdfData.php
  - app/Domain/Invoicing/Support/DocumentPayment.php
  - app/Domain/Invoicing/Support/InvoiceAudit.php
  - app/Domain/Invoicing/Support/InvoiceCurrencies.php
  - app/Domain/Invoicing/Support/InvoiceSnapshot.php
  - app/Domain/Invoicing/Support/IssueChecks.php
  - app/Domain/Invoicing/Support/IssuedDocumentRules.php
  - app/Domain/Invoicing/Support/IssuedEditFinisher.php
  - app/Domain/Payments/Actions/RegeneratePaymentLink.php
  - app/Domain/Payments/Enums/PaymentLinkState.php
  - app/Domain/Payments/Queries/PaymentLinkStatus.php
  - app/Domain/Payments/Support/CardPaymentSwitch.php
  - app/Domain/Payments/Support/PayLinkBlock.php
  - app/Domain/Payments/Support/PayToken.php
  - app/Domain/Payments/Support/PayUrl.php
  - app/Domain/Payments/Support/PaymentLinks.php
  - app/Domain/Settings/Banking/BankAccount.php
  - app/Domain/Settings/Casts/BankAccountListCast.php
  - app/Domain/Settings/Rules/UniqueAccountIds.php
  - app/Domain/Settings/Settings/BankAccountSettings.php
  - app/Filament/Pages/SettingsPage.php
  - app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php
  - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
  - app/Filament/Support/CreateDraftAction.php
  - app/Filament/Support/InvoiceColumns.php
  - app/Filament/Support/InvoiceTable.php
  - app/Filament/Support/PayByCardAction.php
  - database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php
  - database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php
  - database/settings/2026_10_24_000100_add_ids_to_bank_accounts.php
  - lang/cs/enums.php
  - lang/cs/invoice_pdf.php
  - lang/cs/invoices.php
  - lang/cs/kokpit.php
  - lang/en/invoice_pdf.php
  - tests/Arch/BankAccountByIdTest.php
  - tests/Arch/InvoiceUnlockPathTest.php
  - tests/Feature/Invoicing/ConvertProformaTest.php
  - tests/Feature/Invoicing/CreateDraftModalTest.php
  - tests/Feature/Invoicing/DocumentPaymentTest.php
  - tests/Feature/Invoicing/DraftEditorTest.php
  - tests/Feature/Invoicing/InvoiceCurrenciesTest.php
  - tests/Feature/Invoicing/InvoiceDetailTest.php
  - tests/Feature/Invoicing/InvoiceEditAuditTest.php
  - tests/Feature/Invoicing/InvoicePdfTest.php
  - tests/Feature/Invoicing/InvoicePdfVariantsTest.php
  - tests/Feature/Invoicing/IssueDocumentTest.php
  - tests/Feature/Invoicing/IssueFlowTest.php
  - tests/Feature/Invoicing/IssuedCurrencyChangeTest.php
  - tests/Feature/Invoicing/IssuedEditorTest.php
  - tests/Feature/Invoicing/IssuedHeaderEditTest.php
  - tests/Feature/Invoicing/PartnerInvoiceResourceTest.php
  - tests/Feature/Operations/BankAccountSettingsTest.php
  - tests/Feature/Payments/CardSwitchTest.php
  - tests/Feature/Payments/CreatePaymentLinkTest.php
  - tests/Feature/Payments/LinkLifecycleTest.php
  - tests/Feature/Payments/PayButtonOutputsTest.php
  - tests/Feature/Repo/RepositoryFilesTest.php
  - tests/Feature/Schema/InvoiceAdminTablesTest.php
  - tests/Feature/Schema/InvoiceLockGuardTest.php
  - tests/Feature/Schema/InvoiceTablesTest.php
  - tests/Isolation/InvoiceLeakTest.php
  - tests/Isolation/PartnerSafeColumnsTest.php
  - tests/Support/InvoiceFixtures.php
findings:
  critical: 1
  warning: 2
  info: 4
  total: 7
status: issues_found
---

# Phase 14: Code Review Report

**Reviewed:** 2026-10-23
**Depth:** standard
**Files Reviewed:** 73 (the scope list names 74 paths; `app/Domain/Settings/Rules/UniqueCurrencies.php` was deleted by the phase and no longer exists on disk)
**Status:** issues_found

## Summary

The phase is carefully built. The parts the review was asked to focus on mostly hold up:

- **Row guard and migrations.** `kokpit_guard_invoice_row()` grows the open-column list by exactly `payment_method`; the INSERT-branch default, the `invoices_payment_method_kind_check` and the amended `invoices_issued_fields_check` (`(payment_method = 'online') = (bank_snapshot IS NULL)`) are consistent with each other, with the backfill (which covers every non-credit-note row, drafts included) and with the Phase 10.1 body restored by `down()`. The three-valued-logic hole in that check (a NULL method passes it) is closed by the kind check. The backfill disables only `invoices_row_guard`, around one statement, inside the migration transaction. Both `down()` methods refuse when the old constraints could not hold the data. `kokpit_guard_invoice_admin_issued()` still pins `invoice_id` and a set `pay_token` on an issued parent, and the unchanged child guard still freezes `card_payment_enabled`, `pay_token` and `bank_account_id` under a locked parent.
- **Partner leakage.** `payment_method` is the only new column on the Partner-readable `invoices` row, and it is pinned in `PartnerSafeColumnsTest`. The bank id, the card switch and the token stay on `invoice_admin`. `InvoiceSnapshot::bankAccount()` uses the id-free `toArray()`. The Partner detail hides the bank block and the variable symbol for an online document and shows no bank data to an online document through any other surface I could find (list, PDF, detail, activity log, pay page, e-mail block). Switching transfer to online nulls `bank_snapshot` and replaces the stored PDF, so no old bank-bearing file stays.
- **Payment-link boundaries.** Every deactivation (`reconcile`, `deactivateLive`) runs inside the caller's transaction under the invoice row lock, the Stripe-side deactivation and link creation are `afterCommit` jobs, and a rollback discards them. A new link is opened only after the old one is deactivated in the same transaction, so the at-most-one-live-link rule holds.
- **Hygiene.** No real values found in code, tests, migrations or lang files; `scripts/check-sensitive.sh` reports clean; test IBANs come from `FictionalIban`.

One functional dead end was found in the issued-document editor (CR-01), two correctness defects in the card-switch and proforma-conversion rules (WR-01, WR-02), and a few documentation and robustness notes.

## Critical Issues

### CR-01: An open issued transfer document cannot keep its bank account when the currency changes to one without an account

**File:** `app/Filament/Resources/InvoiceResource/Pages/EditInvoice.php:803-809` (with `app/Domain/Invoicing/Support/DocumentPayment.php:244-250` and `:351-353`)
**Issue:** `issuedValues()` sends `bank_account_id` to `UpdateIssuedDocument` only when the form value differs from `filledAccountId()` (the stored account). When the Admin changes the currency, `currencyChosen()` (EditInvoice.php:1694) overwrites the form value with `DocumentPayment::preselect($currency)`. If the Admin then re-picks the original account X in the dropdown (the D-05/D-06 case "any account, any currency, independent of the document currency"), the form value equals the stored value, so the key is dropped from the payload. In `DocumentPayment::forIssued()` the missing key plus `$currencyChanged` takes the preselect branch (`$accountId = self::preselect($currency)`), and `issuedSnapshot()` then falls back to `firstForCurrency($currency)`.

Trace for a EUR document that prints account X (EUR), changed to USD with no USD account configured:

1. The form preselects `null` for USD.
2. The Admin picks X again; the form value equals the stored X, so nothing is sent.
3. The Action preselects `null`, `issuedSnapshot()` finds no account and throws `bank_account_required`.

Every retry gives the same result, and there is no two-step workaround (a currency-only save fails the same way). The Admin can neither keep X nor change the currency. When a USD account does exist, the same path silently replaces X with the USD account even though the Admin explicitly chose X. The Action itself is correct when the id is sent; the page decides wrongly what to send.
**Fix:** Send the account whenever it is non-null and either differs from the stored one or the currency changed:
```php
if (array_key_exists('bank_account_id', $data)) {
    $account = self::text($data['bank_account_id']);
    $currencyChanged = is_string($data['currency'] ?? null)
        && strtoupper($data['currency']) !== $invoice->currency;

    if ($account !== $this->filledAccountId() || ($currencyChanged && $account !== null)) {
        $values['bank_account_id'] = $account;
    }
}
```
Add a Pest case: issued transfer document, currency changed to one with no account, account X re-picked, expect the save to succeed with X in `bank_snapshot` and `invoice_admin.bank_account_id`.

## Warnings

### WR-01: The card switch can end up on while card payment is unavailable (online to transfer, and forced-on for an online document)

**File:** `app/Domain/Invoicing/Support/DocumentPayment.php:313-327` (`issuedSwitch()`)
**Issue:** The availability guard `if ($switch && ! $storedSwitch && ! available())` only runs on the non-online branch and only when the stored switch was off. Two consequences:

1. Online to transfer with no explicit value: the switch is derived from `$locked->client?->online_payment_enabled`. The stored switch of an online document is always on, so the guard never fires. If the global setting was switched off or Stripe credentials were removed after the document was issued, the transfer document keeps `card_payment_enabled = true`. The UI cannot correct it: `methodChosen()` sets the toggle to `flag && available()`, but the toggle is disabled while card payment is unavailable and a disabled field is not dehydrated, so the Action derives the value itself. `method_changed` then forces a new payment link, the PDF prints a "Pay by card" button for the token, and `CreatePaymentLink` fails at Stripe. The customer gets a pay URL that only ever shows "preparing".
2. An online document whose stored switch is off (for example one made by `ConvertProforma` while Stripe was unavailable) gets the switch forced on by any unrelated save, with `card_changed` true and a new link forced, again regardless of availability.

**Fix:** Apply the availability guard to every result that turns the switch on or keeps it on after a method change:
```php
if ($method === DocumentPaymentMethod::Online) {
    // online keeps the existing rule: choosing it needs availability (checked in forIssued) ...
    return $storedSwitch || app(CardPaymentSwitch::class)->available();
}

$switch = match (true) { /* as today */ };

if ($switch && (! $storedSwitch || $storedMethod === DocumentPaymentMethod::Online)
    && ! app(CardPaymentSwitch::class)->available()) {
    $switch = $explicit === true ? throw ValidationException::withMessages([...]) : false;
}
```
(An explicit request to turn it on still throws; the derived value simply degrades to off.) Add tests for online to transfer with Stripe removed and for an unrelated save of an online document with the switch off.

### WR-02: A paid proforma can become unconvertible, or the invoice prints a different bank account than the proforma, when the proforma's account was removed from the settings

**File:** `app/Domain/Invoicing/Actions/ConvertProforma.php:159-168` (with `app/Domain/Invoicing/Support/IssueChecks.php:82-84`)
**Issue:** `inheritedAccount()` returns `null` when the proforma's account id is no longer configured, and the docblock promises "a paid proforma never becomes unconvertible because its account was removed". That holds only if another account has the proforma's currency. D-06 allows an account in any currency, so a proforma that uses a different-currency account X, after X is deleted, gives a draft with no stored account. `IssueChecks::blockers()` then reports `bank_missing` (`DocumentPayment::account()` falls back to `firstForCurrency`, which is null), `assertIssuable()` throws, and the whole conversion fails. The Admin cannot pick an account inside the atomic conversion, so a fully paid proforma is stuck until a matching account is added to the settings. In the other branch (another same-currency account exists) the invoice silently names a different bank account than the proforma the customer paid to.
**Fix:** When the account is gone but the proforma carries a `bank_snapshot`, carry that snapshot instead of failing. For example, give `DocumentIssuer::bankSnapshot()` an optional "inherited snapshot" for converted drafts (a draft with `proforma_id` set whose account cannot be resolved), and skip the `bank_missing` blocker in that case. Cover it with a test: transfer proforma in a currency with no other account, account deleted, conversion succeeds with the proforma's snapshot.

## Info

### IN-01: No database-level tie between the `online` method and the card switch

**File:** `database/migrations/2026_10_24_000300_add_bank_account_and_open_card_switch_to_invoice_admin.php:61-70` (and `:77`)
**Issue:** The invariant "an online document has the card switch on and a token" lives only in `DocumentPayment`. `invoice_admin_pay_token_check` was loosened to a format check and no constraint couples `invoices.payment_method = 'online'` with `invoice_admin.card_payment_enabled`. A raw-SQL writer or a future code path could leave an issued online document with no bank snapshot and no card payment. A cross-table rule needs a trigger rather than a CHECK, so this may be an accepted limit; a short note in the migration docblock (next to the existing "Limits" paragraph) would make that explicit.
**Fix:** Document the application-level-only invariant, or add a guard on `invoice_admin` that rejects `card_payment_enabled = false` while the parent is an issued online document.

### IN-02: Stale wording in docblocks and the hand-over notes

**File:** `app/Domain/Invoicing/Support/IssuedEditFinisher.php:101`, `app/Domain/Invoicing/Support/InvoiceCurrencies.php:25`, `CONTRIBUTING.md` (the Card payments entry)
**Issue:** `IssuedEditFinisher::paymentLink()` still says "its frozen card switch on", but the switch is editable on an open document since OA-1. `InvoiceCurrencies` says a currency without a bank account is "the refusal of the issued currency change", but the currency no longer needs an account of its own (D-05, D-07); only a transfer document without any resolvable account is refused. The CONTRIBUTING Card payments entry says the pay token "is set once, with the first issue", which contradicts `PayToken` and the migration (a first token may be set later on an open issued document).
**Fix:** Reword the three places ("its card switch on", "a transfer document with no resolvable account is refused as `bank_account_required`", "with the first issue or, later, on an open issued document").

### IN-03: The currency list offers every currency CNB ever published

**File:** `app/Domain/Invoicing/Support/InvoiceCurrencies.php:35`
**Issue:** `ExchangeRate::query()->distinct()->pluck('currency')` includes currencies CNB has since stopped publishing (any code that still passes `Money::isKnownCurrency`). D-07 asks for currencies "for which CNB rates are available". A retired code can be selected and only fails at issue or at the issued currency change with `cnb_missing`, which is a clear message but a poor dropdown.
**Fix:** Optionally restrict to codes that have a row on or after a recent cutoff (for example the latest list date) while keeping the union with the account, client and document currencies.

### IN-04: An online document gives a Partner no way to pay when its link is not active

**File:** `app/Filament/Support/InvoiceColumns.php:159-164, 199-222`, `app/Domain/Payments/Queries/PartnerPayButton.php:34-45`
**Issue:** By design (D-01) an online document shows a Partner no bank account and no variable symbol, and the pay button appears only while the newest link is active. While a link is pending, failed or deactivated, the Partner detail shows neither bank details nor a button and no explanation. This is not a leak and matches the spec, but it is a dead-end for the customer; the same holds for the printed PDF, whose button leads to the "preparing" page.
**Fix:** Optionally show a short neutral line on the Partner detail for an unpaid online document without an active link (for example "The payment link is being prepared, please try again shortly"), without exposing the link state, amount or any Admin-only fact.

---

_Reviewed: 2026-10-23_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
