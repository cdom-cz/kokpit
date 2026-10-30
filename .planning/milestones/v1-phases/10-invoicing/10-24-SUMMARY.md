---
phase: 10-invoicing
plan: 24
subsystem: invoicing-email
tags: [invoicing, email, queue, job, notification, work-report, idempotent]
status: complete

requires:
  - phase: 10-invoicing
    provides: "The stored PDF of an issued document linked from invoice_admin.pdf_document_id (plan 10-20), the InvoiceEmail model and table (plan 10-08), the cs invoices copy sections email and errors.email_* (plans 10-21, 10-11), WorkReportSource::fromEntryIds and WorkReportPdf (Phase 8)"
provides:
  - "QueueInvoiceEmail: defaults() for the dialog (client invoice e-mail, subject and body in the document language) and handle() that validates and stores one queued log row and dispatches the job after the commit"
  - "SendInvoiceEmail: idempotent KokpitJob identified by the e-mail row id, conditional claim, stored PDF plus optional work report, plain-text mail, row marked sent"
  - "Failure handling: fixed failure classes transport / attachment_missing / unexpected, one bell notice per Admin naming the number only"
  - "InvoiceMail (plain text, generated attachment names), resources/views/mail/invoice-text.blade.php, lang/{cs,en}/invoice_mail.php"
affects: [10-25, 10-36, 10-37, 10-38]

actuals:
  tokens: 13850
  tasks: 2
  commits: 3
plan_head_before: 88fcf2d95eb8ad3fa470e743ec3881c3478c150a
plan_head_after: 4545d1de37abeaefa989ba06cbb23b2d295f641d
commits: 3

coverage:
  - id: D1
    description: "QueueInvoiceEmail pre-fills recipient, subject and body from the client and the document language (cs or en, UI-SPEC wording with number, amount, due date, supplier) and stores a queued row, dispatching SendInvoiceEmail after the commit"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#pre-fills the dialog from the client and the language of the document"
        status: pass
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#stores a queued row and dispatches the job after the commit"
        status: pass
    human_judgment: false
  - id: D2
    description: "SendInvoiceEmail sends one plain-text mail with the stored PDF (generated name) and, when ticked and time is linked, the work report without prices; a row already sent is skipped"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#sends one plain-text mail with the stored PDF and marks the row sent"
        status: pass
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#attaches the work report of the linked time, without any price"
        status: pass
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#sends nothing when the row was already sent"
        status: pass
    human_judgment: false
  - id: D3
    description: "A send the queue gives up on is marked failed with a fixed class and every Admin gets exactly one bell notice with the document number only (no recipient, amount, provider text); a Partner gets nothing; a missing PDF fails at once"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#marks the row failed with the transport class and sends one clean bell notice to every Admin"
        status: pass
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#fails a row at once, with attachment_missing, when the stored PDF object is gone"
        status: pass
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#gives a partner of the client no notice and no row"
        status: pass
    human_judgment: false
  - id: D4
    description: "R-24-1: a resend is a new row, the first stays failed and the latest row decides"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#is a new row; the first stays failed and the latest row decides"
        status: pass
    human_judgment: false
  - id: D5
    description: "Recipient, subject and body validation: invalid or multi-line recipient refused, CR/LF stripped from the subject, 200 and 5000 character caps, plain text only, draft and Partner refused, work-report flag ignored without linked time"
    requirement: "IN-04"
    verification:
      - kind: unit
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#what the dialog may submit"
        status: pass
    human_judgment: false
  - id: D6
    description: "The job is on the architecture job list with its #[Idempotent] declaration"
    verification:
      - kind: unit
        ref: "tests/Arch/JobContractTest.php#has exactly the expected application jobs under app/"
        status: pass
    human_judgment: false

key-files:
  created:
    - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
    - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
    - app/Domain/Invoicing/Mail/InvoiceMail.php
    - app/Domain/Invoicing/Notifications/InvoiceEmailFailedNotification.php
    - resources/views/mail/invoice-text.blade.php
    - lang/cs/invoice_mail.php
    - lang/en/invoice_mail.php
    - tests/Feature/Invoicing/SendInvoiceEmailTest.php
  modified:
    - tests/Arch/JobContractTest.php

key-decisions:
  - "R-24-1 applied: every send, a resend included, is a new invoice_emails row; the job is idempotent per row (conditional claim queued -> sending); the latest row is the state of the document"
  - "R-24-2 applied: failure classes transport, attachment_missing, unexpected; a missing PDF (no link or no object) is final at once and is not retried"
  - "R-24-4 applied: the work report is attached only when the row asks for it and the document still has linked entries; supplier and client lines are read live"
  - "A run that throws puts the row back to queued so the queue's next try can claim it; failed() marks it failed once the attempts are used up and sends the bell notice only when that update really changed the row"
  - "A transport exception leaves the job as a fixed-message RuntimeException (original kept as previous): the generic failed-job alert prints the first message line into the bell, and a provider answer can name the recipient"
  - "Line breaks in the subject become a single space (not an empty string), so two words never run together; control characters other than tab and, in the body, line break are dropped"

patterns-established:
  - "Conditional-update claim with revert-on-exception for a retried job whose unit is a log row"
  - "Outgoing mail tests read the array transport's Symfony messages (real text body, attachments, headers) instead of Mail::fake()"
  - "Jobs that must keep provider text out of the generic failed-job alert rethrow transport failures with a fixed message"

requirements-completed: [IN-04]

duration: "19 min"
completed: 2026-10-17
---

# Phase 10 Plan 24: Document e-mail Summary

