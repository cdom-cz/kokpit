---
phase: 05-tasks-and-kanban
verified: 2026-10-09T10:05:00Z
status: gaps_found
score: 5/5 roadmap success criteria verified; 1 plan-level gate truth failed (test-only, see gaps)
covered_files: [".planning/phases/05-tasks-and-kanban/05-01-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-01-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-02-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-02-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-03-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-03-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-04-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-04-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-05-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-05-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-06-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-06-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-07-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-07-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-08-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-08-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-09-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-09-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-10-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-10-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-11-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-11-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-12-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-12-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-13-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-13-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-14-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-14-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-15-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-15-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-16-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-16-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-17-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-17-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-18-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-18-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-19-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-19-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-20-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-20-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-21-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-21-SUMMARY.md", "CONTRIBUTING.md", "README.md", "app/Domain/Notifications/NotificationEvent.php", "app/Domain/Tasks/Actions/UpdateTaskDescription.php", "app/Domain/Tasks/Notifications/TaskChangedNotification.php", "app/Domain/Tasks/Notifications/TaskNotification.php", "app/Domain/Tasks/Notifications/TaskNotifier.php", "app/Domain/Tasks/Policies/TaskPolicy.php", "app/Filament/Auth/EditProfile.php", "app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php", "app/Filament/Resources/ActivityResource.php", "lang/cs/kokpit.php", "tests/Feature/Notifications/NotificationPreferencesTest.php", "tests/Feature/Repo/RepositoryFilesTest.php", "tests/Feature/Tasks/PartnerTaskDescriptionTest.php", "tests/Feature/Tasks/TaskEscalationTest.php", "tests/Isolation/NotificationMarkupTest.php", "tests/Isolation/PartnerTaskVisibilityTest.php"]
covered_digest: "v3:sha256:7fdc58cee75983fb71b6083a9522c969541735dd253d52226fc3ae7c22096bf3"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 5/5
  gaps_closed:
    - "UAT G-05-5: a Partner can edit the description of an own task (owner rule D-16: Planned or To clarify only) through the description-only action, with history row and Admin/assignee notification (plans 05-20, 05-21)"
  gaps_remaining:
    - "Closing-gate truth of plan 05-21 (green full Pest suite) is not reliably true: one new test is flaky"
  regressions: []
gaps:
  - truth: "The closing gate of the G-05-5 gap closure is green: full Pest suite (plan 05-21 must-have; 05-21-SUMMARY claims 2036/2043 passed)"
    status: failed
    reason: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php, case 'it lets any Partner of the client edit the description of a task in an editable status, whatever its requester or assignee' (dataset 'planned') fails intermittently at line 254 (`array_last(partnerDescEdits($task))->causer_id` is the first Partner instead of the second). Cause: the helper `partnerDescHistory()` (line 69-76) orders activity rows by `created_at` only, the activity_log timestamps are stored at whole-second precision (debug output showed `.000000`), and two edits by two Partners inside the same second tie, so the 'last' row is arbitrary. Observed: failed in 4 of 7 runs of `tests/Feature/Tasks` (and in the first run of the 883-test phase subset), passed in 4 of 4 runs of the file alone and in the clean full run. The production code is correct (debug output showed both `description_changed` rows with the right causers); the defect is in the test only."
    artifacts:
      - path: "tests/Feature/Tasks/PartnerTaskDescriptionTest.php"
        issue: "partnerDescHistory() orders by created_at only; the assertion at line 252-254 takes array_last() of a tied ordering"
    missing:
      - "Make the ordering deterministic (add a tiebreaker such as `->orderBy('id')`, UUID v7 ids are time ordered across separate requests) or assert on the set of causers instead of the last row"
      - "Re-run `ddev exec vendor/bin/pest tests/Feature/Tasks` at least five times and the full suite once, all green, and record it"
