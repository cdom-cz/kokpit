---
phase: 05-tasks-and-kanban
verified: 2026-10-09T07:30:00Z
status: human_needed
score: 5/5 must-haves verified
covered_files: [".planning/phases/05-tasks-and-kanban/05-01-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-01-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-02-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-02-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-03-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-03-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-04-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-04-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-05-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-05-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-06-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-06-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-07-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-07-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-08-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-08-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-09-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-09-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-10-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-10-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-11-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-11-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-12-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-12-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-13-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-13-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-14-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-14-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-15-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-15-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-16-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-16-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-17-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-17-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-18-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-18-SUMMARY.md", ".planning/phases/05-tasks-and-kanban/05-19-PLAN.md", ".planning/phases/05-tasks-and-kanban/05-19-SUMMARY.md", "CONTRIBUTING.md", "README.md", "app/Domain/Identity/Models/User.php", "app/Domain/Notifications/NotificationChannel.php", "app/Domain/Notifications/NotificationEvent.php", "app/Domain/Notifications/NotificationPreferences.php", "app/Domain/Notifications/UpdateNotificationPreferences.php", "app/Domain/Projects/Actions/UpdateProject.php", "app/Domain/Projects/Models/Project.php", "app/Domain/Shared/Database/MorphMap.php", "app/Domain/Shared/Tags/TagType.php", "app/Domain/Shared/Text/RichText.php", "app/Domain/Tasks/Actions/AddTaskComment.php", "app/Domain/Tasks/Actions/ArchiveTask.php", "app/Domain/Tasks/Actions/ClearEscalation.php", "app/Domain/Tasks/Actions/CreateTask.php", "app/Domain/Tasks/Actions/EscalateTask.php", "app/Domain/Tasks/Actions/MoveTask.php", "app/Domain/Tasks/Actions/RestoreTask.php", "app/Domain/Tasks/Actions/UpdateTask.php", "app/Domain/Tasks/Billing/BillingSource.php", "app/Domain/Tasks/Billing/EffectiveBilling.php", "app/Domain/Tasks/Billing/TaskBillingResolver.php", "app/Domain/Tasks/Board/BoardFilters.php", "app/Domain/Tasks/Board/TaskBoard.php", "app/Domain/Tasks/Enums/TaskBillingType.php", "app/Domain/Tasks/Models/Task.php", "app/Domain/Tasks/Models/TaskBilling.php", "app/Domain/Tasks/Models/TaskChecklistItem.php", "app/Domain/Tasks/Models/TaskComment.php", "app/Domain/Tasks/Notifications/TaskChangedNotification.php", "app/Domain/Tasks/Notifications/TaskCommentedNotification.php", "app/Domain/Tasks/Notifications/TaskCreatedNotification.php", "app/Domain/Tasks/Notifications/TaskEscalatedNotification.php", "app/Domain/Tasks/Notifications/TaskNotification.php", "app/Domain/Tasks/Notifications/TaskNotifier.php", "app/Domain/Tasks/Policies/TaskCommentPolicy.php", "app/Domain/Tasks/Policies/TaskPolicy.php", "app/Domain/Tasks/TaskInput.php", "app/Domain/Tasks/TaskPeople.php", "app/Filament/Auth/EditProfile.php", "app/Filament/Concerns/ManagesTaskBoard.php", "app/Filament/Pages/TaskBoardPage.php", "app/Filament/Partner/Resources/PartnerTaskResource.php", "app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php", "app/Filament/Partner/Resources/PartnerTaskResource/Pages/ListPartnerTasks.php", "app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php", "app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php", "app/Filament/RelationManagers/TaskHistoryRelationManager.php", "app/Filament/Resources/ProjectResource.php", "app/Filament/Resources/ProjectResource/Pages/EditProject.php", "app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php", "app/Filament/Resources/ProjectResource/Pages/ViewProject.php", "app/Filament/Resources/TaskResource.php", "app/Filament/Resources/TaskResource/Pages/EditTask.php", "app/Filament/Resources/TaskResource/Pages/ListTasks.php", "app/Filament/Resources/TaskResource/Pages/ViewTask.php", "app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php", "app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php", "app/Filament/Support/TaskColumns.php", "app/Providers/AccessServiceProvider.php", "app/Providers/Filament/AdminPanelProvider.php", "composer.json", "config/eloquent-sortable.php", "config/kokpit.php", "database/factories/TaskFactory.php", "database/migrations/2026_10_10_000100_create_tasks_table.php", "database/migrations/2026_10_10_000200_add_project_key_freeze_trigger.php", "database/migrations/2026_10_10_000300_create_task_checklist_items_table.php", "database/migrations/2026_10_10_000400_create_task_billing_table.php", "database/migrations/2026_10_10_000500_create_task_comments_table.php", "database/migrations/2026_10_10_000600_add_notification_preferences_to_users_table.php", "lang/cs/enums.php", "lang/cs/kokpit.php", "resources/views/filament/pages/partials/task-preview.blade.php", "resources/views/filament/pages/task-board.blade.php", "tests/Arch/ActivityAllowlistTest.php", "tests/Arch/ModelDeclarationTest.php", "tests/Concurrency/TaskBoardConcurrencyTest.php", "tests/Concurrency/TaskNumberConcurrencyTest.php", "tests/Concurrency/task-worker.php", "tests/Feature/Notifications/NotificationPreferencesTest.php", "tests/Feature/Operations/ActivityViewsTest.php", "tests/Feature/Repo/RepositoryFilesTest.php", "tests/Feature/Schema/SchemaConventionsTest.php", "tests/Feature/Schema/TaskBillingTableTest.php", "tests/Feature/Schema/TasksTableTest.php", "tests/Feature/Tasks/PartnerTaskCommentsTest.php", "tests/Feature/Tasks/PartnerTaskResourceTest.php", "tests/Feature/Tasks/RichTextSanitiserTest.php", "tests/Feature/Tasks/SubtasksTest.php", "tests/Feature/Tasks/TaskActionsTest.php", "tests/Feature/Tasks/TaskArchiveTest.php", "tests/Feature/Tasks/TaskBillingResolverTest.php", "tests/Feature/Tasks/TaskBillingTest.php", "tests/Feature/Tasks/TaskBoardTest.php", "tests/Feature/Tasks/TaskChecklistTest.php", "tests/Feature/Tasks/TaskCommentsTest.php", "tests/Feature/Tasks/TaskEscalationTest.php", "tests/Feature/Tasks/TaskKeyTest.php", "tests/Feature/Tasks/TaskListFiltersTest.php", "tests/Feature/Tasks/TaskNotificationsTest.php", "tests/Feature/Tasks/TaskResourceTest.php", "tests/Feature/Tasks/TaskUpdateTest.php", "tests/Isolation/CanaryRegistryTest.php", "tests/Isolation/NotificationLeakTest.php", "tests/Isolation/NotificationMarkupTest.php", "tests/Isolation/PanelAccessTest.php", "tests/Isolation/PartnerSafeColumnsTest.php", "tests/Isolation/PartnerTaskVisibilityTest.php", "tests/Isolation/RouteWalkTest.php", "tests/Support/CanaryRegistry.php", "tests/Support/PgSchema.php", "tests/Support/UnlockedTaskBoard.php"]
covered_digest: "v3:sha256:0bc0a573fe20e6e54a4093a12462eaec1d9298f42ccfb2492de62f660e948465"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 5/5
  gaps_closed:
    - "CR-01: a deactivated assignee or requester no longer blocks the Admin edit save of a task (plan 05-18)"
    - "G-1: Partner tags are dropped in CreateTask (plan 05-18)"
    - "T-05-44 / U-1 / WR-02: task title, actor name, excerpt and change lines are escaped once in the TaskNotification base class for every audience (plan 05-19)"
  gaps_remaining: []
  regressions: []
