---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 05
subsystem: invoicing
tags: [mail, html-email, invoice-mail, pay-button, escaping, i18n]
status: complete

requires:
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    provides: the shared HTML layout, MailBrand, mail_layout.button_fallback and MailFixtures from plan 13-01
provides:
  - the document e-mail as an HTML message on the shared layout with the unchanged plain-text alternative (InvoiceMail, mail/invoice view)
  - PlainTextHtml::paragraphs(), the escape of an Admin-typed body into HTML paragraph blocks
  - PayLinkBlock::forHtml() and textBlock(): the button and the text block from one predicate
  - invoice_mail.pay_button and invoice_mail.body.with_due_date_online (cs, en)
affects: [13-06, 13-07, 13-09]

plan_head_before: f4a55511e10e168de6f9b43011203ee2fb5eb25a
plan_head_after: 7dadff3eefc941707580c6e3ac822aafa5416fee

actuals:
  tokens: 6500
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A free-text body enters a Markdown mail slot only as escaped HTML paragraph blocks that start flush left (CommonMark HTML block), never as raw text"
    - "One call of PayLinkBlock::forHtml() per send feeds the HTML button and the plain-text block, so the two parts cannot disagree"
    - "An explicit text view on a Markdown Mailable keeps the plain-text part byte for byte"

key-files:
  created:
    - app/Domain/Notifications/Mail/PlainTextHtml.php
    - resources/views/mail/invoice.blade.php
    - tests/Feature/Mail/InvoiceMailHtmlTest.php
  modified:
    - app/Domain/Invoicing/Mail/InvoiceMail.php
    - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
    - app/Domain/Payments/Support/PayLinkBlock.php
    - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
    - lang/cs/invoice_mail.php
    - lang/en/invoice_mail.php
    - tests/Feature/Invoicing/SendInvoiceEmailTest.php

key-decisions:
  - "R-13-05-1: forHtml() returns lead, URL and label (document language) or null exactly when applies() is false; textBlock() renders today's block; forMail() stays as the composition of the two, so older callers and tests are unchanged"
  - "R-13-05-2: InvoiceMail takes (subject, body, payLink, files, language), computes plainText as the body or the right-trimmed body plus the block, and sets the Mailable locale from the document language"
  - "R-13-05-3: PlainTextHtml escapes every line with e(), joins lines with br, wraps paragraphs in p and separates them by one blank line, so CommonMark passes the block through unparsed"
  - "R-13-05-5: SendInvoiceEmail reads the invoice once and calls forHtml() once; bodyToSend() is removed; the log row still stores only the confirmed text"
  - "A-13-4: QueueInvoiceEmail::defaultBody() uses with_due_date_online for an online-method document with a due date; the transfer and credit-note texts are unchanged"

requirements-completed: [P13-03, P13-10]

coverage:
  - id: D1
    description: "A card-enabled document is sent as an HTML e-mail with the supplier header, the typed body, the lead, a 'Zaplatit kartou' button to the stable pay URL and a fallback line with the URL; its text part, subject and attachments are unchanged"
    requirement: "P13-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#the HTML layout (Phase 13)"
        status: pass
      - kind: integration
        ref: "tests/Feature/Payments/PayButtonOutputsTest.php"
        status: pass
    human_judgment: false
  - id: D2
    description: "Nothing the Admin types becomes markup: list, heading, emphasis, link, code, tag and indentation syntax read literally, line breaks and paragraphs are kept, the text part is the typed body"
    requirement: "P13-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/InvoiceMailHtmlTest.php#the body the Admin typed"
        status: pass
    human_judgment: false
  - id: D3
    description: "The button and the pay block appear exactly when PayLinkBlock::applies() is true: absent for a transfer document, a switched-off card, a paid document, a cancelled invoice and a credit note; present for a proforma and a partly paid document"
    requirement: "P13-03"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/InvoiceMailHtmlTest.php#the pay button follows the one predicate of PayLinkBlock"
        status: pass
    human_judgment: false
  - id: D4
    description: "An English document gets lang=en, the label 'Pay by card', the English lead and layout labels; a Czech one gets the Czech ones; cs and en invoice_mail keys stay equal"
    requirement: "P13-10"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/InvoiceMailHtmlTest.php#the language of the document"
        status: pass
      - kind: integration
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#the copy"
        status: pass
    human_judgment: false
  - id: D5
    description: "The pre-filled text of an online-method document no longer points at bank details (cs and en); transfer and credit-note texts are unchanged"
    requirement: "P13-10"
    verification:
      - kind: integration
        ref: "tests/Feature/Invoicing/SendInvoiceEmailTest.php#pre-fills an online-method document without pointing at bank details, in Czech and in English"
        status: pass
    human_judgment: false
  - id: D6
    description: "The e-mail looks right in real mail clients (Gmail, Outlook, Apple Mail), including the button"
    requirement: "P13-03"
    verification:
      - kind: manual
        ref: "Phase 13 human check in real clients, Mailpit at the DDEV port 8025"
        status: pending
    human_judgment: true
---

# Phase 13 Plan 05: Document e-mail on the shared layout Summary

**The invoice and proforma e-mail is now an HTML message on the shared supplier layout: the Admin's confirmed text is escaped into paragraph blocks (never parsed), a "Zaplatit kartou" button follows PayLinkBlock's one predicate, the layout speaks the document's language, and the plain-text part, subject and attachments are byte for byte what they were.**

## Performance

- **Tasks:** 2 (tracer, then an auto/tdd task), 2 commits
- **Files:** 10 created or modified (3 new)
- **Tests:** InvoiceMailHtmlTest 10, SendInvoiceEmailTest 48, EmailDialogTest green; the wave gate (Mail, Invoicing, Payments, Isolation) 1646 green
- Pint clean; PHPStan reports only the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39`

## Accomplishments

- `InvoiceMail` is a Markdown Mailable (`markdown: 'mail.invoice'`, `text: 'mail.invoice-text'`) with the document language as its locale; the plain-text alternative is computed in the constructor from the body and the pay block.
- `PlainTextHtml::paragraphs()` is the only way the Admin's text reaches the Markdown slot; the DOM test shows no list, heading, emphasis, code, link or tag element for every trigger.
- `PayLinkBlock::forHtml()` + `textBlock()` replace the send job's private `bodyToSend()`; the Phase 14 hand-over (OA-5) is recorded as done in the class docblock.
- `with_due_date_online` and `pay_button` exist in Czech and English with equal keys; the dialog pre-fill of an online document stops mentioning bank details (A-13-4).

## Task Commits

1. **Task 1 (tracer): HTML document e-mail with the pay button, text part unchanged** - `ab68efa`
2. **Task 2: escaping, gate and language tests; online default body** - `7dadff3`

## Deviations from Plan

None - plan executed as written. Task 2's escaping, gate and language behaviours already passed against the task 1 implementation, so only the online default body was a real RED step.

## Deferred Issues

- PHPStan still reports the one pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39`; out of scope.

## Known Stubs

None.

## Threat Flags

None. T-13-12 (body parsed as Markdown), T-13-13 (link built from the body; the only link is the button from `PayUrl`) and T-13-14 (dead button) are covered by `InvoiceMailHtmlTest`; composer files unchanged.

## Self-Check: PASSED

- `app/Domain/Notifications/Mail/PlainTextHtml.php`, `resources/views/mail/invoice.blade.php`, `tests/Feature/Mail/InvoiceMailHtmlTest.php`: found.
- Commits `ab68efa` and `7dadff3`: found in history.
