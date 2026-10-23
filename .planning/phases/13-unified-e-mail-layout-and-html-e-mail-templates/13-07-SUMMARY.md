---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 07
subsystem: notifications
tags: [mail, partner-invitation, password-reset, operational-alert, escaping, i18n]
status: complete

requires:
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    provides: the published notification view, MailBrand and MailFixtures from plan 13-01; MailText and the plain-text layout that reverses its escapes from plan 13-04
provides:
  - PartnerInvitation with escaped names, a supplier-named subject and a two-line sign-off
  - App\Domain\Identity\Notifications\ResetPasswordNotification, Kokpit's subclass of Filament's reset notification, used by both call sites
  - OperationalAlert with escaped title and lines on the shared layout
  - reviewed Czech copy for the invitation, the password reset and the alerts; task notification copy reviewed with no change
  - tests/Feature/Mail/NotificationMailsTest.php
affects: [13-08, 13-09]

plan_head_before: 9722e0957a1728afc015a0df7216d1570c56d9fb
plan_head_after: 9de888db64da224d4a65af1f0038262421c29eef

actuals:
  tokens: 5200
  tasks: 3
  commits: 3

tech-stack:
  added: []
  patterns:
    - "A notification that interpolates a typed or runtime value passes it through MailText::escapeMarkdown() before it enters a Markdown line or heading; the subject and URLs are not escaped"
    - "A framework notification whose text must change is subclassed to override only its message builder, keeping its queueing and URL"
    - "A multi-line sign-off is one salutation joined by newlines; the plain-text layout turns the layout's own <br> into real line breaks before stripping tags"

key-files:
  created:
    - app/Domain/Identity/Notifications/ResetPasswordNotification.php
    - tests/Feature/Mail/NotificationMailsTest.php
  modified:
    - app/Domain/Clients/Notifications/PartnerInvitation.php
    - app/Domain/Clients/Actions/SendPartnerPasswordReset.php
    - app/Filament/Pages/Auth/RequestPasswordReset.php
    - app/Domain/Operations/Alerts/OperationalAlert.php
    - resources/views/vendor/mail/text/layout.blade.php
    - lang/cs/kokpit.php
    - tests/Feature/Clients/PartnerInvitationTest.php
    - tests/Feature/Clients/PartnerPasswordResetTest.php

key-decisions:
  - "R-13-07-1: invitee name, client name, alert title and every alert line are escaped with MailText::escapeMarkdown(); subjects and URLs are not"
  - "R-13-07-2: invitation subject 'Pozvánka od :supplier', intro 'zveme vás ...' without a gendered form, sign-off 'S pozdravem' plus the escaped supplier name as a two-line salutation"
  - "R-13-07-3: ResetPasswordNotification is a final subclass of Filament's ResetPassword overriding only buildMailMessage(); the expiry is read from the default broker config and pluralised with trans_choice in Czech (minutu, minuty, minut)"
  - "R-13-07-4: OperationalAlert keeps ->error(), sets no salutation; the 'suppressed' line is reworded to 'stejným způsobem selhaly další úlohy'"
  - "R-13-07-5: the task notification Czech copy was reviewed and needed no change, so keys, placeholders and the notification matrix are untouched"

requirements-completed: [P13-06, P13-07, P13-08, P13-09]

coverage:
  - id: D1
    description: "An invitation sent through InvitePartner and the unchanged InvitationMail, whose invitee and client names hold link, image and emphasis syntax, arrives in the supplier's layout with the reviewed Czech text, the names as typed, the supplier-named subject and the two-line sign-off"
    requirement: "P13-06"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/NotificationMailsTest.php#the invitation"
        status: pass
    human_judgment: false
  - id: D2
    description: "The password reset goes out as Kokpit's subclass with the reviewed text, the same signed reset link, queued; the deactivated, archived-client, unknown and throttled rules are unchanged"
    requirement: "P13-07"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/NotificationMailsTest.php#the password reset"
        status: pass
      - kind: integration
        ref: "tests/Feature/Clients/PartnerPasswordResetTest.php"
        status: pass
    human_judgment: false
  - id: D3
    description: "An alert shows its title and lines (class names, underscores, brackets, link and emphasis syntax) as written in the HTML and the text part, with the error button and no sign-off, and shares the header and footer markup with the invitation"
    requirement: "P13-08"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/NotificationMailsTest.php#the alerts and task notifications"
        status: pass
    human_judgment: false
  - id: D4
    description: "A task notification to the Admin renders on the supplier layout with the reviewed Czech text; recipients, preferences, links and the internal-comment rule are untouched"
    requirement: "P13-09"
    verification:
      - kind: integration
        ref: "tests/Feature/Mail/NotificationMailsTest.php#the alerts and task notifications"
        status: pass
      - kind: command
        ref: "ddev exec vendor/bin/pest tests/Feature/Mail tests/Feature/Operations tests/Feature/Tasks tests/Feature/ExchangeRates/RateAlertTest.php tests/Isolation/NotificationMarkupTest.php tests/Isolation/NotificationLeakTest.php (895 passed)"
        status: pass
    human_judgment: false
