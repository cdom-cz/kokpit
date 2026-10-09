---
phase: 05-tasks-and-kanban
plan: 19
subsystem: notifications
tags: [laravel, filament, markdown, html-escaping, partner-isolation, tdd, gap-closure]

requires:
  - phase: 05-tasks-and-kanban
    provides: "TaskNotifier, the four task notification classes and the notification tests (plans 05-01 to 05-18)"
provides:
  - "TaskNotification base class that escapes every interpolated value once per target, with final toMail and toDatabase"
  - "NotificationMarkupTest: end-to-end and matrix proof that markup in titles, names, excerpts and change lines renders inert for the Admin and for Partners"
  - "CONTRIBUTING escaping rule with a documentation test"
affects: [phase-06, phase-07-api, phase-09, phase-10]

actuals:
  tokens: 8900
  tasks: 3
  commits: 4
plan_head_before: 29499faf1d7366a1128c54f4bec001de230c098a
plan_head_after: 4cd82fde3ca2d1e482a7ecb614b621cfc983368c

tech-stack:
  added: []
  patterns:
    - "Notification text is built in one private base-class builder; subclasses name a text group and supply raw scalars, never a translation call"
    - "Render-time escaping per target (subject raw, mail Markdown-escaped without < and >, bell e()), never stored escaped, so rendering is idempotent"
    - "Markup canaries are proven inert by parsing the rendered output as a DOM (Dom\\HTMLDocument) and inspecting element attributes, not substrings"

key-files:
  created:
    - tests/Isolation/NotificationMarkupTest.php
  modified:
    - app/Domain/Tasks/Notifications/TaskNotification.php
    - app/Domain/Tasks/Notifications/TaskCreatedNotification.php
    - app/Domain/Tasks/Notifications/TaskCommentedNotification.php
    - app/Domain/Tasks/Notifications/TaskEscalatedNotification.php
    - app/Domain/Tasks/Notifications/TaskChangedNotification.php
    - CONTRIBUTING.md
    - tests/Feature/Repo/RepositoryFilesTest.php

key-decisions:
  - "Escaping lives only in the TaskNotification base: toMail and toDatabase are final, one private text() builder escapes per target, subclasses reduced to constructor + textGroup() + optional changeLines()/bellBodyKey()"
  - "The Markdown escape set drops < and > because the mail renderer HTML-encodes each line first; a backslash before them made the mail show a literal &lt;"
  - "The bell excerpt is cut to 120 characters as plain text before e() is applied, so no entity is cut in half"

requirements-completed: [TA-07, TA-04]

coverage:
  - id: D1
    description: "A task title, an actor display name, a comment excerpt or a change line holding HTML or Markdown reaches the Admin and every Partner recipient as visible text only, in the mail and in the bell, for every notification class"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php#renders a markup title and a markup display name as plain text in the comment mail and bell of the Admin and of the Partner assignee"
        status: pass
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php#renders every vector inert and as typed for every notification class and audience (6 datasets)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Values read exactly as written, encoded once; the subject stays plain text; rendering twice or after a queue round trip is identical"
    requirement: TA-07
    verification:
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php#keeps the mail subject plain text with the raw reference and title (6 datasets)"
        status: pass
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php#renders the same notification twice, and after a queue round trip, to identical output (6 datasets)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The 120-character bell cut and the 300-character excerpt are applied to plain text before escaping"
    requirement: TA-04
    verification:
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php#cuts the bell excerpt to 120 characters as plain text before it is escaped"
        status: pass
      - kind: integration
        ref: "tests/Isolation/NotificationMarkupTest.php#shows the excerpt of the notifier complete in the mail, cut at 300 characters before the escape"
        status: pass
    human_judgment: false
  - id: D4
    description: "The escape point cannot be bypassed: final render methods, subclass shape, completeness over discovered subclasses, internal-comment rule unchanged"
    requirement: TA-07
    verification:
      - kind: unit
        ref: "tests/Isolation/NotificationMarkupTest.php#keeps the render methods final and the subclasses to constructors and text hooks, with no translation call"
        status: pass
      - kind: unit
        ref: "tests/Isolation/NotificationMarkupTest.php#builds a matrix row for every concrete task notification class"
        status: pass
      - kind: unit
        ref: "tests/Isolation/NotificationMarkupTest.php#still refuses an internal comment for a Partner in both classes that carry a comment"
        status: pass
    human_judgment: false
  - id: D5
    description: "The escaping rule is documented next to its test and the documentation test pins the names"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 5 classes in CONTRIBUTING.md that exist in app/"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#names the Phase 5 enforcing tests and the hand-over notes for Phases 6, 7, 9 and 10"
        status: pass
    human_judgment: false
  - id: D6
    description: "In a real mail client (Mailpit) and the real bell, a Partner's markup title and name read exactly as typed, with no clickable link, image or styling"
    requirement: TA-07
    verification: []
    human_judgment: true
    rationale: "The human-check of Task 3 (extends UAT test 3 of 05-UAT.md): visual confirmation in Mailpit and the browser bell, which no DOM test can replace"