human_verification:
  - test: "Touch drag on the global and the per-project board at 375 px width (phone or emulated touch device): drag a card across columns, into an empty column, and scroll the board horizontally and vertically without starting a drag by accident"
    expected: "The card lands where it is dropped, the status and position are stored immediately and are unchanged after a reload; scrolling does not start a drag. If dragging fights scrolling, record it for the drag-handle fallback (research A3, A4). Source: 05-11-PLAN human-check, 05-UAT.md test 1."
    why_human: "wire:sort is a browser gesture; the Pest suite calls the Livewire moveCard handler directly, so the pointer and touch behaviour, empty-column drop zone and scroll conflict are not exercised by any test."
  - test: "Livewire SPA navigation: open the global board, click a card title to its task page, go back with the panel navigation, then drag again"
    expected: "Dragging still works after the back navigation without a full page reload. Source: 05-11-PLAN human-check, 05-UAT.md test 2."
    why_human: "SPA mode re-initialises Alpine/Livewire directives in the browser; no browser automation exists in the test stack."
  - test: "Mail rendering in Mailpit (restart the DDEV worker first): let a fictional Partner comment, escalate a task assigned to the Admin and escalate a task assigned to a second fictional Partner of the same client, and let the Admin change a status; open the four mails. Extended by plan 05-19: also create a task whose title holds a Markdown link and an HTML anchor to an example.com address, comment on it, and open the Admin's two mails and the Admin's bell"
    expected: "Czech subject and text, correct task reference, a working button to the right panel URL (Admin or Partner audience), no HTML tags in the excerpt. The markup title and the display name read exactly as typed as plain text: no clickable link, image or styling from them, no visible backslash or entity in the HTML view and the bell. Source: 05-16-PLAN and 05-19-PLAN human-check, 05-UAT.md test 3."
    why_human: "Visual rendering of the queued Markdown mails and of the bell; the DOM-based tests assert on rendered output but cannot replace a real mail client."
  - test: "Czech copy review of every string added in this phase in lang/cs/kokpit.php and lang/cs/enums.php (tasks, partner_tasks, task_board, notifications, billing types and sources, notification events and channels)"
    expected: "Natural Czech, consistent terms (ukol, podukol, resitel, zadavatel, eskalace, interni komentar), no English left. Also confirm the F-9 labels are acceptable or scheduled. Source: 05-17-PLAN human-check, 05-UAT.md test 4."
    why_human: "Wording judgement."
  - test: "End-to-end walk as Admin and as a fictional Partner: Partner creates a task and comments, Admin receives the bell and the mail, Admin answers with an internal and a public comment, Partner sees only the public one, Partner escalates, Admin clears the flag and moves the card on the project board; a second fictional Partner escalates a task assigned to the first Partner, who receives the escalation in the bell and clears the flag from the own task page with no priority or status control present"
    expected: "Every step matches ROADMAP success criteria 1 to 5. Source: 05-17-PLAN human-check, 05-UAT.md test 5."
    why_human: "Whole-flow judgement across two panels, queue worker, mail and bell."
  - test: "Visual light and dark mode check of the board, task list, task page, Partner pages and the bell (UI-REVIEW flags F-1, F-2, F-8)"
    expected: "Status and priority badges are distinguishable from the accent colour in both modes, the board card border is visible on light columns, text at 12 px remains readable. Source: 05-UI-REVIEW.md (16/24, no browser audit was possible), 05-UAT.md test 6."
    why_human: "No render was captured in the UI review; contrast and colour roles need eyes."
