# Phase 16: Per-client e-mail sending toggle - Research

**Researched:** 2026-10-27
**Domain:** Laravel 13 / Filament 5 / PostgreSQL 18 domain change inside the existing Invoicing, Clients and Operations code (no new libraries)
**Confidence:** HIGH (every finding below comes from files read in this session; the few judgment calls are listed in the Assumptions Log)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): a document is editable until a successful e-mail send locks it, the Admin unlocks with a mandatory recorded reason (Phase 10.1, IN-11, IN-12); every lock and unlock is audited (IN-15); the payment confirmation row has a `skipped` state for a client without a usable e-mail (Phase 13); the PPD is locked by the successful send of the payment confirmation (Phase 15 D-10, D-12); the client has a nullable `invoice_email` and an invoice language.

**Scope of the ban**
- **D-01:** The toggle blocks only document e-mails: the document e-mail (`InvoiceMail` via `QueueInvoiceEmail` / `SendInvoiceEmail`) and the payment confirmation e-mail with the PPD attachment (`QueuePaymentConfirmation` / `SendPaymentConfirmation`). System e-mails to Partner users of the client stay unchanged: invitation, password reset and task notifications. Default is "sending enabled" for every existing and new client.

**Manual lock of a document**
- **D-02:** The Admin can lock an issued invoice, proforma or cash receipt (PPD) by hand, for every client, not only for clients with sending disabled. The action needs no reason. Locking is audited (who, when) like the lock by send (IN-15). Unlock stays as today: Admin only, mandatory reason (IN-12), the document stays editable until the next lock. — **Reversibility:** costly — the lock state machine, its database guards, the audit and the Admin UI all gain a second way into the locked state.
- **D-03:** A manual lock applies only to a document that is issued and currently unlocked; a credit note is already locked at issue and a locked document shows no lock action. The lock changes no number, kind or snapshot.

**UI behaviour**
- **D-04:** For a client with sending disabled, the "Send by e-mail" and "Send again" actions are hidden (header of the document detail, row of the document list, row of the Odeslání tab), and the document detail shows a gray note that the client has e-mail sending disabled. The row of the Odeslání tab is not offered for resend either.
- **D-05:** The toggle lives in the client form next to the invoice e-mail (label along the lines of "Send documents by e-mail", default on) and in the client detail; the client detail shows a badge when it is off. A change of the toggle is audited (who, when, old and new value). No column in the client list.

**Enforcement**
- **D-06:** The ban is enforced in the domain, not only in the UI. `QueueInvoiceEmail` refuses a document of a client with sending disabled (validation-style error, no log row). `SendInvoiceEmail` and `SendPaymentConfirmation` re-check the toggle right before the transport call and send nothing when it is off. `QueuePaymentConfirmation` creates the row directly in state `skipped` and dispatches no job for such a client; the payment itself is never blocked.

**Toggle changed while e-mails are queued**
- **D-07:** When the toggle is switched off, an e-mail that is still queued (document e-mail or payment confirmation inside its 5-minute grace window) is not sent: the job's re-check (D-06) marks its row `skipped` and leaves it in the history, so the suppression is visible. The mark never locks a document (IN-11: only a successful send locks). Switching the toggle back on does not revive skipped rows; the Admin sends again by hand.
- **D-08:** `skipped` rows do not count as failed or unsent: the health indicator "unsent invoice e-mails" (FND-10) and the failed-e-mail alert ignore them, and a document whose latest row is `skipped` is not shown as "send failed" in lists and detail.

### Claude's Discretion

Column and enum names, whether `invoice_emails` gets a `skipped` state next to `queued/sending/sent/failed` (its CHECK constraint and the state enum must then be extended, and the sent-at / failure-code constraints reviewed) or the suppressed document e-mail is simply not logged, the migration of the audit allowlist, the exact wording (cs/en), the icon and label of the manual lock action, where on the detail page the lock action sits, and how the lock by hand is recorded in the audit trail next to the lock by send.

### Deferred Ideas (OUT OF SCOPE)

- Blocking system e-mails to Partner users of a hand-served client (invitation, password reset, task notifications): kept out of scope by D-01; a later phase can add a second toggle if needed.
- A required reason or a "handed over on date" record for the manual lock: not wanted now (D-02).
</user_constraints>

<phase_requirements>
## Phase Requirements

ROADMAP lists "Requirements: TBD" for Phase 16. The IDs below are PROPOSED (modelled on how Phase 15 registered P15-01..P15-12 under a new heading in REQUIREMENTS.md, "### Cash Payments and Cash Receipts", with a matching traceability row each and a coverage-count bump). The planner registers them in REQUIREMENTS.md under a new heading "### Per-Client E-mail Sending and Manual Lock" and in the traceability table.

| ID | Description | Research Support |
|----|-------------|------------------|
| P16-01 | A client has a "send documents by e-mail" switch, on by default for every existing and new client; it is in the client form and detail (badge when off), not in the list | Client column + form/infolist sections below |
| P16-02 | A change of the switch is audited with who, when, old and new value | `#[LoggedAttributes]` allowlist on `Client` |
| P16-03 | `QueueInvoiceEmail` refuses a document of a client with sending off; no log row is written | Enforcement section |
| P16-04 | `QueuePaymentConfirmation` writes the row directly as `skipped` and dispatches no job for such a client; the payment is never blocked | Enforcement section, CHECK relaxation |
| P16-05 | `SendInvoiceEmail` and `SendPaymentConfirmation` re-check the switch after the claim, send nothing, mark the row `skipped`, lock nothing; switching back on does not revive rows | Job re-check pattern |
| P16-06 | `skipped` rows are neither failed nor unsent: the System page indicator, the failed-e-mail bell, list rows and detail ignore them | Health indicator section |
| P16-07 | For a client with sending off, "Odeslat e-mailem" / "Odeslat znovu" are hidden in the detail header, the list row and the Odeslání row, the detail shows a gray note, and the post-issue send dialog does not auto-open | UI section |
| P16-08 | The Admin locks an issued, unlocked invoice or proforma by hand, for any client, without a reason; audited; number, kind and snapshots unchanged | `LockInvoice` Action + audit event |
| P16-09 | The Admin locks an open, uncancelled cash receipt (PPD) by hand, same rules | `LockCashReceipt` Action |
| P16-10 | Unlock stays Admin-only with a mandatory reason; the lock literals stay confined (architecture tests extended on purpose); the database guards need no change | Arch test allowlists |
| P16-11 | System e-mails to Partner users (invitation, password reset, task notifications) are unaffected by the switch | D-01 scope test |
</phase_requirements>

## Summary

The phase is a contained change to existing, well-factored code. There are exactly two places that put a document e-mail on the wire (`Mail::to(...)->send(...)` in `SendInvoiceEmail::handle()` and in `SendPaymentConfirmation::handle()`) [VERIFIED: app/Domain/Invoicing/Jobs/SendInvoiceEmail.php, app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php, plus a repo-wide grep for `Mail::` in `app/`], and exactly two places that queue them (`QueueInvoiceEmail::handle()` and `QueuePaymentConfirmation::handle()`). All Partner-facing system mail goes through different classes (`InvitationMail`, `SendPartnerPasswordReset`, `TaskNotifier`), so D-01 is satisfied by touching only those four classes and never the notification classes.

The one structural surprise is the CHECK constraint `payment_confirmations_recipient_check`. It currently reads `CHECK ((recipient IS NULL) = (state = 'skipped') AND (recipient IS NULL OR btrim(recipient) <> ''))` [VERIFIED: database/migrations/2026_10_25_000100_create_payment_confirmations_table.php:62], which hard-couples "skipped" to "no recipient". D-07 marks a queued row (which has a recipient) `skipped`, and D-06 creates a skipped row for a client who may have an address, so this CHECK must be relaxed in a new migration (and the existing schema test at `tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php:189-197` updated). `invoice_emails` needs only its state CHECK extended (`invoice_emails_state_check`, line 236 of `2026_10_17_000300_create_invoice_admin_tables.php`); its sent-at, failure-code and recipient checks already tolerate a `skipped` row. D-07 explicitly wants the suppressed row kept in the history, so add `skipped` to `invoice_emails` rather than not logging.

