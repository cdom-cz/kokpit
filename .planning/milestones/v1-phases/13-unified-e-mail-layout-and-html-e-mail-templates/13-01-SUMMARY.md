---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 01
subsystem: notifications
tags: [mail, markdown-theme, branding, supplier-settings, notifications, i18n]
status: complete

requires:
  - phase: 10-invoicing
    provides: SupplierSettings, InvoiceFixtures::configureSupplier(), the array-transport mail tests
provides:
  - the published kokpit Laravel mail theme (layout, message, header, footer, themes/kokpit.css) set in config mail.markdown
  - MailBrand, the one system-context reader of the supplier branding, bound scoped in AppServiceProvider
  - the published notifications::email view (neutral greeting, optional salutation, layout button fallback)
  - lang/{cs,en}/mail_layout.php with equal keys
  - Tests\Support\MailFixtures (sent(), to()) and MailThemeTest
affects: [13-04, 13-05, 13-06, 13-07, 13-09]

plan_head_before: 9b4085092d7e4c293cc0085d8c53884d7d83ad5a
plan_head_after: 9fc4428b271705b4ed07770d45de0cf1848bb345

actuals:
  tokens: 8300
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "Layout views read the supplier only through MailBrand::current(), a final readonly value class resolved from a scoped container binding; the read runs inside PartnerContext::runAsSystem(), takes a field whitelist and falls back to the application name on any Throwable"
    - "Only the customised framework mail views are published; every other component (button, panel, subcopy, table, text/) is deleted again so the framework copies stay the fallback on the component path"
    - "Labels are translated by the views at render time (__('mail_layout.*')), so each e-mail's locale decides them"

key-files:
  created:
    - app/Domain/Notifications/Mail/MailBrand.php
    - resources/views/vendor/mail/html/layout.blade.php
    - resources/views/vendor/mail/html/message.blade.php
    - resources/views/vendor/mail/html/header.blade.php
    - resources/views/vendor/mail/html/footer.blade.php
    - resources/views/vendor/mail/html/themes/kokpit.css
    - resources/views/vendor/notifications/email.blade.php
    - lang/cs/mail_layout.php
    - lang/en/mail_layout.php
    - tests/Support/MailFixtures.php
    - tests/Feature/Mail/MailThemeTest.php
  modified:
    - config/mail.php
    - app/Providers/AppServiceProvider.php

key-decisions:
  - "R-13-01-1: published with both vendor:publish tags, kept only layout, message, header, footer, themes/kokpit.css and notifications/email.blade.php; button, panel, subcopy, table, default.css and all text/ copies deleted"
  - "R-13-01-2: config/mail.php has an explicit markdown block (theme kokpit, paths, extensions)"
  - "R-13-01-3: MailBrand is final readonly with raw values (name, street, postalCode, city, companyId, vatId, email, phone, website, registrationNote, logoPng, logoWidth, logoHeight), LOGO_NAME, addressLine(), hasLogo(), current(), read(), fallback(); the logo fields stay null for plan 13-04"
  - "R-13-01-5: the footer component prints its slot raw (no Markdown parse); message.blade.php escapes every value with {{ }} and prints up to five lines, each only when it has content"
  - "R-13-01-7: primary button amber #f59e0b with dark text #18181b; success and error colours kept for the level mapping"
  - "R-13-01-8: notifications::email uses mail_layout.greeting for every level, prints a salutation only when set (lines escaped, joined with br) and the mail_layout.button_fallback subcopy"

requirements-completed: [P13-01, P13-02, P13-10]

coverage:
  - id: D1
    description: "Every MailMessage e-mail renders the HTML part through the kokpit theme with the supplier name in the header and title and the details in the footer, from a worker with no user and from a Partner session"
    requirement: "P13-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailThemeTest.php#the supplier layout, the kokpit theme"
        status: pass
    human_judgment: false
  - id: D2
    description: "The brand is read only through MailBrand with a whitelist: no bank account in either part, blank fields print no empty line, a throwing settings read still sends the e-mail with the application name, characters of the company name stay literal"
    requirement: "P13-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailThemeTest.php#the brand reader whitelist and fallback"
        status: pass
    human_judgment: false
  - id: D3
    description: "Neutral greeting, optional escaped salutation and layout button fallback for every notification; the alert keeps the error button colour and shares header and footer markup"
    requirement: "P13-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailThemeTest.php#the notification view, the operational alert"
        status: pass
    human_judgment: false
  - id: D4
    description: "Layout labels exist in Czech and English with equal keys and follow the locale of each e-mail"
    requirement: "P13-10"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailThemeTest.php#the layout strings"
        status: pass
    human_judgment: false
  - id: D5
    description: "Looks of the layout in real mail clients (Gmail, Outlook, Apple Mail)"
    requirement: "P13-01"
    verification:
      - kind: manual
        ref: "Phase 13 human check in real clients, Mailpit at the DDEV port 8025"
        status: pending
    human_judgment: true