---

# Phase 5: Tasks and Kanban Verification Report

**Phase Goal:** Admin organises work as tasks and subtasks with per-project keys and a drag-and-drop board, and a Partner can raise and discuss tasks in visible projects without seeing anything internal
**Verified:** 2026-10-09T07:30:00Z
**Status:** human_needed
**Re-verification:** Yes, after gap-closure plans 05-18 (CR-01, G-1) and 05-19 (T-05-44, U-1, WR-02)

Method: goal-backward against the five ROADMAP success criteria. Re-verification: the five criteria and the ten requirement IDs were re-checked for regression (existence and wiring of the Actions, models, board concern, Partner surface and notifier are unchanged by 05-18 and 05-19 apart from the files named below), and the three previously open defects were verified from the code and by running the tests that pin them. The working tree has no uncommitted source changes (only `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock`). At HEAD I re-ran: `tests/Feature/Tasks tests/Isolation tests/Arch tests/Feature/Schema tests/Concurrency` (723 passed, 4626 assertions, which includes the 10 concurrency cases and their mutation runs) and, as a focused set, `NotificationMarkupTest`, `NotificationLeakTest`, `TaskUpdateTest`, `TaskActionsTest`, `TaskNotificationsTest` and `RepositoryFilesTest` (160 passed, 1312 assertions). The full-suite result (2015 passed, 14955 assertions), Pint, PHPStan, check-licenses and check-sensitive are taken from the 05-19 SUMMARY and the orchestrator's report for this run; I did not re-run the full suite.

