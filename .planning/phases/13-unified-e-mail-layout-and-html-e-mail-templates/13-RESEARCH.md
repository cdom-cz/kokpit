# Phase 13: Unified e-mail layout and HTML e-mail templates - Research

**Researched:** 2026-10-23
**Domain:** Laravel 13 Markdown mail theme (HTML + text), Mailables and MailMessage notifications, queued transactional e-mail, payment confirmation trigger and de-duplication
**Confidence:** HIGH for the framework mechanics (read from the vendored source and run in DDEV this session); MEDIUM for e-mail client rendering (needs a human check in real clients)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Invoice e-mail**
- **D-01:** The invoice e-mail keeps its editable plain-text body (Phase 10 U-11). The Admin-confirmed text is escaped and placed inside the shared HTML layout (header, footer, "Pay by card" button). No HTML sanitiser and no rich text editor are introduced. The subject and the attachments stay as they are (Phase 10 D-22). — **Reversibility:** reversible — only the view and the Mailable content change.

**Shared layout**
- **D-02:** The layout is built on the Laravel mail theme: publish `vendor/mail`, customise the theme, header and footer. `MailMessage`-based e-mails (invitation, password reset, tasks, alerts) pick it up directly; the invoice and payment e-mails use Markdown Mailables on the same theme. — **Reversibility:** costly — every e-mail view depends on the published theme.
- **D-03:** The header shows the supplier name and the supplier logo; the footer shows the supplier details (address, company ID, contact) taken from the supplier settings of the instance, never hard-coded. The logo must be embedded or served so it renders in mail clients. Fictional data only in fixtures and tests (repository hygiene).
- **D-04:** Operational alerts (sent to Admin) use the same layout and branding as the client-facing e-mails; they differ only in content.

**Payment e-mails**
- **D-05:** The payment confirmation goes to the client, to the client's invoice e-mail, in the client's language, after **every** payment credited to an invoice (partial or full), whether paid by card or recorded manually. A partial payment gets the confirmation with the remaining amount; the final payment gets the "paid in full" wording.
- **D-06:** The confirmation has no attachment. It states the invoice number, the amount paid, the date and, for a partial payment, the remaining amount.

**Review of other e-mails**
- **D-07:** The partner invitation, password reset, task notifications and operational alerts move to the shared layout and their subjects, texts and CZ/EN translations are reviewed and corrected in the same pass.

### Claude's Discretion
- Theme colours, typography and button style (consistent with the Filament panel look).
- How the logo is delivered to mail clients (inline attachment, hosted URL) and how the layout degrades when no logo is set.
- The exact trigger point and de-duplication of the confirmation (e.g. a webhook retry or a corrected payment must not send a second e-mail).

### Deferred Ideas (OUT OF SCOPE)
None. Discussion stayed within phase scope. (Scope note from CONTEXT: e-mails only; in-app database notifications are out of scope. Planning must decide whether D-05/D-06 are delivered in "Phase 10.2" or here — resolved below: Phase 10.2 no longer exists, see Summary.)
</user_constraints>

<phase_requirements>
## Phase Requirements

The roadmap lists `Requirements: TBD` and no IDs were mapped. Phase 14 used phase-local IDs (`P14-01`...). The planner should do the same; the table below is a proposed set that covers every decision, so each plan can cite one.

| ID (proposed) | Description | Research Support |
|----|-------------|------------------|
| P13-01 | One published Markdown mail theme (layout, header, footer, button, text variants, CSS) used by every e-mail | Standard Stack, Pattern 1 |
| P13-02 | Header = supplier name + logo, footer = supplier details, read from `SupplierSettings` in a system context; logo embedded inline (cid), layout degrades without a logo | Pattern 2, Pitfalls 1, 2, 3 |
| P13-03 | Invoice e-mail: HTML layout, Admin text escaped (never parsed as Markdown), "Pay by card" button gated by `PayLinkBlock`, plain-text alternative kept, document language | Pattern 3, Pitfall 4 |
| P13-04 | Payment confirmation e-mail content: number, amount, date, remaining amount for a partial payment, "paid in full" wording for the last one, no attachment, document language | Pattern 4 |
| P13-05 | Payment confirmation trigger and de-duplication: one e-mail per payment row, after every manual or card payment, never for a carried proforma payment, never locks the invoice | Pattern 5, Pitfalls 6, 7 |
| P13-06 | Partner invitation on the layout, text reviewed | Pattern 6 |
| P13-07 | Password reset (Filament `ResetPassword`) on the layout, text reviewed | Pattern 6, Pitfall 9 |
| P13-08 | Task notifications on the layout, text reviewed, per-recipient preferences untouched | Pattern 6 |
| P13-09 | Operational alerts on the layout, same branding, text reviewed, values made inert in Markdown | Pattern 6, Pitfall 5 |
| P13-10 | CZ/EN: new payment e-mail texts in both languages with a key-parity test; layout strings follow the mail locale | Pitfall 8 |
| P13-11 | Hygiene and security: no secret or real value in fixtures, no new public route, no Partner data leak, escaping proven by canary tests | Security Domain |
</phase_requirements>

## Project Constraints (from CLAUDE.md)

Source: `.claude/CLAUDE.md` (read this session).

- **Fictional data only** in code, tests, fixtures, docs, `.planning/` and commit messages: `example.com` addresses, company ID `12345678`, paths like `/Users/example/`; test fakes assembled at runtime from fragments.
- Before every commit: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never bypass the lefthook pre-commit hook.
- PHP 8.5, Laravel (v13.38.0 installed), Filament 5 (SPA), PostgreSQL, UUID v7 keys, FK/unique/check constraints in the database.
- **Partner must never see** measured time, rates, prices or finance, nor another client's data; enforced by Policies and global scopes, not UI hiding.
- Immutability: an issued invoice stays editable until a successful **invoice e-mail** send locks it; only `SendInvoiceEmail` may lock (CONTRIBUTING, Editable invoices). A payment confirmation must never lock.
- Everything under `.planning/` in English. Tests run in DDEV (`ddev exec vendor/bin/pest`, `pint`, `phpstan`); the host has no DB (user memory).
- GSD workflow: file edits happen through a GSD command (this research is part of `/gsd-plan-phase`).
- No new paid or closed packages; all dependencies AGPL-compatible (this phase needs no new package).

## Summary

**No new package is needed.** Everything required is already in `laravel/framework` v13.38.0 (Markdown mail, `tijsverkoyen/css-to-inline-styles`, `league/commonmark`) and `symfony/mime` v8.1.7. The work is: publish the mail and notification views, replace the theme CSS/header/footer, add one `MailBrand` reader for the supplier data, make the invoice e-mail a Markdown Mailable, add the new payment confirmation (a small queued job with its own log table), and move the four `MailMessage` e-mails onto the theme with a text review. [VERIFIED: vendor/laravel/framework/src/Illuminate/Mail/MailServiceProvider.php:44-47 and NotificationServiceProvider.php:19-24 publish tags `laravel-mail` and `laravel-notifications`]

**Phase 10.2 no longer exists**, so the CONTEXT question "deliver D-05/D-06 in 10.2 or here" is already answered: `STATE.md:893` says "Phase 10.2 removed: the paid-invoice confirmation e-mail is delivered in Phase 13" and ROADMAP Phase 13 says it "absorbs the former Phase 10.2". Build the confirmation once, here.

