---
phase: 05-tasks-and-kanban
plan: 17
subsystem: documentation
tags: [contributing, readme, documentation-test, phase-gate, gitignore-review, requirements]

requires:
  - phase: 05-tasks-and-kanban
    provides: every Phase 5 mechanism of plans 05-01 to 05-16 (CreateTask, TaskBoard, RichText, TaskBillingResolver, TaskNotifier and their tests)
provides:
  - CONTRIBUTING conventions for tasks and numbering, board, rich text, task billing and task notifications, each naming its enforcing test
  - Extended "Partner-visible data" convention and add-a-model checklist (Partner column pin, canary text column)
  - Hand-over notes for Phases 6, 7, 9 and 10 (replacing the single Phase 5 note)
  - README operator note on queued task notifications
  - Phase 5 documentation tests in RepositoryFilesTest (named classes, methods, constants and tests must exist)
  - REQUIREMENTS and PROJECT import wording corrected to the number_sequences rows (research correction C1)
  - Green phase gate (full Pest, Pint, Larastan, licence check, shell self-tests, gitignore test, sensitive scan of the whole tree)
affects: [phase-06 time tracking, phase-07 calendar and reports, phase-09 documents, phase-10 invoicing, /gsd-verify-work for phase 5]

actuals:
  tokens: 6000
  tasks: 2
  commits: 1

tech-stack:
  added: []
  patterns:
    - "Every convention bullet names the test that enforces it, with the full test path in backticks, so the existing path-existence documentation test checks the reference"
    - "A documentation test fails when a named class, method or constant is gone, and when the superseded single Phase 5 hand-over note comes back"

key-files:
  created: []
  modified:
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - .planning/REQUIREMENTS.md
    - .planning/PROJECT.md

key-decisions:
  - "The Phase 5 documentation test also checks methods and constants (appendToColumn, move, clean, render, nextTaskNumber, MAX_LENGTH, the two Partner pin constants), not only class names, because the conventions rely on them"
  - "The .gitignore review found no new tool output in Phase 5, so .gitignore and test-gitignore.sh are unchanged"
  - "The stale counter wording was also fixed in PROJECT.md (one line, outside files_modified) because it repeated the same wrong projects.next_task_number claim"

patterns-established:
  - "Pattern: a hand-over note per later phase names the rule that phase must keep and the class that holds it"

requirements-completed: [TA-01, TA-02, TA-03, TA-04, TA-05, TA-06, TA-07, KB-01, KB-02, KB-03]

coverage:
  - id: D1
    description: "CONTRIBUTING documents the Phase 5 mechanisms (tasks and numbering, board lock order, rich text, task billing resolver, notifier with the internal-comment rule), the Partner-visible data extension, the hand-over notes for Phases 6, 7, 9 and 10 and the extended add-a-model checklist"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 5 classes in CONTRIBUTING.md that exist in app/"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#names the Phase 5 enforcing tests and the hand-over notes for Phases 6, 7, 9 and 10"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 4 tests in CONTRIBUTING.md that exist"
        status: pass
    human_judgment: false
  - id: D2
    description: "README tells an operator that task notifications reach the Admin and the Partners by e-mail through the queue, so the mail settings and the queue worker must run"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#documents the queued task notifications for the operator in the README"
        status: pass
    human_judgment: false
  - id: D3
    description: "The requirements no longer point the import at projects.next_task_number; the import sets the number_sequences rows"
    requirement: TA-02
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#no longer points the import at a projects counter column"
        status: pass
    human_judgment: false
  - id: D4
    description: "The .gitignore was reviewed for Phase 5 tool output; none found, no rule added"
    verification:
      - kind: other
        ref: "bash scripts/tests/test-gitignore.sh (112 assertions passed, 0 failed)"
        status: pass
    human_judgment: false
  - id: D5
    description: "The phase gate is green: full Pest suite including both concurrency proofs, Pint, Larastan, licence check, shell self-tests, sensitive scan of the whole tree"
    verification:
      - kind: integration
        ref: "ddev exec vendor/bin/pest (1982 passed, 14734 assertions)"
        status: pass
      - kind: other
        ref: "ddev exec vendor/bin/pint --test; ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G; ddev composer check-licenses; bash scripts/tests/run.sh --quick; scripts/check-sensitive.sh --all"
        status: pass
    human_judgment: false
  - id: D6
    description: "Czech copy review of the strings added in Phase 5 and the end-to-end walk as Admin and as a fictional Partner"
    verification: []
    human_judgment: true
    rationale: "Natural Czech and the feel of the whole flow are a human reading; the plan lists both as human checks for /gsd-verify-work (auto mode did not run them)"

duration: 9min
completed: 2026-10-09
status: complete
plan_head_before: bac560c4d13ca9b2b73365308dc0429b184542b0
plan_head_after: a9fc9bc8ee83e8c65f934902fd1b14e0bf01dae1
---