## Gap Closure (re-verification focus)

| Gap | Status | Evidence from the code and tests |
|-----|--------|----------------------------------|
| CR-01: a deactivated assignee or requester blocked every Admin edit save of that task | CLOSED | `TaskResource::peopleOptions(?Task $task, string $field)` (`TaskResource.php:622-638`) takes the `TaskPeople::options` set and adds the record's stored person of that same field when it is not already an option (name read by `User::query()->whereKey()->value('name')`). Both selects call it per field (`assignee_id` at line 243, `requester_id` at line 248). `TaskPeople` and `UpdateTask` are unchanged, so a newly chosen person is still judged by the active set under the row lock. Pinned by `TaskUpdateTest`: `saves a rename of a task whose assignee was deactivated and keeps the assignee`, `keeps a deactivated requester when the Admin saves another change`, `offers a deactivated person only in the field that holds it`, `refuses a new pick of a deactivated or foreign account`, `refuses picking a deactivated person again after the task moved away from them`, `refuses a person deactivated between page load and save`. All green on my run; the SUMMARY records a mutation run (MUT-P1, 4 failures) and a RED run on the pre-fix code. |
| G-1: a Partner's `tags` were not dropped in `CreateTask` | CLOSED | `CreateTask.php:101` now reads `unset($data['status'], $data['priority'], $data['assignee_id'], $data['requester_id'], $data['tags'])` for a Partner, before `TaskInput::tags` runs at line 108. Pinned by `TaskActionsTest`: `drops the tags of a Partner payload and stores the same tags for the Admin` (Admin control case) and `writes no tag when a Partner creation with tags is refused`. Green; MUT-P2 recorded in the SUMMARY. |
| T-05-44 / U-1 / WR-02: unescaped task title and actor name (and inconsistent escaping elsewhere) in notification mails and bell bodies | CLOSED | `TaskNotification` is now the single escaping point. `toMail()` and `toDatabase()` are `final`; one private `text()` builder is the only caller of `__()` for notification text and escapes every value per target (subject raw plain text, mail Markdown-escaped via `escapeMarkdown`, bell `e()`); change lines and the excerpt (cut to 120 characters as plain text before escaping in the bell) go through the same two escapers. A grep over `app/Domain/Tasks/Notifications/` finds no `__(` in the four subclasses; they hold raw scalars plus `textGroup()` (and `changeLines()` / `bellBodyKey()`), so a subclass cannot bypass the escape. The constructor guard (`LogicException` for an internal comment to a Partner) is unchanged. Pinned by `tests/Isolation/NotificationMarkupTest.php` (25 cases: end-to-end through the real Actions and channels, a matrix over TaskCreated, TaskCommented and TaskEscalated for Admin and Partner and TaskChanged for Partner, DOM inspection of element attributes rather than substrings, subject, idempotency across two renders and a serialize round trip, 120/300 length cuts, completeness over discovered subclasses, `final` pin, internal-comment lock) and by the CONTRIBUTING rule plus `RepositoryFilesTest`. `TaskNotificationsTest` and `NotificationLeakTest` were not edited by 05-19 and stay green. Four mutation runs are recorded in the SUMMARY. |

