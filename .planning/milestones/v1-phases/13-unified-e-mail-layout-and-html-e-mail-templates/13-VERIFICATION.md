---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
verified: 2026-10-24T12:00:00Z
status: human_needed
score: 11/11 must-haves verified
covered_files:
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-01-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-01-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-02-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-02-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-03-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-03-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-04-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-04-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-05-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-05-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-06-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-06-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-07-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-07-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-08-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-08-SUMMARY.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-09-PLAN.md"
  - ".planning/phases/13-unified-e-mail-layout-and-html-e-mail-templates/13-09-SUMMARY.md"
  - "CONTRIBUTING.md"
  - "app/Domain/Identity/Notifications/ResetPasswordNotification.php"
  - "app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php"
  - "app/Domain/Invoicing/Actions/RecordPayment.php"
  - "app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php"
  - "app/Domain/Invoicing/Mail/InvoiceMail.php"
  - "app/Domain/Invoicing/Mail/PaymentConfirmationMail.php"
  - "app/Domain/Invoicing/Models/PaymentConfirmation.php"
  - "app/Domain/Notifications/Mail/EmbedMailLogo.php"
  - "app/Domain/Notifications/Mail/MailBrand.php"
  - "app/Domain/Notifications/Mail/MailLogo.php"
  - "app/Domain/Notifications/Mail/MailText.php"
  - "app/Domain/Notifications/Mail/PlainTextHtml.php"
  - "config/mail.php"
  - "database/migrations/2026_10_25_000100_create_payment_confirmations_table.php"
  - "lang/cs/mail_layout.php"
  - "lang/cs/payment_mail.php"
  - "lang/en/mail_layout.php"
  - "lang/en/payment_mail.php"
  - "resources/views/mail/invoice.blade.php"
  - "resources/views/mail/payment-confirmation.blade.php"
  - "resources/views/vendor/mail/html/layout.blade.php"
  - "resources/views/vendor/mail/html/message.blade.php"
  - "resources/views/vendor/mail/html/themes/kokpit.css"
  - "resources/views/vendor/mail/text/message.blade.php"
  - "resources/views/vendor/notifications/email.blade.php"
covered_digest: "v3:sha256:5b1608618e35ff75c280383f1a2a76c04ea918581870537723ef769b22aef607"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "Open every e-mail of the phase in Mailpit (ddev launch -m) and, where a test mailbox exists, in Gmail, Outlook, Apple Mail and one mobile client. Trigger: a card-enabled and a transfer invoice (one Czech and one English client), a partial and then the final payment (wait out the 5-minute grace window with the queue worker running), a partner invitation, a password reset, a Partner comment on a task, an operational alert through AdminAlerter from tinker."
    expected: "Same header on all (logo at most 180 px wide and sharp, or the supplier name as text) and same footer; the logo is not listed as an attachment and no image is broken; the amber button is readable and works; the invoice body keeps its line breaks and typed symbols; the confirmation shows number, amount, date and the remaining amount or the paid-in-full sentence; no Czech label in English e-mails; plain-text views read cleanly with no stray backslashes; the alert matches the client e-mails apart from its red button."
    why_human: "Outlook's Word rendering engine, Gmail image handling, mobile layout and the overall visual look cannot be asserted by the test suite (plan 13-09 task 3 is explicitly the separate human review step and was left open)."
audit_acknowledged:
  milestone: v1
  at: 2026-10-30
  status: human_needed
---

# Phase 13: Unified e-mail layout and HTML e-mail templates Verification Report

**Phase Goal:** Every e-mail the system sends shares one HTML layout built on the Laravel mail theme. The payment e-mails are reworked first: a new confirmation to the client that an invoice has been paid (absorbs the former Phase 10.2), and the invoice e-mail with its "Pay by card" button. The other e-mails (partner invitation, password reset, task notifications, operational alerts) are moved to the layout and their texts reviewed.
**Verified:** 2026-10-24
**Status:** human_needed
**Re-verification:** No, initial verification

All automated checks pass. The only open item is the human look at real mail clients, which the phase plan itself defers to an end-of-phase review. The known review warning WR-01 is recorded below and does not break a must-have.

