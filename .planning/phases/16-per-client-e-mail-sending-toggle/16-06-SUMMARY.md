---
phase: 16-per-client-e-mail-sending-toggle
plan: 06
subsystem: invoicing, clients, docs
tags: [tests, journey, isolation, documentation, hand-over]
requires: [16-01, 16-02, 16-03, 16-04, 16-05]
provides:
  - HandServedClientJourneyTest (the whole hand-served flow in one ordered test)
  - DocumentEmailsScopeTest (D-01: invitation, password reset, task notifications unaffected by the switch)
  - InvoiceLeakTest describe "a hand-served client (Phase 16)" (Partner isolation of the new surfaces)
  - CONTRIBUTING bullet "Per-client e-mail switch." and rewritten lock sentences
  - RepositoryFilesTest case for the new bullet and extended Editable invoices / Cash receipts checks
affects: []
tech-stack:
  added: []
  patterns:
    - one readable journey test through real pages and Actions with the queue faked
    - documentation bullet whose backticked names are resolved by a repository test
key-files:
  created:
    - tests/Feature/Invoicing/HandServedClientJourneyTest.php
    - tests/Feature/Clients/DocumentEmailsScopeTest.php
  modified:
    - tests/Isolation/InvoiceLeakTest.php
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php
key-decisions:
  - "The journey fakes the queue for the whole test, so a job that should not exist cannot hide; the skipped confirmation is asserted on the row and with Queue::assertNotPushed"
  - "The documentation test refuses the old sentence with the fragment 'only code that locks is', so the acceptance grep for the full old sentence finds nothing in either file"
requirements-completed: [P16-01, P16-03, P16-04, P16-07, P16-08, P16-09, P16-11]
duration: 40min
completed: 2026-10-28
status: complete
actuals:
  tokens: 21000
  tasks: 3
  commits: 3
plan_head_before: 4e49558fd3f9bb91419b918505264c614dcc0dc2
plan_head_after: 96f0411786e9763a6cf4740e13b480c32251c420
---

# Phase 16 Plan 06: Hand-served client proof and hand-over Summary

One journey test walks a hand-served client through every surface the phase changed, one test pins the D-01 scope, the Partner isolation suite gains the new surfaces, and CONTRIBUTING hands the switch and the manual lock over with names a repository test resolves.

`commits: 3` counts the code commits; the SUMMARY commit follows `plan_head_after`.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: hand-served client journey | 33a8fc3 |
| 2 | Switch scope for system e-mails and Partner isolation | 61a2062 |
| 3 | CONTRIBUTING hand-over and its documentation test | 96f0411 |

## What was built

- `HandServedClientJourneyTest`: switch the client off on the edit page; issue an invoice through the real pipeline; `send=1` opens no dialog, `sendEmail` is hidden, the gray note shows; a direct `QueueInvoiceEmail` call is refused under `invoice` with no row and no job; the `lock` action of the `open_for_edits` callout locks it (one `invoice_locked_manually` row naming the Admin, no `invoice_locked`, no e-mail row); a cash payment recorded through the Platby tab dialog (the dialog shows the "no confirmation" hint); the confirmation row is `skipped` with the address kept, no mail, no job; the receipt is open and `lockCashReceipt` locks it (one `cash_receipt_locked_manually` row); `UnsentInvoiceEmailsIndicator` answers Ok; switching on again leaves the confirmation `skipped`, sends nothing, shows `sendEmail` again, and the client history holds the two `document_emails_enabled` changes (false, then true) by the Admin.
- `DocumentEmailsScopeTest`: with the switch off, `InvitePartner` still sends the invitation, `SendPartnerPasswordReset` still sends the reset to an active Partner, and a non-internal Admin comment still notifies the Partner requester through `TaskNotifier` (mail and database channels).
- `InvoiceLeakTest` describe "a hand-served client (Phase 16)": the Partner of the client opens the own hand-locked and the own open invoice with no gray note, no "Uzamknout"/"Odemknout", and no `invoice_locked_manually` / `cash_receipt_locked_manually` / `document_emails_enabled` text; those activity rows are invisible to the Partner (paired with Admin positives); `LockInvoice` and `LockCashReceipt` called with the Partner throw `AuthorizationException` and change nothing; the Admin `ViewInvoice` and the client edit page answer 403 to the Partner.
- `CONTRIBUTING.md`: Immutability bullet ("or by the Admin's manual lock"); Editable invoices bullet (sole-locker sentence and the closing "may lock" sentence rewritten to name `LockInvoice`, `invoice_locked_manually` added to the audit sentence, "must not call the lock step" kept); Cash receipts bullet (skipped confirmation clause now covers the switch, `LockCashReceipt` joins the Actions); new bullet "Per-client e-mail switch." with the names the plan lists.
- `RepositoryFilesTest`: phrase list and class map of the Editable invoices test updated (`LockInvoice`, and a check that `LockInvoice.php` writes the locked state), `LockCashReceipt` added to the Cash receipts names, new test "hands the per-client e-mail switch over: the Per-client e-mail switch bullet names only what exists".

