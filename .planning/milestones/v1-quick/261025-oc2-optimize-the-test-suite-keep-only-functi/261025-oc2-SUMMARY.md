---
phase: quick-261025-oc2
plan: 01
subsystem: testing-and-ci
tags: [pest, concurrency, ci, sharding, github-actions, test-pruning]
requires: []
provides:
  - Concurrency suite without broken-lock self-tests (21 tests, all real race tests kept)
  - 15 pruned Feature files with a per-test ledger (96 tests removed)
  - tests job as a 4-shard, time-balanced matrix with ordered steps and a CiParityTest guard
affects: [.github/workflows/hygiene.yml, CONTRIBUTING.md, tests/Concurrency, tests/Support, tests/Feature, tests/.pest/shards.json]
tech-stack:
  added: []
  patterns:
    - Pest --shard with recorded class times (tests/.pest/shards.json)
    - env indirection (PEST_SHARD) so the matrix value never sits inside a run command
key-files:
  created:
    - tests/.pest/shards.json
  modified:
    - .github/workflows/hygiene.yml
    - tests/Feature/Repo/CiParityTest.php
    - CONTRIBUTING.md
    - tests/Concurrency (10 tests, 9 workers)
    - tests/Feature (15 files)
  deleted:
    - tests/Support/UnlockedSequenceAllocator.php
    - tests/Support/UnlockedCardPaymentMatcher.php
    - tests/Support/UnlockedEntryReservation.php
    - tests/Support/UnlockedQueueInvoiceEmail.php
    - tests/Support/UnclaimedIdempotencyStore.php
    - tests/Support/UnlockedTimerLock.php
    - tests/Support/UnlockedTaskBoard.php
key-decisions:
  - "Shard count is 4, not 3: the planned 3-shard split projected 804/776/732 s, the 4-shard round-robin split still 789 s on shard 1"
  - "Shards are balanced by recorded class time (Pest tests/.pest/shards.json), which brings the projection to 634/564/632/632 s"
  - "Inside every shard the Pest work runs as three ordered steps: Unit+Arch, Feature, Isolation+Concurrency (user instruction)"
  - "Tests that mix a keep assertion with a cosmetic one stay; OperationalAlert subject test was restored after a cut"
requirements-completed: [FND-13, FND-05, AUD-01]
duration: ~4.5 h
completed: 2026-10-25
status: complete
commits: 3
plan_head_before: a230514cb5d0e76862a15d0cd19bcba087445664
plan_head_after: 96507ce19a4cc9a68371d93664bc90af1f9225cd
estimate:
  tokens: 240000
  raw_tokens: 240000
  tasks: 3
  confidence: low
actuals:
  tokens: 63650
  tasks: 3
  commits: 3
---

# Phase quick-261025-oc2 Plan 01: Optimize the test suite Summary

Broken-lock self-tests and cosmetic Feature tests are gone, and the non-Audit suite now runs in 4 time-balanced CI shards with cheap suites first, a projected 634 s worst shard (literal plan method) against the 720 s target.

## What was done

| Task | Commit | Result |
| ---- | ------ | ------ |
| 1. Concurrency harness | `fc5c54e` | 12 tests removed (10 broken-lock runs, 2 rendezvous refusals), 7 helper classes deleted, workers and docblocks cleaned, 4 CONTRIBUTING clauses changed |
| 2. Feature pruning | `26d6440` | 96 tests removed from 15 of the 16 files (UpdateIssuedDocumentTest needed no cut) |
| 3. CI sharding | `96507ce` | 4-shard matrix, three ordered Pest steps, audit in shard 1, CiParityTest rewritten, shards.json added |

Docs artifacts (this SUMMARY, STATE.md) are not committed here, as instructed; ROADMAP.md was not touched.

## Before and after

| Scope | Before | After |
| ----- | ------ | ----- |
| Concurrency suite | 33 tests, 416.3 s | 21 tests, 287.6 s (about 129 s less locally) |
| The 16 Feature files | 599 tests, 132.6 s | 503 tests, 98.5 s (-96 tests, -25.7 % time) |
| Whole Feature suite | planner baseline 5634 tests, 435.5 s (not re-measured) | 5540 tests, 576.4 s summed per-test time in one full run on this machine (see note) |
| Whole non-Audit suite | planner list 6881 tests | 6775 tests (-12 Concurrency, -96 Feature, +2 CiParityTest); wall time 973.9 s, 941.2 s and 818.5 s in three full local runs |
| Audit suite | not part of the plan numbers | 592 tests, 181.9 s summed, 182.2 s wall |

