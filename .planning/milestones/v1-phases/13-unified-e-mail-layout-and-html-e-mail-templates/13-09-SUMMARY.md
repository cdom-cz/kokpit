---
phase: 13-unified-e-mail-layout-and-html-e-mail-templates
plan: 09
subsystem: documentation
tags: [contributing, readme, project-decisions, repository-guard, handover]
status: complete

requires:
  - phase: 13-unified-e-mail-layout-and-html-e-mail-templates
    provides: the theme, MailBrand, MailText, PlainTextHtml, PayLinkBlock::forHtml(), the payment confirmation and the eight enforcing test files of plans 13-01 to 13-08
provides:
  - the CONTRIBUTING bullet "E-mail." (D-01 to D-07 written down for later phases), the amended "Task notifications." and "Payment method and bank accounts." bullets
  - the RepositoryFilesTest guard "hands the e-mail layout over: the E-mail bullet names only what exists"
  - the README paragraph on the payment confirmation for operators
  - five Phase 13 rows in the PROJECT.md Key Decisions table, Outcome Pending
affects: [13-VERIFICATION, phase 15 and later phases that send an e-mail]

plan_head_before: 320e46e1c2f477d003eba3608f1986c88ff7031d
plan_head_after: 67c8be063deabcc35824f5f32f82a2692086679f

actuals:
  tokens: 3800
  tasks: 3
  commits: 2

tech-stack:
  added: []
  patterns:
    - "A documentation bullet is guarded by a repository test that resolves every backticked class and Class::method() name, every named test file and the named constant"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .planning/PROJECT.md

key-decisions:
  - "R-13-09-1: the E-mail bullet sits after Task notifications and states the published kokpit theme, MailBrand as the one branding read, the inline cid logo with no route, MailText escaping and its unescape in the text layout, PlainTextHtml and PayLinkBlock under applies(), the payment confirmation (QueuePaymentConfirmation from RecordPayment::recordLocked(), one row per payment, GRACE_SECONDS, never locks), ResetPasswordNotification, the mail_layout and payment_mail groups, Czech-only review of the other e-mails and the no view()->render() rule"
  - "R-13-09-2: the guard resolves the constant QueuePaymentConfirmation::GRACE_SECONDS and MailText::unescapeMarkdown() separately, because the shared name regex only matches Class::method()"
  - "R-13-09-4: the D-07 clarification is recorded as the owner's decision; A-13-1 and A-13-6 sit in the payment confirmation row, A-13-3, A-13-4, A-13-5 and A-13-7 in one row for the owner to overrule"

requirements-completed: [P13-01, P13-11]

coverage:
  - id: D1
    description: "A later phase that sends an e-mail finds the rules of the shared theme, the escaping, the logo, the document e-mail and the payment confirmation in CONTRIBUTING, and the repository test fails the day the text names a class, method, constant or test file that no longer exists"
    requirement: "P13-11"
    verification:
      - kind: integration
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#hands the e-mail layout over: the E-mail bullet names only what exists"
        status: pass
    human_judgment: false
  - id: D2
    description: "An operator learns that clients get a payment confirmation about five minutes after each payment and that it needs the mail settings and the queue worker; the owner finds the phase decisions and the planner assumptions in PROJECT.md"
    requirement: "P13-11"
    verification:
      - kind: command
        ref: "grep of README.md for 'payment confirmation' and of PROJECT.md for Phase 13, A-13-6 and D-07"
        status: pass
    human_judgment: true
    rationale: "Whether the prose reads right to the owner is a judgment; the repository test pins only names and tokens"
  - id: D3
    description: "The full gate is green with pre-compiled views, the development database has the payment_confirmations table, and no public route and no dependency was added in the phase"
    requirement: "P13-11"
    verification:
      - kind: command
        ref: "ddev exec php artisan view:cache, mail and confirmation tests, view:clear; ddev exec vendor/bin/pest (6876 passed, 3 skipped); pint --test; phpstan; scripts/check-sensitive.sh --all"
        status: pass
    human_judgment: false
  - id: D4
    description: "Every e-mail of the phase renders correctly in Mailpit and in real mail clients"
    requirement: "P13-01"
    verification: []
    human_judgment: true
    rationale: "Outlook's Word engine, Gmail image handling and mobile layout cannot be asserted by the test suite; the look is the open human check of task 3"
---

# Phase 13 Plan 09: Hand-over and Full Gate Summary

**CONTRIBUTING now carries the E-mail rule of the phase, guarded by a repository test that resolves every name it gives; README and PROJECT.md record the payment confirmation, the decisions and the assumptions; the full gate is green with pre-compiled views; the look at real mail clients is left open for the end-of-phase review.**

## Performance