## Goal Achievement

The ROADMAP entry has a goal but no separate success-criteria list, so the truths below are derived from the phase-local requirements P13-01 to P13-11 (13-RESEARCH.md) and the `must_haves` of the nine plans.

### Observable Truths

| #  | Truth (requirement) | Status | Evidence |
| -- | ------------------- | ------ | -------- |
| 1  | P13-01: One published `kokpit` mail theme drives every e-mail; no stock Laravel branding left | VERIFIED | `config/mail.php:143-149` sets `markdown.theme = kokpit` with `resources/views/vendor/mail` as the path. `kokpit.css` (307 lines), `html/{layout,message,header,footer}.blade.php`, `text/{layout,message,header}.blade.php` and `vendor/notifications/email.blade.php` exist. `grep -i laravel` over `resources/views/vendor/mail` and `vendor/notifications` finds only the CSS comment describing the theme. `MailThemeTest` passes. |
| 2  | P13-02: Header = supplier name or inline cid logo; footer = supplier details; read in a system context; degrades with no logo | VERIFIED | `MailBrand::read()` uses `PartnerContext::runAsSystem()`, a fixed field whitelist (no bank account, no signature), and a `fallback()` to the app name on any failure. `html/message.blade.php` emits `cid:kokpit-logo.png` only when `hasLogo()`, else the name as text. `EmbedMailLogo` and `MailLogo` (downscale) exist. `MailBrandTest` passes. |
| 3  | P13-03: Invoice e-mail on the layout, Admin text escaped (never Markdown), card button gated by `PayLinkBlock`, plain-text alternative kept, document language | VERIFIED | `InvoiceMail` uses `markdown: 'mail.invoice'` with `text: 'mail.invoice-text'`; `invoice.blade.php` passes the body through `PlainTextHtml::paragraphs()` (escaped, `{!! !!}` only on its output) and renders the button only when `$payLink !== null` (`PayLinkBlock::forHtml()`). The locale is set from the document language. `InvoiceMailHtmlTest` and `SendInvoiceEmailTest` pass. |
| 4  | P13-04: Payment confirmation content: number, amount, date, remaining for a partial payment, paid-in-full wording for the last one, no attachment, client language | VERIFIED | `PaymentConfirmationMail::forPayment()` formats per language; `payment-confirmation.blade.php` branches on `$remaining === null`; `attachments()` returns `[]`; subject keys `full` and `partial`. `PaymentConfirmationMailTest` passes. |
| 5  | P13-05: One confirmation per payment, trigger from `RecordPayment::recordLocked()`, de-duplicated, never for a carried proforma payment, never locks the invoice | VERIFIED | `RecordPayment.php:151` calls `$this->confirmations->handle($locked, $payment)` inside the payment transaction; `QueuePaymentConfirmation` throws outside a transaction, writes one row (unique key `invoice_payment_id`), dispatches `afterCommit` with a 300 s delay; no recipient gives a `skipped` row and no job. `SendPaymentConfirmation` claims the row by a conditional `queued` to `sending` update. It writes only `payment_confirmations`. `PaymentConfirmationTriggerTest`, `PaymentConfirmationSchemaTest` and `PaymentConfirmationAdminTest` pass. |
| 6  | P13-06: Partner invitation on the layout with reviewed text | VERIFIED | `PartnerInvitation::toMail()` builds a `MailMessage` (rendered by the published `notifications/email` view and the `kokpit` theme), with `MailText::escapeMarkdown()` on invitee and client names and a `mail_layout.regards` salutation. `NotificationMailsTest` and `PartnerInvitationTest` pass. |
| 7  | P13-07: Password reset on the layout via a Kokpit subclass; both call sites use it | VERIFIED | `ResetPasswordNotification extends ResetPassword`; both `RequestPasswordReset.php:58` and `SendPartnerPasswordReset.php:50` instantiate it. `PartnerPasswordResetTest` passes. |
| 8  | P13-08: Task notifications on the layout, texts reviewed, preferences untouched | VERIFIED | `TaskNotification` builds a `MailMessage` with `MailText::escapeMarkdown()` on every value line. `TaskNotificationsTest` passes. |
| 9  | P13-09: Operational alerts on the layout, same branding, values inert, red button on error level | VERIFIED | `OperationalAlert::toMail()` uses `MailText::escapeMarkdown()` on title and lines; the published notification view maps `error` and `success` levels to the button colour. `AdminAlertTest` and `NotificationMarkupTest` pass. |
| 10 | P13-10: CZ/EN texts with key parity; layout strings follow the mail locale | VERIFIED | `lang/{cs,en}/mail_layout.php` and `payment_mail.php` exist; a tinker `array_diff_key` in both directions for the top level of both groups gave 0 missing keys. The key-parity tests and `LangEncodingTest` (`tests/Feature/Localisation`) pass. |
| 11 | P13-11: Hygiene and security: no secret, no new public route, no Partner leak, escaping canaries, docs hand-over | VERIFIED | `git diff --stat 9b40850 HEAD -- routes composer.json composer.lock` is empty (no route, no dependency). `PaymentConfirmation` is Admin-only (`AdminOnlyPolicy`, `DeniesPartners`, `PartnerIsolated` per the isolation tests). `tests/Arch` and `tests/Feature/Repo` pass, including the E-mail bullet guard in `RepositoryFilesTest`. CONTRIBUTING, README and PROJECT.md carry the hand-over. |