deferred: []
advisory: []
human_verification:
  - test: "Browser walk of the description edit (plan 05-21 Task 3 human-check, extends UAT test 5). Restart the worker first (`ddev artisan horizon:terminate`). As a fictional Partner open an own task in the status Plánovaný at /admin/my-tasks/KEY-N, click 'Upravit popis', change the text and save. Then open the modal again and save without changing anything. Then open an own task in the status V realizaci. Then, as the Admin, open the task history, the bell, Mailpit and the profile page."
    expected: "The page shows the new text. The task in V realizaci offers no 'Upravit popis'. The unchanged second save changes nothing and sends nothing (record in the SUMMARY or UAT whether the browser rich-text editor re-serialises an unchanged text so that it counts as a change, which would make the second save write a history row and send a notice). Admin: the task history shows 'Popis upraven' with the Partner's name; the bell and Mailpit hold one 'Změna úkolu KEY-N' with the line 'Popis upraven uživatelem ...' and no description text, with a working button to /admin/tasks/KEY-N; the profile page shows the 'Změna úkolu' switches with the helper 'Klient upravil popis úkolu.'."
    why_human: "Needs a real browser, a restarted queue worker, Mailpit and the TipTap editor. Pest drives the Livewire action directly, so the editor's serialisation of an unchanged text, the modal rendering and real mail delivery are not exercised. The executor of 05-21 did not perform this walk and did not claim it passed."
  - test: "Re-run UAT test 5, the end-to-end Admin and Partner walk that raised G-05-5 (05-17-PLAN human-check): Partner creates a task and comments, Admin receives bell and mail, Admin answers with an internal and a public comment, Partner sees only the public one, Partner escalates, Admin clears the flag and moves the card, a second fictional Partner escalates a task assigned to the first Partner, who clears the flag from the own task page with no priority or status control. Add: the Partner edits the description where the status allows it."
    expected: "Every step matches ROADMAP success criteria 1 to 5, and the reporter of G-05-5 confirms the Partner can now edit the description where applicable. 05-UAT.md test 5 can then move from 'issue' to 'pass' and G-05-5 can be closed."
    why_human: "Whole-flow judgement across two panels, queue worker, mail and bell; UAT test 5 is still recorded as 'issue' and only the owner can close it."
  - test: "Mailpit and bell rendering of a markup title (05-19-PLAN human-check): create a task whose title holds a Markdown link and an HTML anchor to an example.com address, comment on it as a Partner, and also edit its description; open the Admin's mails and bell."
    expected: "Title and display name read exactly as typed as plain text: no clickable link, image or styling, no visible backslash or entity in the HTML view and the bell (the text view may show backslashes, accepted in 05-19). Includes the new description-change mail for the Admin audience."
    why_human: "Visual rendering in a real mail client and the bell. 05-UAT.md test 3 lists only the 05-16 steps, so it is not evident that the 05-19 markup extension was walked; the new Admin audience of TaskChangedNotification was added after that test."
  - test: "Czech copy review of the strings added by plans 05-20 and 05-21 in lang/cs/kokpit.php: partner_tasks.actions.edit_description, edit_description_heading, edit_description_submit, partner_tasks.notifications.description_saved, tasks.errors.description_stale, description_not_editable, tasks.notifications.changed.description, notifications.profile.helpers.assignment_change_admin, activity.events.description_changed"
    expected: "Natural Czech, terms consistent with úkol, popis, klient, no English left. UAT test 4 passed before these nine strings existed."
    why_human: "Wording judgement."
  - test: "Light and dark mode look of the new 'Upravit popis' modal and button on the Partner task page, and of the Admin's new 'Změna úkolu' row on the profile page"
    expected: "Gray action button readable in both modes, editor toolbar usable, helper text legible. UAT test 6 passed before these elements existed."
    why_human: "Visual check, no browser audit available."
---

# Phase 5: Tasks and Kanban Verification Report

**Phase Goal:** Admin organises work as tasks and subtasks with per-project keys and a drag-and-drop board, and a Partner can raise and discuss tasks in visible projects without seeing anything internal
**Verified:** 2026-10-09T10:05:00Z
**Status:** gaps_found
**Re-verification:** Yes, after gap-closure plans 05-20 and 05-21 (UAT gap G-05-5). This report replaces the one written after 05-18 and 05-19.

Method: goal-backward against the five ROADMAP success criteria and the plan-level truths of 05-20 and 05-21, reading the code rather than the SUMMARYs, and running the tests myself.

**Headline.** The phase goal is achieved in the code. The ten requirements are satisfied, G-05-5 is closed on the code side, and the roadmap criteria hold. One plan-level truth fails: the closing gate "green full suite" is not reliably green because one new test added by 05-20 is flaky (test-only defect, one-line fix). Several browser/mail checks remain for a human, including the 05-21 Task 3 walk, which nobody has done.

## Evidence base (what I ran at HEAD `8b6db83`)

Working tree: only `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock` differ from HEAD (no source changes). I temporarily instrumented one test to debug the flake and restored it byte for byte (`git status` clean for tests afterwards).