**Four findings change the plan more than anything else:**
1. **Supplier settings fail closed outside the system context.** `SettingsProperty` uses `DeniesPartners`; "without a signed-in user the same read fails closed" (docblock of `tests/Support/Probes/SettingsReadingProbeJob.php`). Queued notifications (invitation, reset, tasks) are not `KokpitJob`s and do not run through `RunsAsSystem`, so a layout that reads `SupplierSettings` directly breaks in the worker. One `MailBrand` reader must wrap the read in `PartnerContext::runAsSystem()`.
2. **The theme's Markdown parse mangles Admin text.** `layout.blade.php` runs `Markdown::parse($slot)`; a plain-text body would lose line breaks and turn `- x`, `# x`, `*x*` into markup. The body must be emitted as pre-escaped HTML paragraph blocks, which CommonMark passes through untouched (verified by running it, see Pattern 3).
3. **Payments are immutable and the invoice must not be touched**, so the "already confirmed" marker cannot be a column on `invoice_payments` or `invoices` (DB triggers refuse updates). The de-duplication needs its own small table with a unique key on the payment id, written in the same transaction as the payment. Do **not** reuse `invoice_emails`: its state drives the lock, the "e-mail in flight" edit rule and the Odeslané tab.
4. **Logo delivery:** there is no public URL for the logo by design (DocumentImage docblock, T-10-07) and the route audit of Phase 12 wants an explicit public-route allowlist. Embed the logo as an inline `cid:` part with a `MessageSending` listener; no route, no hosted URL, works in Mailpit. Downscale it first (stored logos may be up to 1 MB / 4000 px).

**Primary recommendation:** Publish the mail + notification views, add `kokpit` theme CSS, build `MailBrand` (system-context read, memoised per send) and a `MessageSending` listener that embeds the logo as `cid:kokpit-logo.png`; convert `InvoiceMail` to `Content(markdown:, text:)` keeping the existing text view as the plain-text part; add `payment_confirmations` + `SendPaymentConfirmation` job dispatched `afterCommit` from `RecordPayment::recordLocked()`; move the four `MailMessage` e-mails to the theme with a shared Markdown-escape helper and reviewed `kokpit.*` texts.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Layout/theme (header, footer, button, CSS) | Backend views (`resources/views/vendor/mail`, `vendor/notifications`) | Config (`mail.markdown`) | Rendered server side by Laravel's Markdown mailer; no browser tier involved |
| Supplier branding data (name, address, contact, logo bytes) | Backend, Settings (`SupplierSettings`, `DocumentImage`) read through `MailBrand` | — | Settings are Admin/system data; the read must run in system context |
| Logo delivery to the mail client | Backend mail transport layer (`MessageSending` listener, inline MIME part) | — | A hosted URL would need a public route and a public object; cid needs neither |
| Invoice e-mail rendering | Backend Mailable (`InvoiceMail`) inside queued `SendInvoiceEmail` | Domain (`PayLinkBlock` predicate) | Existing pattern: unqueued Mailable inside the queued job so transport failures surface in the job |
| Payment confirmation trigger | Domain Action (`RecordPayment::recordLocked`, the one write core of every payment) | Queue job | Both manual and card payments pass through this method; `carry()` does not |
| Confirmation de-duplication / log | Database (unique key on `invoice_payment_id`) | Domain model | Project rule: idempotence is enforced by DB unique constraints, not only code |
| Other e-mails (invitation, reset, task, alert) | Backend Notification classes | Translation files `lang/cs/kokpit.php`, `lang/cs.json` | They already build `MailMessage`; the theme restyles them |
| Browser/CDN tiers | not involved | — | No route, asset or client-side code is added |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | v13.38.0 [VERIFIED: vendor/composer/installed.json] | Markdown mail (`Illuminate\Mail\Markdown`), `MailMessage`, `Mailable`, `Content`, `MessageSending` event | D-02 fixes the Laravel mail theme |
| tijsverkoyen/css-to-inline-styles | already required by laravel/framework [VERIFIED: vendor/laravel/framework/src/Illuminate/Mail/Markdown.php:14,125] | Inlines the theme CSS into the HTML at render | Built into `Markdown::render()` |
| league/commonmark | already required by laravel/framework [VERIFIED: Markdown.php:11-13] | Parses the Markdown slot in `layout.blade.php` | Built in; used to pass HTML blocks through |
| symfony/mime | v8.1.7 [VERIFIED: vendor/composer/installed.json] | Inline `cid:` parts (`Email::embed()`) | Laravel's mail transport |
| spatie/laravel-settings | ^3.9 [VERIFIED: composer.json] | `SupplierSettings` (name, address, company ID, contacts, `logo_path`) | Already the source of the supplier data |

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| ext-gd | present (used by `DocumentImage`) [VERIFIED: `php -m` lists gd; `app/Domain/Settings/DocumentImage.php` uses GD] | Downscale the logo for e-mail | Once per send, in `MailBrand` |
| Mailpit (DDEV, port 8025) | DDEV v1.25.4 web service lists `web:8025` [VERIFIED: `ddev describe`] | Visual check of the HTML and the inline logo | Manual/human verification |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Inline `cid:` logo | Data URI in `<img>` | Gmail and others strip `data:` images; cid is the reliable route [ASSUMED: general mail-client behaviour] |
| Inline `cid:` logo | Hosted signed URL | Needs a new public route (Phase 12 route allowlist) and a publicly readable object; rejected by T-10-07 |
| New log table for the confirmation | Cache lock or at-least-once like `SendInvoiceEmail` | Cache is not durable; at-least-once can mail twice after a worker death. The table follows the project rule "DB unique constraint is the last line of defence" |
| Subclass of Filament `ResetPassword` | `ResetPassword::toMailUsing()` callback | The callback gets `($notifiable, $token)` but not the URL (`$notification->url` is set by the call sites); the queued worker has no current Filament panel to rebuild it |
| Custom theme `kokpit.css` | Edit `default.css` in place | Equivalent; a named theme keeps `default.css` pristine for upstream diffs |