**Score:** 11/11 truths verified (0 present but behavior-unverified). The human review of rendering is carried separately below.

### Required Artifacts

| Artifact | Expected | Status | Details |
| -------- | -------- | ------ | ------- |
| `config/mail.php` | theme `kokpit` | VERIFIED | `markdown.theme = kokpit` |
| `resources/views/vendor/mail/html/*` and `themes/kokpit.css` | published layout and theme | VERIFIED | all present, substantive (307-line CSS), used by `<x-mail::message>` |
| `resources/views/vendor/mail/text/*` | plain-text layout | VERIFIED | header, body, subcopy and footer from `MailBrand` |
| `app/Domain/Notifications/Mail/{MailBrand,MailText,MailLogo,EmbedMailLogo,PlainTextHtml}.php` | branding and escaping helpers | VERIFIED | all substantive and referenced from the views, jobs and notifications |
| `resources/views/mail/{invoice,invoice-text,payment-confirmation}.blade.php` | e-mail bodies | VERIFIED | wired by `InvoiceMail` and `PaymentConfirmationMail` |
| `app/Domain/Invoicing/{Actions/QueuePaymentConfirmation,Jobs/SendPaymentConfirmation,Mail/PaymentConfirmationMail,Models/PaymentConfirmation}.php` | confirmation pipeline | VERIFIED | trigger, job, mailable and model are connected from `RecordPayment` |
| `database/migrations/2026_10_25_000100_create_payment_confirmations_table.php` | table with unique key, FK cascade and check constraints | VERIFIED | `PaymentConfirmationSchemaTest` passes |
| `app/Domain/Identity/Notifications/ResetPasswordNotification.php` | Kokpit reset notification | VERIFIED | used by both call sites |
| `lang/{cs,en}/{mail_layout,payment_mail}.php` | texts in both languages | VERIFIED | key parity holds |

### Key Link Verification

