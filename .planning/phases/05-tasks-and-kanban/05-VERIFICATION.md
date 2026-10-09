---
phase: 05-tasks-and-kanban
verified: 2026-10-09T04:10:00Z
status: human_needed
score: 5/5 must-haves verified
covered_files: [".planning/phases/05-tasks-and-kanban/05-01-PLAN.md",".planning/phases/05-tasks-and-kanban/05-01-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-02-PLAN.md",".planning/phases/05-tasks-and-kanban/05-02-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-03-PLAN.md",".planning/phases/05-tasks-and-kanban/05-03-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-04-PLAN.md",".planning/phases/05-tasks-and-kanban/05-04-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-05-PLAN.md",".planning/phases/05-tasks-and-kanban/05-05-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-06-PLAN.md",".planning/phases/05-tasks-and-kanban/05-06-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-07-PLAN.md",".planning/phases/05-tasks-and-kanban/05-07-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-08-PLAN.md",".planning/phases/05-tasks-and-kanban/05-08-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-09-PLAN.md",".planning/phases/05-tasks-and-kanban/05-09-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-10-PLAN.md",".planning/phases/05-tasks-and-kanban/05-10-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-11-PLAN.md",".planning/phases/05-tasks-and-kanban/05-11-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-12-PLAN.md",".planning/phases/05-tasks-and-kanban/05-12-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-13-PLAN.md",".planning/phases/05-tasks-and-kanban/05-13-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-14-PLAN.md",".planning/phases/05-tasks-and-kanban/05-14-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-15-PLAN.md",".planning/phases/05-tasks-and-kanban/05-15-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-16-PLAN.md",".planning/phases/05-tasks-and-kanban/05-16-SUMMARY.md",".planning/phases/05-tasks-and-kanban/05-17-PLAN.md",".planning/phases/05-tasks-and-kanban/05-17-SUMMARY.md","CONTRIBUTING.md","README.md","app/Domain/Identity/Models/User.php","app/Domain/Notifications/NotificationChannel.php","app/Domain/Notifications/NotificationEvent.php","app/Domain/Notifications/NotificationPreferences.php","app/Domain/Notifications/UpdateNotificationPreferences.php","app/Domain/Projects/Actions/UpdateProject.php","app/Domain/Projects/Models/Project.php","app/Domain/Shared/Database/MorphMap.php","app/Domain/Shared/Tags/TagType.php","app/Domain/Shared/Text/RichText.php","app/Domain/Tasks/Actions/AddTaskComment.php","app/Domain/Tasks/Actions/ArchiveTask.php","app/Domain/Tasks/Actions/ClearEscalation.php","app/Domain/Tasks/Actions/CreateTask.php","app/Domain/Tasks/Actions/EscalateTask.php","app/Domain/Tasks/Actions/MoveTask.php","app/Domain/Tasks/Actions/RestoreTask.php","app/Domain/Tasks/Actions/UpdateTask.php","app/Domain/Tasks/Billing/BillingSource.php","app/Domain/Tasks/Billing/EffectiveBilling.php","app/Domain/Tasks/Billing/TaskBillingResolver.php","app/Domain/Tasks/Board/BoardFilters.php","app/Domain/Tasks/Board/TaskBoard.php","app/Domain/Tasks/Enums/TaskBillingType.php","app/Domain/Tasks/Models/Task.php","app/Domain/Tasks/Models/TaskBilling.php","app/Domain/Tasks/Models/TaskChecklistItem.php","app/Domain/Tasks/Models/TaskComment.php","app/Domain/Tasks/Notifications/TaskChangedNotification.php","app/Domain/Tasks/Notifications/TaskCommentedNotification.php","app/Domain/Tasks/Notifications/TaskCreatedNotification.php","app/Domain/Tasks/Notifications/TaskEscalatedNotification.php","app/Domain/Tasks/Notifications/TaskNotification.php","app/Domain/Tasks/Notifications/TaskNotifier.php","app/Domain/Tasks/Policies/TaskCommentPolicy.php","app/Domain/Tasks/Policies/TaskPolicy.php","app/Domain/Tasks/TaskInput.php","app/Domain/Tasks/TaskPeople.php","app/Filament/Auth/EditProfile.php","app/Filament/Concerns/ManagesTaskBoard.php","app/Filament/Pages/TaskBoardPage.php","app/Filament/Partner/Resources/PartnerTaskResource.php","app/Filament/Partner/Resources/PartnerTaskResource/Pages/CreatePartnerTask.php","app/Filament/Partner/Resources/PartnerTaskResource/Pages/ListPartnerTasks.php","app/Filament/Partner/Resources/PartnerTaskResource/Pages/ViewPartnerTask.php","app/Filament/Partner/Resources/PartnerTaskResource/RelationManagers/PartnerTaskCommentsRelationManager.php","app/Filament/RelationManagers/TaskHistoryRelationManager.php","app/Filament/Resources/ProjectResource.php","app/Filament/Resources/ProjectResource/Pages/EditProject.php","app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php","app/Filament/Resources/ProjectResource/Pages/ViewProject.php","app/Filament/Resources/TaskResource.php","app/Filament/Resources/TaskResource/Pages/EditTask.php","app/Filament/Resources/TaskResource/Pages/ListTasks.php","app/Filament/Resources/TaskResource/Pages/ViewTask.php","app/Filament/Resources/TaskResource/RelationManagers/SubtasksRelationManager.php","app/Filament/Resources/TaskResource/RelationManagers/TaskCommentsRelationManager.php","app/Filament/Support/TaskColumns.php","app/Providers/AccessServiceProvider.php","app/Providers/Filament/AdminPanelProvider.php","composer.json","config/eloquent-sortable.php","config/kokpit.php","database/factories/TaskFactory.php","database/migrations/2026_10_10_000100_create_tasks_table.php","database/migrations/2026_10_10_000200_add_project_key_freeze_trigger.php","database/migrations/2026_10_10_000300_create_task_checklist_items_table.php","database/migrations/2026_10_10_000400_create_task_billing_table.php","database/migrations/2026_10_10_000500_create_task_comments_table.php","database/migrations/2026_10_10_000600_add_notification_preferences_to_users_table.php","lang/cs/enums.php","lang/cs/kokpit.php","resources/views/filament/pages/partials/task-preview.blade.php","resources/views/filament/pages/task-board.blade.php","tests/Arch/ActivityAllowlistTest.php","tests/Arch/ModelDeclarationTest.php","tests/Concurrency/TaskBoardConcurrencyTest.php","tests/Concurrency/TaskNumberConcurrencyTest.php","tests/Concurrency/task-worker.php","tests/Feature/Notifications/NotificationPreferencesTest.php","tests/Feature/Operations/ActivityViewsTest.php","tests/Feature/Repo/RepositoryFilesTest.php","tests/Feature/Schema/SchemaConventionsTest.php","tests/Feature/Schema/TaskBillingTableTest.php","tests/Feature/Schema/TasksTableTest.php","tests/Feature/Tasks/PartnerTaskCommentsTest.php","tests/Feature/Tasks/PartnerTaskResourceTest.php","tests/Feature/Tasks/RichTextSanitiserTest.php","tests/Feature/Tasks/SubtasksTest.php","tests/Feature/Tasks/TaskActionsTest.php","tests/Feature/Tasks/TaskArchiveTest.php","tests/Feature/Tasks/TaskBillingResolverTest.php","tests/Feature/Tasks/TaskBillingTest.php","tests/Feature/Tasks/TaskBoardTest.php","tests/Feature/Tasks/TaskChecklistTest.php","tests/Feature/Tasks/TaskCommentsTest.php","tests/Feature/Tasks/TaskEscalationTest.php","tests/Feature/Tasks/TaskKeyTest.php","tests/Feature/Tasks/TaskListFiltersTest.php","tests/Feature/Tasks/TaskNotificationsTest.php","tests/Feature/Tasks/TaskResourceTest.php","tests/Feature/Tasks/TaskUpdateTest.php","tests/Isolation/CanaryRegistryTest.php","tests/Isolation/NotificationLeakTest.php","tests/Isolation/PanelAccessTest.php","tests/Isolation/PartnerSafeColumnsTest.php","tests/Isolation/PartnerTaskVisibilityTest.php","tests/Isolation/RouteWalkTest.php","tests/Support/CanaryRegistry.php","tests/Support/PgSchema.php","tests/Support/UnlockedTaskBoard.php"]
covered_digest: "v3:sha256:47e6ef32fe84b52fa039f94abfbc6c2a4a3570b2108181a89ca4ad2aed6157c0"
behavior_unverified: 0
overrides_applied: 0
human_verification:
  - test: "Touch drag on the global and the per-project board at 375 px width (phone or emulated touch device): drag a card across columns, into an empty column, and scroll the board horizontally and vertically without starting a drag by accident"
    expected: "The card lands where it is dropped, the status and position are stored immediately and are unchanged after a reload; scrolling does not start a drag. If dragging fights scrolling, record it for the drag-handle fallback (research A3, A4). Source: 05-11-PLAN human-check, 05-VALIDATION manual table."
    why_human: "wire:sort is a browser gesture; the Pest suite calls the Livewire moveCard handler directly, so the pointer and touch behaviour, empty-column drop zone and scroll conflict are not exercised by any test."
  - test: "Livewire SPA navigation: open the global board, click a card title to its task page, go back with the panel navigation, then drag again"
    expected: "Dragging still works after the back navigation without a full page reload. Source: 05-11-PLAN human-check."
    why_human: "SPA mode re-initialises Alpine/Livewire directives in the browser; no browser automation exists in the test stack."
  - test: "Mail rendering in Mailpit (restart the DDEV worker first): let a fictional Partner comment, escalate a task assigned to the Admin and escalate a task assigned to a second fictional Partner of the same client, and let the Admin change a status; open the four mails"
    expected: "Czech subject and text, correct task reference, a working button to the right panel URL (Admin or Partner audience), no HTML tags in the excerpt. Source: 05-16-PLAN human-check."
    why_human: "Visual rendering of the queued Markdown mails; tests only assert on rendered strings."
  - test: "Czech copy review of every string added in this phase in lang/cs/kokpit.php and lang/cs/enums.php (tasks, partner_tasks, task_board, notifications, billing types and sources, notification events and channels)"
    expected: "Natural Czech, consistent terms (ukol, podukol, resitel, zadavatel, eskalace, interni komentar), no English left. Also confirm the F-9 labels (see Non-blocking observations) are acceptable or scheduled. Source: 05-17-PLAN human-check."
    why_human: "Wording judgement."
  - test: "End-to-end walk as Admin and as a fictional Partner: Partner creates a task and comments, Admin receives the bell and the mail, Admin answers with an internal and a public comment, Partner sees only the public one, Partner escalates, Admin clears the flag and moves the card on the project board; a second fictional Partner escalates a task assigned to the first Partner, who receives the escalation in the bell and clears the flag from the own task page with no priority or status control present"
    expected: "Every step matches ROADMAP success criteria 1 to 5. Source: 05-17-PLAN human-check."
    why_human: "Whole-flow judgement across two panels, queue worker, mail and bell."
  - test: "Visual light and dark mode check of the board, task list, task page, Partner pages and the bell (UI-REVIEW flags F-1, F-2, F-8)"
    expected: "Status and priority badges are distinguishable from the accent colour in both modes, the board card border is visible on light columns, text at 12 px remains readable. Source: 05-UI-REVIEW.md (16/24, no browser audit was possible)."
    why_human: "No render was captured in the UI review; contrast and colour roles need eyes."
  - test: "Owner decision on the two open review/security items that do not break a stated criterion: CR-01 (deactivated assignee or requester blocks every Admin edit save of that task) and T-05-44 / WR-02 (unescaped task title and actor name in notification mails and bell bodies, Admin- and Partner-bound)"
    expected: "Decide whether to close both with a gap-closure plan before Phase 6 / before shipping (this report recommends it) or to accept and track them. Evidence is in the Non-blocking observations section."
    why_human: "Severity and ship-blocking is an owner call; neither contradicts a ROADMAP success criterion or a TA/KB requirement text."