# Phase 5 Plan 17: Phase Gate and Documentation Summary

**CONTRIBUTING now carries every Phase 5 rule next to the test that enforces it, with hand-over notes for Phases 6, 7, 9 and 10; a documentation test fails when a named class, method or test disappears; the import wording no longer points at a counter column that never existed; and the full phase gate is green (1982 Pest tests, Pint, Larastan level 8, licences, shell self-tests, sensitive scan).**

## Performance

- **Duration:** about 9 min
- **Started:** 2026-10-09T00:49:42Z
- **Completed:** 2026-10-09T00:58Z
- **Tasks:** 2 (a tracer and an auto task)
- **Files modified:** 5 (all in one commit; the second task changed no file)

## Accomplishments

- CONTRIBUTING Conventions gained five bullets: "Tasks and numbering" (CreateTask, `DocumentNumbering::nextTaskNumber`, the KP002 key freeze), "Board" (every status or position write through `TaskBoard` under the advisory lock in the order board lock, project, counter), "Rich text" (`RichText::clean` and `RichText::render` only), "Task billing" (`TaskBillingResolver` as the single read) and "Task notifications" (`TaskNotifier`, scalars only, preferences that only narrow, the unconditional internal-comment rule). Each names its enforcing tests by full path.
- The "Partner-visible data" convention now covers `tasks` and `task_comments` (pinned columns, the Partner builders of `TaskColumns`, `PartnerTaskVisibilityTest`) and the Admin-only `TaskChecklistItem` and `TaskBilling` tables. Checklist item 9 names the three pinned tables and the canary text column (`task_billing.internal_note`).
- The single Phase 5 hand-over note is replaced by notes for Phase 6 (non-billable tasks pre-set billable false, rate and estimate from the resolver, the timer starts from a task), Phase 7 (tasks are addressed by `tasks.reference`), Phase 9 (task and comment attachments, the reserved section, internal-comment attachments never reach a Partner) and Phase 10 (`task_billing` is read through the resolver).
- README: an Operations paragraph says task notifications go to the Admin and the Partners by e-mail and bell, through the queue, so the `MAIL_*` settings and the queue worker must run, and that the profile switches only narrow delivery.
- `RepositoryFilesTest` has four new tests (26 in the file now): eleven Phase 5 class names plus methods and constants must exist and be named, the twelve enforcing tests and the four hand-over notes must be present (and the old Phase 5 note gone), the README note exists, and REQUIREMENTS no longer mentions `next_task_number`.
- REQUIREMENTS "Out of Scope" row "Import from previous tool" and the matching PROJECT line now name the `number_sequences` rows (`task:<project uuid>`).

## Task Commits

1. **Task 1 (tracer): CONTRIBUTING, README, documentation tests, requirements correction** - `a9fc9bc` (docs)
2. **Task 2: .gitignore review and the full phase gate** - no commit; the review found nothing to change and the gate needed no fix (see below)

**Plan metadata:** the `docs(05-17)` commits that add this SUMMARY, STATE.md, ROADMAP.md and REQUIREMENTS.md.

Tracer gate (auto mode): the tracer `<verify>` (`RepositoryFilesTest`, 26 passed, Pint clean) and the three acceptance greps passed before Task 2; logged "Tracer verified end-to-end - expanding".

## Phase Gate Results