---

# Phase 13 Plan 07: The other four e-mails on the shared layout Summary

**The partner invitation, the password reset (a Kokpit subclass of Filament's notification), the task notifications and the operational alerts all render on the shared supplier layout; every typed or runtime value is made inert with the shared Markdown escape, and the Czech copy is reviewed.**

## Performance

- **Tasks:** 3 (a tracer, then two auto/tdd tasks), 3 commits
- **Files:** 10 created or modified (2 new)
- **Completed:** 2026-10-23

## Accomplishments

- `PartnerInvitation::toMail()` escapes the invitee and client names, puts the supplier into the subject and signs off with "S pozdravem" and the supplier name on a second line. `InvitationMail` is unchanged; the tracer test sends through `InvitePartner::handle()` and proves the whole path.
- `ResetPasswordNotification` overrides only `buildMailMessage()`; both call sites and the reset test use it. The link, broker, throttle, timebox and refusal rules are untouched. The expiry minutes come from the default broker's config.
- `OperationalAlert::toMail()` escapes the title and each body line, keeps the red error button, sets no sign-off. A test shows the alert and an invitation print the same header and footer markup.
- Reviewed Czech copy: invitation (formal address, no gendered participle), the new `password_reset.mail.*` keys, and the alert "suppressed" line. The task notification copy was read and needed no change.

## Task Commits

1. **Task 1: tracer, the invitation** - `d47a9c3` (feat)
2. **Task 2: the password reset** - `4bdeeec` (feat)
3. **Task 3: alerts and task notifications** - `9de888d` (feat)

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The plain-text part glued the two sign-off lines together**
- **Found during:** Task 1
- **Issue:** The published layout joins salutation lines with `<br>`; the text layout stripped tags, so the text part read "S pozdravemExample Supplier s.r.o."
- **Fix:** `resources/views/vendor/mail/text/layout.blade.php` turns `<br>` into a newline before `strip_tags`. Typed text is HTML-encoded at that point, so it cannot hold a real `<br>`.
- **Files modified:** resources/views/vendor/mail/text/layout.blade.php
- **Commit:** d47a9c3

**2. [Plan wording] Czech plural for the expiry minutes**
- **Issue:** the suggested "Odkaz platí :count minut." is wrong for 1 to 4 minutes.
- **Fix:** the key holds three `trans_choice` forms. Not an architectural change.
- **Commit:** 4bdeeec

Notes (not deviations): task notification copy needed no change. `pint` run on `lang/` reformatted unrelated lang files, which were reverted before the commit.

**Total deviations:** 1 auto-fixed bug, 1 wording refinement.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Mail tests/Feature/Operations tests/Feature/Tasks tests/Feature/ExchangeRates/RateAlertTest.php tests/Isolation/NotificationMarkupTest.php tests/Isolation/NotificationLeakTest.php`: 895 passed.
- Task 1 and 2 targeted runs (invitation, accept, management, reset, lifecycle, arch): green.
- The alert escape test was shown red against the unescaped `OperationalAlert` and green with it.
- Pint clean. PHPStan reports only the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (out of scope).
- `scripts/check-sensitive.sh` clean on all three commits.

## Known Stubs

None.

## Threat Flags

None. T-13-15, T-13-16 and T-13-17 are mitigated as planned; nothing is installed (T-13-SC).

## Issues Encountered

None.

## Next Phase Readiness

Ready for 13-08 and 13-09. English texts for these four e-mails stay out of scope by the owner's D-07 clarification; the layout strings already exist in both languages.

## Self-Check: PASSED

All created files exist; commits `d47a9c3`, `4bdeeec` and `9de888d` are ancestors of HEAD.