---

# Phase 5: Tasks and Kanban Verification Report

**Phase Goal:** Admin organises work as tasks and subtasks with per-project keys and a drag-and-drop board, and a Partner can raise and discuss tasks in visible projects without seeing anything internal
**Verified:** 2026-10-09T04:10:00Z
**Status:** human_needed
**Re-verification:** No, initial verification

Method: goal-backward against the five ROADMAP success criteria. The working tree has no uncommitted source changes (only `.planning/config.json`, `.planning/state.json` and `.planning/milestone.lock`). I read the implementation (Actions, models, migrations, Filament classes, board concern, notifications) rather than the SUMMARYs, and re-ran the phase suites myself: `tests/Feature/Tasks tests/Isolation tests/Arch tests/Feature/Schema` gave 680 passed (4201 assertions), and `tests/Concurrency` gave 10 passed (209 assertions, including both mutation runs that prove the tests detect a missing lock). The full-suite, Pint, PHPStan, check-licenses and check-sensitive results at the phase gate are taken from 05-VALIDATION.md and the 05-17 SUMMARY; I did not re-run the full suite.

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
| `app/Domain/Tasks/Notifications/*` | Scalar-only queued notifications | VERIFIED | See observation O-2 on escaping |
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
| Edit form people selects | `UpdateTask` unchanged-person rule | `TaskResource::peopleOptions` | PARTIAL | See CR-01 below: the form offers active accounts only, contradicting the Action |

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
| Task, isolation, arch and schema suites | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Isolation tests/Arch tests/Feature/Schema` | 680 passed, 4201 assertions | PASS |
| Parallel numbering and board moves with mutation runs | `ddev exec vendor/bin/pest tests/Concurrency` | 10 passed, 209 assertions | PASS |
| Notification leak canary and Partner comments | `ddev exec vendor/bin/pest tests/Isolation/NotificationLeakTest.php tests/Feature/Tasks/PartnerTaskCommentsTest.php` | 16 passed, 507 assertions | PASS |

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

Debt-marker scan (`TODO|FIXME|XXX|TBD`) over `app/Domain/Tasks`, the task Filament classes, the board concern and the board view: no matches. No stub returns on rendered paths. Findings from the code review that I re-read in the code:

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Filament/Resources/TaskResource.php` | 238-247, 616-621 | Assignee/requester selects list active accounts only | Warning | CR-01, see O-1 |
| `app/Domain/Tasks/Notifications/*.php` | mailLine/bellBody | Task title and actor name interpolated unescaped | Warning | T-05-44 / WR-02, see O-2 |
| `app/Domain/Tasks/Actions/AddTaskComment.php` | 45-66 | No re-read of task before insert | Info | WR-03, narrow race |
| `app/Domain/Tasks/Actions/CreateTask.php` / `UpdateTask.php` / `TaskInput.php` | various | Title length and date range enforced only by forms | Info | WR-01; 500 only through forged Livewire payload today, relevant for the Phase 7 API |