The manual lock needs no database trigger change: the live row guard (re-created last in `2026_10_24_000200_add_payment_method_to_invoices.php`) lists `lock_state` among `open_columns` and has no branch that restricts the move `open -> locked`, and the cash receipt guard `kokpit_guard_cash_receipt_row()` likewise lists `lock_state` in `mutable_columns` and only guards the move out of `locked`. What does need changing are the two architecture tests that confine the literals `InvoiceLockState::Locked` and `CashReceiptLockState::Locked` to named files (a new writer "has to extend the allowlist below with a reason"), the audit writers, the activity labels, and the `everSent()` rule that decides whether post-lock edits are audited (see Pitfall 4).

**Primary recommendation:** Add `clients.document_emails_enabled boolean NOT NULL DEFAULT true`; put one domain check (`ClientDocumentEmails`) in front of the two queue Actions (refuse / create-skipped) and after the claim in the two jobs (mark `skipped`); extend the two `skipped` CHECKs and enums; add `LockInvoice` and `LockCashReceipt` Actions that mirror the Unlock Actions (row lock first, model save, explicit audit row with a distinct `*_locked_manually` event); gate the three send actions, the post-issue dialog opener and the health indicator on the same check.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Store the per-client switch | Database / Storage | API / Backend (Client model, `ClientInput`) | A column on `clients`, NOT NULL DEFAULT true, so every existing row is enabled without a data backfill |
| Enforce the ban | API / Backend (domain Actions and Jobs) | Database (CHECK of the `skipped` states) | D-06: domain enforcement; UI hiding is only a convenience |
| Hide send actions, gray note, badge | Frontend Server (Filament/Livewire, server-rendered) | — | Visibility closures re-read the client row per request |
| Manual lock transition | API / Backend (new Actions) | Database (existing row guards accept `open -> locked`) | Same placement as `UnlockInvoice` / `UnlockCashReceipt` |
| Audit of toggle change and manual lock | API / Backend (allowlist + explicit writer) | Database (`activity_log`) | Client uses the allowlisted model log; documents use explicit rows (`InvoiceAudit`, `CashReceiptAudit`) |
| Partner isolation of the new column | Database / Storage + API / Backend (`Client` is `DeniesPartners`) | — | `clients` is an Admin-only model; a Partner never reads the row, so the column cannot leak |

## Standard Stack

### Core

No new package. The phase uses what is installed [VERIFIED: composer.json:21,24,32,50 and `ddev exec` probes]:

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | ^13.17 | Actions, queue jobs, migrations | project stack |
| filament/filament | ^5.0 | Client form/infolist, `Callout`, `Action` visibility | project stack |
| spatie/laravel-activitylog | ^5.1 | audit rows through the existing `LogsAllowlistedActivity` wrapper and `activity()` | project stack |
| pestphp/pest | 5.3.3 (`ddev exec vendor/bin/pest --version`) | tests | project stack |
| PHP / PostgreSQL | 8.5.12 / 18.6 (probed in DDEV) | runtime | project stack |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| New `skipped` state on `invoice_emails` | Do not log the suppressed document e-mail | Rejected: D-07 requires the suppression to be visible in the history; a state is one CHECK edit |
| Distinct audit event for the manual lock | Reuse `invoice_locked` with a payload flag | Distinct event (`invoice_locked_manually`) keeps the label "uzamčen odesláním" truthful and lets `everSent()` find hand-locks by event |
| `skipped` row with `recipient = NULL` (keep CHECK) | Relax the CHECK | Nulling the recipient on a job-time skip erases the address the row was queued for; relaxing keeps the log honest |

**Installation:** none. **Package Legitimacy Audit:** no external packages are added by this phase, so the gate is not applicable (no `package-legitimacy check` run needed).

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| (none) | — | — | — | — | — | No package is installed by this phase |

**Packages removed due to [SLOP] verdict:** none. **Packages flagged as suspicious [SUS]:** none.

## Architecture Patterns

### System Architecture Diagram

```
 Admin form (Client)                        Admin actions (document detail / list / Odeslání / Platby tab)
   toggle document_emails_enabled              "Odeslat e-mailem"  "Odeslat znovu"        "Uzamknout" (new)
        | UpdateClient -> Client save                |  (hidden if client off)                |
        v  (LoggedAttributes -> activity row)        v                                        v
   clients.document_emails_enabled        QueueInvoiceEmail::handle()                 LockInvoice / LockCashReceipt (new)
        ^        ^                        [client off? -> ValidationException,        lockForUpdate invoice row (then receipt row)
        |        |                          no invoice_emails row]                    model save lock_state = locked
        |        |                                   | else queued row + dispatch     InvoiceAudit / CashReceiptAudit::lockedManually
        |        |                                   v                                (event *_locked_manually)
        |        |                           SendInvoiceEmail job (dispatched after commit)
        |        |                           claim(queued->sending) -> [client off? -> sending->skipped, STOP, no lock]
        |        |                                   | else Mail::to()->send -> markSentAndLock (unchanged)
        |        |
        |   RecordPayment -> QueuePaymentConfirmation::handle() (in payment transaction)
        |        |   [client off? -> row state=skipped (recipient may be set), no dispatch]
        |        |   else row queued + SendPaymentConfirmation delayed 300 s
        |        v
        +--- SendPaymentConfirmation job: claim(queued->sending) -> [client off? -> sending->skipped, STOP]
                                           else Mail::to()->send -> markSent (+ PPD lock, unchanged)

 Read side: UnsentInvoiceEmailsIndicator, InvoiceTable list column/filter, Odeslání tab, Platby tab
            all treat state `skipped` as "not failed, not waiting".
```

### Recommended Project Structure

```
app/Domain/Clients/
  Models/Client.php                       # + fillable, LoggedAttributes, cast, @property document_emails_enabled
  Actions/ClientInput.php, CreateClient.php   # + attribute handling (default true; omitted = unchanged on update)
app/Domain/Invoicing/
  Support/ClientDocumentEmails.php        # NEW single check: enabledFor(string $clientId): bool, fresh DB read, withTrashed
  Actions/LockInvoice.php                 # NEW mirror of UnlockInvoice
  Actions/LockCashReceipt.php             # NEW mirror of UnlockCashReceipt
  Support/InvoiceAudit.php, CashReceiptAudit.php   # + lockedManually() writers; everSent() extension
  Actions/QueueInvoiceEmail.php, QueuePaymentConfirmation.php   # + refuse / skipped
  Jobs/SendInvoiceEmail.php, SendPaymentConfirmation.php        # + re-check after claim
  Enums/InvoiceEmailState.php, PaymentConfirmationState.php (label) # + Skipped case on the first
app/Filament/...                          # ClientResource, SendInvoiceEmailAction, InvoiceTable, ViewInvoice,
                                          # InvoiceEmailsRelationManager, InvoicePaymentsRelationManager, RecordPaymentAction
app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php
database/migrations/2026_10_28_0001xx_*   # 3 migrations, see Pattern 1
lang/cs/{kokpit,invoices,cash_receipts,enums}.php
```

### Pattern 1: Migrations (three, ordered, no worktree execution)

Last migration on disk is `2026_10_27_000200_create_cash_receipts_table.php` [VERIFIED: `ls database/migrations | tail`]. Recommended new files (CLAUDE.md: written in a worktree, run only after merge into the HEAD branch):