**Installation:** none. Publish the views:
```bash
ddev exec php artisan vendor:publish --tag=laravel-mail
ddev exec php artisan vendor:publish --tag=laravel-notifications
```
Result: `resources/views/vendor/mail/{html,text}/*.blade.php`, `resources/views/vendor/mail/html/themes/default.css`, `resources/views/vendor/notifications/email.blade.php`. [VERIFIED: the two ServiceProvider `publishes()` calls cited above; framework view tree listed this session: html/{button,footer,header,layout,message,panel,subcopy,table}.blade.php, html/themes/default.css, text/*.blade.php]

**Version verification:** no package is added, so no registry lookup applies. `config('mail.markdown')` in DDEV returns `{"theme":"default","paths":["/var/www/html/resources/views/vendor/mail"],"extensions":[]}`: the published directory is already on the component path, and `config/mail.php` has no `markdown` key (the framework default is merged). [VERIFIED: `ddev exec php artisan tinker`]

## Package Legitimacy Audit

No external package is installed or recommended in this phase. `gsd-tools query package-legitimacy check` was therefore not run.

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| (none) | — | — | — | — | — | — |

**Packages removed due to [SLOP] verdict:** none
**Packages flagged as suspicious [SUS]:** none

## Architecture Patterns

### System Architecture Diagram

```
 TRIGGERS                          BUILD                                   RENDER                               DELIVERY
 ---------                         -----                                   ------                               --------
 Admin sends invoice  --> QueueInvoiceEmail --> SendInvoiceEmail (KokpitJob, system ctx)
                                                   | Mail::to(recipient)->send(InvoiceMail)   [not queued]
                                                   v
 Admin records payment --> RecordPayment::handle -+                         InvoiceMail / PaymentConfirmationMail
 Stripe webhook job   --> RecordCardPayment -------+--> recordLocked()          (Content markdown: + text:)
                                                        | insert invoice_payment            |
                                                        | insert payment_confirmations      |  Markdown::render()
                                                        |   (unique invoice_payment_id,     |    view -> x-mail::message
                                                        |    remaining_minor snapshot)      |    -> layout (Markdown::parse slot)
                                                        | SendPaymentConfirmation           |    -> CssToInlineStyles(theme kokpit)
                                                        |   ::dispatch()->afterCommit()     |
                                                        v                                    |  header/footer read MailBrand
 Admin invites Partner --> PartnerInvitation  (queued Notification)  -----------+            |  (runAsSystem, memoised)
 Partner/Admin reset   --> ResetPassword subclass (queued Notification) --------+--> MailChannel --> notifications::email
 Task event            --> TaskNotification (queued, preferences) ---------------+            |  (published, same x-mail::message)
 Failed job / alert    --> AdminAlerter -> OperationalAlert (sendNow) -----------+            v
                                                                                     Illuminate\Mail\Mailer::send
                                                                                        | MessageSending event
                                                                                        |   EmbedMailLogo listener:
                                                                                        |   html contains "cid:kokpit-logo.png"?
                                                                                        |   -> Email::embed(logo PNG, name, image/png)
                                                                                        v
                                                                                     SMTP/SES transport  (array transport in tests, Mailpit in DDEV)
```

Branching points: no recipient (client without invoice e-mail) -> confirmation row `skipped`, no send; card link not offered (`PayLinkBlock::applies()` false) -> no button; no logo configured or object unreadable -> text header only, no `cid:` emitted, listener does nothing; partial vs final payment -> different subject/wording from `remaining_minor`.

### Recommended Project Structure

```
resources/views/vendor/mail/html/            # published, then customised: layout, message, header, footer, button, subcopy, panel, table
resources/views/vendor/mail/html/themes/kokpit.css
resources/views/vendor/mail/text/            # published, header/footer customised for the plain-text part
resources/views/vendor/notifications/email.blade.php   # published, salutation/greeting defaults reviewed
resources/views/mail/invoice.blade.php               # NEW markdown view of InvoiceMail
resources/views/mail/invoice-text.blade.php          # EXISTING, stays as the plain-text part (body + pay block)
resources/views/mail/payment-confirmation.blade.php  # NEW markdown view
resources/views/mail/payment-confirmation-text.blade.php  # NEW plain-text part
app/Domain/Notifications/Mail/
├── MailBrand.php          # readonly DTO + current(): supplier name/lines/logo, read via runAsSystem, memoised per scoped instance
├── EmbedMailLogo.php      # MessageSending listener (registered explicitly in a provider)
├── MailLogo.php           # reads DocumentImage bytes, downscales with GD (max ~360 px wide), returns PNG bytes or null
├── PlainTextHtml.php      # escaped paragraph/<br> HTML from a plain-text body
└── MailText.php           # escapeMarkdown() moved out of TaskNotification (shared)
app/Domain/Invoicing/
├── Mail/InvoiceMail.php                    # becomes Content(markdown: 'mail.invoice', text: 'mail.invoice-text')
├── Mail/PaymentConfirmationMail.php        # NEW
├── Jobs/SendPaymentConfirmation.php        # NEW KokpitJob, #[Idempotent]
├── Models/PaymentConfirmation.php          # NEW, DeniesPartners
└── Enums/PaymentConfirmationState.php      # queued, sending, sent, failed, skipped
app/Domain/Identity/Notifications/ResetPasswordNotification.php   # extends Filament\Auth\Notifications\ResetPassword
database/migrations/2026_10_25_000100_create_payment_confirmations_table.php
lang/cs/payment_mail.php + lang/en/payment_mail.php   # parity-tested, like invoice_mail.php
```
(The exact folder names are a suggestion; the project convention is `app/Domain/<Domain>/...`.)

### Pattern 1: One theme, three consumers

**What:** Publish the views, set the theme once, and let `Content(markdown:)` Mailables and `MailMessage` notifications render through the same `x-mail::message` component.
**When to use:** all e-mails of the phase.
**Verified mechanics:**
- `Markdown::render()` resolves the theme as view `mail.<theme>` if it exists, else `mail::themes.<theme>` (the CSS file), inlines it with `CssToInlineStyles`. [VERIFIED: Markdown.php:110-126]
- A Markdown Mailable with `markdown` set but also an explicit text view uses the text view: `return $this->textView ?? $this->markdownRenderer()->renderText(...)`. [VERIFIED: vendor/laravel/framework/src/Illuminate/Mail/Mailable.php:424-440] So `new Content(markdown: 'mail.invoice', text: 'mail.invoice-text')` keeps today's plain-text part byte for byte (the existing `CardPaymentJourneyTest.php:205` assertion on the text body then keeps passing).
- `MailChannel` renders notifications through the same `Markdown` renderer with `array_merge($data, $message->data())`, so the published `notifications::email` view and `x-mail::message` are shared. [VERIFIED: Notifications/Channels/MailChannel.php:112-128]
- `Mailer::send` fires `MessageSending` with the Symfony `Email` after the content is built, and a listener may mutate it. [VERIFIED: Mail/Mailer.php:602-610, Mail/Events/MessageSending.php:15-17]

**Theme choice (Claude's discretion):** the panel primary is `Color::Amber` [VERIFIED: app/Providers/Filament/AdminPanelProvider.php:149-150]. Use an amber accent for the button with **dark text** (white on amber-500 fails contrast; amber-500 `#f59e0b` with zinc-900 `#18181b` computes to roughly 8:1) [ASSUMED: contrast computed by hand, not by a tool], a system font stack (web fonts are unreliable in mail), neutral zinc greys for text, one 570 px column. Keep `button-success` and `button-error` colours for the `level` mapping already in `notifications::email` (alerts use `->error()`).
Config: add the explicit block to `config/mail.php`:
```php
'markdown' => [
    'theme' => 'kokpit',
    'paths' => [resource_path('views/vendor/mail')],
    'extensions' => [],
],
```
(Write all three keys: the observed framework default is shown above, so the explicit block cannot lose the path.)

### Pattern 2: `MailBrand` and the inline logo

**What:** a single reader the published `message.blade.php`, `header`, `footer` (html and text) call. It returns name, address lines, company ID, contacts, optional logo bytes. It wraps the settings read in `app(PartnerContext::class)->runAsSystem(...)` (the same call `AdminAlerter::alert` and the `RunsAsSystem` middleware use) and memoises per scoped instance (reset between jobs/requests, see PartnerContext docblock).
**Logo:** the layout emits `<img src="cid:kokpit-logo.png" alt="{{ name }}" width="...">` only when `MailBrand` has logo bytes. `EmbedMailLogo` (listener on `MessageSending`) checks `str_contains($event->message->getHtmlBody(), 'cid:kokpit-logo.png')` and calls `$event->message->embed($pngBytes, 'kokpit-logo.png', 'image/png')`. Symfony resolves the `cid:NAME` reference to the part of that name, makes it a `multipart/related` inline part and rewrites the reference. [VERIFIED: vendor/symfony/mime/Email.php:464-507; confirmed by running it in DDEV: output had `multipart/related`, `Content-Disposition: inline`, and `src="cid:<hash>@symfony`]
**Reading the bytes:** `DocumentImage::bytes()` is private; `dataUri(path, sha256)` is public but requires the hash. Add one public method (e.g. `contents(string $path): ?string`) to `DocumentImage`, reusing its path-pattern check, instead of reading the disk elsewhere. [VERIFIED: app/Domain/Settings/DocumentImage.php `private function bytes`, `public function dataUri`]
**Downscale:** stored logos are PNG up to 1 MB / 4000 px [VERIFIED: DocumentImage::MAX_BYTES = 1048576, MAX_DIMENSION = 4000]. Resize with GD (`imagescale`, keep alpha) to a small width and fix `width`/`height` attributes; do this once per send.

### Pattern 3: Invoice e-mail, Admin text, and the button

**What:** `InvoiceMail` gets `Content(markdown: 'mail.invoice', text: 'mail.invoice-text')` and `->locale($invoice->language->value)` (so the layout strings follow the document language; `Mailable::send()` and `render()` wrap in `withLocale`). [VERIFIED: Mailable.php:204,313,597-604]
**Body rendering (D-01):** do not echo the body into the Markdown slot. Generate escaped HTML paragraph blocks and echo them raw:
```php
// Source: verified by running Markdown::parse() in DDEV this session (see below)
final class PlainTextHtml
{
    public static function paragraphs(string $text): HtmlString
    {
        $paragraphs = preg_split('/\n{2,}/', str_replace(["\r\n", "\r"], "\n", trim($text))) ?: [];

        $html = array_map(
            static fn (string $p): string => '<p>'.implode("<br>\n", array_map(e(...), explode("\n", $p))).'</p>',
            $paragraphs,
        );

        return new HtmlString(implode("\n\n", $html));   // blank line between blocks, no indentation
    }
}
```
```blade
{{-- resources/views/mail/invoice.blade.php: NO indentation of the paragraphs (4 spaces = code block) --}}
<x-mail::message>
{!! \App\Domain\Notifications\Mail\PlainTextHtml::paragraphs($plainText) !!}

@isset($payUrl)
<x-mail::button :url="$payUrl">
{{ $payButton }}
</x-mail::button>
@endisset
</x-mail::message>
```
Evidence: running `Markdown::parse()` on `<p>Dobry den,<br>\n*not italic* &amp; &lt;b&gt;x&lt;/b&gt; [l](http://a.example.com)</p>\n\n<p>- item<br>\n# head<br>\n1. one\_x</p>` in DDEV returned both blocks unchanged (the asterisks, link syntax, `- item`, `# head`, `1.` and the backslash stayed literal), while a following plain line was parsed as Markdown. [VERIFIED: DDEV run, `Illuminate\Mail\Markdown::parse`] CommonMark treats a line starting `<p` as an HTML block that ends at a blank line, so paragraphs must be separated by blank lines and contain none.
**Button:** `PayLinkBlock::url()` is private; add a public sibling (e.g. `forButton(Invoice): ?string`) that returns the same URL under the same predicate, and keep `applies()` / `forMail()` as the single gate. The class docblock states this hand-over: "must keep `applies()` / `forMail()` as its one predicate and must not read `pay_token` itself" (`PayLinkBlock.php:21-24`; CONTRIBUTING repeats it). `SendInvoiceEmail::bodyToSend()` keeps feeding the text part; add the URL and the localized button label as separate constructor values for the HTML part. The button copy is a new key in `invoice_mail` (cs: e.g. "Zaplatit kartou", en: "Pay by card"); `pay_block_lead` stays as the lead sentence above the button and in the text part.
**Subcopy:** keep a fallback line with the plain URL below the button (as `notifications::email` does), because some clients block buttons.

### Pattern 4: Payment confirmation content

Mailable `PaymentConfirmationMail(string $number, string $kind, string $amount, string $date, ?string $remaining, bool $paidInFull, string $locale)`, markdown + explicit text view, no attachments, subject from `lang/{cs,en}/payment_mail.php`. Formatting reuses what `QueueInvoiceEmail::defaultBody()` does: amount `Money::ofMinor(...)->format($language->value)`, date `j. n. Y` for Czech and `Y-m-d` for English, kind noun from `invoice_mail.kind.*`. [VERIFIED: app/Domain/Invoicing/Actions/QueueInvoiceEmail.php defaultBody]
Suggested wording (final text is the executor's, reviewed against the 10-UI-SPEC tone):
- cs full: subject `Potvrzení platby: :number`, line "děkujeme, přijali jsme vaši platbu :amount dne :date. Doklad č. :number je tímto zcela uhrazen."
- cs partial: subject `Potvrzení částečné platby: :number`, line "…přijali jsme vaši platbu :amount dne :date. Zbývá doplatit :remaining."
- en equivalents in `lang/en/payment_mail.php` with exactly the same keys.
Avoid inflecting the document kind in Czech (faktuře / zálohové faktuře) by phrasing around "doklad č. :number".

### Pattern 5: Trigger point and de-duplication

**Trigger:** `RecordPayment::recordLocked()` is the single write core for the manual path (`handle()`) and the card path (`RecordCardPayment::match()` calls `$this->payments->recordLocked(...)`). `carry()` (proforma payments moved to the new invoice) does **not** go through it. [VERIFIED: app/Domain/Invoicing/Actions/RecordPayment.php; RecordCardPayment.php `match()`] So hooking `recordLocked()` after `$payment->save()` and the income booking gives: every manual and card payment, no carried copy. Do not hook the webhook handler or the Filament action (two places, and the manual path would be missed or doubled).
**In the same transaction** (document row already locked by the caller): read `remaining` from `InvoiceBalances::for([id])` (it already includes the payment just saved because it queries the database inside the transaction; it is clamped at 0, and it already subtracts credit notes) and insert one `payment_confirmations` row (`queued`, `remaining_minor`, language, recipient). Then `SendPaymentConfirmation::dispatch($confirmationId)->afterCommit()` (same idiom as `SendInvoiceEmail::dispatch($row->id)->afterCommit()` in `QueueInvoiceEmail::handle`). A rollback removes both, so a rolled-back match mails nobody.
**Why compute the remaining amount at record time:** if two payments land close together, a remaining amount computed when the job runs would describe a later state than the payment it confirms. The snapshot makes each e-mail truthful.
**Table (suggested):** `id uuidv7`, `invoice_payment_id uuid NOT NULL` with `UNIQUE` and FK `ON DELETE CASCADE` (a deleted mistaken payment drops its row; a corrected re-entry is a new payment and gets its own confirmation), `invoice_id` FK, `recipient` nullable, `language char(2)` check cs/en, `remaining_minor bigint` check >= 0, `state` check in (queued, sending, sent, failed, skipped), `attempts`, `failure_code`, `sent_at timestamptz`, `timestampsTz`. Conventions: `uuid('id')->primary()->default(DB::raw('uuidv7()'))`, `timestampsTz()`, check constraints via `DB::statement` as in `2026_10_18_000500_create_card_payments_table.php`. [VERIFIED: that migration read this session]
**Model:** `final class PaymentConfirmation extends KokpitModel implements PartnerIsolated` with `use DeniesPartners` (Admin-only, like `InvoiceEmail`), a morph-map alias in `App\Domain\Shared\Database\MorphMap`, entries in `tests/Arch/ModelDeclarationTest.php` (list at line 76 and the `toContain` at line 146) and `tests/Isolation/CanaryRegistryTest.php`. [VERIFIED: grep results this session]
**Job:** `final class SendPaymentConfirmation extends KokpitJob` with `#[Idempotent(how: '...')]`; claim by conditional update `queued -> sending` (the `SendInvoiceEmail::claim()` pattern, but it does **not** need the invoice-row lock because it writes nothing to the invoice), re-validate the recipient with `email:rfc`, `Mail::to($recipient)->send(new PaymentConfirmationMail(...))` unqueued inside the job, mark `sent`. A final failure marks `failed` with a fixed class; `KokpitJob` already raises the generic failed-job Admin alert through `ReportFailedJob`. [VERIFIED: KokpitJob docblock] Add the class to `EXPECTED_APPLICATION_JOBS` in `tests/Arch/JobContractTest.php:46`.
**No recipient:** the client has no `invoice_email` (`QueueInvoiceEmail::defaults()` treats it as null) -> store the row as `skipped` with no mail; nothing blocks the payment.
**Must not lock:** the job must not call anything in `SendInvoiceEmail::markSentAndLock`; `tests/Arch/InvoiceUnlockPathTest.php` already keeps the lock write out of every other file (allowlist line 63). CONTRIBUTING (Editable invoices) states it in words: "a new e-mail kind (the payment confirmation of Phase 13 first) must not call the lock step".
**Webhook retry:** a repeated Stripe event never reaches `recordLocked()` because `CardPayment::recordOnce()` claims the event/session/intent first (`RecordCardPayment` docblock), so no second payment row and no second confirmation. A duplicate/unmatched card payment records no payment and therefore sends no confirmation.

### Pattern 6: The four `MailMessage` e-mails

- **PartnerInvitation:** add `->salutation()`-free flow, keep greeting/intro/action/expiry/outro; escape `inviteeName` and `clientName` with the shared `MailText::escapeMarkdown()` (Admin-typed values go through Markdown today). Review text: the copy mixes a singular salutation with a plural-polite verb ("Dobrý den, :name," then "byli jste pozváni"); pick one form.
- **Password reset:** two call sites build the notification with `app(ResetPasswordNotification::class, ['token' => $token])` and set `$notification->url`: `app/Filament/Pages/Auth/RequestPasswordReset.php:56` and `app/Domain/Clients/Actions/SendPartnerPasswordReset.php:50`. Replace both with a subclass of `Filament\Auth\Notifications\ResetPassword` that overrides `protected function buildMailMessage($url)` (the framework method that receives the URL) with `kokpit.password_reset.mail.*` texts and a greeting. [VERIFIED: vendor/laravel/framework/src/Illuminate/Auth/Notifications/ResetPassword.php buildMailMessage/resetUrl; Filament subclass sets `public string $url` and overrides `resetUrl`] The existing test `PartnerPasswordResetTest.php` uses `Notification::sent($user, ResetPasswordNotification::class)`; the fake keys sent notifications by concrete class [ASSUMED: NotificationFake storage keyed by get_class], so update those lookups to the new class. Do not edit `lang/cs.json` for these strings: it is the laravel-lang file and `lang:update` can overwrite hand edits [ASSUMED: laravel-lang/common is a dev dependency in composer.json; overwrite behaviour not run].
- **TaskNotification:** already builds `greeting/line/action/line`; the escape helper moves to `MailText` (the private static `escapeMarkdown` in `TaskNotification` is the model). Review `kokpit.tasks.notifications.*` texts; preferences logic in `via()` stays untouched.
- **OperationalAlert:** `->error()` stays (red button). Body lines are currently unescaped (`$message->line($line)`): exception class names and messages contain `\`, `_`, `*`; pass them through `MailText::escapeMarkdown()`. D-04: no separate Admin layout, only content differs; use an explicit salutation (for example the application name) so the default "Regards, {app name}" does not appear twice.
- **Salutation default:** `notifications::email` prints `@lang('Regards,')<br>{{ config('app.name') }}`; with the supplier name in the header and footer, change the default salutation to the supplier name or drop it, in the published view once.

### Anti-Patterns to Avoid

- **Reading `SupplierSettings` directly in a Blade view or notification:** fails closed in a worker or for a Partner-triggered render (Pitfall 1).
- **Passing the Admin body through `{{ $plainText }}` into the Markdown slot:** collapses line breaks and interprets Markdown (Pitfall 4).
- **Reusing `invoice_emails` for the confirmation:** drags in the lock, the in-flight edit rule and the tab state (Summary, finding 3).
- **A `sent_at`/`confirmed` column on `invoice_payments` or `invoices`:** the database refuses updates to both ("the database refuses every update", InvoicePayment docblock; issued `invoices` rows refuse even `updated_at` writes).
- **Hosted logo URL or a new "mail asset" route:** adds a public route to the Phase 12 audit and exposes the stored object.
- **Dispatching the confirmation from the Filament action or the webhook handler:** two places, easy to miss one; use `recordLocked()`.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTML mail layout and CSS inlining | Own table layout and inliner | Published Laravel mail views + `CssToInlineStyles` already in `Markdown::render()` | D-02; battle-tested table markup |
| Button, panel, subcopy | Own button HTML | `x-mail::button`, `x-mail::panel` | Responsive rules already in `layout.blade.php` |
| Inline image MIME handling | Manual multipart building | `Symfony\Component\Mime\Email::embed($bytes, $name, $type)` | Resolves `cid:NAME` and builds `multipart/related` (verified) |
| Plain-text alternative | A second renderer | The existing `mail.invoice-text` view as `Content(text:)`; `renderText` + `text/` components for notifications | Laravel builds multipart/alternative |
| De-duplication of the confirmation | Cache flags, "already sent" booleans on other tables | Unique key + conditional claim update (pattern of `SendInvoiceEmail::claim`, `CardPayment::recordOnce`) | Project rule: DB constraints are the last line of defence |
| Payment balances | New SQL for "remaining" | `InvoiceBalances::for()` | Single definition used by every screen; already subtracts credit notes and clamps at 0 |
| Money and date formatting | `number_format`, manual dates | `Money::format($locale)` and the date formats of `QueueInvoiceEmail::defaultBody()` | Consistent with the invoice e-mail |
| Image type/size validation of the logo | New upload code | Nothing: `DocumentImage` already re-encodes to PNG with caps; only add a read accessor and a GD downscale | Hardened path (T-10-05) |
| Markdown escaping of untrusted values | A second escaper | Move `TaskNotification::escapeMarkdown` to `MailText` and reuse | Already covered by `NotificationMarkupTest` canaries |

**Key insight:** this phase is almost entirely wiring of things the project and the framework already provide. The only genuinely new moving parts are `MailBrand`, the logo listener, the paragraph helper and the confirmation table/job; each has a verified model to copy.

## Runtime State Inventory

Not a rename/refactor/migration phase. No stored string is renamed. One data change exists and is additive: a new table `payment_confirmations`. No existing payment gets a back-filled confirmation (no historical e-mails are sent). For completeness:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None renamed. New table only. | Migration (additive) |
| Live service config | None — mail transport and `MAIL_FROM_*` unchanged (`.env.example:60-64`) | None |
| OS-registered state | None | None |
| Secrets/env vars | None added | None |
| Build artifacts | Compiled Blade cache for the published views | `view:clear` on deploy if the deploy script does not already (not verified) |

## Common Pitfalls

### Pitfall 1: Supplier settings fail closed outside the system context
**What goes wrong:** the header/footer show nothing, or the render throws, in a queued notification or when a Partner triggers a task notification under the `sync` queue (tests use `QUEUE_CONNECTION=sync`, `phpunit.xml:48`).
**Why it happens:** `SettingsProperty` is `PartnerIsolated` with `DeniesPartners` and `PARTNER_VISIBLE_GROUPS = []`; the supplier group is Admin/system only. `KokpitJob` jobs get `RunsAsSystem`; Laravel notifications queued by `ShouldQueue` do not. [VERIFIED: app/Domain/Shared/Models/SettingsProperty.php, tests/Support/Probes/SettingsReadingProbeJob.php docblock, KokpitJob.php]
**How to avoid:** one `MailBrand::current()` that wraps the read in `PartnerContext::runAsSystem()`, whitelists exactly the fields the layout prints (never bank settings), and is used by every view. Test it from a Partner context and with no user.
**Warning signs:** an invitation or task e-mail with an empty footer in the worker; a test that passes only when `actingAs(admin)`.

### Pitfall 2: The logo is large and sits in every message
**What goes wrong:** every e-mail carries up to 1 MB of image; some clients show it as an attachment icon.
**How to avoid:** downscale with GD to the displayed size (about 2x for retina), set explicit `width`; embed once; keep the text header when no logo.

### Pitfall 3: Dangling `cid:` when the logo cannot be read
**What goes wrong:** the layout emitted `cid:kokpit-logo.png` but the listener could not embed it, so the client shows a broken image.
**How to avoid:** make `MailBrand` the single decider: the view emits the `<img>` only if `MailBrand` holds the bytes, and the listener embeds the same memoised bytes. Test: logo path set but object missing -> no `<img>`, no inline part.

### Pitfall 4: Markdown eats the Admin's text (and 4-space indentation turns HTML into code)
**What goes wrong:** with `{{ $plainText }}` the single newlines of "Dobrý den,\n\nv příloze…\n\nS pozdravem\n:supplier" collapse; `- x`, `# x`, `1. x`, `*x*` become markup. Indenting the blade lines by 4 spaces inside `<x-mail::message>` makes CommonMark render a code block.
**How to avoid:** Pattern 3 (`PlainTextHtml`, flush-left, blank line between blocks). Write a test with a body that contains every Markdown trigger, assert the HTML contains the characters literally and no `<strong>`, `<em>`, `<h1>`, `<li>`, `<a href` other than the button.

### Pitfall 5: Markdown escaping leaves backslashes in the plain-text part
**What goes wrong:** `TaskNotification` escapes values with `\` for the Markdown HTML part; the auto-generated text alternative is built by `renderText()` (no Markdown parse, only `html_entity_decode`) and prints those backslashes. [VERIFIED: Markdown.php `renderText` body; TaskNotification `escapeMarkdown` pattern]. Possibly an existing behaviour for tasks; new escaping in the alert/invitation would add it there.
**How to avoid:** add a test that reads `getTextBody()` for a value with `*`/`_`; if backslashes show, give those notifications an explicit text part or escape for the HTML part only. Decide once in `MailText`.

### Pitfall 6: Extra mail in existing tests
**What goes wrong:** with `QUEUE_CONNECTION=sync` and `MAIL_MAILER=array` (`phpunit.xml:46,48`), every test that records a payment for a client with an invoice e-mail now also sends a confirmation. Tests that count messages or assert "exactly one mail to the client" after a payment may fail.
**How to avoid:** run the whole suite early; likely suspects are those that read `Mail::getSymfonyTransport()->messages()` after a payment: `CardPaymentJourneyTest`, `RefundEventTest`, `PaymentAlertsTest`, `CreatePaymentLinkTest`, `SendInvoiceEmailTest` (grep results: these files read the array transport). Filter by recipient/subject instead of counting.

### Pitfall 7: Confirmation must not behave like the invoice e-mail
**What goes wrong:** reusing `SendInvoiceEmail` internals locks the invoice or blocks editing while "in flight".
**How to avoid:** separate job and table; add an architecture assertion that `SendPaymentConfirmation` does not reference `InvoiceLockState` or `InvoiceAudit::locked`.

### Pitfall 8: Language of the layout strings
**What goes wrong:** the footer or "All rights reserved." appears in Czech inside an English invoice e-mail (the job runs under the app locale `cs`, `config/app.php:83`).
**How to avoid:** `->locale($invoice->language->value)` on both Mailables. Note the UI e-mails (invitation, reset, tasks, alerts) have **no English text at all**: `lang/en/` contains only `invoice_items`, `invoice_mail`, `invoice_pdf`, `pay_page`, `payment_links`, `work_report`; there is no `lang/en/kokpit.php` and `User` has no locale preference (grep for `preferredLocale`/`HasLocalePreference` found nothing). So D-07's "CZ/EN" is real work only for the new payment texts; for the four UI e-mails the review is Czech. Do not add English UI texts without a decision (Open Question 2).

### Pitfall 9: `Notification::sent` keyed by concrete class
Replacing the Filament reset notification with a subclass changes the key the fake stores; update `PartnerPasswordResetTest` (and any other `Notification::sent($user, ResetPasswordNotification::class)` lookups). [ASSUMED for the fake's keying]

### Pitfall 10: Blade echo format compiled into cached views
`Markdown::render()` compiles the views it renders with `EncodedHtmlString` as the echo format (`usingEchoFormat`). [VERIFIED: Markdown.php:81-107] Only render these views through the Markdown pipeline (Mailable `Content(markdown:)` or `MailMessage`), never via `view('mail.x')->render()` in application code; tests should use `$mailable->render()` / `->assertSeeInHtml()`.

### Pitfall 11: Mail-client rendering limits
Outlook's Word engine ignores `border-radius` and some `max-width` rules; Gmail clips HTML over about 102 KB [ASSUMED: general client behaviour]. Keep the markup small, no CSS variables, no web fonts. Final proof is a human look in real clients (see Validation Architecture, manual).

### Pitfall 12: Default invoice body text vs payment method (Phase 14 side effect)
`invoice_mail.body.with_due_date` says "Platební údaje najdete v přiloženém dokumentu." for every document, but since Phase 14 an online-method document prints no bank details. Not required by D-01..D-07 (the pre-filled text is editable), but it is a wrong sentence the review pass can fix (Open Question 4).

## Code Examples

### Layout header/footer reading the brand (published `message.blade.php`, sketch)
```blade
{{-- Source: structure from vendor/laravel/framework/src/Illuminate/Mail/resources/views/html/message.blade.php --}}
@php($brand = \App\Domain\Notifications\Mail\MailBrand::current())
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="$brand->website">
@if ($brand->hasLogo)
<img src="cid:kokpit-logo.png" alt="{{ $brand->name }}" width="{{ $brand->logoWidth }}">
@else
{{ $brand->name }}
@endif
</x-mail::header>
</x-slot:header>

{!! $slot !!}

@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

<x-slot:footer>
<x-mail::footer>
{{ $brand->name }}<br>
{{ $brand->addressLine }}<br>
{{ __('kokpit.mail.company_id') }} {{ $brand->companyId }} · {{ $brand->contactLine }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
```
Also rewrite the published `footer.blade.php` to print `{!! $slot !!}` (the stock footer runs `Markdown::parse($slot)`, which would reinterpret `*`/`_` in a company name or address). [VERIFIED: stock footer.blade.php uses `Illuminate\Mail\Markdown::parse($slot)`] The stock `header.blade.php` special-cases the literal text `Laravel`; the published one must drop that branch and not wrap a missing URL in a link.

### The listener
```php
// Source: composed from Illuminate\Mail\Events\MessageSending (public Email $message) and Symfony Email::embed()
final class EmbedMailLogo
{
    public function handle(MessageSending $event): void
    {
        $html = $event->message->getHtmlBody();

        if (! is_string($html) || ! str_contains($html, 'cid:'.MailBrand::LOGO_NAME)) {
            return;
        }

        $png = MailBrand::current()->logoBytes;   // same memoised bytes the view used

        if ($png !== null) {
            $event->message->embed($png, MailBrand::LOGO_NAME, 'image/png');
        }
    }
}
// Registration (explicit, domain folders are not auto-discovered): Event::listen(MessageSending::class, EmbedMailLogo::class);
```
Return nothing (a `false` return would cancel the send: `events->until(...) !== false`, Mailer.php:608).

### Trigger inside `RecordPayment::recordLocked()` (sketch)
```php
// after $payment->save(); bookIncome(...); $this->paymentLinks->reconcile($locked);
$this->confirmations->queueFor($locked, $payment);   // inserts the row (unique invoice_payment_id) and
                                                      // SendPaymentConfirmation::dispatch($row->id)->afterCommit()
```
Keep the new collaborator injected through the constructor like `PaymentLinks`; `RecordPayment` is `final readonly`.

### Test: rendering without a sender
```php
// Mailable assertions exist on Mailable itself (Mailable.php:1705 renders with withLocale)
$mail = new PaymentConfirmationMail(/* fictional values */);
$mail->assertSeeInHtml('12345678')->assertDontSeeInHtml('<strong>');
```
(Use the array transport for the end-to-end assertions: `Mail::getSymfonyTransport()->messages()` as `SendInvoiceEmailTest.php` does.)

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Plain-text invoice e-mail (`Content(text:)`) | Markdown Mailable with HTML + explicit text part | this phase | Tests asserting `getHtmlBody()` is null (`SendInvoiceEmailTest.php:266,768`) must change; the text assertions stay |
| Stock Laravel mail theme (app name, Laravel logo branch) | Published, supplier-branded theme | this phase | `header.blade.php` special case for the word "Laravel" is removed |
| Config `mail.markdown` absent from the app | Explicit block with the `kokpit` theme | this phase | Framework default merged before; now explicit |

**Deprecated/outdated:** none in this stack for the chosen approach. `Markdown::withSecuredEncoding()` exists (Markdown.php:289) as the framework's own hardening for user values in Markdown mail, but the app does not use it and `TaskNotification` has its own escaping tuned to the default mode; do not switch it on globally in this phase (it would change how existing escapes render). Record as a possible follow-up.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `data:` image URIs are stripped by common mail clients, so cid is preferable | Standard Stack | Low: cid is chosen anyway for the route-audit reason |
| A2 | Amber-500 with zinc-900 text has about 8:1 contrast (hand computation) | Pattern 1 | Low; verify with any contrast tool |
| A3 | `NotificationFake` keys sent notifications by concrete class, so a subclass needs test updates | Pattern 6, Pitfall 9 | Low: a test fails loudly and is fixed |
| A4 | `lang:update` of laravel-lang can overwrite hand edits to `lang/cs.json` | Pattern 6 | Low: the recommendation (own keys) is safe either way |
| A5 | Outlook ignores `border-radius`; Gmail clips HTML above about 102 KB | Pitfall 11 | Low: only affects polish |
| A6 | Mailpit's HTML check can score client support | Validation Architecture | Low: manual client check remains the proof |
| A7 | The owner wants a confirmation for **proforma** payments too (D-05 says "invoice") | Open Question 1 | Medium: sending or not sending to clients is user-visible behaviour |
| A8 | A client without an invoice e-mail simply gets no confirmation (row `skipped`) | Pattern 5 | Medium: the Admin may want a visible notice |
| A9 | Deploy runs `view:clear`/`optimize` so the published views take effect | Runtime State Inventory | Low |

## Open Questions

1. **Proforma payments: confirm them?**
   - What we know: D-05 says "every payment credited to an invoice". `recordLocked()` also records proforma payments (`kind->carriesPayment()` includes proforma). The invoice e-mail and `PayLinkBlock` treat invoice and proforma alike.
   - What's unclear: whether the owner means invoices only.
   - Recommendation: send for both kinds (an advance payment is exactly when a client wants an acknowledgement), never for credit notes, never for the carried copy; word the e-mail around "doklad č." so one text serves both. Confirm with the owner in plan review.

2. **English text for the UI e-mails?**
   - What we know: there is no `lang/en/kokpit.php` and no locale on users; only invoice-related texts have English.
   - What's unclear: D-07's "CZ/EN translations are reviewed" may assume English exists for them.
   - Recommendation: review Czech only for invitation, reset, tasks, alerts; English only for the new payment confirmation and the layout strings that follow the document language.

3. **Recording old payments mails the client.**
   - What we know: D-05 is locked: every payment, card or manual. An Admin back-filling an old payment by hand will trigger a confirmation dated in the past.
   - Recommendation: keep D-05 as locked, but show a line in the "Record payment" dialog that the client will get a confirmation (and a different line when the client has no invoice e-mail). Do not add an opt-out unless the owner asks.

4. **Default invoice body for online-method documents** (Pitfall 12): change the pre-filled text to point at the button instead of "payment details are in the attached document"? D-01 leaves subject and attachments alone, not the default text. Recommendation: yes, small key addition in `invoice_mail.body`, chosen by `payment_method`.

5. **Show a "Pay remaining by card" button in a partial-payment confirmation?**
   - What we know: `PayLinkBlock::applies()` would be true for a partially paid card-enabled invoice, and the stable pay URL does not wait for the Stripe link job.
   - D-06 lists the content without a button. Recommendation: do not add it in this phase (keep to D-06); note as a follow-up.

6. **Visibility of confirmations to the Admin.** A "Potvrzení odesláno" indication on the payments relation manager is cheap (`InvoicePaymentsRelationManager`) but not required. Recommendation: optional last task.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| DDEV (web, db, redis) | Tests, migrations, rendering experiments | ✓ | DDEV v1.25.4, PHP 8.5, PostgreSQL 18 | — |
| Mailpit (DDEV web:8025) | Visual check of HTML and inline logo | ✓ (port listed by `ddev describe`) | — | Array transport + saved `.eml` inspection |
| PHP ext-gd | Logo downscale | ✓ | host `php -m` lists gd; DDEV build uses it for `DocumentImage` already | Embed the stored PNG unscaled (not recommended) |
| Node / npm | Not needed (no front-end build) | ✓ (v24.22.1) | — | — |
| Real mail clients (Gmail, Outlook, Apple Mail, mobile) | Final visual proof | ✗ in this environment | — | Human check at end of phase (config `human_verify_mode: end-of-phase`) |

**Missing dependencies with no fallback:** none.
**Missing dependencies with fallback:** real mail clients → manual human verification step.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | Pest ^5.3 on PHPUnit ^13.3 [VERIFIED: composer.json] |
| Config file | `phpunit.xml` (`MAIL_MAILER=array`, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`), `tests/Pest.php` |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Mail tests/Feature/Invoicing/SendInvoiceEmailTest.php` (path of new tests is the planner's choice) |
| Full suite command | `ddev exec vendor/bin/pest` (also `composer lint` = pint --test, `composer stan` = phpstan) |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| P13-01 | Every e-mail renders through the `kokpit` theme (inlined CSS present, no stock "Laravel" branch) | feature | `ddev exec vendor/bin/pest tests/Feature/Mail/MailThemeTest.php` | ❌ Wave 0 |
| P13-02 | Header/footer show fictional supplier data from settings; render in a Partner context and with no user; logo present -> inline cid part, missing object or no logo -> text header, no `cid:`; logo downscaled | feature | `... MailBrandTest.php` | ❌ Wave 0 |
| P13-03 | Invoice mail: HTML + text parts; Markdown triggers in the body stay literal; button only when `PayLinkBlock::applies()`; no card -> no button; document language drives layout strings; attachments unchanged | feature | `... tests/Feature/Invoicing/SendInvoiceEmailTest.php` (update lines 266, 768) + new `InvoiceMailHtmlTest.php` | ✅ update / ❌ new |
| P13-04 | Confirmation content: full vs partial wording, remaining amount, date format cs/en, no attachment, overpayment shows "paid in full" | feature | `... PaymentConfirmationMailTest.php` | ❌ Wave 0 |
| P13-05 | One row and one mail per payment for manual and card; webhook replay sends none; carried proforma payment sends none; client without e-mail -> `skipped`; rollback -> no row; `DeletePayment` removes the row; the invoice stays unlocked | feature + concurrency | `... PaymentConfirmationTriggerTest.php`, extend `CardPaymentJourneyTest` | ❌ Wave 0 |
| P13-05 (DB) | Unique key refuses a second row for a payment; check constraints on state/remaining | feature (DB) | `... PaymentConfirmationSchemaTest.php` | ❌ Wave 0 |
| P13-06..09 | Each notification renders on the theme; values with Markdown/HTML triggers stay inert in HTML; texts exist; `NotificationMarkupTest` canaries still pass | feature + isolation | `... tests/Isolation/NotificationMarkupTest.php tests/Feature/Clients/PartnerInvitationTest.php tests/Feature/Clients/PartnerPasswordResetTest.php tests/Feature/Tasks/TaskNotificationsTest.php tests/Feature/Operations/AdminAlertTest.php` | ✅ update |
| P13-10 | `payment_mail` cs/en key parity (copy the check at `SendInvoiceEmailTest.php:824`); `LangEncodingTest` passes | unit | `... tests/Feature/Localisation/LangEncodingTest.php` + new parity test | ✅ / ❌ |
| P13-11 | Job contract list includes `SendPaymentConfirmation`; model declaration lists include `PaymentConfirmation`; no new route (route audit untouched); `scripts/check-sensitive.sh` clean | arch | `ddev exec vendor/bin/pest tests/Arch` and `scripts/check-sensitive.sh` | ✅ update |
| P13 (all) | Visual quality in real clients | manual | Mailpit + Gmail/Outlook/Apple Mail/mobile screenshots | manual-only (needs real clients) |

### Sampling Rate
- **Per task commit:** the targeted test files of that task (< 30 s), plus `ddev exec vendor/bin/pint --test` on touched files.
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature/Invoicing tests/Feature/Payments tests/Feature/Clients tests/Feature/Tasks tests/Feature/Operations tests/Isolation tests/Arch`.
- **Phase gate:** full suite green (`ddev exec vendor/bin/pest`), `composer lint`, `composer stan`, `scripts/check-sensitive.sh`, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Mail/MailThemeTest.php`, `MailBrandTest.php` — layout, brand, logo
- [ ] `tests/Feature/Mail/InvoiceMailHtmlTest.php` — escaping canaries, button gate, locale
- [ ] `tests/Feature/Invoicing/PaymentConfirmationMailTest.php`, `PaymentConfirmationTriggerTest.php`, `PaymentConfirmationSchemaTest.php`
- [ ] A shared fixture that stores a fictional logo PNG generated at runtime with GD through `DocumentImage::store()` (no binary file in git) and `InvoiceFixtures::configureSupplier()` (exists, `tests/Support/InvoiceFixtures.php:101`)
- [ ] Update: `SendInvoiceEmailTest.php` (HTML now present), `PartnerPasswordResetTest.php` (notification class), `JobContractTest.php`, `ModelDeclarationTest.php`, `CanaryRegistryTest.php`
- [ ] Framework install: none

## Security Domain

`security_enforcement` is enabled, ASVS level 1 (`.planning/config.json`).

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | yes (password reset e-mail) | Existing Filament broker; reset link/URL unchanged, only the wrapper text changes; rate limit and timebox stay |
| V3 Session Management | no | — |
| V4 Access Control | yes | `DeniesPartners` model for `payment_confirmations`; settings read only through `MailBrand` in system context, whitelisted fields; confirmation sent only to the client's own `invoice_email` |
| V5 Input Validation / Output Encoding | yes | `e()` per line in `PlainTextHtml`; `MailText::escapeMarkdown` for values in Markdown lines; recipient re-validated `email:rfc`; subjects built from numbers and fixed text (no CR/LF) |
| V6 Cryptography | no | — (no new secret; pay token untouched) |
| V8 Data Protection | yes | Confirmation carries number, amount, date only; no PDF, no pay token other than via the existing `PayLinkBlock` URL; row stores recipient but the log never stores an exception text or provider answer (copy the `SendInvoiceEmail` rule, T-10-65) |
| V13 API/Web service | no new endpoint | Do not add a route (cid, not a hosted logo) |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Markdown/HTML injection through Admin or Partner-typed text (client name, invitee name, task title, comment excerpt, alert text) | Tampering | Escape per target (HTML `e()` for raw HTML blocks, Markdown escape for lines); canary tests in the style of `tests/Isolation/NotificationMarkupTest.php` (DOM parse, check attributes) |
| Phishing link built from user text in an e-mail sent under the supplier's name | Spoofing | No Markdown parse of Admin body; only the button URL from `PayUrl` (host from `config('app.url')`, never the request) and fixed action URLs |
| Header injection via recipient or subject | Tampering | `cleanRecipient`/`cleanSubject` pattern (CR/LF stripped, `email:rfc`); confirmation subject is fixed text + generated number |
| Supplier or client data leaking to the wrong party | Information disclosure | Footer prints supplier data only (already on every invoice); confirmation goes only to `client->invoice_email`; never Partner-visible finance beyond what the invoice e-mail already carries |
| Settings read in the wrong context exposing Admin-only settings | Information disclosure | `MailBrand` whitelist; no bank accounts, no signature image, no other group |
| Double e-mail on retry (replay) | Repudiation/nuisance | Unique key on `invoice_payment_id` + conditional claim |
| Secrets/real data in fixtures | Information disclosure | Fictional-only rule; logo fixture generated at runtime; `scripts/check-sensitive.sh` |

## Sources

### Primary (HIGH confidence; read or run this session)
- `vendor/laravel/framework/src/Illuminate/Mail/{Markdown.php, Mailable.php, Mailer.php, MailServiceProvider.php, Events/MessageSending.php, Message.php}` — theme resolution, text view precedence, locale handling, `MessageSending`
- `.../Illuminate/Mail/resources/views/{html,text}/*.blade.php`, `.../Notifications/resources/views/email.blade.php`, `.../Notifications/Channels/MailChannel.php`, `.../Auth/Notifications/ResetPassword.php`
- `vendor/symfony/mime/Email.php`, `Part/DataPart.php` — cid resolution
- Project source: `app/Domain/Invoicing/{Mail/InvoiceMail.php, Jobs/SendInvoiceEmail.php, Actions/RecordPayment.php, Actions/QueueInvoiceEmail.php, Actions/DeletePayment.php, Models/InvoicePayment.php, Models/InvoiceEmail.php, Queries/InvoiceBalances.php}`, `app/Domain/Payments/{Actions/RecordCardPayment.php, Support/PayLinkBlock.php, Support/PayUrl.php}`, `app/Domain/Settings/{DocumentImage.php, Settings/SupplierSettings.php}`, `app/Domain/Clients/{Notifications/PartnerInvitation.php, InvitationMail.php, Actions/SendPartnerPasswordReset.php}`, `app/Filament/Pages/Auth/RequestPasswordReset.php`, `app/Domain/Tasks/Notifications/TaskNotification.php`, `app/Domain/Operations/{Alerts/OperationalAlert.php, Alerts/AdminAlerter.php, Jobs/KokpitJob.php}`, `app/Domain/Shared/{Auth/PartnerContext.php, Auth/DeniesPartners.php, Models/SettingsProperty.php, Models/KokpitModel.php}`, `config/mail.php`, `phpunit.xml`, `composer.json`, migration `2026_10_18_000500_create_card_payments_table.php`
- `lang/cs/{invoice_mail.php, kokpit.php}`, `lang/en/invoice_mail.php`, `lang/cs.json`
- `CONTRIBUTING.md` (Editable invoices, Payment method and bank accounts hand-overs), `.planning/{ROADMAP.md, STATE.md, config.json}`
- Experiments in DDEV: `Markdown::parse()` HTML-block pass-through; Symfony `Email::embed()` cid resolution; `config('mail.markdown')` value

### Secondary (MEDIUM confidence)
- Existing test files read for conventions: `tests/Feature/Invoicing/SendInvoiceEmailTest.php`, `tests/Feature/Payments/CardPaymentJourneyTest.php`, `tests/Isolation/NotificationMarkupTest.php`, `tests/Arch/{JobContractTest.php, ModelDeclarationTest.php}`

### Tertiary (LOW confidence)
- General mail-client behaviour (data URIs, Outlook CSS limits, Gmail clipping) from training knowledge, tagged `[ASSUMED]` above. No web search or Context7 query was needed or made: every framework behaviour that the plan depends on was read from the installed vendor source or executed.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — no new packages; versions read from `vendor/composer/installed.json`
- Architecture: HIGH — trigger point, immutability constraints and settings fail-closed behaviour read from source; the cid and Markdown pass-through mechanics executed in DDEV
- Pitfalls: MEDIUM-HIGH — text-part backslashes (Pitfall 5) and the extra-mail test impact (Pitfall 6) are reasoned from code, not yet run; client rendering needs human proof

**Research date:** 2026-10-23
**Valid until:** 2026-11-22 (stable; re-check only if `laravel/framework` is bumped across a minor that touches `Markdown` or the mail views)