| From | To | Via | Status | Details |
| ---- | -- | --- | ------ | ------- |
| `RecordPayment::recordLocked()` | `QueuePaymentConfirmation::handle()` | constructor-injected, called after the payment is stored | WIRED | `RecordPayment.php:66,151` |
| `QueuePaymentConfirmation` | `SendPaymentConfirmation` | `dispatch(...)->afterCommit()->delay(300 s)` | WIRED | |
| `SendPaymentConfirmation` | `PaymentConfirmationMail` | `Mail::to(...)->send(forPayment(...))` | WIRED | |
| `html/message.blade.php` | `MailBrand::current()` | `@php($brand = ...)` | WIRED | |
| `EmbedMailLogo` | `MailBrand` logo bytes | cid `kokpit-logo.png` | WIRED | the same `hasLogo()` decider as the header |
| `InvoiceMail` | `PayLinkBlock::forHtml()` and `textBlock()` | constructor arguments | WIRED | one predicate for HTML and text |
| `RequestPasswordReset` and `SendPartnerPasswordReset` | `ResetPasswordNotification` | `app(...)` | WIRED | |
| `RecordPaymentAction` dialog | `QueuePaymentConfirmation::recipientFor()` | recipient notice in the dialog | WIRED | `RecordPaymentAction.php:205` |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
| -------- | ------------- | ------ | ------------------ | ------ |
| Layout header and footer | `$brand` | `SupplierSettings` via `MailBrand::read()` as system | Yes (fallback to app name only on failure) | FLOWING |
| Payment confirmation | `remaining_minor` | `InvoiceBalances` under the invoice row lock | Yes | FLOWING |
| Payment confirmation recipient | `recipient` | client `invoice_email` read in the payment transaction | Yes | FLOWING |
| Invoice e-mail pay button | `$payLink` | `PayLinkBlock::forHtml()` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
| -------- | ------- | ------ | ------ |
| Mail, confirmation, notification, localisation, arch and repo tests | `ddev exec vendor/bin/pest tests/Feature/Mail tests/Feature/Invoicing/PaymentConfirmation* tests/Isolation/NotificationMarkupTest.php tests/Feature/Clients/PartnerInvitationTest.php tests/Feature/Clients/PartnerPasswordResetTest.php tests/Feature/Tasks/TaskNotificationsTest.php tests/Feature/Operations/AdminAlertTest.php tests/Feature/Localisation tests/Arch tests/Feature/Repo` | 455 passed, 3306 assertions | PASS |
| Static analysis | `ddev exec vendor/bin/phpstan analyse` | 1 error, pre-existing in `app/Console/Commands/ResetAdminTwoFactorCommand.php` (out of Phase 13 scope, already recorded in the 13-09 summary) | PASS (for this phase) |
| Lang key parity cs/en for `mail_layout` and `payment_mail` | tinker `array_diff_key` in both directions | 0 missing keys | PASS |
| No new route or dependency | `git diff --stat 9b40850 HEAD -- routes composer.json composer.lock` | empty | PASS |

The full suite (6876 passed, 3 skipped) was reported by plan 13-09; I did not re-run it in full, only the phase-relevant subset above.

### Probe Execution

Step 7c: SKIPPED (the phase declares no probe scripts).

### Requirements Coverage

P13-01 to P13-11 are phase-local (13-RESEARCH.md) and are not in REQUIREMENTS.md. No requirement in REQUIREMENTS.md maps to Phase 13, so there are no orphans there.

| Requirement | Source Plans | Description | Status | Evidence |
| ----------- | ------------ | ----------- | ------ | -------- |
| P13-01 | 13-01, 13-04, 13-09 | One published mail theme | SATISFIED | Truth 1. Plan 13-09 task 3 (human look in real clients) is open and is listed below. |
| P13-02 | 13-01, 13-04 | Supplier header and footer, inline cid logo | SATISFIED | Truth 2 |
| P13-03 | 13-05 | Invoice e-mail on the layout with the card button | SATISFIED | Truth 3 |
| P13-04 | 13-06 | Payment confirmation content | SATISFIED | Truth 4 |
| P13-05 | 13-02, 13-03, 13-06, 13-08 | Confirmation trigger and de-duplication | SATISFIED | Truth 5 |
| P13-06 | 13-07 | Partner invitation | SATISFIED | Truth 6 |
| P13-07 | 13-07 | Password reset | SATISFIED | Truth 7 |
| P13-08 | 13-04, 13-07 | Task notifications | SATISFIED | Truth 8 |
| P13-09 | 13-07 | Operational alerts | SATISFIED | Truth 9 |
| P13-10 | 13-01, 13-05, 13-06 | CZ/EN texts and parity | SATISFIED | Truth 10 |
| P13-11 | 13-02, 13-06, 13-08, 13-09 | Hygiene and security | SATISFIED | Truth 11 |