1. `2026_10_28_000100_add_document_emails_enabled_to_clients_table.php`: `ALTER TABLE clients ADD COLUMN document_emails_enabled boolean NOT NULL DEFAULT true`. The default alone gives "enabled for every existing client" without an UPDATE. Follows the shape of `$table->boolean('online_payment_enabled')->default(false);` [VERIFIED: database/migrations/2026_10_09_000100_create_clients_table.php:47].
2. `2026_10_28_000200_add_skipped_state_to_invoice_emails.php`: drop and re-add `invoice_emails_state_check` as `CHECK (state IN ('queued', 'sending', 'sent', 'failed', 'skipped'))`. Current text: `CHECK (state IN ('queued', 'sending', 'sent', 'failed'))` [VERIFIED: database/migrations/2026_10_17_000300_create_invoice_admin_tables.php:236]. Reviewed and left unchanged: `invoice_emails_sent_at_check` `CHECK ((state = 'sent') = (sent_at IS NOT NULL))` (skipped has no `sent_at`), `invoice_emails_failure_code_check` `CHECK (failure_code IS NULL OR state = 'failed')` (skipped has no code), `invoice_emails_recipient_check` `CHECK (btrim(recipient) <> '')` (a skipped document e-mail keeps its recipient) [VERIFIED: same file, lines 238-241]. `down()` must first delete or convert any `skipped` rows or it cannot restore the narrower CHECK.
3. `2026_10_28_000300_relax_payment_confirmation_recipient_check.php`: replace `payment_confirmations_recipient_check` with a CHECK that allows `skipped` rows with or without a recipient but still forbids a null recipient on a non-skipped row and a blank recipient anywhere, e.g. `CHECK ((recipient IS NULL AND state = 'skipped') OR (recipient IS NOT NULL AND btrim(recipient) <> ''))`. The state CHECK already contains `'skipped'` [VERIFIED: 2026_10_25_000100:56].

The invoices and cash receipts guards are NOT migrated (Pattern 4). Per the project rule the migration docblocks must contain fictional data only.

### Pattern 2: One domain gate, read fresh, used by four classes and the UI

Create `ClientDocumentEmails::enabledFor(string $clientId): bool` that reads `Client::query()->withTrashed()->whereKey($clientId)->value('document_emails_enabled')` and returns it (fail-open to `true` only for a missing row cannot happen: `invoices.client_id` is a restrict FK; treat a null result as `true` to match the column default, but see Assumption A4). Reasons: `QueuePaymentConfirmation::clientOf()` already reads the client this way (`Client::query()->withTrashed()->whereKey($invoice->client_id)->first()`) because an archived client "keeps getting confirmations" [VERIFIED: QueuePaymentConfirmation.php, `clientOf` and class docblock]; the jobs run through `KokpitJob` middleware that enters the system context (a worker has no user and the Partner scopes are fail-closed) [VERIFIED: app/Domain/Operations/Jobs/KokpitJob.php class docblock "middleware (a worker has no user, and the Partner scopes are fail-closed)"], so the same `Client::query()` read is allowed there; a Partner request never reaches the helper because `Client` is `DeniesPartners`. Never read the flag from a stale in-memory model in a job or in `QueueInvoiceEmail`: `Client::factory()->create()` returns a model without DB-default columns loaded, so a fresh column read is also the test-safe form.

### Pattern 3: Enforcement placement (D-06, D-07)

- **`QueueInvoiceEmail::handle()`**: inside the existing transaction, after `$document = $this->document(...)` (row lock held) and the `isIssued()` check, before the row is built: `if (! ClientDocumentEmails::enabledFor($document->client_id)) { throw ValidationException::withMessages(['invoice' => __('invoices.errors.email_client_disabled')]); }`. The key `invoice` is the one already used for a non-issued document and is mapped to the recipient field by `SendInvoiceEmailAction::underModal()` [VERIFIED: SendInvoiceEmailAction.php `underModal`: "An error that belongs to no field of the modal ... lands on the recipient"]. No `invoice_emails` row is written because the throw precedes `forceFill()->save()`. `defaults()` needs no change (the dialog is hidden for a disabled client).
- **`QueuePaymentConfirmation::handle()`**: compute `$enabled = ClientDocumentEmails::enabledFor($locked->client_id)`; `'state' => ($recipient === null || ! $enabled) ? Skipped : Queued`; dispatch only when `$recipient !== null && $enabled`. Keep storing `$recipient` for a skipped-by-switch row (needs migration 3). `recipientFor()` must stay "the address the confirmation would go to" because `RecordPaymentAction::confirmationHint()` uses it; add a sibling check there so the hint reads "the client has e-mail sending disabled, the confirmation will not be sent" (new lang key) instead of promising a send.
- **`SendInvoiceEmail::handle()`**: after `claim()` returns true and the row is loaded, as the first statement inside the `try`, before `files()`: if disabled, run a conditional update `sending -> skipped` (`whereKey(...)->where('state', Sending)->update(['state' => Skipped, 'updated_at' => now()])`) and `return`. Do not throw (a throw would send the row back to `queued` and the queue would retry). Do not call `tellAdmins` and do not touch `lock_state` (IN-11). Check before `files()` so a disabled client with a missing PDF becomes `skipped`, not `attachment_missing` + a bell notice. The residual window between the check and the transport call is the PDF read; accepted (same class as the stated at-least-once guarantee).
- **`SendPaymentConfirmation::handle()`**: same position (after the claim, first thing in `try`, before the recipient and receipt work), same conditional update `sending -> skipped`, return. A receipt attached to a skipped confirmation stays open (consistent with AS-10 "a confirmation that is skipped, failed or never sent leaves the receipt open") and is closed by the manual lock.
- The `#[Idempotent(how: ...)]` attribute text of both jobs mentions the terminal states; update the strings to include "skipped by the switch" (tests/Arch/JobContractTest.php covers the contract; check that it only needs the attribute present).

### Pattern 4: Manual lock, mirrored from the unlock

New `LockInvoice::handle(User $actor, Invoice $invoice): Invoice`, shaped like `UnlockInvoice`:

1. `Gate::forUser($actor)->authorize('update', $invoice)` (Partner refused before anything else) [VERIFIED: UnlockInvoice.php].
2. `DB::transaction`: `Invoice::query()->whereKey(...)->lockForUpdate()->firstOrFail()` first (the lock order of every writer: invoice, then children) [VERIFIED: UnlockInvoice.php, SendInvoiceEmail::markSentAndLock].
3. Refuse on the locked row: draft (`not_issued`), credit note (`credit_note_locked`), already locked (`document_locked`); recommended also cancelled (`cancelled_not_editable`) and converted proforma (`converted_not_editable`), reusing the existing `invoices.errors.*` keys that `IssuedDocumentRules::editBlocker()` uses (`not_issued`, `credit_note_locked`, `document_locked`, `cancelled_not_editable`, `converted_not_editable`) [VERIFIED: IssuedDocumentRules.php lines ~60-66]. Reason for refusing cancelled/converted: `UnlockInvoice` refuses exactly those, so allowing a hand-lock would create a document that can never be reopened (Assumption A2). A pending e-mail (`email_in_flight`) does NOT block the lock: locking is always safe, and `markSentAndLock` already skips the lock for a document that is "locked already" [VERIFIED: SendInvoiceEmail docblock].
4. `$locked->forceFill(['lock_state' => InvoiceLockState::Locked])->save();` through the model, never a bulk update (the guard sees one row change that touches `lock_state` and `updated_at` only). This is a NEW file naming the token `InvoiceLockState::Locked`, so `tests/Arch/InvoiceUnlockPathTest.php` test "writes the locked state only in the send job, the issue of a credit note and the model that casts it" (expects exactly `app/Domain/Invoicing/Issuing/DocumentIssuer.php`, `.../Jobs/SendInvoiceEmail.php`, `.../Models/Invoice.php`) must be extended by `app/Domain/Invoicing/Actions/LockInvoice.php`, and the file header comment ("There is no manual 'mark as sent' anywhere (D-02)") rewritten to say a manual lock is the owner-approved second writer (Phase 16 D-02). Do not put the literal in any other file (the Filament action must call the Action, not name the case). The unlock setting literal `kokpit.invoice_unlock` is NOT needed and must not appear.
5. `InvoiceAudit::lockedManually($locked, $actor)` in the same transaction.

