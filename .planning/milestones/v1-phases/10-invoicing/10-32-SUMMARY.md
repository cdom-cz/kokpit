---
phase: 10-invoicing
plan: 32
subsystem: invoicing-phase-close
tags: [invoicing, isolation, end-to-end, documentation, hand-over, validation, phase-gate]
status: complete

requires:
  - phase: 10-invoicing
    provides: "Every Phase 10 plan: the Partner leak harness and InvoiceScenario (10-26), the payment, proforma, storno and e-mail flows (10-16 to 10-24, 10-30, 10-31), DeletePayment (10-41), the Partner view (10-27, 10-28) and the dashboard card (10-35, 10-37, 10-39, 10-40)"
provides:
  - "tests/Isolation/InvoiceLeakTest.php: the Partner sweep of every Admin invoicing surface (transactions screen, the three invoice relation managers, the client invoice panel and overview widget, the dashboard card endpoint, ViewInvoice, the activity route), each refusal paired with an Admin positive, and the whole phase flow in one test"
  - "CONTRIBUTING: the rewritten Phase 10 (invoicing) bullet with the inherited rules, the new Phase 11 (Stripe) hand-over bullet and the corrected Time entries sentence (EntryBilling is the single writer, R-09-2)"
  - "README: the bundled Roboto fonts and their OFL-1.1 licence"
  - "RepositoryFilesTest: every class, method, route and test named in the Phase 10 paragraph must exist; the Phase 11 bullet and the README note are checked"
  - "10-VALIDATION.md: 103 task rows for plans 10-01 to 10-41, Wave 0 done, nyquist_compliant true, manual and backstop rows for the end-of-phase human verification"
affects: [11-stripe, phase-10-verification]

actuals:
  tokens: 25500
  tasks: 3
  commits: 3
plan_head_before: d600a5b950beb7a8b3ab14a1b1711d5a28e87646
plan_head_after: 6c6eb0464fe7314008f6142ae406048a25e718dc
commits: 3

tech-stack:
  added: []
  patterns:
    - "A Partner sweep asserts the refusal of a Livewire component (assertForbidden) next to an Admin assertSuccessful on the same component, so a refusal cannot pass because the component fails for another reason"
    - "An end-to-end test builds its own fictional client in the test body and leaves the shared two-client scenario untouched, with its own canaries for the note, the tracked time and both payment notes"

key-files:
  created: []
  modified:
    - tests/Isolation/InvoiceLeakTest.php
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .planning/phases/10-invoicing/10-VALIDATION.md

key-decisions:
  - "The existing RepositoryFilesTest case that asserted the old hand-over wording of the Phase 10 bullet (MarkEntriesBilled bills, the Dashboard tile to add) was rewritten, not weakened: it now asserts the new rules, the Phase 11 bullet, and that the superseded Phase 6 sentence is gone"
  - "The project names printed on an issued invoice are Partner-visible line texts, so the end-to-end test treats them as shown and checks the secrets (internal note, tracked-time text, payment notes, the draft) instead"
  - "No .gitignore entry was added: the phase writes only to the S3 disk, the Dompdf cache path storage/framework/dompdf/ and storage/framework/testing/* were already excluded, and the repository test now pins both lines"

patterns-established:
  - "The Phase 11 hand-over lives next to the Phase 10 bullet in CONTRIBUTING and is removed by the Phase 11 closing plan, like the earlier hand-over bullets"

requirements-completed: [IN-01, IN-02, IN-03, IN-04, IN-05, IN-06, IN-07, IN-08, IN-09, IN-10, CL-03, FI-03]

coverage:
  - id: D1
    description: "A Partner meets no Admin invoicing surface: 403 on the transactions screen and the income of a payment, on every invoice relation manager, the client invoice panel and overview widget, the dashboard card, ViewInvoice and the activity route, each paired with an Admin positive; no invoice number, amount, note or canary on the Partner dashboard, in the bell or in the search"
    requirement: "IN-09"
    verification:
      - kind: integration
        ref: "tests/Isolation/InvoiceLeakTest.php#the Admin invoicing surfaces, each refused to a Partner and open to the Admin"
        status: pass
    human_judgment: false
  - id: D2
    description: "The whole flow runs in one test: draft from time with a manual line, issue with a stored PDF, one e-mail sent, a partial payment, a mistaken second payment deleted with its income, overdue after the due date (reader and dashboard card), the full payment with income in the system category, a proforma paid and converted (income at the payment date, time moved to the invoice), a storno that releases the time and issues a credit note, and a Partner view of issued documents only with a downloadable PDF"
    requirement: "IN-04"
    verification:
      - kind: integration
        ref: "tests/Isolation/InvoiceLeakTest.php#the whole phase, end to end"
        status: pass
    human_judgment: false
  - id: D3
    description: "CONTRIBUTING states the rules Phase 10 hands on and the Phase 11 notes, README names the Roboto fonts with OFL-1.1, and the repository test fails when a documented class, method, route or test is missing"
    requirement: "FI-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 10 classes, methods and tests in CONTRIBUTING.md that exist"
        status: pass
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#hands over to Phases 7, 10 and 11, carries no Phase 8 or Phase 9 note and no longer carries the Phase 6 note or the stale labels"
        status: pass
    human_judgment: false
  - id: D4
    description: "The validation map lists every task of plans 10-01 to 10-41 and the phase gate passes: full Pest suite, Pint, PHPStan level 8, composer check-licenses and scripts/check-sensitive.sh --all"
    requirement: "IN-01"
    verification:
      - kind: integration
        ref: "ddev exec vendor/bin/pest (5875 passed, 3 skipped, 634 s); pint --test PASS; composer stan [OK] No errors; composer check-licenses 225 packages allowed; check-sensitive --all clean"
        status: pass
    human_judgment: false