**An issued invoice, proforma or credit note is sent by an idempotent queued job as a plain-text mail with the stored PDF and, on request, a price-free work report of the linked time; a send that keeps failing is marked with a fixed failure class and tells each Admin once, by document number only.**

## Performance

- **Duration:** 19 min
- **Started:** 2026-10-17T15:28:32Z
- **Completed:** 2026-10-17T15:47:00Z
- **Tasks:** 2
- **Files modified:** 9 (8 created, 1 modified)

## Accomplishments

- `QueueInvoiceEmail::defaults()` gives the dialog the client's invoice e-mail (null when the client has none), the subject and body of the UI-SPEC wording in the document language with amount, due date and supplier name, and the work-report tick (on when time is linked). `handle()` validates, stores one `queued` row and dispatches `SendInvoiceEmail` after the commit.
- `SendInvoiceEmail` claims the row with a conditional update, attaches the PDF stored at issue (generated name) and, when the row asks and entries are still linked, the work report from `WorkReportSource::fromEntryIds()` in the document language, sends through `Mail::send()` and marks the row sent. Sent, failed, sending and missing rows are skipped.
- A failed send: back to `queued` between tries, `failed` with `transport` / `unexpected` once the queue gives up, `attachment_missing` immediately when the PDF cannot be read. One `InvoiceEmailFailedNotification` per Admin, database channel only, number only.
- Validation per the plan: `email:rfc` recipient without control characters, subject without CR/LF capped at 200, body capped at 5 000, plain text only, drafts refused (`email_not_issued`), Partners refused by the policy.
- 31 tests in `SendInvoiceEmailTest`, `SendInvoiceEmail` added to the job list of `JobContractTest`.

## Task Commits

1. Task 1 (tracer): `5f4d024` feat(10-24): send an issued document by queued e-mail with its PDF and optional work report
2. Task 2: `6a0e4fa` feat(10-24): fail a document e-mail cleanly, one bell notice, a resend is a new row
3. Follow-up: `4545d1d` style(10-24): order the imports of the document e-mail test (Pint, missed before the Task 2 commit)

**Plan metadata:** the docs commit that carries this file.

The tracer feedback gate: the tracer `<verify>` (the test file, Pint, PHPStan) was re-run end to end after commit and passed before Task 2 started.

## Decisions Made

See `key-decisions` above. The two worth repeating: the revert-to-queued on exception is what lets a claim-by-conditional-update job still be retried by the queue, and the fixed-message rethrow of transport failures is what keeps the recipient out of the bell.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] A provider answer could reach the bell through the generic failed-job alert**
- **Found during:** Task 2 (test "marks the row failed with the transport class ...", after delivering the deferred alert)
- **Issue:** `ReportFailedJob` prints the first line of the exception message into the Admin bell. The message of a refused transport can name the recipient and carries the provider's wording, which the plan prohibits in the bell (T-10-65).
- **Fix:** `handle()` rethrows a `TransportExceptionInterface` as a `RuntimeException` with a fixed message, keeping the original as the previous exception (kept in `failed_jobs` and the log only); `failed()` classifies by the exception or its previous.
- **Files modified:** app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
- **Verification:** the test delivers the deferred technical alert and asserts the whole bell holds neither the recipient nor the provider text. It failed (RED) before the fix.
- **Committed in:** 6a0e4fa

**2. [Scope] Notification class and the job-list entry moved into the Task 1 commit**
- The job cannot run its failure path without `InvoiceEmailFailedNotification`, and `JobContractTest` fails on an unlisted job, so both belong to the commit that adds the job. Task 2 holds the transport sanitisation and the tests for failure, resend and validation.

**3. [Scope] Task 2 had little RED**
- Task 1 already had to implement the claim, the revert and `failed()` to be a coherent job, so most Task 2 tests passed on first run; the one real RED was deviation 1.

**Total deviations:** 1 auto-fixed (Rule 2), 2 scope notes. **Impact:** none on the contract; the sanitisation tightens T-10-65.

## Issues Encountered

- `Queue::fake()` also fakes `dispatchSync`, so tests that run the job after a faked dispatch call it through its middleware pipeline instead (`invMailTry`); the failure end-to-end test uses the real sync connection.
- The guard on `invoice_admin.pdf_document_id` allows clearing the link only when its file is deleted, so the "no PDF link" case is built through `DeleteDocument`.

## Known Stubs

None.

## Threat Flags

None new. T-10-63 (header injection, markup in the body) and T-10-65 (leak through bell or log) are mitigated and tested; T-10-64 is accepted as planned and the docblock of the job states the exact repeat window.

One observation for the owner of the System page (plan 10-37): the generic Phase 3 failed-job alert still lists the job class, the attempt count and the exception class for this job, as for every job; it no longer contains provider text for a transport failure.

## Human Check Pending

None for this plan. The e-mail dialog, the log tab and the System page slot come in later plans (10-36, 10-37).

## Next Phase Readiness

Ready for the next plan of the phase. Plans that build the dialog and the "Odeslání" tab can call `QueueInvoiceEmail::defaults()` and `::handle()` as they are; the state of a document is the state of its latest `invoice_emails` row, and a row left `sending` by a dead worker is for plan 10-37's stale threshold to treat as failed.

## Self-Check: PASSED

- All files in key-files exist; commits 5f4d024, 6a0e4fa and 4545d1d are ancestors of HEAD.
- Acceptance criteria of both tasks re-run: PASS. Full suite (`ddev exec vendor/bin/pest --compact`): 5574 passed, 3 skipped (none in this plan). Pint `--test` and PHPStan clean; `scripts/check-sensitive.sh` and the hook clean on every commit.