`LockCashReceipt` is the twin: `Invoice` row lock first, then the receipt row (`UpdateCashReceipt` order: "the invoice row is locked, then the receipt row" [VERIFIED: UpdateCashReceipt.php docblock item 2]); refuse cancelled (`cash_receipts.errors.cancelled`) and already locked; `forceFill(['lock_state' => CashReceiptLockState::Locked])->save()`; `CashReceiptAudit::lockedManually()`. `tests/Arch/CashReceiptUnlockPathTest.php` test "writes the locked state of a cash receipt only in the confirmation send job and the model that casts it" (expects `SendPaymentConfirmation.php` and `CashReceipt.php`) gets `LockCashReceipt.php` added. A manual PPD lock is independent of the confirmation row state (no `sending` refusal needed: `SendPaymentConfirmation::markSent` only locks "when ... the receipt is neither cancelled nor locked yet").

**Why no trigger change:** the live invoice guard's only lock branch starts `IF OLD.lock_state = 'locked' THEN`; for `OLD.lock_state = 'open'` the function reaches `RETURN NEW` provided the non-open columns are untouched, and `lock_state` and `updated_at` are in `open_columns` [VERIFIED: database/migrations/2026_10_24_000200_add_payment_method_to_invoices.php:55-58, 90-100; the 10.1 migration comment states "Locking is always safe, so an open row moves to locked freely"]. The cash receipt guard has the same shape: `mutable_columns` contains `'lock_state'` and the only lock branch is `IF OLD.lock_state = 'locked' THEN` [VERIFIED: database/migrations/2026_10_27_000200_create_cash_receipts_table.php:123-130, 191-205]. The children guards key on `invoices.lock_state = 'open'`, so locking freezes items, sources and the admin row automatically (A8: the same effect as a lock by send).

### Pattern 5: Audit

- **Client toggle (P16-02):** add `'document_emails_enabled'` to both `#[Fillable([...])]` and `#[LoggedAttributes([...])]` on `Client` [VERIFIED: Client.php attribute lists, `'online_payment_enabled'` is the last entry of both]. `LogsAllowlistedActivity` logs only listed, dirty attributes, so a change writes one `updated` row with `old`/`new` booleans, causer and time, with no new writer [VERIFIED: app/Domain/Audit/LogsAllowlistedActivity.php]. The column must exist for `ActivityAllowlistColumnsTest` (rule d) and the name passes `AuditDeclaration` (`SENSITIVE_NAME` regex has no match for `document_emails_enabled`) [VERIFIED: tests/Support/AuditDeclaration.php]. Add the label `'document_emails_enabled' => 'Odesílání dokladů e-mailem'` under `kokpit.activity.attributes.client` in `lang/cs/kokpit.php` (next to `'online_payment_enabled' => 'Online platba'`, lines ~1060-1070 region) so the history reads "Odesílání dokladů e-mailem: ano -> ne" (the presenter prints booleans as `ano`/`ne`) [VERIFIED: ActivityPresenter::value()]. `tests/Arch/ActivityAllowlistTest.php` lists logging models explicitly and Client is already in it; no change to that list.
- **Manual lock (P16-08/09):** explicit rows, same shape as the existing lock rows. Recommended events `invoice_locked_manually` and `cash_receipt_locked_manually` with `['attributes' => ['lock_state' => 'locked'], 'old' => ['lock_state' => 'open']]`, causer = the Admin. Add labels `'invoice_locked_manually' => 'Doklad uzamčen ručně'` and `'cash_receipt_locked_manually' => 'Příjmový doklad uzamčen ručně'` beside the existing `invoice_locked` / `cash_receipt_locked` entries in `kokpit.activity.events` [VERIFIED: lang/cs/kokpit.php:419-425; existing text 'Doklad uzamčen odesláním']. The event filter of the activity overview lists only created/updated/deleted/description_changed [VERIFIED: ActivityResource.php filters()], so no filter edit is needed. The exact label wording for the existing events is asserted at `tests/Feature/Invoicing/InvoiceEditAuditTest.php:522-523`; extend that test with the two new labels.

### Pattern 6: Client form and detail (D-05)

- **Form:** add `Toggle::make('document_emails_enabled')` directly after the `invoice_email` TextInput in `ClientResource::form()` (the section containing `invoice_email` then `Toggle::make('online_payment_enabled')` [VERIFIED: ClientResource.php:308-316]), `->default(true)`, label "Odesílat doklady e-mailem" with a hint that it only concerns invoices, proformas, credit notes and payment confirmations, not Partner system e-mails. `ClientResource::actionData()` currently ends with `'online_payment_enabled' => (bool) ($data['online_payment_enabled'] ?? false)` [VERIFIED: ClientResource.php:560]; for the new key use `?? true` (a missing key must mean "enabled").
- **ClientInput (pitfall 1):** `ClientInput::attributes()` has `'online_payment_enabled' => (bool) ($data['online_payment_enabled'] ?? false)` [VERIFIED: ClientInput.php:151]. Do NOT copy `?? false`. Recommended: add the attribute only when the key exists in `$data` (`array_key_exists`), so `UpdateClient` with a payload that omits the key leaves the stored value alone; `CreateClient::withDefaults()` sets `document_emails_enabled = true` when absent (mirroring how it defaults `online_payment_enabled` from settings [VERIFIED: CreateClient.php:115-117]); add `document_emails_enabled?: bool|null` to the `ClientData` phpstan type [VERIFIED: CreateClient.php @phpstan-type block].
- **Detail:** add `IconEntry::make('document_emails_enabled')->boolean()` beside the `online_payment_enabled` IconEntry [VERIFIED: ClientResource.php:361-363] and a visible-when-off badge (a `TextEntry` with `->badge()->color('warning')->visible(fn (Client $record) => ! $record->document_emails_enabled)`, or a heading badge on `ViewClient`; `ViewClient` shows no `getHeading` override in the first 120 lines I read [VERIFIED: ViewClient.php:1-120]; the planner should confirm the rest of the file). The list table gets no column (D-05).
- **Factory/tests:** `ClientFactory::definition()` lists `'online_payment_enabled' => false` and nothing for the new column [VERIFIED: database/factories/ClientFactory.php:29-39]; add `'document_emails_enabled' => true` and a `withoutDocumentEmails()` state so in-memory models carry the value.

### Pattern 7: Document UI (D-04)

