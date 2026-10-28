---
phase: 16-per-client-e-mail-sending-toggle
verified: 2026-10-28T12:00:00Z
status: passed
score: 11/11 must-haves verified
covered_files:
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-01-PLAN.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-01-SUMMARY.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-02-PLAN.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-02-SUMMARY.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-03-PLAN.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-03-SUMMARY.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-04-PLAN.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-04-SUMMARY.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-05-PLAN.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-05-SUMMARY.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-06-PLAN.md"
  - ".planning/phases/16-per-client-e-mail-sending-toggle/16-06-SUMMARY.md"
  - "app/Domain/Clients/Actions/ClientInput.php"
  - "app/Domain/Clients/Actions/CreateClient.php"
  - "app/Domain/Clients/Models/Client.php"
  - "app/Domain/Invoicing/Actions/LockCashReceipt.php"
  - "app/Domain/Invoicing/Actions/LockInvoice.php"
  - "app/Domain/Invoicing/Actions/QueueInvoiceEmail.php"
  - "app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php"
  - "app/Domain/Invoicing/Jobs/SendInvoiceEmail.php"
  - "app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php"
  - "app/Domain/Invoicing/Support/CashReceiptAudit.php"
  - "app/Domain/Invoicing/Support/ClientDocumentEmails.php"
  - "app/Domain/Invoicing/Support/InvoiceAudit.php"
  - "app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php"
  - "app/Filament/Resources/ClientResource.php"
  - "app/Filament/Resources/ClientResource/Pages/ViewClient.php"
  - "app/Filament/Resources/InvoiceResource/Pages/ViewInvoice.php"
  - "app/Filament/Resources/InvoiceResource/RelationManagers/InvoiceEmailsRelationManager.php"
  - "app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php"
  - "app/Filament/Support/InvoiceTable.php"
  - "app/Filament/Support/RecordPaymentAction.php"
  - "app/Filament/Support/SendInvoiceEmailAction.php"
  - "database/migrations/2026_10_28_000100_add_document_emails_enabled_to_clients_table.php"
  - "database/migrations/2026_10_28_000200_add_skipped_state_to_invoice_emails.php"
  - "database/migrations/2026_10_28_000300_relax_payment_confirmation_recipient_check.php"
  - "tests/Arch/CashReceiptUnlockPathTest.php"
  - "tests/Arch/InvoiceUnlockPathTest.php"
covered_digest: "v4:sha256:7e8a48faf2109483b950b00eff141a3814aca31b4b0f506dc0536437b93e002e"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "Open a client with the switch off and one with it on in the browser (create form, edit form, detail)"
    expected: "The toggle \"Odesílat doklady e-mailem\" sits next to the invoice e-mail, defaults to on in the create form, and the detail heading carries the warning badge \"E-maily s doklady vypnuté\" only when it is off; the client list has no such column"
    why_human: "Visual placement, badge rendering and wording are not asserted by DOM-level tests"
  - test: "Open the detail of an issued invoice of a hand-served client, then of a normal client; issue a document of a hand-served client from the editor"
    expected: "Hand-served: no \"Odeslat e-mailem\" in the header, a gray note appears, no send dialog opens after the issue, the Odeslání tab and list row offer no resend; \"Uzamknout\" on the open callout asks for a confirmation and locks; the Platby tab offers \"Uzamknout\" on an open receipt"
    why_human: "Layout, gray colour of the note, modal flow and the post-issue redirect are visual / interactive behaviour"
  - test: "With a real queue worker and Mailpit: record a payment or queue a document e-mail, switch the client off inside the 5-minute grace window, let the worker run"
    expected: "Nothing arrives in Mailpit, the row ends skipped (gray badge), the document stays unlocked, no failure bell; switching on again does not send the old row"
    why_human: "Tests fake the transport and drive the job synchronously; a real worker and mailer end to end is not exercised by the suite"
---

# Phase 16: Per-client e-mail sending toggle Verification Report

