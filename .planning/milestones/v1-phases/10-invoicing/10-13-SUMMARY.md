---
phase: 10-invoicing
plan: 13
subsystem: invoicing-time-billing
tags: [invoicing, draft, time, reservation, grouping, cnb-preview, picker, sql]

requires:
  - phase: 10-invoicing
    provides: "Money::times(), convertThroughCzk(), timesThroughCzk(), InvoiceQuantity::fromSeconds() (plan 10-01)"
  - phase: 10-invoicing
    provides: "EntryBilling::reserve() and the link guard of time_entries (plans 10-06, 10-09)"
  - phase: 10-invoicing
    provides: "CreateDraft, DraftTotals, InvoiceFixtures, the error copy (plan 10-11)"
  - phase: 10-invoicing
    provides: "TimeReportReader and the effective-billing parity test (plan 10-34)"
provides:
  - "EffectiveBillingSql: the billing type and rate SQL fragments and the shared joins, extracted from TimeReportReader and used by both readers"
  - "InvoiceableEntries: candidates() and summary() of billable time for a draft, Admin or system only"
  - "EntryReservation::claim(): candidates locked FOR UPDATE in id order, only still-free rows reserved"
  - "TimeItemGrouping: lines per project or task, split by rate, single-rounding preview of converted lines"
  - "ItemGrouping enum (project, task) with Czech labels"
  - "CreateDraft time source: period, projects, entries without a project, grouping; one transaction"
  - "invoice_items copy in cs and en (no_project, no_task)"
affects: [10-14, 10-17, 10-20, 10-21, 10-35, 10-40]

actuals:
  tokens: 18600
  tasks: 2
  commits: 2
plan_head_before: 4b24b53ad15b45c601471cf6c3760d0a979f9f17
plan_head_after: 1a5f1d28e97393b45a991316677df48ec29d8286
commits: 2

coverage:
  - deliverable: "The Admin creates a draft from unbilled time by choosing client, period (Prague days), projects and the grouping; one time line per project with the effective rate, hours from the exact seconds; exactly the eligible entries (finished, billable, unbilled, free, of the client, in the period) are reserved for the draft and stay unbilled"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#the tracer: a draft from last month, grouped per project -> it creates one hour line per project with the effective rate and reserves exactly the eligible entries"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#the tracer: a draft from last month, grouped per project -> it does not let a second draft take the entries a first draft holds"
        status: pass
    human_judgment: false
  - deliverable: "Grouping per task (reference and title, a project-without-task line), lines split by effective rate, entries without a project as one line only when asked, descriptions in the language of the draft"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#grouping, rates and projects"
        status: pass
    human_judgment: false
  - deliverable: "Fixed-price entries are never added as zero-priced hour lines and are not reserved (ruling R-13-1, D-07); an archived project holding unbilled time is accepted; a project of another client, a bad period, a bad day and an unknown grouping are field errors and create nothing"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#grouping, rates and projects -> it never adds the hours of a fixed-price project or task and leaves them unbilled and unreserved"
        status: pass
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#grouping, rates and projects -> it refuses a bad period, a bad day and an unknown grouping with field errors and creates nothing"
        status: pass
    human_judgment: false
  - deliverable: "A group whose rate currency differs from the draft currency is previewed with today's CNB rates by the single-rounding method: unit price by convertThroughCzk, line amount by timesThroughCzk rounded once (59,70 EUR and 597,01 EUR for 10 h of 1 500 Kc at 25,125), price_converted true; two quotes for a non-CZK pair; a missing list refuses the draft under currency and reserves nothing"
    requirement: "IN-07"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#the foreign-currency preview"
        status: pass
    human_judgment: false
  - deliverable: "The picker summary returns the count, the exact seconds, the estimate in the draft currency equal to the totals the created draft then has, the entries other drafts hold and the fixed-price projects among the chosen ones; a Partner is refused both reader methods"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Invoicing/CreateDraftFromTimeTest.php#the picker summary"
        status: pass
    human_judgment: false
  - deliverable: "The time report and the picker share one implementation of the effective billing chain (extracted, not copied) and the parity test stays green"
    requirement: "IN-02"
    verification:
      - kind: test
        ref: "tests/Feature/Reports/EffectiveBillingParityTest.php"
        status: pass
    human_judgment: false