Notes, stated plainly:
- The Feature wall time is not comparable with the planner's 435.5 s: the planner measured on a lighter machine state, and I did not re-measure the old whole suite, because that would have cost another 16 minutes for a number the plan does not gate on. Test counts reconcile exactly: 5634 - 96 + 2 = 5540.
- The 16-file "before" run was the first run on a cold cache, so per-file time deltas contain run-to-run noise (for example IssuedHeaderEditTest shows -5.2 s for 3 tests of 0.9 s together). Test counts are exact, times are indicative.
- Concurrency CI factor: 427 s (CI) / 416.3 s (local) = 1.026.
- Three tests are skipped in every full run (`WorkReportPdfTest`, "many pages"). They were skipped before this task, and I did not touch them.

## Task 1: removed Concurrency tests (12)

Removed, with local time from the baseline run: board without advisory lock 49.5 s, payment match without document lock 27.0 s, timer without lock 15.6 s, idempotency without claim 8.4 s, invoice issue without row lock 7.4 s, invoice edit vs send without lock 6.1 s, reservation without lock 5.5 s, payment deletion without document lock 5.5 s, task number without row lock 4.3 s, sequence allocator without row lock 4.2 s, timer rendezvous refusal 0.8 s, idempotency rendezvous refusal 0.7 s.

Kept: 21 tests, none of them touched in WORKERS, PER_WORKER, ROUNDS or BARRIER_SECONDS (`PAYMENT_MATCH_ROUNDS = 5` verified by grep). Dead code removed with them: every `*_MUTATION_ROUNDS` constant, the rendezvous directory bookkeeping of the timer and idempotency tests, the `unlocked`, `skip-lock`, rendezvous and `Unlocked*` modes of the nine workers. The `unlocked` board switch of `task-worker.php` stays, because the kept "counter row lock alone" test uses it. All header docblocks of the Concurrency files now describe what the remaining test proves (real processes, one start barrier, distinct-pid checks).

## Task 2: Feature ledger

Per file (tests and summed time, before to after):

| File | Tests | Time (s) |
| ---- | ----- | -------- |
| Invoicing/PartnerInvoiceResourceTest | 19 to 15 | 17.9 to 11.4 |
| Invoicing/IssuedHeaderEditTest | 54 to 51 | 15.7 to 10.5 |
| Invoicing/InvoiceListTest | 23 to 14 | 11.8 to 7.4 |
| Invoicing/IssuedEditorTest | 40 to 34 | 10.9 to 8.0 |
| Payments/CardPaymentRecordTest | 61 to 60 | 10.1 to 9.2 |
| Invoicing/InvoiceDetailTest | 42 to 37 | 7.8 to 7.0 |
| Payments/LinkLifecycleTest | 34 to 33 | 7.0 to 6.1 |
| Payments/InvoiceListLinkTest | 8 to 5 | 7.3 to 4.4 |
| Invoicing/DraftEditorTest | 59 to 48 | 7.3 to 5.7 |
| Invoicing/SendInvoiceEmailTest | 48 to 42 | 6.3 to 5.4 |
| Invoicing/UpdateIssuedDocumentTest | 30 to 30 | 5.7 to 5.4 |
| Reports/TimeReportTest | 71 to 41 | 5.2 to 3.1 |
| Payments/CreatePaymentLinkTest | 35 to 33 | 4.9 to 4.1 |
| Payments/CardPaymentsOverviewTest | 23 to 16 | 4.7 to 3.5 |
| Documents/DocumentResourceTest | 31 to 27 | 4.7 to 3.8 |
| Invoicing/EmailDialogTest | 21 to 17 | 5.1 to 3.7 |

