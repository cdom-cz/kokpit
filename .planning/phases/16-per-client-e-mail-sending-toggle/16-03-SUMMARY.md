---
phase: 16-per-client-e-mail-sending-toggle
plan: 03
subsystem: invoicing, operations
tags: [email, queue, job, filament, health-indicator, migration]
requires:
  - phase: 16-01
    provides: ClientDocumentEmails gate (enabledFor, enabledForInvoice, allows), InvoiceFixtures client override
provides:
  - invoice_emails.state value 'skipped' (check constraint widened, down() refused while a skipped row exists)
  - InvoiceEmailState::Skipped with Czech label "Neodesláno (vypnuto u klienta)"
  - SendInvoiceEmail skip path (after the claim, right before the transport call, stale give-up)
  - skipped read as "not sent" in the list column, the 'not sent' filter and the Odeslání tab (gray badge)
  - no resend in the list row and the Odeslání row for a client with the switch off
  - UnsentInvoiceEmailsIndicator ignores skipped rows and documents of hand-served clients
affects: [16-04, 16-05]
tech-stack:
  added: []
  patterns:
    - fresh-read gate twice in a job (after the claim, last statement before the transport call)
    - end state that is neither failed nor unsent, never revived
key-files:
  created:
    - database/migrations/2026_10_28_000200_add_skipped_state_to_invoice_emails.php
  modified:
    - app/Domain/Invoicing/Enums/InvoiceEmailState.php
    - lang/cs/enums.php
    - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
    - app/Filament/Support/InvoiceTable.php
    - app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEmailsRelationManager.php
    - app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php
    - tests/Feature/Invoicing/SendInvoiceEmailTest.php
    - tests/Unit/Invoicing/InvoiceEnumsTest.php
    - tests/Feature/Schema/InvoiceAdminTablesTest.php
    - tests/Feature/Invoicing/EmailDialogTest.php
    - tests/Feature/Invoicing/InvoiceListTest.php
    - tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php
key-decisions:
  - "The skip path is a conditional update from sending to skipped plus return, never a throw, so the queue cannot re-queue the row"
  - "The stale give-up reads the client id under the existing invoice row lock and ends a switched-off client's stale row skipped (no failure code, no bell) instead of failed"
  - "The indicator excludes hand-served clients with a NOT EXISTS over invoices and clients; archived clients with the switch on still count"
requirements-completed: [P16-05, P16-06, P16-07]
duration: 35min
completed: 2026-10-28
status: complete
actuals:
  tokens: 9000
  tasks: 3
  commits: 3
plan_head_before: c448413a5afb0264203722fbe06ee0b4ca3b54f5
plan_head_after: 780f7d468bb8d1cbd2f1ab9f613b1ef1affb7deb
---

# Phase 16 Plan 03: Skipped document e-mail Summary

A document e-mail still queued when the Admin switches the client off now ends `skipped`: the send job re-reads the switch right after its claim and again as the last statement before the transport call, sends nothing, locks nothing and rings no bell; the list, filter and Odeslání tab read it as "not sent", and the System page stays quiet for it and for hand-served clients.

`commits: 3` counts the code commits; the SUMMARY commit follows `plan_head_after`.

## Tasks

| Task | Name | Commit |
|------|------|--------|
| 1 | Tracer: skipped state, job skip path, list and tab match arms | 63c718d |
| 2 | No resend for a hand-served client in list and tab; 'not sent' filter finds skipped | af542f7 |
| 3 | Indicator ignores skipped rows and hand-served clients | 780f7d4 |

## What was built

- Migration `2026_10_28_000200_add_skipped_state_to_invoice_emails.php` drops and re-adds `invoice_emails_state_check` with `skipped`. The header records the review of the other checks (sent_at, failure_code, recipient stay valid for a skipped row). `down()` throws a `RuntimeException` while a skipped row exists, else restores the original check verbatim.
- `InvoiceEmailState::Skipped` and the label `enums.invoice_email_state.skipped`.
- `SendInvoiceEmail`: private `skippedBySwitch()` called as the first statement in the `try` after the claim (so a switched-off client with a missing PDF ends skipped, not `attachment_missing`) and as the statement immediately before `Mail::to()`. `claim()` now reads `client_id` under the same invoice row lock; a stale queued row of a switched-off client goes to `skipped` instead of `failed`. `#[Idempotent]` text and class docblock updated.
- `InvoiceTable`: `emailKey()` maps Skipped to `none`; the `none` filter is "latest IS NULL OR latest = skipped"; the row resend also requires `ClientDocumentEmails::allows($record->client)` (client is eager-loaded).
- `InvoiceEmailsRelationManager`: gray badge for Skipped; the row resend also requires `ClientDocumentEmails::enabledFor(client_id)`.
- `UnsentInvoiceEmailsIndicator`: `HAND_SERVED_SQL` applied beside `LATEST_ROW_SQL`; docblock extended.
- Tests: skip path (queued then off, no revive and rerun, stale row, enabled client still sends, switch flipped while the stored PDF is read via an `eloquent.retrieved: Document` listener), enum label and cases, schema check for skipped, tab and list rendering, resend hiding, filter, indicator cases.

## Deviations from Plan

One small deviation: the plan listed a SendInvoiceEmailTest case "disabled client with a missing PDF ends skipped" only implicitly through the job ordering; I did not add a dedicated test for it, because making the stored PDF vanish on an issued document risked tripping immutability triggers I could not run here. The ordering is in the code (first statement of the `try`) and pinned by the acceptance grep.

Otherwise none - plan executed as written. Tests switch the client with a system `Client::query()->update()` rather than through `UpdateClient`, which needs a full form payload and adds nothing to what is being tested.

## Verification status

Worktree checks only, per the project rules: `php -l` clean on every touched file, `scripts/check-sensitive.sh` clean for each commit, hooks (sensitive-content, gitleaks) passed. Not run here (no vendor or DB): migration, Pest, Pint, PHPStan. Post-merge, in this order: `ddev exec php artisan migrate`, then the per-task Pest commands and the plan gate (`pest tests/Feature/Schema tests/Feature/Invoicing tests/Feature/Operations tests/Feature/Clients tests/Unit tests/Isolation tests/Arch`, `pint --test`, `phpstan analyse`).

Points worth watching in the post-merge run (untested here):
- The read-window test relies on the stored PDF `Document` model firing `eloquent.retrieved` between the two checks (`files()` -> `storedPdf()` -> `$invoice->admin?->pdfDocument`); if the relation is cached from an earlier read, adjust the listener target.
- `assertTableColumnStateSet('state', InvoiceEmailState::Skipped, ...)` and the gray badge rely on the existing `effectiveState()` pass-through.
- Pint may reorder the new `ClientDocumentEmails` imports; the imports were placed alphabetically.
- `Client::query()->whereKey(...)->first()?->delete()` in the archived-client indicator test assumes soft delete without hooks (none found in `Client`).

## Known Stubs

None.

## Threat Flags

None. The added SQL joins `clients` by id and reads only the boolean; the indicator's select list is unchanged.

## Self-Check: PASSED

- Files exist: migration, SUMMARY, all modified files listed above.
- Commits 63c718d, af542f7, 780f7d4 are ancestors of HEAD.