| Run | Result |
|-----|--------|
| `pest tests/Feature/Tasks tests/Isolation tests/Arch tests/Feature/Schema tests/Concurrency tests/Feature/Notifications tests/Feature/Repo` | 883 passed, **1 failed** (the flaky case below) |
| `pest tests/Feature/Tasks/PartnerTaskDescriptionTest.php` x4 | 28 passed each time |
| `pest tests/Feature/Tasks` x7 | 4 runs with 1 failure (same case), 3 runs 365 passed |
| `pest tests/Concurrency` | 10 passed (209 assertions) |
| Full `pest`, clean run | 2047 passed, 15327 assertions |
| Full `pest`, run overlapping an aborted background run on the shared `kokpit_test` database | 4 Concurrency failures; not counted, caused by my own overlapping run, the same files pass alone |
| Pint `--test app tests` | pass, 392 files |
| PHPStan | No errors |
| `scripts/check-sensitive.sh --all` | clean |
| Debt-marker scan (`TBD|FIXME|XXX|TODO|HACK`) over the 7 new or changed source and test files of 05-20/05-21 | no matches |

## Gaps

| Gap | Severity | Detail |
|-----|----------|--------|
| Flaky test in the G-05-5 closing gate | Low (test-only), but it falsifies the plan 05-21 truth "full Pest suite is green" | See the `gaps:` entry. `partnerDescHistory()` orders by `created_at` only; the `activity_log` timestamps have second resolution, so two edits in one second tie. Reproducible at about 55 percent in the `tests/Feature/Tasks` directory run. Fix: add `->orderBy('id')` (or assert on the causer set). Production behaviour is not affected: with debug output both `description_changed` rows were present with the correct causers. |

## Gap Closure (re-verification focus): G-05-5

UAT test 5 reported: the Partner should also be able to edit the task description, where applicable. Owner rule D-16 (05-CONTEXT.md): a Partner may edit the description only on a task of an own client-visible project in the status Plánovaný or K upřesnění, nothing else of the task.

| Must-have (plan 05-20) | Status | Evidence in code |
|---|---|---|
| Header action 'Upravit popis' with a description-only modal (no file attachments), filled with the stored text, saving the `RichText::clean` result | VERIFIED | `ViewPartnerTask::editDescriptionAction()` (RichEditor `description`, `fileAttachments(false)`, Partner create toolbar, hidden `based_on`), calls only `UpdateTaskDescription::handle($actor, $task, $description, $basedOn)`; the Action cleans through `TaskInput::description`. Tests: first and `stores and shows an edited Partner description without any hostile part (D-10)` green. |
| Nothing but the description changes; no edit page, route or row action; `UpdateTask` still refuses Partners | VERIFIED | The Action takes no data array and writes `forceFill(['description' => $clean])` only. `PartnerTaskResource::getPages()` is still `index, create, view` (asserted in a test; `route:list` shows only those three `my-tasks` routes). `UpdateTask` line 97 `authorize('update')`; `TaskPolicy` has no Partner `update`. Tests `changes nothing but the description when the action payload is forged...`, `offers only the description editor in the modal and still refuses a Partner the full update`. |
| D-16 scope: any Partner of the client, visible non-archived project, task not archived, status Planned or To clarify; refused for the four other statuses, another client, hidden project, archived task | VERIFIED | `TaskPolicy::DESCRIPTION_EDITABLE_STATUSES = [Planned, ToClarify]`; `editDescription` = `ownsProjectOf` (through the Partner-scoped project relation) and not trashed and status in the list. Tests with datasets for every other status and for the isolation cases green. |
| Status re-checked on the row locked for the save; Planned to To clarify does not block | VERIFIED | `UpdateTaskDescription::handle`: scoped `lockForUpdate()->firstOrFail()` then `Gate::forUser($actor)->denies('editDescription', $locked)` raises a field error. Test `refuses the save when the status left the editable statuses while the editor was open` (two datasets, page and Action level). |
| Exactly one history row 'Popis upraven' with the Partner as author, no description text anywhere; Admin sees it, Partner reads no activity | VERIFIED | `activity()->useLog(...)->event('description_changed')->log('description_changed')` with no properties; `description` is absent from `Task`'s `#[LoggedAttributes]` allowlist (checked in the model). `ActivityResource` event filter has `description_changed`. Tests `writes no description text into the history row`, `filters the activity overview by the description edit`. |
| Hostile markup removed before storage and from both pages | VERIFIED | Same sanitiser as every write; test 377. |
| Over-long text is a field error and writes nothing; emptied editor stores null and is logged | VERIFIED | Tests 401, 414. |
| Stale save refused; unrelated change does not block | VERIFIED | `fingerprint()` compared with `hash_equals` on the locked row; tests 450, 480. |
| Unchanged save writes and logs nothing; same text twice logs once | VERIFIED | `if ($clean === $locked->description) return;` test 528. |
| Each guard has a mutation that fails a named test (recorded) | VERIFIED (claim), not re-run | 05-20-SUMMARY has a six-row mutation table (policy ownership, sanitiser, stale comparison, unchanged shortcut, policy status, locked-row re-check) with named failing cases. I did not repeat the mutations; the guards exist in code and the named tests pass. |
| Prohibitions (3, `verification: test`): no other attribute; no write outside D-16 statuses; no description text in logs | VERIFIED | Each has wired enforcement above (forgery test, status datasets, history-row test). None is unverified. |