Files under 10 % and why: UpdateIssuedDocumentTest (0 cuts: every test asserts the named editability convention, a refusal rule or money rounding), CardPaymentRecordTest (1 cut: the rest are one-per-column database guards and check constraints, class K3/K4), LinkLifecycleTest (1 cut: refusal and reconcile paths are K3/K4), IssuedHeaderEditTest, CreatePaymentLinkTest (rest are distinct failure classes or states of the document around the Stripe call).

Removed tests (class, and the covering test for C1 and C5). Names are shortened.

PartnerInvoiceResourceTest
- offers the PDF as a row action of the list as well: C5, covered by "hands the Partner the signed PDF link of the document and the link returns the PDF"
- keeps the navigation entry at sort 30 for the Partner: C2
- shows the empty state of a client without documents: C2
- shows an online proforma the same way: C5, covered by "shows an online invoice with its method and amount and without the account or the variable symbol"

IssuedHeaderEditTest
- answers a due date before the issue date with the existing copy: C1, covered by "refuses a due date before the issue date and one that is not a day"
- is refilled from the chosen account when it is still configured, and from the first account of the currency when it was removed: C1, covered by the two single-case refill tests next to it
- never takes the snapshot from the data when the method or the account changes: C1, covered by "is never taken from the data: a typed account is ignored ..."

InvoiceListTest
- omits the awaiting tab when no paid proforma waits: C1, covered by the last assertion of "drops the awaiting tab once the proforma has its invoice"
- uses the Czech plural of the days late (3 dataset rows): C2
- shows a dash for what a draft and a credit note do not have: C2
- marks an archived client in the client filter: C2
- puts drafts first by creation time, then issued documents by issue date: C2
- shows the empty state of a list without documents; shows the empty state of a tab: C2 each

IssuedEditorTest
- offers the currency, the dates, the language and the customer lines for editing: C1, covered by the editor saves in IssuedHeaderEditTest
- does not show the parties section on a draft: C2
- shows no "Upravit" on the detail of a document that cannot be edited: C1, covered by InvoiceDetailTest "offers Upravit on an open document only"
- shows the printed account while nothing changes and the chosen one once it does: C1, covered by "preselects the first account of the new currency on a currency change ..."
- keeps the stored account when the currency changes to one with its own account ...: C1, covered by "preselects the first account of the new currency on a currency change, and any account can be picked afterwards"
- tells in the currency confirmation that the account is preselected: C2

CardPaymentRecordTest
- carries a Czech label for every type, reason, resolution and status: C2

InvoiceDetailTest
- shows the number with the kind and state badges and the client link; shows the issue facts of the document; has no related section when nothing is related; registers the tabs in the order of the spec; shows an empty payments tab with its heading and body: C2 each

LinkLifecycleTest
- regenerates a proforma link as well: C5, covered by "replaces an active link, the old one deactivated first and never two live at once"

InvoiceListLinkTest
- shows every document again when cleared: C3
- works on the invoice tab of a client: C5, covered by "lists exactly the documents in each state and shows its indicator chip"
- is toggleable and hidden by default: C2

DraftEditorTest
- opens the editor from a draft row of the list; shows the header and the lines of the draft; shows the origin badge and the exact H:MM of a time line; shows no CZK preview and no VAT row for a CZK draft; shows the account of the currency when it exists; shows the empty row text for a draft without items; gives each delete button the name of its row; asks with the destructive copy; shows the empty state of a draft without linked time: C2 each
- shows the danger message with a link to the settings when the currency has no bank account: C1, covered by "leaves the account empty for a currency without one, says so with the link to the settings, and blocks the issue"
- offers the add buttons below the cap, and no cap note: C1, covered by "hides the add buttons and names the cap at the most lines"

SendInvoiceEmailTest
- keeps the transfer wording ... and the credit note wording: C2
- pre-fills an English proforma in English: C5, covered by "pre-fills the dialog from the client and the language of the document"
- names the work report in English for an English document: C5, covered by "attaches the work report of the linked time, without any price"
- locks a proforma the same way: C5, covered by "locks the invoice together with the sent mark when the transport accepts the message"
- recipient dataset row "blank": C4, covered by the row "empty"
- has the same keys in Czech and English: C2