Residual, not a gap: the plain-text alternative of a notification mail shows a backslash before an escaped Markdown character of a value (for example `\(` in a title with brackets); the HTML part and the bell read as typed. Accepted by plan 05-19; it is covered by the Mailpit human check below.

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin creates tasks and one-level subtasks with title, status, description, dates, priority, assignee, tags and a todo checklist, plus per-task billing type, fixed price, rate override and estimate | VERIFIED | `CreateTask` (title, description sanitised via `TaskInput::description`, status, priority, dates with order check, tags via `syncTagsWithType`, people from `TaskPeople`, parent re-read under lock, depth 0/1). `TaskResource` form has the people selects, dates, `Repeater::make('checklistItems')->relationship()`. Billing lives in the Admin-only `task_billing` 1:1 table written by `UpdateTask`; `TaskBillingResolver` gives the effective value and source. DB proof in `TasksTableTest` (one-level via composite FK `tasks_parent_fk`, SQLSTATE 23503; date/done/escalation CHECKs), `TaskBillingTableTest`, `TaskChecklistTest`, `TaskBillingTest`, `TaskBillingResolverTest`, `SubtasksTest`. All green. Files on tasks are deferred to Phase 9 by ROADMAP mapping note (TA-01 "files" part). |
| 2 | Each new task gets the next `KEY-N` from its project counter; parallel creation never duplicates or skips; deleted numbers never reused; a task is found and opened by key in search and URL; project key cannot change after the first task | VERIFIED | `CreateTask` takes the board advisory lock, then the project row `FOR SHARE`, then `DocumentNumbering::nextTaskNumber` (counter row `task:<project uuid>` in `number_sequences`, locked) inside one transaction. `TaskNumberConcurrencyTest` ran 8 workers x 25 creations and got exactly 1..200, plus a counter-row-lock-only run and a mutation run that fails without the row lock. Archive is a soft delete and the counter is never decremented, so numbers are not reused; hard delete of a project/parent with tasks is refused (SQLSTATE 23001). `TaskResource` is searchable by `reference` and `title` only (`getGloballySearchableAttributes`), page route `/admin/tasks/{record}` keyed by reference; Partner search returns nothing (`TaskResourceTest`). Key freeze: trigger `projects_key_frozen_guard` (KP002) in `2026_10_10_000200`, plus `UpdateProject` check (`tasks()->withTrashed()->exists()`) and disabled form field; `TaskKeyTest` green. The "usable in API" part of TA-02 is the Phase 7 surface; reference addressing is in place for it. |
| 3 | Admin filters the list by client, project, status, priority, assignee, tag and due date, and adds comments to tasks and subtasks, optionally internal | VERIFIED | `TaskResource::filters()` defines `SelectFilter` client, project, status, priority, assignee, tag and a `Filter::make('due')` range (`TaskResource.php:667-709`); `TaskListFiltersTest` covers each, inclusive due range, empty state and stable order. `TaskCommentsRelationManager` on tasks and subtasks through `AddTaskComment`, `is_internal` honoured for the Admin only; `TaskCommentsTest` green. |
| 4 | Admin drags cards on the per-project and the global board; status and position persist immediately and survive a reload; the global board filters by client, assignee, tag and priority | VERIFIED (server side); gesture routed to human | `TaskBoardPage` (global) and `ProjectBoard` (per project) share `ManagesTaskBoard`; the view uses `wire:sort="moveCard"` with `wire:sort:group-id` per status. `moveCard` is synchronous and calls `MoveTask` (UUID check, scoped lookup, `Gate::authorize('update')`, advisory lock, re-read `FOR UPDATE`, `TaskBoard::move`). Position uniqueness per active status column is enforced by a deferred exclusion constraint (23P01 tests). Columns: one per `ProjectStatus` in enum order, cards by `position`, Done capped by `kokpit.board.done_limit`. Filters client/assignee/tag/priority in `BoardFilters` + `TaskBoard::applyFilters`; the client filter is ignored on a one-project board. `TaskBoardTest` and `TaskBoardConcurrencyTest` (two processes, 25 moves each, mutation run without the lock fails) green. The actual drag gesture, touch, empty-column drop and SPA re-init are not tested: human items 1 and 2. |
| 5 | A Partner creates tasks and comments in visible projects but cannot change status or priority or move cards, sees a read-only task list, never sees internal comments or another client's tasks, and Admin is notified of Partner tasks and comments | VERIFIED | Partner panel `PartnerTaskResource` registers only `index`, `create`, `view` pages (no edit, no board; both board pages are `Audience::AdminOnly`). `CreateTask` unsets Partner `status/priority/assignee_id/requester_id` and forces requester=Partner, assignee=Admin; a Partner may not pass a parent. `TaskComment` global Partner scope keeps only `is_internal = false`; `AddTaskComment` honours the flag for the Admin only (mutation-checked). `Task` Partner scope goes through the scoped project query; `PartnerTaskVisibilityTest`, `PartnerSafeColumnsTest` (column pin on `tasks` and `task_comments`), canary registry entries for Task, TaskChecklistItem, TaskBilling, TaskComment. `TaskNotifier` fans out to the Admin on Partner task/comment/escalation; the internal-comment-to-Partner path is blocked in the notifier, in the notification constructor (`LogicException`) and by preferences only narrowing; `NotificationLeakTest` (391+ assertions) re-run green. |