| Check | Command | Result |
|---|---|---|
| Full test suite (both concurrency proofs included) | `ddev exec vendor/bin/pest` | 1982 passed (14734 assertions), 159 s, exit 0 |
| Formatting | `ddev exec vendor/bin/pint --test` | PASS, 455 files |
| Static analysis | `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | [OK] No errors |
| Licences | `ddev composer check-licenses` | 210 packages, every one has an allowed licence |
| Gitignore self-test | `bash scripts/tests/test-gitignore.sh` | 112 assertions passed, 0 failed |
| Shell self-tests | `bash scripts/tests/run.sh --quick` | PASS 8, FAIL 0, SKIP 2 (the two skipped need lefthook or gitleaks run mode of the full suite) |
| Sensitive scan | `scripts/check-sensitive.sh --all` | clean (whole tree, generic patterns; no local denylist set) |
| Hook | lefthook `sensitive-content` and gitleaks on the commit | passed |

## .gitignore Review

No change needed. Phase 5 added no tool output: `git status --ignored` shows only the known ignored classes (`.env`, `vendor/`, `storage/framework/*` content, `storage/logs/laravel.log`, `public/{css,fonts,js}` Filament assets, `bootstrap/cache`, `.idea/`, `.ddev` and `.claude` generated files, the untracked-by-design `.planning/codebase/`). The only new published vendor file of the phase, `config/eloquent-sortable.php`, is source: it is tracked and `git check-ignore --no-index` confirms it is not ignored. No rule and no case in `scripts/tests/test-gitignore.sh` was added.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing critical] The same stale counter claim sat in PROJECT.md**
- **Found during:** Task 1 (`git grep next_task_number`)
- **Issue:** `.planning/PROJECT.md` (Out of Scope list) repeated "the import must set counters (`projects.next_task_number`, `number_sequences`)", the claim research correction C1 refutes. A later reader of PROJECT.md would be sent to a column that does not exist.
- **Fix:** One-line wording change to name the `number_sequences` rows. Other planning files that mention the column (CONTEXT, RESEARCH, `.planning/research/*`) are historical records of the correction itself or pre-build research and were left as they are.
- **Files modified:** `.planning/PROJECT.md` (outside `files_modified`)
- **Committed in:** `a9fc9bc`

**2. [Plan nuance] The new documentation test caught two unnamed classes**
- **Found during:** Task 1 (first test run)
- **Issue:** The draft convention text named the tables `task_comments` and `task_checklist_items` but not the models `TaskComment` and `TaskChecklistItem`; the test (written from the plan's class list) failed on the first.
- **Fix:** The "Partner-visible data" convention names both models. This is the test doing its job, not a defect of the code.
- **Committed in:** `a9fc9bc`

**Total deviations:** 1 auto-fixed (Rule 2) and 1 plan nuance. **Impact:** none on scope; one extra file touched by a single line.

## Issues Encountered

- A `ddev exec` run right after a file write could read a stale copy (bind-mount timing, noted in every earlier plan); a few seconds of wait before each Pest or Pint run was used and the test counts were checked (26 in `RepositoryFilesTest`, 1982 in the full run, which is 1978 + the 4 new tests).
- `PIPESTATUS` is not available in the zsh used by the Bash tool, so the exit codes of the piped gate commands were read from their printed results (each ended with its own success line) rather than from a status variable.
- The unrelated uncommitted files `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock` were not touched or staged.

## For the owner

Open items for the owner to confirm or decide. None of them blocks the phase gate; each is a recorded decision or an assumption the plans adopted.

**Assumptions the plans adopted and the owner should confirm (research A-numbers):**

- **A1:** tags and the checklist of a task are hidden from Partners (task tags are Admin-only; the checklist lives in an Admin-only table). A Partner sees only project tags, as before.
- **A2:** the stable task address is `/admin/tasks/KEY-N` (every resource lives under the panel path); there is no root-level `/tasks/KEY-N` redirect. A lower-case key resolves to the same task.
- **A5:** "relevant changes" that notify the Partner side are status, priority and assignee; dates, title and description do not notify; one notification per save lists every change. The recipient matrix is: a Partner task or comment tells the Admin (and an eligible assignee); a non-internal Admin comment tells the Partner requester and assignee; an escalation goes to the assignee. The escalation recipient and who may clear the flag are not owner items, they follow the locked decisions D-06 and D-07 (the assignee, also a Partner assignee, is notified with the Admin as fallback when the assignee cannot receive; the assignee or the Admin clears the flag).
- **A13:** an assignee who can receive the escalation but switched it off on both channels gets nothing, and the Admin is not told in that place, because the D-15 preferences only narrow delivery. This is the planner's reading of where the D-07 Admin fallback ends; the flag on the board card stays visible. Please confirm, or choose the Admin fallback for that case: it is one branch in `TaskNotifier::escalated` plus one test (the A13 case flips).
- **A6:** a Partner cannot edit a task or a comment after creating it; comments are append-only for everyone.
- **A9:** deletion is archive-only; a parent with active subtasks cannot be archived (refused, not cascaded), and a subtask of an archived parent cannot be restored until the parent is.
- **A8:** the rich-text limit is 100000 and is counted in bytes (about 90000 characters of Czech text), a longer text is a field error and nothing is cut silently.
- `task_billing.internal_note`: a nullable text column added to the Admin-only billing table so the canary harness can prove the table non-vacuously (never activity-logged).
- No rate limit on Partner notifications (research Pitfall 9): a Partner could flood the Admin's mail and bell; the per-user switches are the only control.

**Open items found while building (explicit decisions still pending):**

1. **UI-SPEC F-8:** `ProjectStatus::InReview` returns `'primary'` (the accent colour) while the contract wants a non-accent colour. The proposed one-line enum change touches Phase 4 code and was NOT made. Status badges on the task screens use the enum as built.
2. **UI-SPEC F-9:** label changes on the built Admin task pages ("Uložit úkol" and the object-bearing header action labels) were not made; only the profile page got its specific save label ("Uložit nastavení").
3. **UI-SPEC E-5:** a refused or stale board move (a card archived or moved by another session) still ends in the framework error instead of the planned toast "Úkol už není dostupný. Nástěnka se obnovila.".
4. The UI-SPEC was force-approved after the maximum of 2 revision rounds; the one open copywriting point (the profile save label) was fixed in plan 05-14.
5. Two owner todos captured during the phase are still pending: sort tasks by priority, then nearest due date, by default (todo `2026-10-08-sort-tasks-by-priority-then-nearest-due-date-by-default`; the lists are ordered by update time, the Partner list by creation time) and install and configure `fruitcake/laravel-debugbar` for development (todo `2026-10-08-install-and-configure-laravel-debugbar-for-development`).
6. The codebase map in `.planning/codebase` is stale (a codebase-drift advisory has been shown since wave 1); it is also gitignored by design. Recommended: run `/gsd-map-codebase`.

**Smaller behaviours worth knowing (from the plan SUMMARIES):**

- The quick-create project select on a project board is preset but still editable; UI-SPEC Surface E says "not editable" (`TaskResource` was outside plan 05-11).
- A notification goes to every active account with the Admin role (one in practice); the project is named by its key in notification texts.
- The old assignee of a reassignment is not told; only the current Partner requester and assignee are.
- The checklist repeater saves the whole list, so two simultaneous Admin sessions on one checklist end with the last save.
- The estimate inherits literally (research A7); Phase 6 decides whether tracked time is compared with an inherited estimate.
- Moving a card out of a column leaves a gap in its positions by design; only the target column is contiguous.

## Human Checks for `/gsd-verify-work`

Auto mode did not run any of these (they need a browser, a mail UI or a reading human). Collected from the plans:

1. **Czech copy review (this plan):** read every new string in `lang/cs/kokpit.php` and `lang/cs/enums.php` added in this phase (tasks, partner_tasks, task_board, notifications, task billing types, billing sources, notification events and channels). Expect natural Czech, consistent terms (úkol, podúkol, řešitel, zadavatel, eskalace, interní komentář), no English left.
2. **End-to-end walk as Admin and as a fictional Partner (this plan):** a Partner creates a task and comments; the Admin gets the bell and the mail; the Admin answers with an internal and a public comment; the Partner sees only the public one; the Partner escalates; the Admin clears the flag and moves the card on the project board; a second fictional Partner escalates a task assigned to the first Partner, who gets the escalation in the bell and clears the flag from the own task page without any priority or status control. Expect every step to match roadmap success criteria 1 to 5.
3. **Touch drag at 375 px and SPA navigation (plan 05-11, research A3 and A4):** drag a card across columns and into an empty column on a phone or emulated touch, scroll without starting a drag, drop inside Done (it snaps back, A11), open a task from a card and come back, drag again without a full reload.
4. **Mailpit (plan 05-16):** the four notification mails (task created, comment, escalation, change) through a running queue worker, in a real mail client view.
5. **Visual checks (plans 05-04 to 05-16):** the rich-text editor toolbar, the subtasks tab and archive confirmation, the checklist repeater drag feel, the billing sections, the slide-over and the project board in light and dark mode, "Moje úkoly" populated and empty, the escalation modal and the danger badge, and the profile "Upozornění" rows at 375 px.

## Known Stubs

None. The task attachments section is an intentional, tested reservation for the documents phase (D-11), not a stub.

## Threat Flags

None. This plan adds documentation, a test and a wording fix. T-05-41 (real names, addresses or values in the documentation) is mitigated: only fictional examples were used, `scripts/check-sensitive.sh --all` is clean over the whole tree, and the lefthook hook and gitleaks passed on the commit.

## User Setup Required

None - no external service configuration required.

## Next Phase Readiness

Phase 5 is complete and green: all 17 plans have a SUMMARY, all of TA-01 to TA-07 and KB-01 to KB-03 are covered by passing automated tests, and the human checks and owner decisions above are written down. Ready for `/gsd-verify-work` for Phase 5, then Phase 6 (time tracking), which finds its rules in the CONTRIBUTING hand-over notes.

## Self-Check: PASSED

- Modified files exist on disk: `CONTRIBUTING.md`, `README.md`, `tests/Feature/Repo/RepositoryFilesTest.php`, `.planning/REQUIREMENTS.md`, `.planning/PROJECT.md`.
- Commit `a9fc9bc` is an ancestor of HEAD; `git rev-list --count` from the recorded base `bac560c` gives 1.
- All acceptance criteria re-run: the CONTRIBUTING greps (`TaskBoard`, `TaskNotifier`, `RichText`, `KP002`), `TaskBillingResolver` in the test file, `task:<project uuid>` in REQUIREMENTS.md, `RepositoryFilesTest` exit 0, the full Pest suite exit 0, `check-licenses` and `test-gitignore.sh` exit 0.

---
*Phase: 05-tasks-and-kanban*
*Completed: 2026-10-09*