---

# Phase 10 Plan 32: Phase Close Summary

**One end-to-end test takes tracked time to a paid, converted and cancelled set of documents and shows the Partner of the client only the issued result; the Partner sweep, the CONTRIBUTING and README hand-over and the completed validation map close the phase with a green gate.**

## Performance

- **Tasks:** 3 of 3
- **Commits:** 3 (plus the closing metadata commit)
- **Full suite:** 5875 passed, 3 skipped, 45588 assertions, 634 s

## Accomplishments

- **Partner sweep.** Four new Admin-and-Partner cases in `InvoiceLeakTest`: the transactions screen and the income of a payment (index, edit and create answer 403 to a Partner and 200 to the Admin), the three invoice relation managers, `ClientInvoicesRelationManager`, `ClientOverviewStats`, `UnpaidInvoices` and `ViewInvoice` (Livewire `assertForbidden` against `assertSuccessful`), the client page, the invoice list, view, edit and activity routes, and the Partner dashboard, bell and search free of every own and foreign invoice number, amount and canary. No leak was found, so no application code changed.
- **End-to-end flow.** One fictional client with two projects and three time entries: a time draft plus a manual line (total 4 000), issued with a stored PDF, e-mailed once (array transport, row `Sent`), a partial payment of 1 500, a mistaken payment of 1 000 deleted with its income through `DeletePayment`, the clock moved to 2026-11-05 (status `Overdue`, 8 days, 2 500 left, the "Po splatnosti" card text), the rest paid (income in the system category on the payment date), a proforma from more time paid and converted by `ConvertProforma` (one income at the payment date, the entry moved to the invoice), the first invoice stornoed by `IssueCreditNote::storno` (entries unbilled, credit note issued). The Partner of the client then sees exactly the invoice, the proforma, the converted invoice and the credit note with the Partner states (Stornováno, Zaplaceno, Zaplaceno, Dobropis), cannot open the leftover draft (404), meets no payment row, time row, canary or forbidden string, and downloads the PDF through the signed route.
- **Documentation.** The Phase 10 bullet of `CONTRIBUTING.md` now describes the module and the inherited rules: `EntryBilling` as the single writer (R-09-2, replacing the Phase 6 wording), the `DocumentIssuer` pipeline and its order, the guards and their limits (R-04-4 to R-04-6, R-06-1, R-06-6, D-13), the invoice owner kind and the PDF route, the payment and income rules (R-19-1, R-41-1 to R-41-5, D-19 as amended, Transactions API included), the single rounding (R-01-1, R-17-1), the frozen appearance (R-03-1), and the licence notes. The new Phase 11 (Stripe) bullet carries the pay-button placement, matching by invoice id, the kind-scoped VS matching (R-07-4), the only write paths and the open R-41-4 decision. The Time entries sentence that named `MarkEntriesBilled` as the only writer was corrected.
- **Repository checks.** `RepositoryFilesTest` pins 38 classes, five methods and one enum case, the `files.invoice` route, the deletion flag, the two enforcing tests, the README note, the three font files and the two `.gitignore` lines.
- **Validation map.** 103 rows (every task of plans 10-01 to 10-41, all referenced test paths exist), Wave 0 and the sign-off ticked, `nyquist_compliant: true`, the three original manual checks kept and six UI-SPEC backstop rows added.

## Task Commits

1. **Task 1: Partner sweep and end-to-end flow** - `ee4cda1` (test)
2. **Task 2: Hand-over documentation, licence note, repository checks** - `3a452fc` (docs)
3. **Task 3: Validation map and the phase gate** - `6c6eb04` (docs)

## Deviations from Plan

None - the plan was executed as written. Two notes:

- The old `RepositoryFilesTest` hand-over case asserted wording that this plan was asked to replace (R-09-2); it was rewritten to assert the new text, not relaxed.
- The first run of the end-to-end test failed because it listed the project names as hidden; they are the printed line texts of the issued documents and belong on the Partner page. The test was corrected, not the code.

## Phase Gate

| Check | Result |
|-------|--------|
| `ddev exec vendor/bin/pest` | 5875 passed, 3 skipped, 634 s, no failure |
| `ddev exec vendor/bin/pint --test` | PASS |
| `ddev exec composer stan` | [OK] No errors |
| `ddev composer check-licenses` | 225 packages, every one has an allowed licence |
| `scripts/check-sensitive.sh --all` | clean |

The known flaky `PartnerDashboardTest.php:403` did not fail in the gate run.

## Known Stubs

None.

## Threat Flags

None. T-10-78 is mitigated by the sweep (no leak found) and T-10-79 by fictional-only data checked with `scripts/check-sensitive.sh` on every changed file and `--all` at the gate; T-10-SC: no `composer.json` or `composer.lock` change in this plan.

## Remaining for the Phase

The manual-only and backstop rows of `10-VALIDATION.md` (PDF at three sizes, draft table at 375 px, accent button contrast, create-modal spinner, long client names, PDF diacritics and page spill, long e-mail body, synchronous PDF render) are left for the end-of-phase human verification. The phase is not marked complete here; the orchestrator runs the verification.

## Self-Check: PASSED

- FOUND: tests/Isolation/InvoiceLeakTest.php, CONTRIBUTING.md, README.md, tests/Feature/Repo/RepositoryFilesTest.php, .planning/phases/10-invoicing/10-VALIDATION.md
- FOUND commits: ee4cda1, 3a452fc, 6c6eb04