**Score:** 5/5 roadmap success criteria verified (0 present but behavior-unverified). The browser-only parts are carried as human items, not as unverified truths.

### Plan-level truths and prohibitions spot-checked

| Item | Status | Evidence |
|------|--------|----------|
| D-01..D-03 board shape, Done cap, subtasks as own cards | VERIFIED | `TaskBoard::columns`, `card()` includes parent reference; constant-query test at 200 cards |
| D-04/D-05 people rules; forged id rejected | VERIFIED | `TaskPeople::allowedIds/assertAllowed`, `CreateTask::peopleFor`, tests in `TaskActionsTest`, `TaskUpdateTest` |
| D-06 escalation requires comment, no automatic priority change, assignee or Admin clears | VERIFIED | `EscalateTask` / `ClearEscalation`, `TaskEscalationTest` |
| D-07/D-15 notifications to both sides, per-user channel preferences | VERIFIED | `NotificationPreferences`, `EditProfile`, `TaskNotificationsTest`, `NotificationPreferencesTest` |
| D-10 sanitiser with Partner canary | VERIFIED | `RichText::clean/render`, `RichTextSanitiserTest` |
| D-12..D-14 billing in `task_billing`, inherit at read time | VERIFIED | `TaskBilling` DeniesPartners, `TaskBillingResolver`, resolver matrix test |
| Prohibitions (9 plan blocks, all `verification: test`) | VERIFIED | Each has wired enforcement: Partner column pin, canary harness, `NotificationLeakTest`, comment scope/mutation tests, sanitiser tests. None is unverified. |

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Tasks/Actions/*` (8 Actions) | Create/Update/Move/Archive/Restore/AddComment/Escalate/ClearEscalation | VERIFIED | Substantive, used by Filament pages and tests |
| `app/Domain/Tasks/Models/*` (Task, TaskBilling, TaskChecklistItem, TaskComment) | Partner-scoped or Admin-only models | VERIFIED | Declared in architecture test `ModelDeclarationTest` |
| `app/Domain/Tasks/Board/TaskBoard.php`, `BoardFilters.php` | Lock-guarded move arithmetic | VERIFIED | Concurrency proofs green |
| `app/Domain/Tasks/Notifications/*` | Scalar-only queued notifications, escaped once in the base | VERIFIED | Escaping closed by 05-19 (see Gap Closure) |
| 6 migrations `2026_10_10_0001..0006` | tasks, KP002 trigger, checklist, billing, comments, preferences | VERIFIED | Schema tests green |
| `app/Filament/Resources/TaskResource*`, `Pages/TaskBoardPage`, `ProjectBoard`, `Partner/.../PartnerTaskResource*` | Admin list/page/board, Partner read-only surface | VERIFIED | Access rules declared, route walk entries present |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| Board view `wire:sort` | `MoveTask` | `moveCard` -> `MoveTask::handle` | WIRED | `ManagesTaskBoard.php:191-201` |
| `CreateTask` | `number_sequences` counter | `DocumentNumbering::nextTaskNumber` in same transaction | WIRED | Only caller of `nextTaskNumber` |
| `UpdateProject` / DB | key freeze | Action check + trigger KP002 | WIRED | Both layers present |
| Task mutations | `TaskNotifier` | `CreateTask`, `UpdateTask`, `MoveTask`, `AddTaskComment`, `EscalateTask` | WIRED | Fan-out tests green |
| Partner pages | pinned builders | `PartnerTaskResource` / `TaskColumns` | WIRED | `PartnerSafeColumnsTest` |
| Edit form people selects | `UpdateTask` unchanged-person rule | `TaskResource::peopleOptions($task, $field)` | WIRED | Closed by 05-18: the stored person of the same field stays an option; changed values are still judged by `UpdateTask` |
| Notification classes | escaping | `TaskNotification` final `toMail` / `toDatabase` + private `text()` | WIRED | Closed by 05-19: the only place that reads a notification text and escapes values |

### Data-Flow Trace (Level 4)

| Artifact | Data Variable | Source | Produces Real Data | Status |
|----------|---------------|--------|--------------------|--------|
| Board view | `$this->columns` | `TaskBoard::columns` (Eloquent with eager loads, counts) | Yes | FLOWING |
| Admin list | table query | `Task` query with filters | Yes | FLOWING |
| Partner list | table query | pinned Partner-scoped `Task` query | Yes | FLOWING |
| Bell/mail bodies | notification args | scalar facts from `TaskNotifier` | Yes | FLOWING |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Task, isolation, arch, schema and concurrency suites (re-verification) | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Isolation tests/Arch tests/Feature/Schema tests/Concurrency` | 723 passed, 4626 assertions | PASS |
| Gap-closure seams | `ddev exec vendor/bin/pest tests/Isolation/NotificationMarkupTest.php tests/Isolation/NotificationLeakTest.php tests/Feature/Tasks/TaskUpdateTest.php tests/Feature/Tasks/TaskActionsTest.php tests/Feature/Tasks/TaskNotificationsTest.php tests/Feature/Repo/RepositoryFilesTest.php` | 160 passed, 1312 assertions | PASS |

### Probe Execution

Step 7c: SKIPPED. No PLAN or SUMMARY declares a `probe-*.sh`, and `scripts/*/tests/probe-*.sh` is not part of this phase.

### Requirements Coverage

All ten IDs appear in PLAN frontmatter and all ten are the only IDs REQUIREMENTS.md maps to Phase 5. No orphaned requirement.

| Requirement | Source Plan(s) | Description | Status | Evidence |
|-------------|----------------|-------------|--------|----------|
| TA-01 | 05-01, 02, 03, 04, 05, 17 | Tasks, one-level subtasks, fields | SATISFIED | SC1; "files" part delivered in Phase 9 per ROADMAP mapping note |
| TA-02 | 05-01, 02, 03, 17 | KEY-N counter, locked, never recycled, searchable, URL | SATISFIED | SC2; API use is Phase 7 |
| TA-03 | 05-06, 17 | Todo checklist | SATISFIED | `TaskChecklistTest`, repeater on form, progress count without N+1 |
| TA-04 | 05-09, 13, 17 | Comments, internal flag, Partner never internal | SATISFIED | SC3, SC5; attachments part is Phase 9 |
| TA-05 | 05-03, 17 | List with filters | SATISFIED | SC3 |
| TA-06 | 05-07, 08, 17 | Fixed price, billing type, rate override, estimate | SATISFIED | `task_billing`, resolver |
| TA-07 | 05-12, 13, 14, 15, 16, 17 | Partner create/comment, no status/priority, Admin notified | SATISFIED | SC5 |
| KB-01 | 05-10, 11, 17 | Kanban per project and global, columns by status, order by position | SATISFIED | SC4 |
| KB-02 | 05-10, 11, 17 | Drag and drop synchronous; global filters | SATISFIED | SC4; gesture is a human item |
| KB-03 | 05-10, 12, 17 | Partner has no board, read-only list | SATISFIED | SC5 |

### Anti-Patterns Found

Debt-marker scan (`TODO|FIXME|XXX|TBD`) over `app/Domain/Tasks`, `TaskResource.php`, the board concern and `NotificationMarkupTest.php`: no matches (re-run at HEAD). No stub returns on rendered paths. Findings from the code review that I re-read in the code:

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Filament/Resources/TaskResource.php` | 622-638 | Assignee/requester selects list active accounts only | Resolved | CR-01 closed by 05-18 |
| `app/Domain/Tasks/Notifications/*.php` | mailLine/bellBody | Task title and actor name interpolated unescaped | Resolved | T-05-44 / WR-02 closed by 05-19 |
| `app/Domain/Tasks/Actions/AddTaskComment.php` | 45-66 | No re-read of task before insert | Info | WR-03, narrow race |
| `app/Domain/Tasks/Actions/CreateTask.php` / `UpdateTask.php` / `TaskInput.php` | various | Title length and date range enforced only by forms | Info | WR-01; 500 only through forged Livewire payload today, relevant for the Phase 7 API |

