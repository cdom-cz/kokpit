---
phase: 10-invoicing
plan: 17
subsystem: invoicing-foreign-currency
tags: [invoicing, cnb, conversion, preview, vat-non-payer, issue]

requires:
  - phase: 10-invoicing
    provides: "DocumentIssuer, IssueChecks, the billing step and the time source of drafts (plans 10-12, 10-13, 10-14)"
  - phase: 10-invoicing
    provides: "InvoicePdf html() for the non-payer PDF assertions (plan 10-15)"
  - phase: 01-foundation
    provides: "Money::timesThroughCzk() and convertThroughCzk(), the single-rounding conversion (plan 10-01 R-01-1)"
provides:
  - "DocumentIssuer conversion step: before the state flips, every non-manual time line whose rate currency differs from the document currency is priced with the CNB lists of the issue date, amount = timesThroughCzk (one rounding), unit price = convertThroughCzk (display), price_converted true, conversion_snapshot frozen, document totals recomputed from the stored line amounts"
  - "DraftPreview: the one conversion method (convertLine), linePreview() and czkPreview() for the draft editor and the issue confirmation, Admin or system only"
  - "UpdateDraft re-preview: every save and every currency change re-previews generated converted lines single-rounded, never touches a typed price, and refuses a currency change whose list is missing with cnb_missing under currency"
  - "IssueChecks blockers: cnb_missing for every line rate currency that needs a quote, vat_payer_unsupported while payer calculations are deferred; assertIssuable keeps every message of a repeated key"
affects: [10-18, 10-20, 10-21, 10-23]

actuals:
  tokens: 26000
  tasks: 3
  commits: 3
plan_head_before: 8f3ab7b9a0cf26f8dfebc673cebb2465f2fa45db
plan_head_after: 72d2a51dd03a68e31c7ab62962ce18095801138a
commits: 3

coverage:
  - deliverable: "At issue every non-manual time line in another currency than the document is converted with the lists of the issue date: line amount rounded once from the exact conversion (59701 against the two-rounding 59700), printed unit price a display figure, price_converted set, quotes and list date frozen in conversion_snapshot, document totals the sum of the stored amounts, and the CNB trio and CZK total frozen in invoice_admin"
    requirement: "IN-07"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#it is issued with the lists of the issue day, one rounding per line, and freezes every quote"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#it converts a USD-rated line on a EUR invoice through both quotes with one rounding"
        status: pass
    human_judgment: false
  - deliverable: "A hand-typed price is never converted and stays exact; a missing CNB list for any currency the issue needs refuses the issue with cnb_missing naming the currency and the date, and nothing is consumed"
    requirement: "IN-07"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#it keeps the unit price typed by hand and converts the other line"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#it refuses the issue naming the currency when a list is missing and consumes nothing"
        status: pass
    human_judgment: false
  - deliverable: "The draft previews converted lines and the CZK equivalent with the lists of today by the same single-rounding method; every save and currency change re-previews generated lines without making a two-rounding figure or changing a typed price; the issued line equals the last preview when the list is the same; the preview is never stored as the final rate; both readers refuse a Partner"
    requirement: "IN-07"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#the draft preview"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#saving a draft keeps converted lines single-rounded"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/ForeignCurrencyIssueTest.php#changing the currency of a draft"
        status: pass
    human_judgment: false
  - deliverable: "Non-VAT-payer mode is the default and proven end to end: issued invoices and proformas have vat_minor 0, no item VAT rate, no taxable-supply date, the PDF has no VAT column or supply date and prints the non-payer note in Czech and English, and the database refuses a VAT amount on a non-payer document"
    requirement: "IN-08"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/NonVatPayerTest.php#the default non-payer mode"
        status: pass
    human_judgment: false
  - deliverable: "While VAT-payer calculations are deferred, issuing in payer mode is blocked with 'Režim plátce DPH zatím není podporován. Přepněte režim DPH v Nastavení.', consumes nothing, and works again after switching back"
    requirement: "IN-08"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/NonVatPayerTest.php#it blocks issuing with the Czech message and consumes nothing, and issuing works again in non-payer mode"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "One conversion method for a converted time line (DraftPreview::convertLine) shared by the preview, the save of a draft and the issue, so with the same list the preview and the issued figures are identical and the amount is rounded exactly once"
    - "A generated line's shown price is not a typed price: only a unit price that differs from the stored one marks the line manual, and a change of currency ignores the figure sent for generated lines"