duration: 10min
completed: 2026-10-09
status: complete
---

# Phase 5 Plan 19: Task notification output escaping Summary

**Every value a task notification interpolates is now escaped once in the TaskNotification base (Markdown-escaped in mail lines, e() in the bell, raw in the subject) behind final toMail/toDatabase, with a DOM-inspected markup matrix over all six class/audience pairs (T-05-44, WR-02).**

## Performance

- **Duration:** 10 min
- **Started:** 2026-10-09T05:46:44Z
- **Completed:** 2026-10-09T05:56:13Z
- **Tasks:** 3
- **Files modified:** 8 (1 created, 7 modified)

## Accomplishments

- T-05-44 / U-1 / WR-02 closed: a Partner can no longer put a link, image, style or emphasis into the Admin's or another Partner's bell or mail through a task title, a display name, an excerpt or a change line. The mail lines now escape every replacement (before, only the excerpt and change lines were escaped, the title and actor not at all); the bell escapes title and actor too (before, only `TaskChangedNotification` did).
- The escaping point is structural: `toMail()` and `toDatabase()` are `final`, a single private `text()` builder is the only caller of the translation helper, and the four subclasses are reduced to their unchanged constructors plus `textGroup()` (and `changeLines()` / `bellBodyKey()` where needed). A new subclass without a matrix builder fails the completeness case.
- Values read exactly as typed: "A < B & C" shows as such in the HTML mail and in the bell, with no visible entity and no visible backslash. The cause of the earlier literal "&lt;" (a backslash before `<`/`>` in the Markdown escape set) was removed.
- `NotificationMarkupTest` (25 cases): the end-to-end case through the real Actions, channels and stored rows; the matrix over TaskCreated (Admin), TaskCommented and TaskEscalated (Admin, Partner) and TaskChanged (Partner); subject, idempotency (twice and after a serialize round trip), 120/300 length cuts, completeness, structure and the unchanged internal-comment lock.
- CONTRIBUTING states the rule; `RepositoryFilesTest` fails if `NotificationMarkupTest` or the backticked `TaskNotification` disappears.

## RED evidence (Task 1)

| Case | Command | Result on the pre-fix code |
|---|---|---|
| `renders a markup title and a markup display name as plain text in the comment mail and bell of the Admin and of the Partner assignee` | `ddev exec vendor/bin/pest tests/Isolation/NotificationMarkupTest.php` | exit 1; `Failed asserting that two arrays are identical`: expected `[]`, actual `['a[href]', 'img[src]']` at the Admin's mail findings assertion. Semantic assessment: the target test ran and failed on the planned assertion, namely a live Markdown link and a live Markdown image built from the title and the display name in the mail line, which is exactly the vector the planner measured. The mail assertion runs before the bell assertion, so the failing bell attributes were not reached in this run; the bell leg is covered by the mutation (a) below, which makes the matrix and the bell length case fail. |

## Mutation runs (security-critical, GREEN code)

Each run was applied to the committed `TaskNotification.php`, confirmed visible in the container, and reverted with `git checkout -- app/Domain/Tasks/Notifications/TaskNotification.php`.