All three send entry points funnel through `app/Filament/Support/SendInvoiceEmailAction.php` (`make`, `forListRow`, `forLogRow`). Put the visibility rule in one static method there (or in the three call sites), e.g. `private static function clientAllows(Invoice $invoice): bool { return ClientDocumentEmails::enabledFor($invoice->client_id); }`:
- `make()` currently `->visible(static fn (): bool => self::fresh($invoice())->isIssued())`: add `&& self::clientAllows(...)`.
- `InvoiceTable::resendEmailAction()` `->visible(static fn (Invoice $record): bool => in_array(self::emailKey($record), ['failed', 'none'], true))`: add the client check; the list eager-loads `client` [VERIFIED: InvoiceResource.php `withListRelations`: `'client'`], so use `$record->client?->document_emails_enabled` there to avoid a query per row (a Partner sees `client` null and gets no send action anyway; the Admin path is the one that matters).
- `InvoiceEmailsRelationManager` row action `->visible(fn (InvoiceEmail $record): bool => $this->effectiveState($record) === InvoiceEmailState::Failed)`: AND the client check.
- `ViewInvoice::openSendDialog()` mounts `sendEmail` after an issue (`?send=1`, set by `ViewInvoice` lines ~350, ~567 and `EditInvoice` ~501) [VERIFIED: grep]: it must return without mounting when the client has sending off, otherwise the issue flow of a hand-served client tries to open a hidden action. Also skip setting `sendDialogPending` in that case.
- **Gray note:** `ViewInvoice::banners()` is a list of `Callout::make(...)` with `->key()`, `->info()`, `->warning()`, `->visible()`; `Callout` has `color(string|array|Closure|null)` so `->color('gray')` works [VERIFIED: vendor/filament/schemas/src/Components/Callout.php:118]. Add `Callout::make(__('invoices.detail.banners.email_disabled'))->key('email_disabled')->color('gray')->visible(issued && client off)`.
- **Where the lock button sits:** the info callout `invoices.detail.banners.open_for_edits` (`->visible(isEditableDocument() && ! isLocked())`) already says the document is open; its sibling "locked" callout carries `->actions([$this->unlockAction()])` [VERIFIED: ViewInvoice.php banners()]. Mirror that: give `open_for_edits` an `->actions([$this->lockAction()])` and visibility of the action `isEditableDocument() && ! isLocked()` (this matches the domain refusals, so the button never offers a refused lock). Use a confirmation (`requiresConfirmation()`), not a reason field (D-02). The PPD lock goes into the `ActionGroup` of the Platby tab next to `unlockReceiptAction()`: visible when `cashReceipt !== null && ! isCancelled() && ! isLocked()`; wrap the call with the existing `runReceiptAction()` frame [VERIFIED: InvoicePaymentsRelationManager.php `unlockReceiptAction`, `runReceiptAction`].
- **Enum match sites that break when `Skipped` is added to `InvoiceEmailState`** (exhaustive `match`, so PHPStan/runtime `UnhandledMatchError`): `InvoiceTable::emailKey()` (lines ~429-434: `Sent`, `Queued, Sending`, `Failed`, `null`) and the color `match` in `InvoiceEmailsRelationManager::table()` (`Sent`, `Failed`, `Queued, Sending`). Handle `Skipped` as: list `emailKey` -> `'none'` (reads "Neodesláno", not failed), Odeslání badge color `'gray'`. The list `email` filter's `'none'` branch is `latest IS NULL` [VERIFIED: InvoiceTable.php:~574]; change it to `IS NULL OR = 'skipped'` so the filter and the icon agree. Add `'skipped'` to `lang/cs/enums.php` `invoice_email_state` and update the pin at `tests/Unit/Invoicing/InvoiceEnumsTest.php:124-128`. `PaymentConfirmationState::Skipped` already exists with label 'Bez e-mailu' and the Platby tab colors it gray by `default` [VERIFIED: InvoicePaymentsRelationManager.php `match ($record->confirmation?->state)` has `Sent`, `Failed`, `default => 'gray'`], so that tab needs no change.

### Pattern 8: Health indicator and failed-e-mail alert (D-08)

`UnsentInvoiceEmailsIndicator` counts per the latest row of each document: `COUNT(*) FILTER (WHERE state = ? OR (state = ? AND updated_at <= ?)) AS failed` (Failed, or Sending past the stale threshold) and `COUNT(*) FILTER (WHERE state IN (?, ?) AND updated_at <= ? ...) AS waiting` (Queued/Sending past the warning threshold) [VERIFIED: UnsentInvoiceEmailsIndicator.php check()]. A latest row in state `skipped` matches neither filter, so D-08 holds for the indicator with NO code change; add a test that pins it. The failed-e-mail bell is `tellAdmins()` called only from `claim()` (stale give-up) and `giveUp()` (failed) in `SendInvoiceEmail` [VERIFIED: SendInvoiceEmail.php]; the skip path calls neither. There is no "failed alert" for payment confirmations except the generic failed-job report in `KokpitJob`; the skip path returns normally, so no job failure is recorded.

**Gap not covered by D-08 (decide, Assumption A3):** a document whose latest row is `failed` for a client that is switched off LATER keeps the indicator at Error, and the resend that clears it is now hidden (D-04) — an unclearable red slot. Recommended: join `invoices`/`clients` in the indicator's query and ignore documents whose client has `document_emails_enabled = false` (both the `failed` and `waiting` counts). The same document in the list still shows its true "Odeslání selhalo" history; only the alarm is suppressed.

### Anti-Patterns to Avoid

- **Hiding the button only:** D-06 requires the Actions and jobs to refuse; a crafted Livewire call or a queued job must not send.
- **Throwing from the job to skip:** a throw sends the row back to `queued` (catch block) and retries; skipping must be a conditional update plus `return`.
- **Reading the flag from a cached `$invoice->client` in a job or a queue Action:** the toggle can change in the grace window; use the fresh read.
- **Copying `(bool) (... ?? false)` for the new attribute:** an omitted key would silently disable sending (Pitfall 1).
- **Naming `InvoiceLockState::Locked` in Filament code:** keeps the arch confinement honest; the page calls the Action.
- **A "mark as sent" shortcut:** the manual lock must not create an `invoice_emails` row or set `sent_at`; "sent" must keep meaning a delivered e-mail.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Audit of the client switch | A custom activity writer | `#[LoggedAttributes]` allowlist entry on `Client` | The wrapper already logs dirty allowlisted attributes with causer and time; a hand writer would bypass `ActivityAllowlistTest` |
| Row-level safety of the lock | A new DB trigger or flag setting | The existing guards (`open -> locked` is free) + `lockForUpdate()` invoice row first | The 10.1 design already makes locking "always safe"; a new setting literal would need its own arch confinement |
| Recipient validity | A second e-mail validator | `QueuePaymentConfirmation::cleanAddress()` / `QueueInvoiceEmail::cleanRecipient()` | Already shared by queue and job |
| Skipped-state display | New components | `PaymentConfirmationState::Skipped` + badge `default => 'gray'`; extend `InvoiceEmailState` | Same vocabulary in both logs |
| Idempotent skip | A new table or flag | Conditional `UPDATE ... WHERE state = 'sending'` | The claim pattern of both jobs; a second run of a finished row does nothing |

**Key insight:** every behavior this phase needs has an existing sibling (unlock Action, `skipped` confirmation state, allowlisted client audit, claim-and-conditional-update jobs). The risk is in the seams (CHECK constraints, exhaustive matches, arch-test allowlists), not in new mechanisms.

## Runtime State Inventory

Not a rename/refactor phase; however the migration touches live data, so the five categories are answered explicitly:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | `clients` rows exist in production; `invoice_emails` / `payment_confirmations` rows may exist. None carries the new concept | Column default `true` covers existing clients; no data migration. Existing `payment_confirmations` rows satisfy the relaxed CHECK (relaxing is monotonic) |
| Live service config | None — verified: no external service holds a per-client e-mail setting (Stripe, mail transport are global) | None |
| OS-registered state | None — queue workers load code at start | After deploy, restart the queue worker so the changed job classes (re-check) are the ones that process already-queued rows |
| Secrets/env vars | None — the switch is a DB column, not config | None |
| Build artifacts | None | None |

Queued jobs in flight at deploy time: a row `queued` before the deploy is picked up by the NEW job code (the payload only carries the row id), so it is re-checked; no payload-compat issue.

## Common Pitfalls

### Pitfall 1: A missing key disables sending
**What goes wrong:** `ClientInput::attributes()` and `ClientResource::actionData()` default a missing boolean to `false` (`online_payment_enabled`). Copying the idiom makes any `UpdateClient` call that omits the key (older tests, scripts) set `document_emails_enabled = false`.
**Why it happens:** the only template boolean has default-off semantics.
**How to avoid:** add the attribute to `ClientInput` only when `array_key_exists`, and default `true` in `CreateClient::withDefaults()` and `actionData()` (`?? true`).
**Warning signs:** a test that updates a client without the key and then finds `document_emails_enabled === false`.

