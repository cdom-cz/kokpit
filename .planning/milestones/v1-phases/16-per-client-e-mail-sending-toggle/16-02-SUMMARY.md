---
phase: 16-per-client-e-mail-sending-toggle
plan: 02
subsystem: invoicing
tags: [invoicing, email, lock, audit, filament]
requires: [16-01]
provides:
  - App\Domain\Invoicing\Actions\LockInvoice (manual lock of an issued, open invoice or proforma)
  - InvoiceAudit::lockedManually() and everSent() counting an invoice_locked_manually row
  - QueueInvoiceEmail refusal under key invoice for a client with the switch off
  - ViewInvoice lock action on the open_for_edits callout and the gray email_disabled callout
affects: [16-03, 16-04, 16-05]
tech-stack:
  added: []
  patterns:
    - second owner-approved writer of the locked state, confined by the architecture test
    - fresh-read domain gate asked by the Action and the page
key-files:
  created:
    - app/Domain/Invoicing/Actions/LockInvoice.php
    - tests/Feature/Invoicing/HandServedDocumentTest.php
    - tests/Feature/Invoicing/LockInvoiceTest.php
  modified:
    - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
    - app/Domain/Invoicing/Support/InvoiceAudit.php
    - app/Filament/Support/SendInvoiceEmailAction.php
    - app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php
    - tests/Arch/InvoiceUnlockPathTest.php
    - lang/cs/invoices.php
    - lang/cs/kokpit.php
    - tests/Feature/Invoicing/InvoiceEditAuditTest.php
    - tests/Feature/Invoicing/InvoiceDetailTest.php
key-decisions:
  - "LockInvoice judges every refusal on the invoice row read with lockForUpdate, so a lock by hand and a lock by send serialize and one lock row results"
  - "The manual lock writes its own event invoice_locked_manually and no invoice_emails row or sent_at"
  - "everSent() reads the activity log for the manual-lock event (owner answer A1)"
requirements-completed: [P16-03, P16-07, P16-08, P16-10]
duration: 35min
completed: 2026-10-28
status: complete
actuals:
  tokens: 21000
  tasks: 3
  commits: 3
plan_head_before: c448413a5afb0264203722fbe06ee0b4ca3b54f5
plan_head_after: 70adaa1586bdede033677458e72f8effd351b156
---

# Phase 16 Plan 02: Hand-served client detail and manual lock Summary

The invoice detail of a client with the document e-mail switch off no longer offers "Odeslat e-mailem" or the post-issue dialog and says why in a gray note; a crafted queue call is refused without a row; and the Admin can lock any open issued invoice or proforma by hand, audited under its own event and counted as a hand-over for later edits.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: hidden header action, gray note, LockInvoice, lock button, audit, arch test, copy | c7f4aa3 |
| 2 | Queue refusal, tests for the closed dialog, lock refusals, lock against send, unlock afterwards | 70b1af6 |
| 3 | everSent() counts the manual lock, reworded lock texts, edit audit and banner tests | 70adaa1 |

## What was built

- `LockInvoice` (`final readonly`): Gate `update`, then one transaction with the invoice row read `lockForUpdate`; refusals in order draft (`not_issued`), credit note (`credit_note_locked`), locked (`already_locked`), cancelled (`cancelled_not_editable`), converted proforma (`converted_not_editable`), all under key `lock`; saves `lock_state` through the model; writes `InvoiceAudit::lockedManually()`. No `invoice_emails` row, no `sent_at`, no unlock setting.
- `InvoiceAudit::lockedManually()` (event `invoice_locked_manually`, Admin as causer, old open to locked) and `everSent()` which is now true for a sent e-mail row or a manual-lock activity row.
- `QueueInvoiceEmail::handle()` throws `invoice => email_client_disabled` right after the issued check, before the recipient is cleaned or any row is built, for every kind.
- `SendInvoiceEmailAction::make()` visible only for an issued document whose client allows document e-mails. `ViewInvoice::mount()` and `openSendDialog()` apply the same condition, so `send=1` opens no dialog for a hand-served client.
- `ViewInvoice`: gray callout `email_disabled` after the overdue callout; `lockAction()` (`lock`, confirmation, no reason) attached to the `open_for_edits` callout; the page asks `isLocked()` and never names the lock case.
- `tests/Arch/InvoiceUnlockPathTest.php`: header point 2 rewritten, locked-token list now four files including `LockInvoice.php`.
- Czech copy: new keys per R-16-02-7 and the reworded open/locked banners, unlock description and `document_locked` so no text claims an e-mail where none was sent; activity label `invoice_locked_manually`.
- Tests: `HandServedDocumentTest` (hidden action and note for invoice and proforma, lock from the callout with audit row and unchanged snapshots, queue refusal for three kinds with no row and no job, stale-model case, closed and open dialog), `LockInvoiceTest` (lock of invoice and proforma, five refusals, Partner, both lock-against-send orders, edit refused, unlock and relock), `InvoiceEditAuditTest` (everSent for a hand lock, edit audited after hand lock and unlock, label), `InvoiceDetailTest` (reworded banner texts in both directions).

## Deviations from Plan

None in substance. Ordering only: the `mount()` and `openSendDialog()` guards of Task 2 step 3 were written together with the rest of the `ViewInvoice` change in the Task 1 commit (same file); their tests are in Task 2.

## Verification status

Worktree only, as required by the project rules: `php -l` clean on every touched PHP file, `scripts/check-sensitive.sh` and the lefthook hook (sensitive-content, gitleaks) clean on each commit. Not run here (no vendor or DB): Pest, Pint, PHPStan. Post-merge for the orchestrator: the per-task Pest commands, `pint --test`, `phpstan analyse`, and the plan gate (`pest tests/Feature/Schema tests/Feature/Payments tests/Feature/Invoicing tests/Feature/Clients tests/Isolation tests/Arch`).

Points worth watching in the post-merge run (untested here): the `lock` action is addressed as `TestAction::make('lock')->schemaComponent('open_for_edits', schema: 'infolist')`; after the lock the callout is hidden, so the tests assert `assertDontSee('Uzamknout')` rather than addressing the hidden action; `Callout::color('gray')` is used for the note; the order-A race test runs the job through its middleware pipeline (as `SendInvoiceEmailTest::invMailTry`) because `Queue::fake()` is active; the credit note case of the queue refusal uses `IssueCreditNote::storno` of an issued invoice of the hand-served client.

## Known Stubs

None.

## Threat Flags

None beyond the plan's register. No migration, no composer change, no new endpoint.

## Self-Check: PASSED

- Files exist: LockInvoice.php, HandServedDocumentTest.php, LockInvoiceTest.php, this SUMMARY.
- Commits c7f4aa3, 70b1af6, 70adaa1 are ancestors of HEAD.