**Phase Goal:** A client setting that disables sending e-mails (invoices and related documents) to that client, for clients served exclusively by hand; sending stays enabled by default.
**Verified:** 2026-10-28
**Status:** human_needed
**Re-verification:** No, initial verification

The ROADMAP carries no Success Criteria array for this phase, so the must-haves are the eleven requirements P16-01 to P16-11 (REQUIREMENTS.md) plus the decisions D-01 to D-08 of 16-CONTEXT.md. Every claim below was checked against the code, not the SUMMARY files.

## Goal Achievement

### Observable Truths

| #   | Truth (requirement) | Status | Evidence |
| --- | ------- | ------ | -------- |
| 1 | P16-01: switch default on for every existing and new client, edited in the form, badge in detail when off, no list column | VERIFIED | Migration `2026_10_28_000100` adds `document_emails_enabled` boolean NOT NULL default true (Postgres fills existing rows, no backfill). `ClientResource` has the `Toggle` (default true) next to `invoice_email`, an `IconEntry` in the infolist, `ViewClient::getHeading()` renders a warning badge only when off. `ClientInput::attributes` leaves the key out when absent/null, `CreateClient::withDefaults` forces true. `ClientResourceTest` (opens form on, switches off, create stores true, badge shown/not shown, `assertTableColumnDoesNotExist`) passes. |
| 2 | P16-02: a change is audited with who, when, old and new value | VERIFIED | Attribute added to `Client` `#[LoggedAttributes]`; `ClientResourceTest` reads the activity row: `attributes` => false, `old` => true, with causer; the cs attribute label exists in `lang/cs/kokpit.php`. |
| 3 | P16-03: a document e-mail to a switched-off client is refused by the domain, no e-mail log row | VERIFIED | `QueueInvoiceEmail::handle` throws `ValidationException(invoice)` via `ClientDocumentEmails::enabledFor` inside the transaction before any row is written and before dispatch; kind-agnostic (invoice, proforma, credit note). `ClientDocumentEmailsTest` and `HandServedDocumentTest` pass. |
| 4 | P16-04: payment confirmation row is `skipped`, no job queued, payment never blocked | VERIFIED | `QueuePaymentConfirmation` computes `$enabled = ClientDocumentEmails::allows($client)` from the client read in the payment transaction; state `Skipped` when `$recipient === null \|\| ! $enabled`, `dispatch` only when both hold; recipient kept. Migration `2026_10_28_000300` relaxes `payment_confirmations_recipient_check` so a skipped row may keep the address (down() refuses while such a row exists). `PaymentConfirmationTriggerTest` (payment and income recorded, row skipped with address, no job) passes. `RecordPaymentAction` hint says nothing is sent. |
| 5 | P16-05: queued document e-mail / confirmation is not sent when the switch goes off; job re-checks after claim, marks `skipped`, locks nothing; switching on revives nothing | VERIFIED | `SendInvoiceEmail` and `SendPaymentConfirmation` call `skippedBySwitch()` right after the claim and again as the last statement before `Mail::to()`; the conditional update `sending -> skipped` never throws, sets no failure code, writes no lock and no bell. A stale queued row of a switched-off client also ends `skipped` in `claim()`. Only a `queued` row is ever claimed, so nothing is revived. Migration `2026_10_28_000200` extends `invoice_emails_state_check` (sent_at / failure_code checks reviewed). Tests for the re-check before transport, no revival, stale path and "switch on still sends and locks" pass (behavioural, exercised through the job). |
| 6 | P16-06: `skipped` counts as neither failed nor unsent in the System indicator, the bell, list rows and detail; the indicator ignores hand-served clients | VERIFIED | `UnsentInvoiceEmailsIndicator` counts only failed/queued/sending states and adds `HAND_SERVED_SQL` (excludes documents of clients with the switch off; archived client with switch on still counted). `InvoiceTable::emailKey` maps `Skipped` to `none` and the "none" filter includes a latest skipped row. `InvoiceEmailsRelationManager` shows a gray badge and offers resend on failed rows only. The failure notification fires only on `giveUp`, never on the skip path. `UnsentInvoiceEmailsIndicatorTest` Phase 16 block and `InvoiceListTest` pass. |
| 7 | P16-07: send / resend hidden in detail header, list row and Odeslání row; gray note in detail; no dialog after issue | VERIFIED | `SendInvoiceEmailAction::make` visible only if issued and `enabledFor`; `InvoiceTable::resendEmailAction` adds `ClientDocumentEmails::allows($record->client)` (the list eager-loads `client`, the relation is `withTrashed`); the relation manager row action adds `enabledFor`; `ViewInvoice` has the gray `email_disabled` callout, and both `mount()` (`send=1`) and `openSendDialog()` check the switch. `EmailDialogTest`, `InvoiceDetailTest`, `HandServedDocumentTest` pass. Visual aspects go to human verification. |
| 8 | P16-08: Admin locks an issued open invoice/proforma by hand without a reason; refused for draft, credit note, locked, cancelled, converted proforma; audited; number/kind/snapshots unchanged; later edit after unlock audited | VERIFIED | `LockInvoice` authorizes `update` (Partner refused), locks the invoice row first, `refuse()` matches exactly those cases, saves `lock_state` through the model (row guard allows open to locked), writes `InvoiceAudit::lockedManually` (own event `invoice_locked_manually`, Admin as causer). `InvoiceAudit::everSent()` counts the manual lock event, so edits after unlock are audited. `SendInvoiceEmail::markSentAndLock` skips the lock when the document is already locked (no second lock row). UI: "Uzamknout" action with confirmation on the open callout. `LockInvoiceTest`, `InvoiceEditAuditTest` (lines 308, 319) pass. |
| 9 | P16-09: Admin locks an open, uncancelled cash receipt by hand with the same rules | VERIFIED | `LockCashReceipt` (policy, invoice row then receipt row lock, refuses cancelled / already locked, `CashReceiptAudit::lockedManually`); "Uzamknout" action in the Platby tab, visible only for an open uncancelled receipt. `CashReceiptLockTest` (including a later confirmation send leaving a hand-locked receipt alone with one lock row) passes. |
| 10 | P16-10: unlock stays Admin-only with mandatory reason; lock literals confined to named files (arch tests extended on purpose); DB row guards unchanged | VERIFIED | `UnlockInvoice` / `UnlockCashReceipt` untouched by the phase diff. `InvoiceUnlockPathTest` and `CashReceiptUnlockPathTest` now list `LockInvoice.php` / `LockCashReceipt.php` explicitly in the allowlist of files naming the locked token; the unlock-flag tests are unchanged. The three phase migrations touch only a column and two CHECK constraints; no guard function or trigger migration exists. |
| 11 | P16-11: system e-mails to the client's Partner users unaffected | VERIFIED | `ClientDocumentEmails` is referenced only from the document queue Actions, the two send jobs and document screens; `InvitePartner`, `SendPartnerPasswordReset` and `TaskNotifier` do not reference it. `DocumentEmailsScopeTest` proves invitation, password reset and task notification still go out for a switched-off client. |

