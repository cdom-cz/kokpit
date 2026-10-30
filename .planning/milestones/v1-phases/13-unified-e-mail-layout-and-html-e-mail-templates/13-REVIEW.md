---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
reviewed: 2026-10-24T00:00:00Z
depth: standard
files_reviewed: 52
files_reviewed_list:
  - CONTRIBUTING.md
  - README.md
  - app/Domain/Clients/Actions/SendPartnerPasswordReset.php
  - app/Domain/Clients/Notifications/PartnerInvitation.php
  - app/Domain/Identity/Notifications/ResetPasswordNotification.php
  - app/Domain/Invoicing/Actions/QueueInvoiceEmail.php
  - app/Domain/Invoicing/Actions/QueuePaymentConfirmation.php
  - app/Domain/Invoicing/Actions/RecordPayment.php
  - app/Domain/Invoicing/Enums/PaymentConfirmationState.php
  - app/Domain/Invoicing/Jobs/SendInvoiceEmail.php
  - app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php
  - app/Domain/Invoicing/Mail/InvoiceMail.php
  - app/Domain/Invoicing/Mail/PaymentConfirmationMail.php
  - app/Domain/Invoicing/Models/InvoicePayment.php
  - app/Domain/Invoicing/Models/PaymentConfirmation.php
  - app/Domain/Notifications/Mail/EmbedMailLogo.php
  - app/Domain/Notifications/Mail/MailBrand.php
  - app/Domain/Notifications/Mail/MailLogo.php
  - app/Domain/Notifications/Mail/MailText.php
  - app/Domain/Notifications/Mail/PlainTextHtml.php
  - app/Domain/Operations/Alerts/OperationalAlert.php
  - app/Domain/Payments/Support/PayLinkBlock.php
  - app/Domain/Settings/DocumentImage.php
  - app/Domain/Shared/Database/MorphMap.php
  - app/Domain/Tasks/Notifications/TaskNotification.php
  - app/Filament/Pages/Auth/RequestPasswordReset.php
  - app/Filament/Resources/InvoiceResource/RelationManagers/InvoicePaymentsRelationManager.php
  - app/Filament/Support/RecordPaymentAction.php
  - app/Providers/AccessServiceProvider.php
  - app/Providers/AppServiceProvider.php
  - config/mail.php
  - database/migrations/2026_10_25_000100_create_payment_confirmations_table.php
  - lang/cs/enums.php
  - lang/cs/invoice_mail.php
  - lang/cs/invoices.php
  - lang/cs/kokpit.php
  - lang/cs/mail_layout.php
  - lang/cs/payment_mail.php
  - lang/en/invoice_mail.php
  - lang/en/mail_layout.php
  - lang/en/payment_mail.php
  - resources/views/mail/invoice.blade.php
  - resources/views/mail/payment-confirmation.blade.php
  - resources/views/vendor/mail/html/footer.blade.php
  - resources/views/vendor/mail/html/header.blade.php
  - resources/views/vendor/mail/html/layout.blade.php
  - resources/views/vendor/mail/html/message.blade.php
  - resources/views/vendor/mail/html/themes/kokpit.css
  - resources/views/vendor/mail/text/header.blade.php
  - resources/views/vendor/mail/text/layout.blade.php
  - resources/views/vendor/mail/text/message.blade.php
  - resources/views/vendor/notifications/email.blade.php
findings:
  critical: 0
  warning: 1
  info: 7
  total: 8
status: issues_found
---

# Phase 13: Code Review Report

**Reviewed:** 2026-10-24T00:00:00Z
**Depth:** standard
**Files Reviewed:** 52
**Status:** issues_found

## Summary

The phase adds a shared e-mail layout and theme (`MailBrand`, `EmbedMailLogo`, `MailLogo`, `MailText`, `PlainTextHtml`), an HTML invoice e-mail, a payment confirmation e-mail with its own table, job and Admin tab, and a Kokpit-owned password reset e-mail.

The focus areas hold up:

- **HTML and Markdown injection.** The Admin-typed invoice body goes through `PlainTextHtml::paragraphs()`. Every line ends in `<br>\n` or `</p>`, so no whitespace-only line can end the CommonMark HTML block early. Typed text therefore never reaches the Markdown parser. In Markdown views `{{ }}` compiles to `EncodedHtmlString` (verified in `vendor/laravel/framework/src/Illuminate/Mail/Markdown.php`), so `<`, `>`, `&` and quotes are encoded before parsing. `MailText::escapeMarkdown()` covers links, images, emphasis, code, headings and tables, and its regex and its inverse are correct, including the `\\\*` round trip. The header, footer, title and `alt` values are Blade-escaped, and the footer is deliberately not Markdown-parsed. The supplier `website` is only validated with `url`, which in Laravel does not accept `javascript:`.
- **Partner isolation.** `PaymentConfirmation` is `PartnerIsolated`, uses `DeniesPartners` and has an `AdminOnlyPolicy`. `MailBrand` reads a fixed whitelist (no bank account, no signature) as system and is scoped per request or job. The confirmation recipient is always read from the client record inside the payment transaction, never from request input.
- **Invoice immutability.** `QueuePaymentConfirmation` and `SendPaymentConfirmation` write only `payment_confirmations`. There is no `$touches`, no invoice lock and no audit row. The document e-mail remains the only lock writer.
- **Concurrency.** The remaining-amount snapshot is taken under the invoice row lock held by `RecordPayment`, so two close payments get consistent figures. Dispatch is `afterCommit`. The claim (`queued` to `sending`) is one conditional update, and a unique key plus a cascading FK make a second or orphan row impossible.

One real robustness defect remains in the payment confirmation job (a row can get stuck in `sending` and the client is never told). The rest are minor correctness and hygiene items. No security vulnerability was found in the reviewed files.

## Warnings

### WR-01: A payment confirmation row can get stuck in `sending`; the client is silently never mailed and nobody is told

**File:** `app/Domain/Invoicing/Jobs/SendPaymentConfirmation.php:62-66` (also `:120-130`, `:77-78`)
**Issue:**
`handle()` claims the row (`queued` to `sending`) and then runs `PaymentConfirmation::query()->findOrFail(...)` on line 66, outside the `try`. The `catch` that puts the row back to `queued` therefore does not cover it. A transient database error on that read (or on any step before line 68, such as the worker dying or hitting the 60 s `Timeout`) leaves the row in `sending`.

On every redelivery `claim()` returns false (the row is no longer `queued`) and `handle()` returns normally. The job then counts as succeeded, so `failed()` and the failed-job alert never fire. The same happens after a `TimeoutExceededException` kill that does not exhaust the 3 tries. The row stays "Čeká na odeslání" in the Platby tab forever, the confirmation never leaves, and no alert reaches the Admin.

`SendInvoiceEmail` copes with the same shape through its `stale` class and the System page. This job has no equivalent, and nothing else in `app/` references `payment_confirmations` (checked with grep). A confirmation left `queued` because its job was lost (queue flushed or worker down) is never detected either. No test covers a row stuck in `sending` (checked in `PaymentConfirmationTriggerTest`).

There is also no way to resend. The row is unique per payment, and a failed or stuck row cannot be re-queued from the UI. The only workaround is to delete the payment, which also deletes its income.

**Fix:**
1. Move the `findOrFail` inside the `try`, so the reset to `queued` and the rethrow run for it as well:

```php
if (! $this->claim()) {
    return;
}

try {
    $row = PaymentConfirmation::query()->findOrFail($this->confirmationId);
    $recipient = QueuePaymentConfirmation::cleanAddress($row->recipient);
    // ... unchanged
} catch (Throwable $e) {
    PaymentConfirmation::query()
        ->whereKey($this->confirmationId)
        ->where('state', PaymentConfirmationState::Sending->value)
        ->update(['state' => PaymentConfirmationState::Queued->value, 'updated_at' => now()]);

    throw $e instanceof TransportExceptionInterface ? new RuntimeException(self::TRANSPORT_FAILED, 0, $e) : $e;
}
```

2. Let `claim()` take over a `sending` row whose `updated_at` is older than a stale threshold (more than the job timeout plus the mail timeout). Alternatively add a scheduled reaper that marks old `queued` or `sending` rows `failed` with the class `stale`, as `SendInvoiceEmail` does.
3. Cover it with a test: a row left in `sending` is recovered or reported.

## Info

### IN-01: The plain-text layout un-escapes supplier values that were never escaped

**File:** `resources/views/vendor/mail/text/layout.blade.php:4,12` (values from `resources/views/vendor/mail/text/message.blade.php:25-40`)
**Issue:** `unescapeMarkdown()` is applied to the whole footer and header. Those carry raw `MailBrand` values (company name, street, registration note), which the HTML layout prints without Markdown escaping. A supplier value that contains `\` followed by one of ``\ ` * _ [ ] ( ) # | ~ !`` (for example a registration note like `C:\_x`) loses a backslash in the plain-text part only. The HTML and text parts then disagree, against the "both parts state the same facts" intent. The effect is small but real.
**Fix:** Run `unescapeMarkdown()` only over the slot and subcopy (the parts whose values `escapeMarkdown()` touched). Print the header and footer through `strip_tags()` alone.