### Pitfall 2: The payment-confirmation CHECK forbids a skipped row with a recipient
**What goes wrong:** D-06 (row created `skipped` for a client with an address) and D-07 (queued row moved to `skipped`) both violate `payment_confirmations_recipient_check` (`SQLSTATE 23514`) [VERIFIED: migration line 62]. The existing test `PaymentConfirmationSchemaTest.php:194` asserts a skipped row WITH a recipient is refused, so it must be changed to the new contract.
**How to avoid:** migration 3 of Pattern 1; rewrite that test case and add one for "queued row with null recipient is still refused" and "blank recipient is refused".

### Pitfall 3: Exhaustive `match` on the extended enum
**What goes wrong:** adding `InvoiceEmailState::Skipped` makes `InvoiceTable::emailKey()` and the Odeslání badge color `match` non-exhaustive (PHPStan error, `UnhandledMatchError` at runtime when a skipped row is rendered).
**How to avoid:** handle the case in both (Pattern 7) and run `composer stan` after the merge. Also `IssuedDocumentRules::emailInFlight()` filters `whereIn('state', [Queued, Sending])` and `InvoiceAudit::everSent()` filters `Sent`; a skipped row correctly counts as neither, no change.

### Pitfall 4: Edits after a manual lock + unlock are not audited as "after first send"
**What goes wrong:** IN-15 audits every change "after its first send"; `InvoiceAudit::everSent()` is `InvoiceEmail ... where state = Sent ... exists()` [VERIFIED: InvoiceAudit.php `everSent`], and `edited()` writes nothing when it is false. A hand-locked document of a hand-served client was never "sent", so after the Admin unlocks it (with a reason) and corrects it, the edit would NOT be audited as an after-handover change even though the customer holds the document.
**How to avoid / decide:** extend `everSent()` (callers: `UpdateIssuedDocument`, `AddFixedAmount`, `AddEntriesToDraft` [VERIFIED: grep]) so it also returns true when an `invoice_locked_manually` activity row exists for the invoice (`Activity::query()->where('subject_type','invoice')->where('subject_id',...)->where('event','invoice_locked_manually')->exists()`), and update its docblock. This is the main reason for a distinct event name. The test `InvoiceEditAuditTest.php:303-304` (`everSent($queued)` false, `everSent($sent)` true) gets a third case. Flagged as Assumption A1 because CONTEXT does not state it.

### Pitfall 5: Post-issue send dialog for a hand-served client
**What goes wrong:** the issue flow redirects with `?send=1` and `ViewInvoice::openSendDialog()` mounts the `sendEmail` action; for a disabled client the action is hidden and the mount misbehaves (or silently no-ops). Guard in `openSendDialog()` and where `sendDialogPending` is set (ViewInvoice ~153) (Pattern 7).

### Pitfall 6: Concurrency between lock by send and lock by hand
**What goes wrong:** both write `lock_state` and an audit row. If they ran without the invoice row lock, a document could get two lock audit rows.
**How to avoid:** `LockInvoice` takes `lockForUpdate()` on the invoice first, exactly like `markSentAndLock`; whichever runs second sees `isLocked()` and writes nothing (send path) or throws `document_locked` (manual path). Optionally add one case to `tests/Concurrency/InvoiceEditSendConcurrencyTest.php`.

### Pitfall 7: Stale queued row of a disabled client is reported as failed
**What goes wrong:** `SendInvoiceEmail::claim()` gives up a queued row older than the stale threshold as `failed` with a bell notice before the new re-check runs. Only reachable when the worker was down for more than `kokpit.health.unsent_invoice_email_stale_after` (3600 s) AND the switch was turned off meanwhile. Accept: it is the existing worker-down alarm and it is honest; document it in the job docblock.

### Pitfall 8: The PPD of a hand-served cash payment is never locked by itself
**What goes wrong:** with sending off, `QueuePaymentConfirmation` skips the row, the receipt is never attached or sent, so it stays open forever (D-10: only the confirmation send locks it). This is exactly why D-02 adds the manual lock; the Platby tab action is the only way to close it. Cover with a journey test (cash payment for a disabled client -> skipped row -> receipt open -> manual lock -> locked, audited).

## Code Examples

Patterns assembled from the verified files (adapt names; not copied verbatim from a library).

### Job re-check after the claim (SendInvoiceEmail)
```php
// Source: structure of app/Domain/Invoicing/Jobs/SendInvoiceEmail.php::handle() (claim, then try/catch)
$row = InvoiceEmail::query()->findOrFail($this->emailId);

try {
    $clientId = Invoice::query()->whereKey($row->invoice_id)->value('client_id');

    if (! is_string($clientId) || ! ClientDocumentEmails::enabledFor($clientId)) {
        InvoiceEmail::query()
            ->whereKey($row->id)
            ->where('state', InvoiceEmailState::Sending->value)
            ->update(['state' => InvoiceEmailState::Skipped->value, 'updated_at' => now()]);

        return; // nothing sent, nothing locked (IN-11), no bell, no retry
    }

    $files = $this->files($row);
    // ... unchanged
```

### Manual lock Action skeleton
```php
// Source: structure of app/Domain/Invoicing/Actions/UnlockInvoice.php (authorize, transaction, lock row, refuse, save, audit)
public function handle(User $actor, Invoice $invoice): Invoice
{
    Gate::forUser($actor)->authorize('update', $invoice);

    return DB::transaction(function () use ($actor, $invoice): Invoice {
        $locked = Invoice::query()->whereKey($invoice->getKey())->lockForUpdate()->firstOrFail();

        $this->refuse($locked); // not_issued | credit_note_locked | document_locked | cancelled_not_editable | converted_not_editable

        $locked->forceFill(['lock_state' => InvoiceLockState::Locked])->save();

        InvoiceAudit::lockedManually($locked, $actor);

        return $locked->refresh();
    });
}
```

### Audit writer (twin of `locked()`)
```php
// Source: structure of InvoiceAudit::locked() in app/Domain/Invoicing/Support/InvoiceAudit.php
public static function lockedManually(Invoice $invoice, User $actor): void
{
    activity(self::LOG)
        ->performedOn($invoice)
        ->causedBy($actor)
        ->event('invoice_locked_manually')
        ->withChanges(['attributes' => ['lock_state' => 'locked'], 'old' => ['lock_state' => 'open']])
        ->log('invoice_locked_manually');
}
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Frozen at issue (state = issued) | Locked by successful e-mail send (`lock_state`) | Phase 10.1 | The lock now has a writer concept; Phase 16 adds the second writer, the Admin by hand |
| Confirmation `skipped` = "no address" only | `skipped` = "not sent, by design" (no address or sending off) | Phase 16 | The recipient/skipped coupling in the CHECK is dropped |

**Deprecated/outdated:** the comment "There is no manual 'mark as sent' anywhere (D-02)" in `tests/Arch/InvoiceUnlockPathTest.php` and its twin becomes false; rewrite it, do not just edit the array.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Edits after a manual lock + unlock should be audited like edits after a first send, so `everSent()` also counts an `invoice_locked_manually` row | Pitfall 4 | If the owner wants hand-locked documents treated as never handed over, skip the extension; otherwise IN-15 has a hole for hand-served clients |
| A2 | The manual lock should refuse a cancelled invoice and a converted proforma (mirror of the unlock refusals); D-03 literally says only "issued and currently unlocked" | Pattern 4 | If allowed, a locked cancelled document can never be reopened (`UnlockInvoice` refuses it); if refused wrongly, an Admin cannot lock a cancelled document, which has no editor anyway |
| A3 | The System page indicator should ignore documents whose client has the switch off (so a failed row from before the switch change cannot leave the slot red with no resend) | Pattern 8 | If not done, an unclearable Error slot for hand-served clients; if done, a failed send to a client switched off later is not alarmed (intended) |
| A4 | `ClientDocumentEmails::enabledFor()` treats a missing client row as enabled (cannot occur: restrict FK) | Pattern 2 | None in practice; fail-closed would also be acceptable and arguably safer |
| A5 | The post-issue send dialog (`?send=1`) must not open for a disabled client | Pattern 7 | Without it the issue flow of a hand-served client hits a hidden action |
| A6 | Czech wording proposals ("Odesílat doklady e-mailem", "Uzamknout", "Doklad uzamčen ručně", state label "Přeskočeno") are placeholders for the planner | Patterns 5-7 | Cosmetic; CONTEXT leaves wording to discretion |
| A7 | The lock button lives in the `open_for_edits` callout of the detail (and the Platby tab for the PPD), not in the list row | Pattern 7 | Cosmetic; D-02 does not require a list-row lock |

## Open Questions

1. **Should hand-locked documents count as handed over for the edit audit (A1)?**
   - What we know: IN-15 ties the edit audit to "after its first send"; `everSent()` reads sent e-mail rows only.
   - What's unclear: whether the owner considers a manual lock the equivalent of a send.
   - Recommendation: yes, extend `everSent()` (cheap, one EXISTS), and confirm in plan review.
2. **Indicator behavior for a failed latest row of a now-disabled client (A3).** Recommendation: ignore disabled clients in the indicator; confirm.
3. **Does the PPD manual lock need a list affordance?** D-02 names invoice, proforma and PPD only; the Platby tab is the PPD's only UI. Recommendation: Platby tab only.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV | all tests/migrations (host has no DB) | yes | v1.25.5 (`ddev describe`) | — |
| PHP in DDEV | tests, stan | yes | 8.5.12 | — |
| PostgreSQL in DDEV | schema tests, triggers | yes | 18.6 | — |
| Pest | tests | yes | 5.3.3 | — |

**Missing dependencies:** none. Per CLAUDE.md: in a worktree write code and tests and run only cheap DB-free checks; run migrations and the full suite after the merge into the HEAD branch, in DDEV.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.3 on PHPUnit config `phpunit.xml`, `tests/Pest.php` (run inside DDEV) |
| Config file | `phpunit.xml`, `tests/Pest.php`, CI shard timings in `tests/.pest/shards.json` |
| Quick run command | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Invoicing/<File>.php` |
| Full suite command | `ddev exec composer test` (= `vendor/bin/pest --exclude-testsuite=Audit`), plus `ddev exec composer partner-audit`, `ddev exec composer lint`, `ddev exec composer stan`; aggregate `ddev exec composer ci` [VERIFIED: composer.json scripts] |