**Score:** 11/11 truths verified (0 present, behavior-unverified). Behavior-dependent truths (5, 8, 9) each have a passing behavioural test that exercises the transition.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `database/migrations/2026_10_28_000100_add_document_emails_enabled_to_clients_table.php` | column, default true | VERIFIED | boolean default true, reversible |
| `database/migrations/2026_10_28_000200_add_skipped_state_to_invoice_emails.php` | `skipped` in state check | VERIFIED | check extended; down() guarded |
| `database/migrations/2026_10_28_000300_relax_payment_confirmation_recipient_check.php` | recipient check allows kept address on skipped | VERIFIED | down() guarded |
| `app/Domain/Invoicing/Support/ClientDocumentEmails.php` | single fail-closed domain gate | VERIFIED | fresh DB read, `withTrashed()`, used by every document path |
| `app/Domain/Invoicing/Actions/LockInvoice.php`, `LockCashReceipt.php` | manual lock Actions | VERIFIED | substantive, wired from `ViewInvoice` and `InvoicePaymentsRelationManager` |
| `app/Domain/Invoicing/Support/InvoiceAudit.php`, `CashReceiptAudit.php` | `lockedManually` rows | VERIFIED | own events, allowlisted payload |
| `UnsentInvoiceEmailsIndicator`, `InvoiceTable`, `InvoiceEmailsRelationManager`, `SendInvoiceEmailAction`, `ViewInvoice`, `ClientResource`, `ViewClient`, `RecordPaymentAction` | UI and read-side wiring | VERIFIED | each changed as described under the truths above |
| `lang/cs/*` | texts for badge, note, lock dialogs, skipped label, audit labels | VERIFIED | present (the project keeps only mail/PDF strings in `lang/en`, none needed here) |
| `CONTRIBUTING.md` | hand-over of the switch and the manual lock | VERIFIED | "Per-client e-mail switch" bullet plus updated immutability / cash-receipt bullets (committed in 96f0411) |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `QueueInvoiceEmail` | `ClientDocumentEmails` | `enabledFor` inside the transaction | WIRED | before row write and dispatch |
| `SendInvoiceEmail` / `SendPaymentConfirmation` | `ClientDocumentEmails` | `enabledForInvoice` after claim and before transport | WIRED | twice each |
| `QueuePaymentConfirmation` | `ClientDocumentEmails::allows` | client read in payment transaction | WIRED | |
| `ViewInvoice` Uzamknout action | `LockInvoice` | `app(LockInvoice::class)->handle` | WIRED | |
| `InvoicePaymentsRelationManager` Uzamknout | `LockCashReceipt` | `runReceiptAction` | WIRED | |
| `UnsentInvoiceEmailsIndicator` | clients table | `HAND_SERVED_SQL` | WIRED | |
| `InvoiceAudit::everSent` | `invoice_locked_manually` activity | query on `activity_log` | WIRED | |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Client form / infolist / heading badge | `document_emails_enabled` | `clients` column via model cast | Yes | FLOWING |
| Invoice detail note and header action | `enabledFor(client_id)` | fresh query of `clients` | Yes | FLOWING |
| Invoice list resend action | `$record->client` | eager-loaded `client` (`withTrashed`) | Yes | FLOWING |
| System indicator | `HAND_SERVED_SQL` | join `invoices` and `clients` | Yes | FLOWING |

