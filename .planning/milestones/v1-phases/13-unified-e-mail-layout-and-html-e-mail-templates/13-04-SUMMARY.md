---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 04
subsystem: notifications
tags: [mail, inline-logo, cid, plain-text, markdown-escape, gd]
status: complete

requires:
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    provides: MailBrand, the kokpit html theme and MailFixtures from plan 13-01
provides:
  - the supplier logo as one downscaled inline cid part (MailLogo, EmbedMailLogo, DocumentImage::contents(), MailBrand logo fields)
  - the plain-text layout (text/layout, text/message, text/header) with the supplier name and footer lines
  - MailText, the one Markdown escape and its plain-text reversal; TaskNotification escapes through it
  - MailFixtures::storeLogo() and MailBrandTest
affects: [13-05, 13-06, 13-07, 13-09]

plan_head_before: fb6f2c928836efcd1eb4da882f98d15a08961b49
plan_head_after: 9fab69c289c28f125f1ba38f0e186ef747620a29

actuals:
  tokens: 8700
  tasks: 2
  commits: 2

tech-stack:
  added: []
  patterns:
    - "One decider for the logo: MailBrand::hasLogo() gates both the cid image in the html header and the EmbedMailLogo listener, so a cid is never emitted without its part"
    - "The text layout is the html layout minus markup: values printed with {{ }} (renderText decodes entities once), the header slot printed raw because a string cast loses the Htmlable escape protection"
    - "Markdown escaping happens in MailText only; the text layout calls unescapeMarkdown() on header, slot, subcopy and footer"

key-files:
  created:
    - app/Domain/Notifications/Mail/MailLogo.php
    - app/Domain/Notifications/Mail/EmbedMailLogo.php
    - app/Domain/Notifications/Mail/MailText.php
    - resources/views/vendor/mail/text/layout.blade.php
    - resources/views/vendor/mail/text/message.blade.php
    - resources/views/vendor/mail/text/header.blade.php
    - tests/Feature/Mail/MailBrandTest.php
  modified:
    - app/Domain/Settings/DocumentImage.php
    - app/Domain/Notifications/Mail/MailBrand.php
    - app/Domain/Tasks/Notifications/TaskNotification.php
    - app/Providers/AppServiceProvider.php
    - resources/views/vendor/mail/html/message.blade.php
    - tests/Support/MailFixtures.php
    - tests/Feature/Mail/MailThemeTest.php

key-decisions:
  - "R-13-04-1: logo bytes are read only through MailBrand (system context) and DocumentImage::contents(); MailLogo::fromPng() scales down to at most 360 x 120 and returns a display size fitting 180 x 60, never enlarged; EmbedMailLogo embeds under kokpit-logo.png only when the html body holds the cid reference and returns nothing"
  - "R-13-04-2: escapeMarkdown() moved unchanged into MailText; unescapeMarkdown() removes exactly one backslash in front of the escaped set"
  - "R-13-04-3: kept text/layout, text/message, text/header; every other publish copy deleted again so the framework components stay the fallback"
  - "R-13-04-4: storeLogo() draws a GD PNG at runtime and stores it through DocumentImage::store() as the system; tests call DocumentFixtures::fakeDisk() first (storeLogo does not, so it never wipes other stored files)"
  - "The text header is called without the website URL; the website already stands in the footer line, and the part must start with the name"

requirements-completed: [P13-01, P13-02, P13-08]

coverage:
  - id: D1
    description: "A configured logo is one inline image/png part named kokpit-logo.png, at most 360 x 120 px, shown at most 180 x 60 and never enlarged; no logo or a missing object gives the name as text and no cid; no route, URL or data URI"
    requirement: "P13-02"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailBrandTest.php#the logo in the header"
        status: pass
      - kind: integration
        ref: "tests/Feature/Mail/MailBrandTest.php#the logo reader"
        status: pass
    human_judgment: false
  - id: D2
    description: "The plain-text part starts with the supplier name, carries the footer lines with the labels of its locale, prints no empty line, no bank account, no Laravel or copyright text, and no entity or backslash"
    requirement: "P13-01"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailThemeTest.php#the plain-text part"
        status: pass
    human_judgment: false
  - id: D3
    description: "A Markdown-escaped task value reads literally in both parts; escape and unescape round-trip; TaskNotification keeps no private copy and its behaviour is unchanged"
    requirement: "P13-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/MailThemeTest.php#the Markdown escape"
        status: pass
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php"
        status: pass
    human_judgment: false
  - id: D4
    description: "The logo looks sharp and sized correctly in real mail clients (Gmail, Outlook, Apple Mail)"
    requirement: "P13-02"
    verification:
      - kind: manual
        ref: "Phase 13 human check in real clients, Mailpit at the DDEV port 8025"
        status: pending
    human_judgment: true
---

# Phase 13 Plan 04: Inline logo, plain-text layout and MailText Summary

**The supplier logo travels in every e-mail as one downscaled inline cid part (name as text when there is none), the plain-text part carries the same header and footer, and the one Markdown escape lives in MailText with a reversal that keeps backslashes out of the text part.**

## Performance

- **Tasks:** 2 (tracer, then an auto/tdd task), 2 commits
- **Files:** 14 created or modified (7 new)
- **Tests:** 33 in tests/Feature/Mail; the task-2 gate (Mail, notification canaries, Clients, AdminAlert) 398 green; the wide gate (Mail, Invoicing, Clients, Tasks, Operations, Isolation, Arch) 2365 green

## Accomplishments

- `DocumentImage::contents()` is the one public read of a stored image, behind the existing path-pattern check; `MailLogo::fromPng()` scales with GD (alpha kept) to at most 360 x 120 and computes the 180 x 60 display size; `MailBrand::read()` fills the logo fields inside its system-context read and falls back to no logo on any failure.
- `EmbedMailLogo` (registered on `MessageSending` in `AppServiceProvider::boot()`) embeds the bytes only when the html body holds `cid:kokpit-logo.png`, giving `multipart/related`; the html header emits the image only when `MailBrand::hasLogo()`.
- The text views mirror the html header and footer lines with the `mail_layout` labels; the layout strips tags and removes the escape backslashes.
- `MailText::escapeMarkdown()` is the old `TaskNotification` helper moved unchanged; `TaskNotification` calls it at its three sites and keeps no copy.

## Task Commits

1. **Task 1 (tracer): inline logo, MailLogo, EmbedMailLogo, storeLogo fixture** - `bdb4947`
2. **Task 2: plain-text layout and MailText** - `9fab69c`

## Deviations from Plan

None - plan executed as written. One bug found by my own test during task 2 (not a plan deviation): the first text header printed the slot with `{{ }}` after a string cast, which double-escaped `&` in a company name (`&amp;` in the text part); fixed in the same task by printing the slot raw.

## Deferred Issues

- `vendor/bin/phpstan analyse` still reports the one pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39`; out of scope. All files of this plan analyse clean.
- The text part still shows Markdown remnants of the notification view itself (`# Dobrý den,` heading marker and the `[url](url)` subcopy link), as the framework text components did before; not part of this plan.

## Known Stubs

None.

## Threat Flags

None. No new route, URL or storage; T-13-04 (no hosted logo) and T-13-05 (downscale once per scope) are covered by `MailBrandTest`.

## Self-Check: PASSED

- `app/Domain/Notifications/Mail/{MailLogo,EmbedMailLogo,MailText}.php`, `resources/views/vendor/mail/text/{layout,message,header}.blade.php`, `tests/Feature/Mail/MailBrandTest.php`: found.
- Commits `bdb4947` and `9fab69c`: found in history.