key-files:
  created:
    - app/Domain/Invoicing/Support/DraftPreview.php
    - tests/Feature/Invoicing/ForeignCurrencyIssueTest.php
    - tests/Feature/Invoicing/NonVatPayerTest.php
  modified:
    - app/Domain/Invoicing/Issuing/DocumentIssuer.php
    - app/Domain/Invoicing/Support/IssueChecks.php
    - app/Domain/Invoicing/Actions/UpdateDraft.php
    - lang/cs/invoices.php

key-decisions:
  - "R-17-1 applied as written: one rounding per converted line; the owner confirmation point of the plan (this behaviour together with plan 10-01 R-01-1, or the two-step rule) is still open and is recorded here for the owner"
  - "R-17-2 snapshot {source_currency, source_rate, source_unit, target_currency, target_rate, target_unit, rate_date}; the CZK side is rate '1', unit 1, rate_date is the later of the foreign list dates"
  - "R-17-3 payer mode blocks issuing with the blocker key vat_payer_unsupported, checked for every kind of document"
  - "czkPreview() and linePreview() throw ExchangeRateMissing when a needed list does not exist (never a zero); czkPreview() returns null only for CZK"
  - "A line whose source already carries a conversion_snapshot is not converted again at issue, so a frozen line copied later (proforma conversion) keeps its quotes"

patterns-established:
  - "Pest helpers of the new tests are prefixed foreignIssue and nonPayer to stay unique in the global function namespace"

requirements-completed: [IN-07, IN-08]

duration: about 1 h 20 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 17: Foreign-currency issue, current-rate preview and non-VAT-payer proof Summary

**A EUR invoice built from CZK-rated time is priced at issue with the CNB lists of the issue date, one rounding per line (59,70 EUR shown, 597,01 EUR amount, not 597,00), every quote frozen in the line and the document; the draft previews the same figures with today's lists and keeps them single-rounded on every save, and the default non-payer mode is proven end to end while payer mode blocks issuing.**

## Performance

- **Duration:** about 1 h 20 min (the start time was not recorded precisely)
- **Tasks:** 3 (1 tracer, 2 auto/tdd)
- **Files:** 3 created, 4 modified

## Accomplishments

- `DocumentIssuer::convertTimeLines()` runs before anything is written for the issue: it converts each non-manual time line through `DraftPreview::convertLine()` (amount `timesThroughCzk`, unit price `convertThroughCzk`), sets `price_converted`, freezes the conversion snapshot, and recomputes the document totals from the stored amounts before `writeAdminRow()` computes the CZK total. A missing list becomes `cnb_missing` naming the currency and the date; a converted total that is no longer positive is refused with `total_not_positive`.
- `DraftPreview` is the single place that converts a line. `linePreview()` and `czkPreview()` give the editor and the confirmation the figures of today's lists; both are Admin or system only.
- `UpdateDraft` re-previews generated converted lines on every save (so an edit of the quantity gives 71 642 for 12 h, not the two-rounding 71 640), restores or converts them on a currency change with or without `items`, keeps typed prices exactly as typed, and keeps the stored preview of a line when its list is missing. A currency change whose list is missing is refused under `currency`.
- `IssueChecks` adds a `cnb_missing` blocker for every line rate currency that needs a quote and `vat_payer_unsupported`; `assertIssuable()` now keeps every message of a repeated key instead of the last one only.
- `NonVatPayerTest` pins the non-payer data, the PDF (Czech and English), the database CHECKs on an invoice and a line, and the payer-mode blocker with a round trip back to non-payer.

## Task Commits

1. **Task 1 (tracer): conversion at issue with frozen quotes and the line-currency blockers** - `1f00947` (feat)
2. **Task 2: DraftPreview re-preview on save and on a currency change** - `c9e0a5a` (feat)
3. **Task 3: non-VAT-payer proof and the payer-mode blocker** - `72d2a51` (feat)

## Verification