## Deviations from Plan

### Not done in the worktree

**1. [Rule 3 - Blocked by project rules] `tests/.pest/shards.json` not refreshed**
- **Issue:** The refresh needs `ddev exec vendor/bin/pest --exclude-testsuite=Audit --update-shards`, which cannot run in the worktree (no vendor, no DB, CLAUDE.md forbids it). No Phase 16 test class is in the file yet, so the acceptance `grep -q "HandServedClientJourneyTest" tests/.pest/shards.json` does not hold until the orchestrator runs the command after the merge. I did not hand-write timings.
- **Files:** `tests/.pest/shards.json` (untouched)

**2. [Rule 3] Task 3 gate not run**
- `ddev exec php artisan migrate` and `ddev exec composer ci` are post-merge steps for the orchestrator. The Task 3 precondition (`git diff --quiet -- CONTRIBUTING.md`) held in the worktree.

### Small wording choices

- The plan names the hint in the human-check as "E-maily s doklady vypnuté"; the actual copy built by 16-01 is `kokpit.clients.badges.document_emails_off`, which the test reads through `__()` where it checks it.
- The plan asks every journey step to assert with a message. Pest's `expect()` chain used elsewhere in this suite carries no message argument, so each step is a separate `expect()` chain under a numbered comment; a failing line names the surface.

## Verification status

Worktree only: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` and the hook (sensitive-content, gitleaks) clean on each commit. Acceptance greps checked here: the `Per-client e-mail switch.` string is in both CONTRIBUTING.md and RepositoryFilesTest, the full old sentence "The only code that locks is" has 0 matches in both files, `LockInvoice`, `LockCashReceipt`, `ClientDocumentEmails` are in CONTRIBUTING.md.

Post-merge, for the orchestrator, in this order:
1. `ddev exec php artisan migrate` (three migrations of the phase: 2026_10_28_000100, 000200, 000300)
2. `ddev exec vendor/bin/pest tests/Feature/Invoicing/HandServedClientJourneyTest.php tests/Feature/Clients/DocumentEmailsScopeTest.php tests/Isolation/InvoiceLeakTest.php tests/Feature/Repo/RepositoryFilesTest.php`
3. `ddev exec vendor/bin/pest --exclude-testsuite=Audit --update-shards`, keep `tests/.pest/shards.json`
4. `ddev exec composer ci` (phase gate), then commit the refreshed shards file.

Points worth watching in the post-merge run (untested here):
- `EditClient` save of an `InvoiceFixtures::client()` row (journey steps 1 and 8): the form is filled from the record; if a required field is empty in the fixture the save will report a form error.
- The payment dialog in step 5 is filled with `fillForm(['amount' => '400.00', 'method' => 'cash'], 'mountedActionSchema0')` after `mountAction`; the default `paid_on` is today, and the notification text is read from `invoices.payments.record.toast_invoice`.
- The journey and the isolation describe re-run `actingAs($this->admin)` after fixtures, per the lessons of earlier waves.
- `Activity::query()` as a Partner is assumed scoped to nothing for Admin-only rows, as the Phase 10.1 describe in the same file already relies on.
- Pint may reorder imports in the touched files.

## Known Stubs

None.

## Threat Flags

None. Only tests and documentation changed; no migration, no composer change, no endpoint.

## Self-Check: PASSED

- Files exist: HandServedClientJourneyTest.php, DocumentEmailsScopeTest.php, this SUMMARY.
- Commits 33a8fc3, 61a2062, 96f0411 are ancestors of HEAD.