tech-stack:
  added: []
  patterns:
    - "SQL fragments of a business rule live in one class (EffectiveBillingSql) and every hand-written reader uses its constants and joins; the resolvers stay the specification and a parity test pins the SQL"
    - "Reservation by lock-then-claim: read candidates without a lock, lock them in id order, decide eligibility on the locked rows, reserve those still free, build the lines from the claimed rows with the seconds read under the lock"
    - "A converted draft line stores the display unit price, the once-rounded line amount and price_converted; whether it needs conversion at issue is derived (source rate currency differs from the draft currency, price not manual), not stored"

key-files:
  created:
    - app/Domain/Reports/Queries/EffectiveBillingSql.php
    - app/Domain/Invoicing/Queries/InvoiceableEntries.php
    - app/Domain/Invoicing/Enums/ItemGrouping.php
    - app/Domain/Invoicing/Support/TimeItemGrouping.php
    - app/Domain/Invoicing/Support/EntryReservation.php
    - lang/cs/invoice_items.php
    - lang/en/invoice_items.php
    - tests/Feature/Invoicing/CreateDraftFromTimeTest.php
  modified:
    - app/Domain/Reports/Queries/TimeReportReader.php
    - app/Domain/Invoicing/Actions/CreateDraft.php
    - lang/cs/enums.php
    - lang/cs/invoices.php

key-decisions:
  - "R-13-1 to R-13-5 applied as planned: fixed-price time stays unbilled (D-07 over UI-SPEC Surface B), no-project and no-task lines, groups split by effective rate, a missing CNB list refuses the draft under currency, converted preview lines are rounded once"
  - "Candidates require the effective billing type hourly (an allow-list), so a task that is explicitly non-billable is excluded as well as fixed price, consistent with EntryBilling::isHourly()"
  - "Lines are ordered by project name (no project last), then task reference (no task last), then rate, so a draft is reproducible"
  - "A group with zero seconds makes no line (the database forbids a zero quantity); its zero-length entries are still reserved and billed with the draft"
  - "needs_conversion is a derived flag of the grouping output and is not stored; conversion_snapshot stays null until the issue (plan 10-17)"

patterns-established:
  - "Pest helpers of the time-draft test are prefixed timeDraft to stay unique in the global function namespace"

requirements-completed: [IN-02, IN-07]

duration: 7 min
completed: 2026-10-17
status: complete
---

# Phase 10 Plan 13: Draft from tracked time Summary

**`CreateDraft` now bills unbilled time: a shared effective-billing SQL feeds a picker query, a lock-then-claim reservation holds the entries for the draft, and a grouping builds one priced line per project or task and rate, previewing foreign-currency lines with today's CNB rates rounded once.**

## Performance

- **Duration:** about 7 min (2026-10-17T08:44Z to 08:51Z)
- **Tasks:** 2 (1 tracer, 1 auto/tdd)
- **Files:** 8 created, 4 modified

## Accomplishments