| Must-have (plan 05-21) | Status | Evidence |
|---|---|---|
| A Partner's real description change notifies every active Admin by queued mail and bell: title 'Změna úkolu KEY-N', line 'Popis upraven uživatelem <name>', subject 'Změna úkolu KEY-N: <title>', link /admin/tasks/KEY-N; the author never | VERIFIED | `TaskNotifier::descriptionChanged` (Partner actors only; Admins from `admins()`; `unset` of the actor), line from `kokpit.tasks.notifications.changed.description`; `TaskChangedNotification` with `recipientIsPartner`; `afterCommit()` in the base. Called in `UpdateTaskDescription` only after the write. Test `tells the Admin of a Partner description edit by mail and in the bell with the admin link` green. |
| Assignee told when eligible and not the author; deactivated gets nothing; other-client Partner never | VERIFIED | `addCandidate` (active, same client and `partners_may_read`, or Admin); keyed by id so an Admin assignee is told once. Test `tells the assignee of the same client and never the author or a Partner of another client`. |
| No description text in mail, bell or queued payload | VERIFIED | The only variable text is the actor name; the notification holds raw scalars with `excerpt: null`. Test `puts no text of the description into the notification of the edit`. |
| Refused, stale, unchanged save and Admin edit send nothing; EditTask sends nothing | VERIFIED | Notifier call sits after the unchanged shortcut and is skipped for non-Partner actors. Tests `sends nothing for a refused, stale or unchanged save or an Admin edit through the Action`, `sends no notification when the Admin changes the description on the edit page`, `...saves only the description through UpdateTask`. |
| Admin profile switch 'Změna úkolu' with its own helper; Partner rows and helper unchanged | VERIFIED | `NotificationEvent::forRole(Admin)` now includes `AssignmentChange`; `helper(RoleName $role)` returns `assignment_change_admin` only for Admin plus AssignmentChange; `EditProfile` passes `$this->role()`. Partner key `assignment_change` text is unchanged in the lang diff. Tests in `NotificationPreferencesTest` and `lets the Admin narrow the description edit notice per channel` green. |
| Markup in title or actor name inert in the new Admin audience | VERIFIED | `NotificationMarkupTest` matrix extended (diff +4 lines) and green; the base class escaping is unchanged. |
| CONTRIBUTING names all Partner write paths; documentation test pins the symbols; README and UI-SPEC updated | VERIFIED | The CONTRIBUTING "Partner writes on tasks" bullet lists CreateTask, AddTaskComment, EscalateTask, ClearEscalation and UpdateTaskDescription and the rule of one ability and one Action per new path; README and the notification bullet extended; `RepositoryFilesTest` +6 lines, green. |
| Closing gate green: full Pest, Pint, Larastan, licence check, gitignore self-test, sensitive scan | **FAILED (test-only)** for the full-suite part | Pint, PHPStan and the sensitive scan are green in my run; the suite is intermittently red because of the flaky case (see Gaps). I did not re-run `check-licenses` or `test-gitignore.sh` (`RepositoryFilesTest` and the sensitive scan, which I did run, are green). |

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin creates tasks and one-level subtasks with title, status, description, dates, priority, assignee, tags, checklist, plus billing type, fixed price, rate override, estimate | VERIFIED (regression check) | `CreateTask`, `UpdateTask`, `TaskBillingResolver`, `task_billing` table unchanged by 05-20/05-21 (`git diff 4becb0f HEAD --stat` touches none of them); suites in `tests/Feature/Tasks` and `tests/Feature/Schema` green. Files on tasks stay Phase 9 per ROADMAP mapping note. |
| 2 | KEY-N from the project counter, no duplicate or gap under parallel creation, never reused, found by key in search and URL, project key frozen after the first task | VERIFIED | `tests/Concurrency` 10 passed (including `TaskNumberConcurrencyTest` 8 workers x 25, counter-lock-only and mutation runs), `TaskKeyTest`, `TasksTableTest`. API use of the key is Phase 7. |
| 3 | List filters by client, project, status, priority, assignee, tag, due date; comments on tasks and subtasks, optionally internal | VERIFIED | `TaskListFiltersTest`, `TaskCommentsTest` green. |
| 4 | Drag cards on per-project and global board; status and position persist; global filters | VERIFIED (server side); gesture confirmed by the owner in UAT tests 1 and 2 (pass) | `TaskBoardTest`, `TaskBoardConcurrencyTest` green; unchanged by 05-20/05-21. |
| 5 | Partner creates tasks and comments in visible projects but cannot change status or priority or move cards, sees a read-only list, never sees internal comments or another client's tasks; Admin notified of Partner tasks and comments | VERIFIED | The one new Partner write (description, D-16) changes neither status nor priority nor position and adds no edit page; `PartnerTaskVisibilityTest`, `PartnerSafeColumnsTest`, `NotificationLeakTest`, `PartnerTaskCommentsTest` and the canary registry green; Admin is now also notified of a Partner's description edit. |