All eleven IDs appear in at least one plan's `requirements` frontmatter, and the ROADMAP lists all eleven for Phase 13.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
| ---- | ---- | ------- | -------- | ------ |
| `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php` | 62-66 (also 120-130, 77-78) | WR-01 (from 13-REVIEW.md): `findOrFail` runs after `claim()` but outside the `try`; a failure there, or a worker killed after the claim, leaves the row in `sending`. Redeliveries then return silently, `failed()` never fires, and no alert is raised. I confirmed this by reading the code. There is also no UI path to resend. | Warning | Narrow failure window (a transient DB error right after the claim, or a timeout kill). The must-have "a refused transport puts the row back for the retry and, once the queue gives up, marks it failed" holds for the transport failure path, which is the one the tests cover. It does not break a must-have. Recommend fixing in a follow-up (move the read into the `try`, add a stale-`sending` takeover or reaper, add a test). |
| `resources/views/vendor/mail/text/layout.blade.php` | 4, 12 | IN-01: `unescapeMarkdown()` also runs over the raw supplier header and footer | Info | Edge case: a supplier value holding a backslash before a Markdown symbol loses it in the plain-text part only |
| `app/Domain/Notifications/Mail/EmbedMailLogo.php` | 25-31 | IN-02: the brand is read before the cheap `cid:` check | Info | Wasted work; failure is swallowed |
| `app/Domain/Settings/DocumentImage.php` | 127-130 | IN-03: `contents()` is not limited to the logo kind | Info | Protected today by the save-time rule only |
| `app/Domain/Notifications/Mail/MailLogo.php` | 80-82 | IN-04: output buffer not closed in a `finally` | Info | Possible buffer leak in a worker |
| `app/Domain/Payments/Support/PayLinkBlock.php` | 67-75 | IN-05: `forMail()` is dead application code | Info | Used only by a test |
| `database/migrations/2026_10_25_000100_create_payment_confirmations_table.php` | 54 | IN-06: cascade also removes the log row of an already sent confirmation | Info | Audit gap on payment deletion |
| `resources/views/mail/payment-confirmation.blade.php` | 22-23 | IN-07: a block marker at the start of a source line is not neutralised | Info | Cosmetic, Admin-controlled value |

No `TBD`, `FIXME` or `XXX` marker is a blocker; the info items above come from the review, were not re-scanned in full, and none is a stub or blocks a truth. The one PHPStan error is outside the phase.

### Human Verification Required

#### 1. Rendering of all phase e-mails in Mailpit and real mail clients

**Test:** In DDEV (`ddev launch -m`), with a fictional supplier that has a logo and fictional clients, trigger every e-mail of the phase: a card-enabled and a transfer invoice (one Czech and one English client), a partial and then the final payment (wait out the 5-minute grace window with the queue worker running), a partner invitation, a password reset, a Partner comment on a task, and an operational alert through `AdminAlerter` from tinker. Open each in Mailpit and, where a test mailbox exists, in Gmail, Outlook, Apple Mail and one mobile client.
**Expected:** The same header (logo at most 180 px wide and sharp, or the supplier name) and footer on all; the logo not listed as an attachment and no broken image; the amber button readable and working; the invoice body keeping its line breaks and typed symbols; the confirmation showing number, amount, date and the remaining amount or the paid-in-full sentence; no Czech label in English e-mails; readable plain-text views with no stray backslashes; the alert matching the client e-mails apart from its red button.
**Why human:** Outlook's Word engine, Gmail image handling, mobile layout and the visual look cannot be asserted by tests. Plan 13-09 task 3 deliberately defers this to an end-of-phase review.

### Owner Decisions Awaiting Confirmation

PROJECT.md records the planner assumptions A-13-1 (proforma payments are confirmed), A-13-3 to A-13-5 and A-13-7, and A-13-6 (five-minute grace window) as "Pending" for the owner to confirm or overrule. They are decisions, not defects.

### Gaps Summary

No gaps. All eleven phase requirements are backed by code, wiring and passing tests. WR-01 is a robustness warning in the confirmation job, with real code evidence and a clear fix, but it does not defeat a stated must-have. The phase waits only on the human check of real mail clients.

---

_Verified: 2026-10-24_
_Verifier: Claude (gsd-verifier)_