TimeReportTest (30 removed)
- C1: counts a running entry at its elapsed time (covered by "counts a running entry at its elapsed time from the frozen clock and prices those seconds"); counts the day rows as the leaves of a breakdown (covered by "renders at most the configured number of leaf rows, says so, and keeps every total")
- C2 or C3, layout, a11y markup, CSS, copy, the other 28 tests: "accessible tree" (4), "readable table" (4 of 5; the escaped-markup test stays), "levels of the tree" (5), "tree opens as deep as the filters" (3), filter strip presentation (project select hint, active range text, date inputs, range error absent, submit on every change, archived client mark), Rozpad link marking and no child rows, no fixed-price marker, no row cap notice, documented config value, full content width, empty period state

CreatePaymentLinkTest
- names the proforma in the product of its link; sends an English document the English product name and messages: C2 each

CardPaymentsOverviewTest
- hides the Stripe references by default, shortened; marks an archived client in the client filter; explains the empty list once and each empty tab; explains each kind of record in one banner; shows one banner only and the resolved banner wins; gives each banner its colour: C2 each
- pages by 25 with the options 25, 50 and 100: C3

DocumentResourceTest
- lists no create page and no record page, only the index: C3
- sorts the names in Czech order ...; sorts by client name in Czech order ...; sorts by the uploader name in Czech order: C2 each

EmailDialogTest
- shows the field error of a recipient that is no address: C1, covered by the SendInvoiceEmailTest recipient dataset and "shows an empty recipient with the helper ... and refuses to send"
- names the kind in the accusative in the heading; shows the empty state of a document that was never sent; is one of the tabs of the detail, after the files and before the history: C2 each

Kept on purpose (examples): every tracer test, every Partner refusal, every money, numbering, lock, state, idempotency and balance test, one test per validation rule, the query-count guard of each list (InvoiceList, InvoiceListLink, CardPaymentsOverview, DocumentResource, TimeReport), attention tabs and overdue callouts. `OperationalAlert` subject test in CreatePaymentLinkTest was cut first and then restored, because it also pins `withLine()` behaviour that no other test covers. No Feature file deleted, no skip or todo marker added (18 markers before and after), `tests/Isolation`, `tests/Audit` and `tests/Arch` byte-identical to HEAD.

## Task 3: CI

Workflow, `tests` job: matrix `shard: [1, 2, 3, 4]` with `fail-fast: false`, name "Tests (PostgreSQL 18, shard N/4)", `timeout-minutes: 20`, `PEST_SHARD` set once in the job env. Steps in order: boot from `.env.example`, then "Pest (Unit, Arch)", "Pest (Feature)", "Pest (Isolation, Concurrency)", each `vendor/bin/pest --testsuite=<names> --shard="${PEST_SHARD}/4"`, then "Partner audit" with `if: matrix.shard == 1`. `ci-passed.needs` is unchanged. `actionlint`, `zizmor --offline` and `bash scripts/tests/run.sh` pass.

CiParityTest (tests rewritten and added): the sharded steps together list every suite of `phpunit.xml` except Audit, none twice; their order is Unit and Arch before Feature before Isolation and Concurrency, and an unranked new suite fails the test; the matrix equals `range(1, N)` for the N in the run commands, `fail-fast` is false; exactly one audit step, equal to the composer `partner-audit` script, `if: matrix.shard == 1`, after all sharded steps; `composer test`, `partner-audit` and `ci` stay unsharded; `tests/.pest/shards.json` exists and parses. Mutation checks done by hand: a matrix of `[1, 2]` and a step that drops `Concurrency` both fail the parity tests.

Partition check (all non-Audit tests, 3 steps x 4 shards, time-balanced): sorted union of the shard lists equals the sorted unsharded list, 6776 tests, 0 duplicates. One normalisation is needed: Pest's `--list-tests` appends a trailing "." to nine test names when `--filter` is active (the sharded runs), so both sides are compared after `sed 's/\.$//'`. Shard 1/4 was also run for real, in step order: 39 + 1370 + 4 tests, all green, 1.95 s + 140.1 s + 106.4 s locally.

Whole suite: `pest --exclude-testsuite=Audit` 6772 passed, 3 skipped; `pest --testsuite=Audit` 592 passed; `ddev composer ci` green (suite, audit, Pint, PHPStan, licences).

### Projected CI time per shard