| Mutation | Result | Revert |
|---|---|---|
| (a) bell escaper returns its input unchanged (`e($value)` and `e($line)` replaced by the raw value) | 8 failed: the end-to-end case, all 6 matrix datasets and `cuts the bell excerpt to 120 characters as plain text before it is escaped` | reverted, file green again |
| (b) mail escaper returns its input unchanged (`return $text;` at the top of `escapeMarkdown`) | 8 failed: the end-to-end case, all 6 matrix datasets and `shows the excerpt of the notifier complete in the mail...` | reverted, file green again |
| (c) `final` removed from `toMail` | 1 failed: `keeps the render methods final and the subclasses to constructors and text hooks, with no translation call` | reverted, file green again |
| (d) `<` and `>` put back into the Markdown escape set | 8 failed: the end-to-end case, all 6 matrix datasets and the 300-character case; the matrix failure reads `notifMarkupVisibleDefects` returned `['&lt;', '&gt;']` (the visible entity) | reverted, file green again (25 passed) |

## Task Commits

1. **Task 1: Tracer, markup title and name rendered as plain text** - `87de717` (test, RED), `fc767ca` (feat, GREEN)
2. **Task 2: matrix, completeness, structure and mutation proofs** - `2c3c63b` (test)
3. **Task 3: CONTRIBUTING rule, documentation test, closing gate** - `4cd82fd` (docs)

**Plan metadata:** recorded in the docs commit that follows this file.

## Files Created/Modified

- `app/Domain/Tasks/Notifications/TaskNotification.php` - single escaping point; final `toMail`/`toDatabase`; `textGroup()`, `changeLines()`, `bellBodyKey()` hooks; private `text()`, `bellExcerpt()`, `escapeMarkdown()`
- `app/Domain/Tasks/Notifications/TaskCreatedNotification.php`, `TaskCommentedNotification.php`, `TaskEscalatedNotification.php`, `TaskChangedNotification.php` - constructors unchanged; text methods removed
- `tests/Isolation/NotificationMarkupTest.php` - new: end-to-end, matrix and pin cases
- `CONTRIBUTING.md` - escaping rule in the "Task notifications" convention
- `tests/Feature/Repo/RepositoryFilesTest.php` - `NotificationMarkupTest` in the enforcing-test list; `TaskNotification` in the class map plus a backticked-name expectation

## Decisions Made

- Escaping only in the base class at render time, from raw scalars, so a retried queued delivery or the mail and the bell of one notification never escape cumulatively.
- `<` and `>` stay out of the Markdown escape set (documented in the method comment): the mail renderer HTML-encodes them first. `e()` is not applied to mail lines (it would double-encode).
- The excerpt is cut as plain text (120 in the bell, 300 by `TaskNotifier::excerpt`) before it is escaped.

## TDD Gate Compliance

Task 1 has `test(05-19)` `87de717` before `feat(05-19)` `fc767ca`. Task 2 is test-only by design (the Task 1 code already satisfies it); its guard is the four mutation runs. No refactor commits were needed.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical functionality] Markdown escaper also flattens white space**
- **Found during:** Task 1 (GREEN)
- **Issue:** The plan's escape set cannot stop a value that contains a line break from starting a new Markdown block (for example a heading or list) in the mail, because the escaper only handles single characters. Task titles and names are single-line in practice, but the mail path should not rely on that.
- **Fix:** `escapeMarkdown` first collapses runs of white space (including line breaks) into one space. Excerpts were already collapsed by `TaskNotifier::excerpt`, so ordinary text is unchanged.
- **Files modified:** `app/Domain/Tasks/Notifications/TaskNotification.php`
- **Verification:** all notification suites green unchanged; matrix and end-to-end cases pass
- **Committed in:** `fc767ca`

---

**Total deviations:** 1 auto-fixed (1 missing critical)
**Impact on plan:** Defence in depth only; no behaviour change for ordinary text, no scope creep.

## Verification (closing gate of the gap closure)