---

# Phase 13 Plan 01: Shared kokpit mail theme and supplier branding Summary

**A published Laravel mail theme named kokpit gives every MailMessage e-mail the supplier's header and footer, read through one system-context reader (MailBrand) that falls back to the application name, plus a neutral greeting with no second sign-off.**

## Performance

- **Tasks:** 2 (tracer, then an auto/tdd task), 2 commits
- **Files:** 13 created or modified (11 new)
- **Tests:** 17 in MailThemeTest; 382 in the task-2 gate and 2457 in the wide gate (Invoicing, Payments, Tasks, Operations, Isolation, Arch), all green

## Accomplishments

- Published `vendor/mail` and `vendor/notifications`, reduced them to the six files this plan customises and renamed the theme CSS to `kokpit.css`; `config/mail.php` names the theme explicitly with the component path.
- `MailBrand` (readonly, scoped) reads only the whitelisted `SupplierSettings` fields inside `runAsSystem()`; a queued notification with no user, a Partner session and an alert during an outage all render the same header and footer.
- The footer component prints its slot raw and every value is escaped in `message.blade.php`, so `*Star* & <b>` in a company name stays literal.
- `notifications::email` opens with the `mail_layout.greeting`, prints a salutation only when the notification sets one, and explains the button with the `mail_layout.button_fallback` sentence; the stock Laravel branding, copyright line and "Regards" default are gone from the HTML part.
- `lang/{cs,en}/mail_layout.php` (company ID, VAT ID, greeting, regards, button fallback) with a key-parity test; labels follow each e-mail's locale.

## Task Commits

1. **Task 1 (tracer): supplier-branded kokpit theme, MailBrand, strings, fixtures** - `d6b47ec`
2. **Task 2: notification view and brand-reader hardening tests** - `9fc4428`

## Deviations from Plan

None - plan executed as written. `MailBrand` needed no change in task 2: the whitelist and the fallback tests passed against the task-1 implementation.

Notes on small adaptations (no rule triggered):
- The test for the two-line salutation uses `Kind regardsExample ...` as the expected text content because the line break element contributes no whitespace to `textContent`.
- The `MailMessage` has no `outro()` method; the outro in the no-sign-off test is a line after the action.

## Deferred Issues

- `vendor/bin/phpstan analyse` reports one pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (`is_string()` always true, `function.alreadyNarrowedType`). The file is not part of this plan; left untouched. All files of this plan analyse clean.
- The plain-text part of notification e-mails still comes from the framework `text/` components (stock header and `(c)` footer lines); plan 13-04 takes it over.
- `mail_layout.regards` is defined for the notifications that set a salutation (plans 13-06 and 13-07) and is not used yet.

## Known Stubs

None. `MailBrand::logoPng`, `logoWidth` and `logoHeight` are declared and always null by design; plan 13-04 fills them (R-13-04-1).

## Threat Flags

None. No new route, endpoint or storage; the only new read of stored data is the whitelisted supplier fields (T-13-01), covered by tests.

## Self-Check: PASSED

- `app/Domain/Notifications/Mail/MailBrand.php`, `resources/views/vendor/mail/html/{layout,message,header,footer}.blade.php`, `resources/views/vendor/mail/html/themes/kokpit.css`, `resources/views/vendor/notifications/email.blade.php`, `lang/cs/mail_layout.php`, `lang/en/mail_layout.php`, `tests/Support/MailFixtures.php`, `tests/Feature/Mail/MailThemeTest.php`: found.
- Commits `d6b47ec` and `9fc4428`: found in history.