Method of the plan: 150 s setup + 2.2 x local time of non-Concurrency classes + 1.026 x local time of Concurrency classes + 50 s Partner audit on shard 1. Local class times come from the full run (350 classes, 972.0 s).

| Shard | Classes (Unit+Arch / Feature / Isolation+Concurrency) | Local seconds (non-Concurrency / Concurrency) | Projected CI seconds |
| ----- | ----------------------------------------------------- | --------------------------------------------- | -------------------- |
| 1 | 1 / 70 / 1 | 147.8 / 106.5 | 634 (incl. audit 50) |
| 2 | 1 / 70 / 8 | 151.2 / 79.6 | 564 |
| 3 | 18 / 69 / 13 | 197.0 / 47.2 | 632 |
| 4 | 18 / 69 / 12 | 198.7 / 44.0 | 632 |

Worst projected shard: 634 s (10.6 min), under the 720 s target and under the 20 minute timeout.

Honest caveat on the 50 s audit figure: the audit suite takes 182 s locally here, and the plan's 50 s was an assumption (the CI audit step was cancelled on PR #23, so no CI number exists). If the audit scales with the Feature factor 2.2 on CI (about 400 s), shard 1 would project to about 985 s. That is still inside the 20 minute timeout but above 720 s. The Hygiene run on the first push settles it; if shard 1 is the slow one, move the audit to a dedicated job or give shard 1 less work.

## Deviations from Plan

**1. [User instruction] Ordered Pest steps instead of one Pest step**
- The user asked for cheap tests first inside each shard. The single `Pest` step became three steps (Unit+Arch, Feature, Isolation+Concurrency), and CiParityTest checks the union and the order instead of the old exact command.
- Files: `.github/workflows/hygiene.yml`, `tests/Feature/Repo/CiParityTest.php`. Commit `96507ce`.

**2. [Rule 3 - Blocking] 4 shards with time-balanced partitioning instead of 3 round-robin shards**
- Found during: Task 3 projection. The plan's own rule raises the shard count above 720 s, and 4 round-robin shards still gave shard 1 about 789 s (Isolation and the heavy Concurrency classes landed together).
- Fix: shard count 4 and a committed `tests/.pest/shards.json` (Pest's built-in time-balanced mode; class names and seconds only). Projection fell to 634/564/632/632 s. Refresh command is in CONTRIBUTING.md; a stale file only costs balance, never coverage (new classes are spread round-robin and Pest prints a notice).
- Rounds of the projection: 3 round-robin shards 804/776/732 s, 4 round-robin 789/498/546/629 s, 4 time-balanced 634/564/632/632 s.
- Files: `.github/workflows/hygiene.yml`, `CONTRIBUTING.md`, `tests/.pest/shards.json`. Commit `96507ce`.

**3. [Rule 1 - Bug in my own cut] OperationalAlert subject test**
- I first removed it as a copy test, then found no other test covers the `withLine()` subject behaviour, and restored it before the commit.

**4. [Plan wording] Partition check normalisation**
- The plan's diff of sorted `--list-tests` lines cannot be empty as written, because filtered runs add a trailing "." to nine names. The check strips that character on both sides and also counts duplicates (0).

**5. [Plan wording] PEST_SHARD in the job env, not the Pest step env**
- With three Pest steps, one job-level variable is simpler; the matrix value still reaches the shell only through the env var, and zizmor reports no finding.

## Auth gates

None.

## Known Stubs

None.

## Threat Flags

None. `tests/.pest/shards.json` holds class names and timings only; `scripts/check-sensitive.sh` was clean before each commit.

## Human check still open

After the branch is pushed, the Hygiene run should show four "Tests (PostgreSQL 18, shard N/4)" jobs, each well under 15 minutes, the Partner audit running in shard 1 and skipped in shards 2 to 4, and "CI Passed" green. This cannot be verified locally and has not been verified.

## Self-Check: PASSED

Commits `fc5c54e`, `26d6440` and `96507ce` are ancestors of HEAD; `tests/.pest/shards.json`, the workflow, CiParityTest and the edited workers exist; no `Unlocked*` or `Unclaimed*` file is left in `tests/Support`; the working tree holds only the three unrelated `.planning` files named in the task, plus this task directory.