- **Tasks:** 3 (a tracer, an automated gate task, a human-review hand-off with no file change), 2 production commits plus this summary
- **Files:** 4 modified
- **Completed:** 2026-10-23

## Accomplishments

- `CONTRIBUTING.md`: the `- **E-mail.**` bullet after "Task notifications." The "Task notifications." bullet says the Markdown escape lives in `MailText::escapeMarkdown()`; the Phase 13 sentence of "Payment method and bank accounts." now states what was built and keeps `PayLinkBlock::applies()` and the token `Phase 13`. "Editable invoices." is untouched.
- `RepositoryFilesTest`: the new test requires the bullet and its phrases, resolves every backticked class and `Class::method()` through `repoClassesNamed()`, checks the grace constant, the unescape step, the eight named test files, the theme name in `config('mail.markdown.theme')`, the theme CSS and the lang files, and the neighbouring bullets' tokens. RED first (bullet missing), then green.
- `README.md`: one paragraph in "Operations" after the task notification paragraph, with the fictional address `billing@example.com`.
- `.planning/PROJECT.md`: five Key Decisions rows, all "— Pending": the shared theme and branding, the escaped plain-text invoice e-mail with the card button, the payment confirmation (with A-13-1 and A-13-6), the D-07 clarification as the owner's decision, and the assumptions A-13-3, A-13-4, A-13-5 and A-13-7 for the owner to confirm or overrule.
- The phase migration was missing in the DDEV development database; `ddev exec php artisan migrate` applied `create_payment_confirmations_table` and `migrate:status` reads Ran.

## Task Commits

1. **Task 1: tracer** - `7052b6c` (docs) - the E-mail bullet and the guard test
2. **Task 2: README, PROJECT.md, gate** - `67c8be0` (docs)
3. **Task 3: human review hand-off** - no commit; the plan forbids any file change

## Deviations from Plan

None - plan executed exactly as written.

Notes (not deviations):
- The plan-head ledger file was written after the first commit, from its parent (`320e46e`), because the first commit had already run; the base is the same HEAD the plan started on.
- `routes/public.php`, `composer.json` and `composer.lock` have no commit since the phase's first plan base (`9b40850`), so no public route and no dependency was added in the phase (T-13-23, T-13-SC).
- `tests/Isolation/TimeLeakTest.php` did not fail in the full run.

**Total deviations:** 0.

## Verification

- `ddev exec vendor/bin/pest tests/Feature/Repo/RepositoryFilesTest.php`: 45 passed; `--filter="e-mail layout"`: 1 passed.
- Pre-compiled views: `view:cache`, then `tests/Feature/Mail` plus the two payment confirmation test files: 76 passed, then `view:clear`.
- Full suite `ddev exec vendor/bin/pest`: 6876 passed, 3 skipped, exit 0.
- `ddev exec vendor/bin/pint --test`: clean. PHPStan: only the known pre-existing error in `app/Console/Commands/ResetAdminTwoFactorCommand.php:39` (out of scope).
- `scripts/check-sensitive.sh --all`: clean; both commits passed the lefthook sensitive-content and gitleaks hooks.
- Task 3 automated check (migration Ran, Mailpit answers inside DDEV): exit 0; the task changed no file.

## Human review

Open, and not performed or approved by the executor. Under `workflow.human_verify_mode: end-of-phase` the verifier collects it into the phase UAT. In DDEV (`ddev launch -m`), with a fictional supplier that has a logo and fictional clients, trigger every e-mail of the phase (a card-enabled and a transfer invoice, one Czech and one English client; a partial and then the final payment, waiting out the five-minute grace window with the worker running; a partner invitation; a password reset; a Partner comment on a task; an operational alert through `AdminAlerter` from tinker). Open each in Mailpit and, where a test mailbox exists, in Gmail, Outlook, Apple Mail and one mobile client. Expect the same header (logo at most 180 px wide and sharp, or the supplier name) and footer, the logo not listed as an attachment, no broken image, the amber button readable and working, the invoice body keeping its line breaks and typed symbols, the confirmation showing number, amount, date and the remaining amount or the paid-in-full sentence, no Czech label in English e-mails, readable plain-text views with no stray backslashes, and the alert matching the client e-mails apart from its red button.

## Known Stubs

None.

## Threat Flags

None. Documentation, a test and planning text only; fictional values (`billing@example.com`) and a clean sensitive-data scan (T-13-22).

## Issues Encountered

None.

## Next Phase Readiness

All nine plans of Phase 13 are executed. Ready for phase verification and the end-of-phase UAT, which carries the human look at the e-mails in real clients.

## Self-Check: PASSED

`CONTRIBUTING.md`, `README.md`, `tests/Feature/Repo/RepositoryFilesTest.php` and `.planning/PROJECT.md` exist and carry the changes; commits `7052b6c` and `67c8be0` are ancestors of HEAD.