### IN-02: `EmbedMailLogo` reads the brand (database, object storage, GD) before it checks the message

**File:** `app/Domain/Notifications/Mail/EmbedMailLogo.php:25-31`
**Issue:** `MailBrand::current()` is evaluated on line 26, before the cheap `is_string($html)` and `str_contains` tests. Every outgoing message, including text-only messages and any message that never uses the layout, therefore triggers a settings read, a storage `get` of the logo and a GD decode and scale. This is wasted work and an unneeded failure surface on a path that must never block mail. It is not a correctness bug because `MailBrand::read()` swallows failures.
**Fix:** Check `$html` and `str_contains($html, 'cid:'.MailBrand::LOGO_NAME)` first, and only then call `MailBrand::current()`.

### IN-03: `DocumentImage::contents()` is not limited to the logo kind

**File:** `app/Domain/Settings/DocumentImage.php:127-130`, called from `app/Domain/Notifications/Mail/MailBrand.php:122`
**Issue:** `contents()` delegates to `bytes()`, which accepts both the `logo` and the `signature` path patterns. The `MailBrand` docblock promises "never the signature image (T-13-01)", but that holds only because `SupplierSettings::rules()` constrains `logo_path` on save. A value written without validation (a migration, a seeder) could embed the signature image into every e-mail.
**Fix:** Give the mail reader its own kind check, for example `contents(string $path, string $kind = 'logo')` matching only `pathPattern($kind)`. Alternatively assert the logo pattern in `MailBrand::readLogo()`.

### IN-04: `MailLogo::fromPng` can leave an output buffer open

**File:** `app/Domain/Notifications/Mail/MailLogo.php:80-82`
**Issue:** `ob_start()` and `ob_get_clean()` are not paired in a `finally`. If `imagepng()` throws (for example a `ValueError`), the outer `catch (Throwable)` returns null with the buffer still open. In a long-lived queue worker the buffer leaks into the following jobs' output handling. `DocumentImage::reencode()` has the same pattern, but it runs in a short web request.
**Fix:**
```php
ob_start();
try {
    $written = imagepng($image, null, 6);
} finally {
    $bytes = (string) ob_get_clean();
}
```

### IN-05: `PayLinkBlock::forMail()` is dead application code

**File:** `app/Domain/Payments/Support/PayLinkBlock.php:67-75`
**Issue:** After the move to `forHtml()` plus `textBlock()`, `forMail()` is referenced only by a test (`tests/Feature/Payments/PayButtonOutputsTest.php:295`). No application path calls it. It is a second route to the same block that the new docblock says must not exist ("they cannot disagree").
**Fix:** Delete `forMail()` and move the test assertion to `forHtml() === null`.

### IN-06: Deleting a payment also deletes the log row of a confirmation that was already sent

**File:** `database/migrations/2026_10_25_000100_create_payment_confirmations_table.php:54`
**Issue:** `ON DELETE CASCADE` is meant for the unsent row inside the grace window (A-13-6). It also removes `sent` rows when a payment is deleted after the e-mail went out. The client then holds an e-mail about a payment that no longer exists, and nothing records that it was sent. The `InvoicePayment` deletion is logged, but the confirmation is not.
**Fix:** Either log a `deleted` activity for a `sent` confirmation, or keep the row (nullable `invoice_payment_id` with `ON DELETE SET NULL`) and let the job skip rows without a payment.

### IN-07: `escapeMarkdown()` does not neutralise a block marker at the start of a source line

**File:** `resources/views/mail/payment-confirmation.blade.php:22-23` (also `resources/views/vendor/notifications/email.blade.php:36` through `PartnerInvitation::toMail()`)
**Issue:** The supplier name is printed on its own source line (after `<br>`, or after `"\n"` in the salutation). `escapeMarkdown()` leaves `-`, `+`, `=`, `1.` and `>` alone, so a name that begins with `- ` or `1. ` would render as a list item (`1.` can interrupt a paragraph). The docblock claims a value "can not start a new block". The value is Admin-controlled, so this is cosmetic, not an injection.
**Fix:** Keep such values mid-line (for example `{{ regards }}: {{ name }}`), or have `escapeMarkdown()` also escape a leading `-`, `+`, `=`, `>` and `\d+[.)]`.

---

_Reviewed: 2026-10-24T00:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