The command shape comes from the user's memory note (`ddev exec php -d memory_limit=1G vendor/bin/pest ...`) and the Phase 15 validation file (`ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Schema tests/Arch tests/Isolation`). `ddev exec vendor/bin/pest --version` was run this session and returned Pest 5.3.3. (Tests were not executed in this research session because migrations and DB runs belong to the HEAD branch per CLAUDE.md.)

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| P16-01 | Column default true for existing rows; form default on; omitted key on update leaves value; create defaults true; detail badge only when off | schema + feature | `ddev exec vendor/bin/pest tests/Feature/Schema/ClientTablesTest.php tests/Feature/Clients/ClientResourceTest.php tests/Feature/Clients/ClientDefaultsTest.php` | extend |
| P16-02 | Toggle change writes one `updated` activity row with old/new boolean and causer | feature | `ddev exec vendor/bin/pest tests/Feature/Clients/ClientResourceTest.php tests/Arch/ActivityAllowlistTest.php tests/Feature/Operations/ActivityAllowlistColumnsTest.php` | extend |
| P16-03 | `QueueInvoiceEmail` throws `ValidationException` (key `invoice`) and writes no `invoice_emails` row for a disabled client; enabled client unchanged | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/EmailDialogTest.php tests/Feature/Invoicing/SendInvoiceEmailTest.php` | extend |
| P16-04 | `QueuePaymentConfirmation`: skipped row, recipient kept, no job dispatched (`Queue::fake`), payment recorded; CHECK accepts skipped-with-recipient, refuses queued-with-null and blank | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/PaymentConfirmationTriggerTest.php tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php` | extend (rewrite the case at :189-197) |
| P16-05 | Both jobs, row `queued`, switch turned off before the run: row ends `skipped`, no mail on the array transport (`invMailSent()` empty), document NOT locked, no audit row, no bell notification; turning the switch on again does not revive; a rerun of the job is a no-op | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/SendInvoiceEmailTest.php tests/Feature/Invoicing/CashReceiptDeliveryTest.php` | extend |
| P16-06 | Indicator Ok with a latest `skipped` row; list icon/filter "none" for a latest skipped row; Odeslání tab shows the skipped badge and no resend; bell not sent | feature | `ddev exec vendor/bin/pest tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php tests/Feature/Invoicing/InvoiceListTest.php tests/Unit/Invoicing/InvoiceEnumsTest.php` | extend |
| P16-07 | `sendEmail`/`resendEmail` hidden in header, list row, Odeslání row for a disabled client and visible for an enabled one (Livewire `assertActionHidden`/`assertActionVisible`); gray callout shown; `?send=1` does not mount | feature (Livewire) | `ddev exec vendor/bin/pest tests/Feature/Invoicing/InvoiceDetailTest.php tests/Feature/Invoicing/EmailDialogTest.php tests/Feature/Invoicing/InvoiceListTest.php` | extend |
| P16-08 | `LockInvoice`: locks an open issued invoice and proforma of any client, no reason, one `invoice_locked_manually` row with causer, number/kind/snapshots unchanged; refuses draft, credit note, locked (and cancelled/converted per A2); child guards then refuse item writes; Partner refused; unlock afterwards still needs a reason; edit after unlock is audited (A1) | feature + schema | `ddev exec vendor/bin/pest tests/Feature/Invoicing/InvoiceLockTest.php tests/Feature/Invoicing/UnlockInvoiceTest.php tests/Feature/Schema/InvoiceLockGuardTest.php tests/Feature/Invoicing/InvoiceEditAuditTest.php` | new `LockInvoiceTest.php` (W0) + extend |
| P16-09 | `LockCashReceipt`: locks open receipt, refuses cancelled/locked, audit row `cash_receipt_locked_manually`, UI action visible only when open and uncancelled; a skipped confirmation leaves the receipt open until the manual lock | feature | `ddev exec vendor/bin/pest tests/Feature/Invoicing/CashReceiptLockTest.php tests/Feature/Invoicing/CashReceiptAdminTest.php` | extend |
| P16-10 | Arch allowlists name the new writer files on purpose; the unlock literal still appears only in the two Unlock Actions; lock-vs-send race yields one lock audit row | arch + concurrency | `ddev exec vendor/bin/pest tests/Arch/InvoiceUnlockPathTest.php tests/Arch/CashReceiptUnlockPathTest.php tests/Concurrency/InvoiceEditSendConcurrencyTest.php` | update |
| P16-11 | A client with sending off still receives invitation, password-reset and task notifications (`Notification::fake`/array mail) | feature | `ddev exec vendor/bin/pest tests/Feature/Clients tests/Feature/Tasks` (use the existing invitation/notification test files) | extend |
| Isolation | New column and new events never reach a Partner: Partner gets no lock action, no activity row, no client data; API client lookup still returns id and name only | isolation | `ddev exec vendor/bin/pest tests/Isolation` and `ddev exec composer partner-audit` | extend (InvoiceLeakTest event list; no `PartnerSafeColumnsTest` change: it pins only `projects` and `tasks`) |

### Sampling Rate
- **Per task commit:** the single new or extended test file of the task via `ddev exec` (worktree: only DB-free checks such as `ddev exec vendor/bin/pint --test` on touched files; DB tests after the merge, per CLAUDE.md)
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Schema tests/Feature/Clients tests/Feature/Operations tests/Arch tests/Isolation`
- **Phase gate:** `ddev exec composer ci` green (test, partner-audit, lint, stan, check-licenses) before `/gsd-verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Invoicing/LockInvoiceTest.php` — covers P16-08 (pattern of `UnlockInvoiceTest.php`, helper `unlockRows`-style activity query)
- [ ] Extend `tests/Arch/InvoiceUnlockPathTest.php` and `tests/Arch/CashReceiptUnlockPathTest.php` allowlists (expected arrays gain the new Action files; rewrite the header comments)
- [ ] Rewrite the skipped/recipient case at `tests/Feature/Invoicing/PaymentConfirmationSchemaTest.php:189-197`
- [ ] Update `tests/Unit/Invoicing/InvoiceEnumsTest.php:124-128` (new case, new label, cases list)
- [ ] `database/factories/ClientFactory.php`: `'document_emails_enabled' => true` + `withoutDocumentEmails()` state (no new shared fixture file otherwise)
- [ ] `tests/Feature/Invoicing/InvoiceEditAuditTest.php`: two new event labels (line 522 block) and the `everSent` third case (line ~303)
- [ ] Framework install: none