**Score:** 5/5 roadmap success criteria verified, 0 present but behavior-unverified. One plan-level truth (closing gate) failed; see Gaps.

### Required Artifacts (05-20, 05-21)

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Tasks/Actions/UpdateTaskDescription.php` | Only Partner write path of the description | VERIFIED | 109 lines, substantive; used by `ViewPartnerTask` and tests; no other caller needed |
| `app/Domain/Tasks/Policies/TaskPolicy.php` | `editDescription`, `DESCRIPTION_EDITABLE_STATUSES` | VERIFIED | Present, used by the Action (twice), the page visibility and the docs test |
| `ViewPartnerTask.php` `Action::make('editDescription')` | Header action | VERIFIED | First of three header actions, pinned by tests |
| `TaskNotifier::descriptionChanged`, `TaskChangedNotification` (`recipientIsPartner`) | Admin and assignee notice | VERIFIED | Called from the Action after a real write |
| `NotificationEvent` + `EditProfile` + lang | Admin switch and helper | VERIFIED | `helper($this->role())` wired |
| `CONTRIBUTING.md`, `README.md`, `RepositoryFilesTest`, `05-UI-SPEC.md` | Documentation | VERIFIED | Greps pass; documentation test green |
| `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` | End-to-end proofs | PARTIAL | 22 cases, substantive, but one case is flaky (Gaps) |

### Key Link Verification

| From | To | Via | Status |
|------|----|-----|--------|
| `ViewPartnerTask` action | `UpdateTaskDescription` | `app(UpdateTaskDescription::class)->handle(actor, task, description, based_on)`; only those four values | WIRED |
| `UpdateTaskDescription` | `TaskPolicy::editDescription` | `authorize` before the transaction and `denies('editDescription', $locked)` on the locked row | WIRED |
| `UpdateTaskDescription` | `TaskInput::description` | sanitiser and length limit | WIRED |
| `UpdateTaskDescription` | `TaskNotifier::descriptionChanged` | after the history row, inside the transaction, notification `afterCommit` | WIRED |
| `TaskNotifier` | `TaskChangedNotification` | passes audience and raw line; base escapes once | WIRED |
| `EditProfile` | `NotificationEvent::helper($role)` | row helper | WIRED |

### Data-Flow Trace (Level 4)

| Artifact | Data variable | Source | Real data | Status |
|----------|---------------|--------|-----------|--------|
| Partner modal editor | `description`, `based_on` | `fillForm` reads the task record, fingerprint of the stored description | Yes | FLOWING |
| Partner task page after save | task description | `$task->refresh()` then Livewire re-render | Yes | FLOWING |
| Admin history relation manager / overview | `description_changed` rows | `activity_log` row written by the Action | Yes | FLOWING |
| Admin bell and mail | change line | `kokpit.tasks.notifications.changed.description` with the actor name | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Partner description path, policy, notices | `pest tests/Feature/Tasks/PartnerTaskDescriptionTest.php` | 28 passed (x4) | PASS |
| Phase subset | see Evidence base | 883 passed, 1 flaky failure | FAIL (flaky) |
| Full suite | `pest` clean run | 2047 passed | PASS (intermittently red via the flake) |
| Static analysis, style, sensitive scan | Pint, PHPStan, `check-sensitive.sh --all` | clean | PASS |

### Probe Execution

Step 7c: SKIPPED. No PLAN or SUMMARY declares a `probe-*.sh`.

### Requirements Coverage

All ten IDs are declared in PLAN frontmatter and are the only IDs REQUIREMENTS.md maps to Phase 5; no orphan. REQUIREMENTS.md marks all ten Complete.

| Requirement | Source Plans (this round) | Description | Status | Evidence |
|-------------|---------------------------|-------------|--------|----------|
| TA-01 | 05-01..05, 17, 18, 20 | Tasks, one-level subtasks, fields | SATISFIED | SC1; the Partner now edits the description within D-16. "Files" part is Phase 9 by mapping note |
| TA-02 | 05-01..03, 17 | KEY-N counter, locked, never recycled, searchable | SATISFIED | SC2; API use is Phase 7 |
| TA-03 | 05-06, 17 | Todo checklist | SATISFIED | `TaskChecklistTest` |
| TA-04 | 05-09, 13, 17, 19 | Comments, internal flag, Partner never internal | SATISFIED | SC3, SC5; attachments part is Phase 9 |
| TA-05 | 05-03, 17 | List with filters | SATISFIED | SC3 |
| TA-06 | 05-07, 08, 17 | Fixed price, billing type, rate override, estimate | SATISFIED | resolver and table tests |
| TA-07 | 05-12..16, 17, 18, 19, 20, 21 | Partner create and comment, no status or priority change, Admin notified | SATISFIED | SC5 and the G-05-5 additions; the Partner still cannot change status or priority |
| KB-01 | 05-10, 11, 17 | Kanban per project and global | SATISFIED | SC4 |
| KB-02 | 05-10, 11, 17 | Drag and drop synchronous; global filters | SATISFIED | SC4; gesture confirmed by the owner in UAT |
| KB-03 | 05-10, 12, 17, 20 | Partner has no board manipulation, read-only list | SATISFIED | No new Partner page or list action; header pin test updated to three actions |

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `tests/Feature/Tasks/PartnerTaskDescriptionTest.php` | 69-76, 252-254 | Non-deterministic ordering (`orderBy('created_at')`, second-resolution timestamps) feeding `array_last()` | Blocker for the "green suite" truth (test-only) | Intermittent red suite; see Gaps |
| (05-20/05-21 source files) | - | TODO/FIXME/XXX/TBD | none found | - |

Carried, non-blocking (unchanged from the previous report): review items WR-01, WR-03, WR-04 and IN-01..IN-07 in 05-REVIEW-DISPOSITION.md; UI review flags F-1, F-2, F-5, F-8, F-9. A new code review of the G-05-5 closure exists (`8b6db83`); I did not re-read it.

Bookkeeping note (not a gap): the ROADMAP Phase 5 header line still says "05-20 and 05-21 planned" and the phase checkbox is unchecked while both plans are ticked; update it when the phase closes.

### Human Verification Required

Five items, listed in the frontmatter `human_verification`. None is marked passed. Already confirmed by the owner in `05-UAT.md` and therefore not repeated here: touch drag (test 1), SPA navigation (test 2), Mailpit of the 05-16 mails (test 3), Czech copy as of then (test 4), visual check as of then (test 6) and the owner decision (test 7). UAT test 5 is still recorded as `issue` until the owner re-walks it.

### Gaps Summary

The phase goal and all ten requirements are met in the code. G-05-5 is implemented as the owner specified (D-16): a description-only Partner action guarded twice by one policy ability, status re-checked on the locked row, sanitised, stale and unchanged saves handled, history without text, Admin and assignee notified without any description text, Admin profile switch added, and the Partner write paths documented and pinned by a documentation test. Isolation, concurrency, schema and notification suites are green, and so are Pint, PHPStan and the sensitive scan.

One gap remains and it is in a test, not in the product: `PartnerTaskDescriptionTest` has an order-dependent assertion that fails about half of the time when the `tests/Feature/Tasks` directory runs, which contradicts the plan 05-21 truth that the closing gate is green. Fix the helper ordering (a one-line change), re-run the Tasks directory several times and the full suite, then re-verify. Independently of that fix, the browser walk of 05-21 Task 3 and the re-walk of UAT test 5 are needed before G-05-5 can be marked closed.

---

_Verified: 2026-10-09T10:05:00Z_
_Verifier: Claude (gsd-verifier)_