### Human Verification Required

See the `human_verification` list in the frontmatter (seven items): touch drag at 375 px, SPA navigation, Mailpit rendering, Czech copy review, full Admin and Partner walk-through, light and dark visual check, and the owner decision on O-1 and O-2.

### Non-blocking observations (evidence for the owner decision)

**O-1. CR-01 confirmed in code.** `TaskResource::peopleOptions()` returns `TaskPeople::options($project)` (active Admin and active Partners of the client) with no fallback to the task's current person, while `UpdateTask` documents and implements that an unchanged person is not re-validated "so a task keeps a person who was deactivated since". Filament's single Select adds an `in` rule from the option list, so saving any edit of a task whose assignee or requester was deactivated afterwards (for example an offboarded Partner) is rejected with an invalid-value error on that field until the Admin re-picks someone. Why this does not break a criterion: task creation, board moves, archive, comments and escalation are unaffected; the edit form works as soon as another person is chosen; the data layer has no defect. It does contradict the Action's own documented intent and a plan prohibition's spirit, and no test pins the save of such a task. It is an annoyance on an edge path, not a missing capability, so it is recorded here and not as a gap against TA-01. Recommended fix: the reviewer's `peopleOptions` that adds the current inactive person, plus a Livewire test of a rename on such a task.

**O-2. T-05-44 / U-1 and WR-02 confirmed in code.** `TaskCreatedNotification::mailLine/bellBody`, `TaskCommentedNotification::mailLine`, and the escalation and changed counterparts interpolate `taskTitle` and `actorName` through `__()` with no `e()` or `escapeMarkdown()`; only `TaskChangedNotification::bellBody` and the excerpt path escape. A Partner controls the title of the tasks they create and their own display name, so a Partner can place links, styles or remote images into the Admin's bell and mails, and (WR-02) into mails for another Partner of the same client. No script execution, no internal data and no cross-client data is exposed, the internal-comment guard is intact, and neither TA-07 nor SC5 states anything about markup in titles, so it does not make a criterion false. It is a real phishing-class integrity weakness rated medium and below the project's blocking threshold in 05-SECURITY.md (`threats_open: 0` at the high threshold). Recommended fix: escape in the `TaskNotification` base class and extend `NotificationLeakTest`; G-1 (Partner `tags` not dropped in `CreateTask`) fits the same closure plan.

