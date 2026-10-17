---
phase: 10-invoicing
plan: 37
subsystem: operations-health
tags: [invoicing, system-page, health, email, monitoring]
status: complete

requires:
  - phase: 10-invoicing
    provides: "InvoiceEmail log and SendInvoiceEmail job with the latest-row rule (plans 10-08, 10-24), InvoiceEmailState (plan 10-33), the invoices.system copy (plan 10-21), the e-mail filter of the invoice list"
provides:
  - "UnsentInvoiceEmailsIndicator: the real System page slot over the e-mail log, graded Ok / Warning / Error on the latest row of each document"
  - "HealthResult optional url and urlLabel, rendered by the System page next to the detail"
  - "kokpit.health.unsent_invoice_email_warning_after (900 s) and unsent_invoice_email_stale_after (3600 s), ruling R-37-1"
affects: [10-38, 10-39]

actuals:
  tokens: 4400
  tasks: 2
  commits: 3
plan_head_before: b2709aa96c0153e51f1356f82f0780c6fd97a167
plan_head_after: 80960bf5f2cfd08b0f93e0cdcd7be84d6c08a794
commits: 3

coverage:
  - deliverable: "A document whose latest e-mail row failed turns the System page slot Error with 'Neodesláno: N. Odešlete je znovu z přehledu faktur.' and the link to the invoice list filtered by the failed e-mails; a later sent row clears it"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php#answers Error with the count, the resend hint and the link to the list filtered by the failed e-mails"
        status: pass
      - kind: test
        ref: "tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php#counts every document once and lets a later successful send clear the failure"
        status: pass
    human_judgment: false
  - deliverable: "A queued or sending row waiting past the warning threshold is Warning; a sending row older than the stale threshold counts as failed; the stale threshold never sits below the warning one"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php#grades a sending row by the time since the claim: fresh is Ok, slow is Warning, older than the stale threshold counts as failed"
        status: pass
      - kind: test
        ref: "tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php#answers Warning once a queued e-mail has waited past the warning threshold, and Ok before it"
        status: pass
    human_judgment: false
  - deliverable: "No recipient, subject, amount, failure code or exception text reaches the System page in any grade"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php#never shows a recipient, a subject, an amount or a failure code in any grade"
        status: pass
    human_judgment: false
  - deliverable: "The registry holds the real indicator for the slot and a placeholder only for UnprocessedWebhooks"
    requirement: "IN-04"
    verification:
      - kind: test
        ref: "tests/Feature/Operations/HealthRegistryTest.php#measures the unsent invoice e-mails for real, so no placeholder holds the slot"
        status: pass
    human_judgment: false

key-files:
  created:
    - app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php
    - tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php
  modified:
    - app/Domain/Operations/Health/HealthResult.php
    - app/Providers/OperationsServiceProvider.php
    - config/kokpit.php
    - resources/views/filament/pages/system-page.blade.php
    - tests/Feature/Operations/HealthRegistryTest.php
    - tests/Feature/Operations/SystemPageTest.php

key-decisions:
  - "R-37-1 applied: warning after 900 s of waiting (queued or sending), a sending row counts as failed after 3600 s; the stale value is clamped to at least the warning one"
  - "The age of a row runs from updated_at: a queued row is untouched until a worker claims it and the claim stamps updated_at, so a sending row older than the threshold belongs to a dead worker"
  - "A failure outranks a wait: when any document is failed or stale the slot is Error and the value counts only those; Warning counts the waiting ones"
  - "The link is part of HealthResult (url, urlLabel) so any indicator can point at its fixing screen; the URL targets the invoice list with tableFilters[email][value]=failed (Error) or queued (Warning)"

patterns-established:
  - "An indicator over an Admin-only model reads it as system through the model (toBase aggregate), never DB::table, to satisfy the query escape hatch architecture test"

requirements-completed: [IN-04]

duration: "35 min"
completed: 2026-10-17
---