### Human Verification Required

See the `human_verification` list in the frontmatter (six items, mirrored in `05-UAT.md` tests 1 to 6): touch drag at 375 px, SPA navigation, Mailpit rendering (extended with the markup-title check from plan 05-19), Czech copy review, full Admin and Partner walk-through, and the light and dark visual check. The former seventh item (owner decision on CR-01 and T-05-44 / WR-02 / G-1) is resolved: the owner chose to close them and plans 05-18 and 05-19 did so; `05-UAT.md` test 7 can be marked as resolved with that evidence.

### Non-blocking observations

**O-1. WR-04 and the other open review items.** 05-REVIEW findings WR-01 (title length and date range enforced only by forms), WR-03 (no task re-read before comment insert, narrow race), WR-04 (only the Admin description editor is pinned by a test for `fileAttachments(false)`, so the 05-SECURITY wording is overstated for the Partner-facing editors) and IN-01..IN-07 remain open and are tracked in 05-REVIEW-DISPOSITION.md. None breaks a criterion; WR-01 matters again for the Phase 7 API.

**O-2. UI review (16/24).** Flags F-1 (Amber primary), F-2 (off-scale sizes), F-5 (no keyboard board move), F-8 ("Ke kontrole" uses the accent role) and F-9 (generic labels on the Admin task and edit pages) are open; cosmetic or accessibility departures from 05-UI-SPEC, not functional gaps. The ROADMAP checkbox for Phase 4 still shows unchecked although its VERIFICATION exists; bookkeeping outside this phase.

### Gaps Summary

No success criterion or requirement is unmet, and no gap remains. All five ROADMAP success criteria and all ten requirement IDs (TA-01 to TA-07, KB-01 to KB-03) are backed by implemented, wired code and by tests that I re-ran green at HEAD. The three defects found in the first verification (CR-01, G-1, T-05-44 / U-1 / WR-02) are closed, each verified in the code and by named tests with recorded RED and mutation evidence; the 05-18 and 05-19 changes touched only `TaskResource.php`, `CreateTask.php`, the notification classes, CONTRIBUTING and tests, and no regression was found. The status stays `human_needed` because six checks need a browser, a mail client or a reader of Czech (touch drag, SPA navigation, Mailpit and bell rendering, Czech copy, end-to-end walk, light and dark visual check); they are pending in `05-UAT.md` and were deliberately not marked passed here.

---

_Verified: 2026-10-09T07:30:00Z_
_Verifier: Claude (gsd-verifier)_