### Behavioral Spot-Checks

Run in DDEV, single named files only, no migrations and no code edits.

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Phase 16 domain, lock, scope, indicator and arch tests | `ddev exec vendor/bin/pest` on `ClientDocumentEmailsTest`, `HandServedClientJourneyTest`, `HandServedDocumentTest`, `LockInvoiceTest`, `CashReceiptLockTest`, `DocumentEmailsScopeTest`, `UnsentInvoiceEmailsIndicatorTest`, `InvoiceUnlockPathTest`, `CashReceiptUnlockPathTest` | 73 passed (387 assertions) | PASS |
| Wider surfaces touched by the phase | `tests/Feature/Clients`, `PaymentConfirmation*Test`, `SendInvoiceEmailTest`, `InvoiceListTest`, `EmailDialogTest`, `InvoiceDetailTest`, `InvoiceEditAuditTest`, `CashReceiptDeliveryTest`, `tests/Isolation/InvoiceLeakTest.php`, `RepositoryFilesTest` | 564 passed (8450 assertions) | PASS |
| Context from the orchestrator (not re-run by me): full suite failed only in `StorageCheckTest`, `CiParityTest`, `DdevConfigTest` (caused by the owner's uncommitted rustfs/DDEV edits) and one flaky `TimeLeakTest` that passes alone; Pint and PHPStan clean | n/a | n/a | accepted as reported |

### Probe Execution

Step 7c: SKIPPED (no probe scripts declared by the Phase 16 plans).

### Requirements Coverage

| Requirement | Source Plan | Description | Status | Evidence |
| ----------- | ----------- | ----------- | ------ | -------- |
| P16-01 | 16-01 | switch default on, form, badge, no list column | SATISFIED | truth 1 |
| P16-02 | 16-01 | audit of the change | SATISFIED | truth 2 |
| P16-03 | 16-01, 16-02 | queueing refused, no log row | SATISFIED | truth 3 |
| P16-04 | 16-05 | skipped confirmation, no job | SATISFIED | truth 4 |
| P16-05 | 16-03, 16-05 | job re-check, skipped, no lock, no revival | SATISFIED | truth 5 |
| P16-06 | 16-03 | skipped not failed/unsent, indicator ignores hand-served | SATISFIED | truth 6 |
| P16-07 | 16-02, 16-03 | actions hidden, gray note, no dialog | SATISFIED (visual parts: human) | truth 7 |
| P16-08 | 16-02 | manual invoice/proforma lock | SATISFIED | truth 8 |
| P16-09 | 16-04 | manual cash receipt lock | SATISFIED | truth 9 |
| P16-10 | 16-02, 16-04 | unlock unchanged, arch allowlists, guards unchanged | SATISFIED | truth 10 |
| P16-11 | 16-06 | system e-mails unaffected | SATISFIED | truth 11 |

No orphaned requirements: all of P16-01 to P16-11 are mapped to Phase 16 and claimed by plans.

### Anti-Patterns Found

None that block the goal. Debt-marker scan (TBD / FIXME / XXX) over the modified files found no unreferenced markers. No stub, hollow prop or empty handler in the changed code.

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| `.planning/REQUIREMENTS.md`, `.planning/ROADMAP.md` | n/a | P16-xx still `Pending` / `[ ]`, plan checkboxes unticked | Info | bookkeeping only; update on phase completion |
| `tests/.pest/shards.json` | n/a | no Phase 16 class in the recorded timings | Info | only the shard balance is affected, not correctness (already known) |
| `app/Domain/Invoicing/Support/ClientDocumentEmails.php` | docblock | working tree differs from HEAD (an import replaces a FQCN in a `@see`) | Info | comment-only, belongs to the owner's uncommitted edits, not touched here; the digest above is taken on the working tree |

### Human Verification Required

#### 1. Client form, detail badge

**Test:** Create a client, then edit one and switch "Odesílat doklady e-mailem" off; open both details and the client list.
**Expected:** Toggle next to the invoice e-mail, on by default; the warning badge "E-maily s doklady vypnuté" in the detail heading only for the switched-off client; no list column.
**Why human:** Placement, badge look and wording are visual.

#### 2. Hand-served document journey in the browser

**Test:** For a switched-off client, issue an invoice, open its detail, the Odeslání tab and the list; click "Uzamknout" on the open callout; record a cash payment and lock its receipt from the Platby tab.
**Expected:** No send or resend anywhere, a gray note, no dialog after the issue, the confirmation modals work and the locked state shows afterwards.
**Why human:** Layout, colour and interactive modal flow.

#### 3. Real worker end to end

**Test:** With a queue worker and Mailpit, queue a document e-mail or payment confirmation, switch the client off inside the grace window, let the worker run, switch on again.
**Expected:** Nothing is delivered, the row ends `skipped`, the document is not locked, no failure bell, nothing is revived.
**Why human:** The suite fakes the transport and runs jobs synchronously.

### Gaps Summary

No gaps. All eleven requirements are implemented in the code, wired from the UI and the queue paths to a single fail-closed domain gate, and backed by passing behavioural tests (73 targeted plus 564 wider tests re-run during this verification). The status is `human_needed` only because the visual and real-worker items above cannot be proven by the automated checks. The unrelated full-suite failures (storage, CI parity, DDEV config, one flaky time-leak test) are not caused by Phase 16 and are not counted here.

---

_Verified: 2026-10-28_
_Verifier: Claude (gsd-verifier)_