# Phase 10 Plan 37: Unsent invoice e-mails indicator Summary

**The System page slot "Neodeslané e-maily s fakturou" is measured for real: the latest e-mail row of each document decides Ok, Warning (waiting past 15 min) or Error (failed, or sending for over an hour), with a link to the invoice list filtered by e-mail state and no recipient or failure text on screen.**

## Accomplishments

- `UnsentInvoiceEmailsIndicator` selects the latest row per invoice (`NOT EXISTS` a later `(created_at, id)`) and counts failed or stale-sending rows and waiting rows in one aggregate query, as system. It reads states and timestamps only.
- Texts come from the plan 10-21 copy (`invoices.system.*`); the value is the count of the grade shown.
- `HealthResult` gained optional `url` and `urlLabel`; the System page renders them as a link next to the detail. Error links the list with `tableFilters[email][value]=failed`, Warning with `queued`.
- The indicator is in the `$real` list of `OperationsServiceProvider`; `missingSlots()` therefore no longer registers a placeholder for it. The two R-37-1 thresholds are in the `health` block of `config/kokpit.php`.
- 12 indicator tests plus the registry test (only `UnprocessedWebhooks` remains a placeholder).

## Task Commits

1. Task 1 (tracer): `b9c4e23` a failed invoice e-mail turns the System page slot red with the count and the link
2. Task 2: `be05075` grade waiting and stale invoice e-mails by the thresholds
3. Fix: `80960bf` read the e-mail log through the model, not a raw table query

Tracer gate: indicator tests, Pint and PHPStan were green before the grading task (`Tracer verified end-to-end`). Full suite `ddev exec vendor/bin/pest --compact`: 5684 passed, 3 skipped. Pint and PHPStan clean.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] The link target needs a carrier on HealthResult and a place on the page**
- **Found during:** Task 1
- **Issue:** The truth requires a link to the filtered list, but `HealthResult` had only status, value and detail and the System page rendered no link.
- **Fix:** optional `url` and `urlLabel` on `HealthResult` (backward compatible) and an anchor in `system-page.blade.php`.
- **Files modified:** `app/Domain/Operations/Health/HealthResult.php`, `resources/views/filament/pages/system-page.blade.php` (not in the plan file list)
- **Commit:** b9c4e23

**2. [Rule 1 - Bug] Existing System page and registry tests assumed the slot is a placeholder**
- **Found during:** Task 1 (Operations suite)
- **Issue:** `SystemPageTest` counted at least three "not available" badges and exactly one green badge; `HealthRegistryTest` listed the slot as a placeholder.
- **Fix:** the not-available count is now at least one (only webhooks), the colour test replaces the slot with a not-available probe, the registry test lists only `UnprocessedWebhooks` and adds the real-indicator test (the registry test update was planned for Task 2 and moved to Task 1 so every commit is green).
- **Files modified:** `tests/Feature/Operations/SystemPageTest.php`, `tests/Feature/Operations/HealthRegistryTest.php`
- **Commit:** b9c4e23

**3. [Rule 1 - Bug] The first Task 2 implementation used `DB::table`, refused by the query escape hatch architecture test**
- **Found during:** full suite run
- **Fix:** `InvoiceEmail::query()->...->toBase()->first()` as system.
- **Files modified:** `app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php`
- **Commit:** 80960bf

**Total deviations:** 3 auto-fixed (1 blocking, 2 bugs). **Impact:** two extra files touched; no scope change.

## Known Stubs

None.

## Threat Flags

None. The slot is on the Admin-only System page; T-10-89 (no recipient, amount or exception text) and T-10-90 (failures do not go unnoticed) are covered by tests.

## Next Phase Readiness

Ready for 10-38.

## Self-Check: PASSED

- Files exist: indicator, test, SUMMARY; commits b9c4e23, be05075, 80960bf are ancestors of HEAD.
- Acceptance criteria of both tasks pass; full suite green.