- `EffectiveBillingSql` holds the billing type and rate fragments and the shared joins; `TimeReportReader` and `InvoiceableEntries` both use them, and the parity test against the resolvers is green.
- `InvoiceableEntries::candidates()` returns the finished, billable, unbilled, free, hourly entries of a client in a half-open Prague-day period for the chosen projects (and optionally those without a project); `summary()` returns count, exact seconds, the estimate of the lines a draft would get, the entries held by other drafts and the chosen fixed-price projects. Both refuse everybody but the Admin and a system run.
- `EntryReservation::claim()` locks the candidates FOR UPDATE in id order, keeps only rows that are still free on the locked rows (also still billable, finished and of the draft's client) and reserves them through `EntryBilling::reserve()`.
- `TimeItemGrouping` builds lines per project or per task, split by effective rate, with `Ostatní práce` / `Other work` for time without a project and `:project – ostatní` / `:project – other` for project time without a task. A converted line gets the display unit price (`convertThroughCzk`), the once-rounded amount (`timesThroughCzk`) and `price_converted`.
- `CreateDraft` takes `source: time` with `period_from`, `period_to`, `project_ids`, `without_project` and `grouping`; the draft, its admin row, the claim, the lines, the sources and the totals are one transaction. A missing CNB list is a `currency` field error and rolls everything back.

## Task Commits

1. **Task 1 (tracer): the draft from time, per project, with the reservation** - `2144d23` (feat)
2. **Task 2: the picker summary and the proofs of grouping, rates, fixed-price exclusion and the preview** - `1a5f1d2` (feat)

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Reports tests/Feature/Localisation`: 408 passed, 2 skipped, 0 failed (19 tests in `CreateDraftFromTimeTest`).
- Tracer feedback gate: the tracer `<verify>` (new test, report tests, Pint, PHPStan) passed before expansion.
- `pint --test` and PHPStan clean after both tasks; `scripts/check-sensitive.sh` clean on both staged sets; lefthook (sensitive-content, gitleaks) passed on both commits.
- All acceptance greps of both tasks pass.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] Candidates require an hourly effective type, not just "not fixed price"**
- **Found during:** Task 1
- **Issue:** The task billing type `non_billable` also exists; "not fixed price" would have turned the entries of such a task into hour lines, while `EntryBilling::isHourly()` bills only hourly entries.
- **Fix:** `InvoiceableEntries` filters on the effective type `= 'hourly'`, which still excludes every fixed-price entry.
- **Files modified:** `app/Domain/Invoicing/Queries/InvoiceableEntries.php`
- **Commit:** 2144d23

**2. [Rule 3 - Blocking] Copy outside the plan's file list**
- **Found during:** Task 1
- **Issue:** `ItemGrouping` implements `HasLabel`, and `EnumLabelsTest` requires a Czech label for every case; the time source needs field-error copy for a reversed period, projects of another client and an unknown grouping.
- **Fix:** Added `enums.item_grouping` to `lang/cs/enums.php` and `period_order`, `projects_unknown`, `grouping` to the "Time added to a draft" section of `lang/cs/invoices.php` (no existing key changed).
- **Files modified:** `lang/cs/enums.php`, `lang/cs/invoices.php`
- **Commit:** 2144d23

**3. [Rule 3 - Blocking] Task boundary moved**
- **Found during:** Task 1
- **Issue:** `CreateDraft` of the tracer already calls `ItemGrouping`, `TimeItemGrouping` and the copy, and the grouping code is one unit.
- **Fix:** The complete `TimeItemGrouping` (per task, rate split, no-project line, conversion preview), `ItemGrouping` and both `invoice_items` language files are in the Task 1 commit; Task 2 added `summary()` and the tests of grouping, rates, fixed price, conversion and the picker, which pass against the code of Task 1 except the summary tests.
- **Commit:** 2144d23

**4. [Test shape] The "third currency" case**
- **Found during:** Task 2
- **Issue:** The database requires a client's rate to be in the client's currency, so a USD-rated line on a EUR invoice cannot arise from a EUR client.
- **Fix:** The two-quote case is tested as a EUR-rated client with a USD draft (50,00 EUR at 25,125 / 22,5 = 55,83 USD, rounded once).
- **Commit:** 1a5f1d2

**Total deviations:** 4 (1 missing critical, 2 blocking, 1 test shape). **Impact:** none on behaviour; the final code matches the plan's contracts.

## Known Stubs

None.

## Threat Flags

None. T-10-34 (double billing) is mitigated by lock-then-claim on locked rows with the link guard behind it (the concurrent proof with a mutation twin is plan 10-35), T-10-35 by validating the projects as the client's and filtering the candidates by client, T-10-36 by the Admin/system-only authorisation of `InvoiceableEntries` (tested with a Partner). No new endpoint, auth path or file access.

## Notes for later plans

- Plan 10-17 re-previews and converts at issue: a line needs conversion when its `invoice_item_sources.source_rate_currency` differs from the draft currency and `price_manual` is false; `conversion_snapshot` is still null after this plan.
- Plan 10-14 adds a fixed amount and the "time optionally billed with it" option; this plan never claims fixed-price time, so that option has to widen the candidate filter deliberately.
- Plan 10-35 proves the claim under concurrent processes; `EntryReservation::claim()` is the unit to mutate there.
- Plan 10-21 rewrites the Surface B fixed-price callout per ruling R-13-1.

## Self-Check: PASSED

- Created files exist: `EffectiveBillingSql.php`, `InvoiceableEntries.php`, `ItemGrouping.php`, `TimeItemGrouping.php`, `EntryReservation.php`, `lang/cs/invoice_items.php`, `lang/en/invoice_items.php`, `CreateDraftFromTimeTest.php`.
- Commits `2144d23` and `1a5f1d2` are ancestors of HEAD.