- Tracer gate: `ForeignCurrencyIssueTest`, `IssueDocumentTest`, `IssueBillingTest` (33 tests), Pint and PHPStan passed before the expansion tasks.
- `tests/Feature/Invoicing`: 172 passed after Task 3; Pint `--test` and PHPStan clean after every task.
- Full suite once at the end (`ddev exec vendor/bin/pest --compact`): 5426 passed, 3 skipped, 0 failed (includes the architecture tests).
- All acceptance greps of the three tasks pass; `scripts/check-sensitive.sh` clean on every staged set; lefthook (sensitive-content, gitleaks) passed on all commits.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] `assertIssuable()` collapsed repeated blocker keys**
- **Found during:** Task 1
- **Issue:** `array_column($blockers, 'message', 'key')` keeps only the last message of a key; with several currencies missing the exception would name only one.
- **Fix:** Messages are grouped per key into lists, so `errors()['cnb_missing']` holds one message per missing currency (`[0]` is unchanged for a single blocker).
- **Files modified:** `app/Domain/Invoicing/Support/IssueChecks.php`
- **Commit:** 1f00947

**2. [Rule 2 - Missing critical functionality] Currency change without `items`, overflow and quantity-stale preview fallback**
- **Found during:** Task 2
- **Issue:** A currency change that carries no `items` would leave generated lines in the old currency's figures under the new currency code; a missing list on an ordinary save must keep the stored preview but a changed quantity would make the stored amount violate the database bound; the conversion can overflow.
- **Fix:** `repriceGenerated()` re-previews the generated lines on a currency change without `items`; with a missing list the stored unit price is kept and the amount follows a changed quantity; an overflow is an `items` field error.
- **Files modified:** `app/Domain/Invoicing/Actions/UpdateDraft.php`
- **Commit:** c9e0a5a

**3. [Rule 3 - Blocking] A pre-issue re-total and a double-conversion guard in the issuer**
- **Found during:** Task 1
- **Issue:** The blockers check the stored preview total, so a converted total could round to zero and trip the database sign CHECK at the flip; a line already frozen (a later copy of a proforma line) must not be converted twice.
- **Fix:** The issuer re-checks the sign of the converted total (`total_not_positive`) and skips a source that already carries a `conversion_snapshot`.
- **Files modified:** `app/Domain/Invoicing/Issuing/DocumentIssuer.php`
- **Commit:** 1f00947

**Total deviations:** 3 auto-fixed (2 missing critical, 1 blocking). **Impact:** none on the plan's contracts; `UpdateDraft` and `DocumentIssuer` gained constructor dependencies (`DraftPreview`, `ExchangeRateConverter`) resolved by the container.

## Known Stubs

None.

## Threat Flags

None. T-10-46 is mitigated by a blocker for every needed currency, `rateFor()` throwing and Money refusing a zero rate (tested with a currency that has no list). T-10-47 by the issue looking the lists up again (tested with two different lists on two days). T-10-87 by `timesThroughCzk()` being the only path for converted amounts in the issuer, the preview and `UpdateDraft` (tests pin 59701 and 71642 against 59700 and 71640). No new endpoint, auth path or file access.

## Notes for later plans

- Owner confirmation point of ruling R-17-1 (together with plan 10-01 R-01-1) is still pending: a converted line prints its amount rounded once, which can differ by rounding from quantity times the printed unit price. Plan 10-21 records it in the UI-SPEC.
- Plan 10-21 reads `DraftPreview::linePreview()` (display unit price, single-rounded amount, rate, list date) and `czkPreview()` (CZK total, rate, unit, date). Both throw `ExchangeRateMissing` when a needed list does not exist, so the page catches it and shows the `cnb_missing` blocker instead of a figure. The field key for a refused currency change is `currency`.
- Plan 10-20 (proforma conversion): lines whose source carries a `conversion_snapshot` are skipped by the issuer's conversion step.
- `vat_payer_unsupported` is a new key of `lang/cs/invoices.php` errors and of the blockers list (plan 10-21 lists it in the confirmation modal and the UI-SPEC).

## Self-Check: PASSED

- Created files exist: `DraftPreview.php`, `ForeignCurrencyIssueTest.php`, `NonVatPayerTest.php`.
- Commits `1f00947`, `c9e0a5a` and `72d2a51` are ancestors of HEAD.