## Security Domain

Config: `security_enforcement: true`, `security_asvs_level: 1` [VERIFIED: .planning/config.json].

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | unchanged |
| V3 Session Management | no | unchanged |
| V4 Access Control | yes | `Gate::forUser($actor)->authorize('update', $record)` in both lock Actions (Admin only; Partner refused before any work); Filament visibility is convenience only; `Client` stays `DeniesPartners` so the toggle is never readable by a Partner |
| V5 Input Validation | yes | the toggle is a boolean cast in `ClientInput`; the lock Actions take no free-text input (no reason, D-02); the refused-send error is a fixed translated message |
| V6 Cryptography | no | none added |
| V7 Error/Logging | yes | audit rows hold lock state and booleans only; no recipient address, body or token enters the activity payload (existing rule of `InvoiceAudit`) |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| A crafted Livewire call invokes a hidden send action | Tampering / Elevation | `QueueInvoiceEmail` re-checks the switch in the domain (D-06); jobs re-check before the transport |
| Toggle flipped between queue and send (TOCTOU) | Tampering | job re-check after claim, fresh read; residual window = PDF read, accepted |
| Partner reads the toggle or the new lock events | Information disclosure | `clients` is Admin-only; activity log rows are Admin-only; add the new events to the Partner leak expectations (`tests/Isolation/InvoiceLeakTest.php` already asserts no `invoice_unlocked` row reaches a Partner) |
| Lock bypass writing `lock_state` from arbitrary code | Tampering | architecture test confinement of the `Locked` token to named files |
| Silent e-mail suppression hiding a real delivery failure | Repudiation | `skipped` rows stay visible in the Odeslání/Platby history; the toggle change itself is audited |
| Mass-assignment of the new attribute | Tampering | add to `#[Fillable]` deliberately, only the Admin form/Actions write it |

## Project Constraints (from CLAUDE.md)

- Fictional data only in code, tests, fixtures, docs, planning docs and commit messages: use `example.com` addresses, placeholder company ID `12345678`, paths like `/Users/example/`; assemble test fakes at runtime from fragments (existing tests use `implode('.', ['example', 'com'])` and `exampleEmail(...)`).
- Review procedure before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`; never bypass the lefthook pre-commit hook; rerun `scripts/check-sensitive.sh .claude/CLAUDE.md` after regenerating GSD blocks.
- Development in git worktrees: write code and tests there, run only cheap checks without shared state; no migrations from a worktree; run migrations, the full test suite and static analysis after the merge into the HEAD branch, in DDEV (`ddev exec`), and treat that result as the final verdict.
- GSD workflow enforcement: file edits go through a GSD command (plan/execute), not ad hoc.
- Stack and rules from the project brief: Partner must never see measured time, rates, prices or finance nor another client's data (policies and scopes, not UI hiding); UUID v7 keys; FK/unique/check constraints in the DB; issued documents immutable except through the stated lock/unlock paths; number and kind never change.
- All content under `.planning/` in English; Czech UI text in `lang/cs`.

## Sources

### Primary (HIGH confidence — files opened with Read/grep this session)
- `.planning/phases/16-per-client-e-mail-sending-toggle/16-CONTEXT.md`, `.planning/REQUIREMENTS.md` (P15-01..12 registration pattern, IN-03/04/11/12/15, FND-10), `.planning/config.json`, `.planning/ROADMAP.md` (Phase 16 entry)
- `app/Domain/Invoicing/Actions/{QueueInvoiceEmail,QueuePaymentConfirmation,UnlockInvoice,UnlockCashReceipt,UpdateCashReceipt}.php`, `app/Domain/Invoicing/Jobs/{SendInvoiceEmail,SendPaymentConfirmation}.php`
- `app/Domain/Invoicing/Enums/{InvoiceLockState,CashReceiptLockState,InvoiceEmailState,PaymentConfirmationState}.php`, `app/Domain/Invoicing/Support/{InvoiceAudit,CashReceiptAudit,IssuedDocumentRules}.php`, `app/Domain/Invoicing/Models/{Invoice,InvoiceEmail,PaymentConfirmation,CashReceipt}.php`
- `database/migrations/2026_10_17_000300_create_invoice_admin_tables.php`, `2026_10_22_000100_add_lock_state_to_invoices.php`, `2026_10_24_000200_add_payment_method_to_invoices.php`, `2026_10_25_000100_create_payment_confirmations_table.php`, `2026_10_27_000200_create_cash_receipts_table.php`, `2026_10_09_000100_create_clients_table.php`
- `app/Domain/Clients/Models/Client.php`, `app/Domain/Clients/Actions/{ClientInput,CreateClient,UpdateClient}.php`, `app/Filament/Resources/ClientResource.php`, `app/Filament/Resources/ClientResource/Pages/ViewClient.php`, `app/Http/Resources/Api/V1/ClientResource.php`, `database/factories/ClientFactory.php`
- `app/Filament/Support/{SendInvoiceEmailAction,InvoiceTable,ActivityPresenter}.php`, `app/Filament/Resources/InvoiceResource.php`, `.../Pages/ViewInvoice.php`, `.../RelationManagers/{InvoiceEmailsRelationManager,InvoicePaymentsRelationManager}.php`, `app/Filament/Support/RecordPaymentAction.php`, `app/Filament/Resources/ActivityResource.php`
- `app/Domain/Operations/Health/Indicators/UnsentInvoiceEmailsIndicator.php`, `app/Domain/Audit/LogsAllowlistedActivity.php`
- `tests/Arch/{InvoiceUnlockPathTest,CashReceiptUnlockPathTest,ActivityAllowlistTest}.php`, `tests/Support/AuditDeclaration.php`, `tests/Isolation/PartnerSafeColumnsTest.php`, `tests/Feature/Invoicing/{SendInvoiceEmailTest,UnlockInvoiceTest,CashReceiptLockTest,PaymentConfirmationSchemaTest}.php` (headers/grep), `tests/Feature/Operations/UnsentInvoiceEmailsIndicatorTest.php`, `tests/Unit/Invoicing/InvoiceEnumsTest.php` (grep), `lang/cs/{enums,kokpit,invoices}.php` (excerpts)
- `composer.json` scripts and versions; `ddev describe`, `ddev exec vendor/bin/pest --version`, `ddev exec php -v`, `ddev exec psql --version`
- `.planning/phases/15-cash-payment-method-for-invoices-and-cash-receipt-documents/15-VALIDATION.md` (command format)
- `vendor/filament/schemas/src/Components/Callout.php:118` (`color()` exists)

### Secondary / Tertiary
- None. No web research was needed: the phase uses only installed libraries whose behavior is established by the repository's own code and tests.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; versions probed in DDEV
- Architecture: HIGH — every placement has a verified sibling in the codebase
- Pitfalls: HIGH for the CHECK constraint, exhaustive matches, arch allowlists and the default-false idiom (all read in source); MEDIUM for A1/A3 (design judgments flagged in the Assumptions Log)

**Research date:** 2026-10-27
**Valid until:** 2026-11-26 (stable internal code; re-check only if Phases 10.1/13/15 files change before planning)