- `ddev exec vendor/bin/pest`: 2015 passed (14955 assertions), including the unchanged `TaskNotificationsTest`, `NotificationLeakTest`, `TaskEscalationTest`, `PartnerTaskCommentsTest`, `NotificationPreferencesTest` and the concurrency proofs. No commit of this plan touches `TaskNotificationsTest.php` or `NotificationLeakTest.php` (`git log --grep='(05-19)'` on them prints nothing).
- `ddev exec vendor/bin/pint --test`: pass. `ddev exec vendor/bin/phpstan analyse`: no errors.
- `ddev composer check-licenses`: 210 packages, every licence allowed. `bash scripts/tests/test-gitignore.sh`: 112 assertions passed, 0 failed. `scripts/check-sensitive.sh --all`: clean.
- The lefthook hook (sensitive-content, gitleaks) was clean on every commit.

## Requirements marking

- `requirements.ready-ids ... TA-07 TA-04` returned `ready: [TA-07, TA-04]`, `blocked: []`.
- `requirements.mark-complete TA-07 TA-04` returned `updated: false`, `marked_complete: []`, `already_complete: [TA-07, TA-04]`, `not_found: []`, and every `write_set` entry `applied: false` (checkbox and traceability surfaces). Both IDs were already marked Complete in `.planning/REQUIREMENTS.md`, so nothing was written; this plan did not change their status.

## Issues Encountered

- DDEV file-sync latency again delayed the container seeing edited files (first run after the subclass edit failed with the old file; re-run after a short wait). Mutations were confirmed visible in the container before each run. No effect on committed code.

## Known Stubs

None.

## Threat Flags

None. No new endpoint, auth path, file access or schema change; T-05-44 is mitigated and tested, T-05-SC accepted (no package added).

## For the owner

- **Plain-text part of the mail:** the plain-text alternative of a notification mail shows a backslash before an escaped Markdown character of a value (for example `\(` in a title with brackets), as it already did for excerpts and change lines. The HTML part, which mail clients show, and the bell read exactly as written. Accepted by the plan.
- **Open human check (Task 3, extends UAT test 3 of 05-UAT.md):** restart the worker first (`ddev artisan horizon:terminate`). As a fictional Partner, create a task whose title holds a Markdown link and an HTML anchor to an example.com address, comment on it, then open the Admin's two mails in Mailpit and the Admin's bell. Expected: title and name appear exactly as typed as plain text, no clickable link, image or styling from them, no visible backslash or entity in the HTML view and the bell.
- **Recommended 05-VALIDATION rows** (05-VALIDATION.md was not edited, per the plan):
  - 05-18: `tests/Feature/Tasks/TaskUpdateTest.php` (people options keep the stored person, refuse new picks) and `tests/Feature/Tasks/TaskActionsTest.php` (Partner tags dropped), covering CR-01 and G-1, with MUT-P1 and MUT-P2 recorded in 05-18-SUMMARY.md.
  - 05-19: `tests/Isolation/NotificationMarkupTest.php` (end-to-end plus the six class/audience matrix, subject, idempotency, 120/300 cuts, completeness and structure pins) and `tests/Feature/Repo/RepositoryFilesTest.php` (CONTRIBUTING pin), covering T-05-44, U-1 and WR-02, with mutations (a) to (d) above.
- Not touched, still open in 05-REVIEW-DISPOSITION.md: WR-01, WR-03, WR-04, IN-01 to IN-07, UI-REVIEW flags, pending UAT, and the Operations alert notifications.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

The gap closure of Phase 5 ends with a green gate (full suite, Pint, Larastan, licences, .gitignore self-test, sensitive scan). Remaining work is the later gates that update 05-VALIDATION.md, 05-SECURITY.md, 05-VERIFICATION.md and 05-REVIEW-DISPOSITION.md, and the pending Mailpit check.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*

## Self-Check: PASSED

- Files present: TaskNotification.php, the four subclasses, NotificationMarkupTest.php, CONTRIBUTING.md, RepositoryFilesTest.php.
- Commits 87de717, fc767ca, 2c3c63b, 4cd82fd are ancestors of HEAD.