**O-3. Other open items from 05-REVIEW (WR-01, WR-03, WR-04, IN-01..IN-07) and the UI review (16/24).** None breaks a criterion. WR-04 means the 05-SECURITY claim "`fileAttachments(false)` on all five editors, pinned by test" is overstated: only the Admin description editor is pinned by a test, so the Partner-facing editors rely on code alone (I did not re-verify the other four editors in code; the reviewer states they carry the call). UI flags F-1 (Amber primary), F-2 (off-scale sizes), F-5 (no keyboard board move), F-8 ("Ke kontrole" uses the accent role) and F-9 (generic labels on the Admin task and edit pages) are open and are cosmetic or accessibility departures from 05-UI-SPEC, not functional gaps. The ROADMAP checkbox for Phase 4 still shows unchecked although its VERIFICATION exists; that is bookkeeping outside this phase.

### Gaps Summary

No success criterion or requirement is unmet. All five ROADMAP success criteria and all ten requirement IDs (TA-01 to TA-07, KB-01 to KB-03) are backed by implemented, wired code and by tests that I re-ran green, including the concurrency proofs with mutation runs. The status is `human_needed` because the plans' `<human-check>` items (touch drag, SPA navigation, Mailpit rendering, Czech copy, end-to-end walk, visual check) cannot be exercised by the test stack, and because the owner should decide whether CR-01 and T-05-44 / WR-02 / G-1 are closed by a gap-closure plan before the phase ships. Both are real defects with a small fix; I recommend closing them, but they do not falsify a stated criterion.

---

_Verified: 2026-10-09T04:10:00Z_
_Verifier: Claude (gsd-verifier)_
