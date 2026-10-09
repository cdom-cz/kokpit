# Phase 5: Tasks and Kanban - Research

**Researched:** 2026-10-08
**Domain:** Laravel 13 / Filament 5 / Livewire 4 CRUD plus a custom drag-and-drop board on PostgreSQL 18, with Partner (client account) isolation
**Confidence:** HIGH for the reuse map, schema design and board mechanics (code read this session and PostgreSQL behaviour measured); MEDIUM for notification recipient rules and Partner visibility of tags and checklist (design recommendations marked `[ASSUMED]`)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

Already fixed in earlier phases (not re-opened): Partner isolation via `users.client_id`, fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); typed settings, activity log allowlist on the model, queued mail pattern (Phase 3); statuses and priorities switch freely with no workflow, optional dates, billing data in a separate Admin-only 1:1 table (Phase 4 D-05, D-16); custom `wire:sort` kanban board with `spatie/eloquent-sortable` and an advisory lock around moves, no Flowforge (Phase 3 spike `03-SPIKE-KANBAN.md`); `projects.next_task_number` counter and sequence allocator; project key frozen after the first task.

#### Statuses and kanban board
- **D-01:** Task status is the same enum as the project status (Planned, To clarify, In progress, In review, Ready to release, Done; Czech labels from Phase 4 D-16). One enum, one set of labels, free switching, no workflow.
- **D-02:** The board has one column per status. The Done column shows only the most recent N cards (default 20, ordered by completion time, value in `config/kokpit.php`); older done tasks are reachable through the list. Position is stored per status column.
- **D-03:** Subtasks are cards of their own: a subtask has its own status, sits in its own column and can be dragged. The card shows `KEY-N`, title, priority, due date, tags and a link or label of its parent. Parent and subtask statuses are independent (no automatic roll-up).

#### People: assignee and requester
- **D-04:** Every task and subtask has both an assignee (řešitel) and a requester (zadavatel), both NOT NULL in the DB. Each may be the Admin or a Partner account. A task created by Admin defaults to requester = Admin, assignee = Admin (overridable). A task created by a Partner defaults to requester = the Partner and assignee = Admin; the Partner cannot choose the assignee.
- **D-05:** The pickers offer only the Admin and active Partner accounts of the client that owns the project. A Partner never sees accounts of another client; a forged value is rejected by validation and Policy.

#### Partner escalation, notifications and comments
- **D-06:** A Partner escalates a task with an "Escalate" action that requires a comment. It creates a non-internal comment and sets an escalation flag on the task (who, when). Priority is never changed by the Partner or automatically; the assignee resolves the flag and raises priority manually. The flag can be cleared.
- **D-07:** Notifications are queued e-mail plus a database notification (Filament bell) for both sides. Admin or assignee is notified of a task, comment or escalation by a Partner. Partner (requester or assignee) is notified of non-internal comments and relevant changes by Admin. The escalation flag notifies the assignee, falling back to the Admin. Internal comments never notify a Partner and never appear in any notification body, e-mail, activity log or export visible to a Partner.
- **D-08:** Comments exist on tasks and subtasks, with an internal flag. A Partner comment is never internal (forced server-side, not just hidden in the form). A Partner never sees internal comments.
- **D-15:** Notification delivery is configurable per user in their profile: for each event type (task created by a Partner, comment, escalation, assignment or change) the user switches the e-mail and the bell channel on or off. Defaults are all on. This applies to Admin and Partner accounts alike. The preferences only narrow delivery: the rule that internal comments never reach a Partner is unconditional and not a preference. Exact event list and profile page layout are Claude's discretion.

#### Task detail and text format
- **D-09:** A task opens as a full page at a stable URL by key (`/tasks/KEY-N`, found by key in global search). From the board a card opens a slide-over preview. Quick creation is one modal asking for title and project, then continues on the detail page.
- **D-10:** Description and comments use the Filament rich text editor, stored as sanitised HTML. Partner input passes the same sanitisation (XSS) and needs a canary test.
- **D-11:** A subtask has the same fields as a task (own status, assignee, requester, dates, priority, tags, checklist, comments, billing) but cannot have subtasks itself (DB check). It takes its number from the same project counter. File attachments are deferred to Phase 9; the UI only reserves the place.

#### Task billing fields (TA-06)
- **D-12:** Task billing type values: inherit (default), hourly, fixed price, non-billable. Non-billable pre-sets the billable flag to false on time entries (Phase 6). Projects keep only hourly and fixed (04 D-15); non-billable exists on tasks only.
- **D-13:** Billing type, fixed price, rate override and estimate live in a separate 1:1 table `task_billing`, Admin-only (`#[DeniesPartners]`), same pattern as `project_billing`. A Partner query on tasks never loads these columns (selects, search, exports, error output, activity log). — **Reversibility:** costly — every task form, list and the later billing read goes through this relation.
- **D-14:** Empty values mean inherit at read time (no copy): subtask empty falls back to the parent task, then the project, then the client (rate resolution order from the brief). A subtask has its own `task_billing` row when it overrides anything.

### Claude's Discretion
Class and file names, exact number of cards shown in the Done column if config differs, escalation flag column names, notification and mailable wording, Czech label wording (including "Escalate"), sanitiser configuration, card layout details, list column set and default sort, checklist storage shape, tag handling (same Spatie tags as projects).

### Deferred Ideas (OUT OF SCOPE)
- File attachments on tasks and comments: Phase 9.
- Time entries, timer start from a task: Phase 6.

Also out of scope per the phase boundary: billing from tasks (Phase 10), API endpoints for tasks (Phase 7).
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| TA-01 | Tasks and one-level subtasks with title, status, description, dates, priority, assignee, tags and files | `tasks` schema with composite-FK one-level guarantee (measured), `Task` model, `CreateTask`/`UpdateTask` Actions, RichText sanitiser, `TagType::Task`; files are a Phase 9 placeholder |
| TA-02 | `KEY-N` from one per-project counter, locked transaction, never recycled; searchable and usable in URL and API | `DocumentNumbering::nextTaskNumber` + `SequenceAllocator` inside `CreateTask` transaction, `tasks.reference` unique column used as Filament route key and global search attribute, project key freeze trigger (measured) |
| TA-03 | Todo checklist on tasks and subtasks | `task_checklist_items` table (Admin-only), relation-backed Filament repeater |
| TA-04 | Comments on tasks and subtasks, optionally internal; Partner never sees internal | `task_comments` table, `TaskComment` Partner constraint `is_internal = false`, server-side forcing in `AddTaskComment`, strict sanitiser |
| TA-05 | List view with filters (client, project, status, priority, assignee, tag, due date) | `TaskResource` table filters (custom `Filter` for client, tag, due range), eager loading |
| TA-06 | Fixed price, billing type, rate override, time estimate on task level | `task_billing` table mirroring `project_billing`, `TaskBillingType` enum, read-time `TaskBillingResolver` |
| TA-07 | Partner creates and comments in visible projects, cannot change status or priority; Admin notified | `PartnerTaskResource` (create, list, view, comment, escalate), `TaskPolicy` explicit grants, notification pipeline with per-user preferences |
| KB-01 | Kanban per project and global, columns by status, card order by position | `ManagesTaskBoard` page trait, global page and project page, `tasks.position` with deferred partial exclusion constraint |
| KB-02 | Drag and drop changes status and position synchronously; global view filters by client, assignee, tag, priority | Guarded `MoveTask` service under a transaction advisory lock, neighbour-recompute algorithm for filtered boards, synchronous `wire:sort` (no `.async`) |
| KB-03 | Partner has no board manipulation and sees a read-only task list | No Partner board page at all; Admin-only board pages enforce `#[AccessRule]` at Livewire boot (403); Partner list is a separate read-only resource |
</phase_requirements>

## Summary

Phase 5 is mostly a faithful application of patterns that already exist in the repository: `Project`/`ProjectBilling` for the model pair, `CreateProject`/`UpdateProject` for Domain Actions, `PartnerProjectResource` for the Partner surface, `PartnerScope` + `KokpitPolicy` for isolation, `PartnerInvitation` for queued notifications, and the registry tests that fail until each new model and Filament class is declared. The new engineering is in four places: (1) the `tasks` table, where a composite foreign key plus a generated column gives a real database guarantee for "one-level subtasks" and "same project", (2) the numbering and key-freeze path, where an in-repo allocator already exists but the freeze does not, (3) the board mover, where the Livewire 4 `wire:sort` handler only supplies an index and a group id, so filtered boards need a server-side neighbour recompute, and (4) the notification pipeline with per-user channel preferences and an unconditional internal-comment guard.

Several statements in CONTEXT.md and earlier phases do not match the code and must be corrected before planning: the counter is **not** a column `projects.next_task_number`; it is a row in `number_sequences` with scope key `task:<project uuid>`, already wired through `DocumentNumbering::nextTaskNumber`. The project key freeze is **not** implemented anywhere (the Phase 4 `UpdateProject` Action still rewrites the key). The Filament bell is currently Admin-only and global search is switched off for the whole panel, both of which this phase must change. `TagType` has no task case. Details are in "Corrections to the planning documents" below.

PostgreSQL 18 behaviours that the design depends on were measured in this session (temp tables and a scratch schema that was dropped): a `DEFERRABLE INITIALLY DEFERRED` partial `EXCLUDE USING btree` constraint accepts sequential per-row position rewrites and rejects a duplicate at COMMIT; a unique index cannot be deferrable; a composite FK on a generated column rejects sub-subtasks, cross-project parents and the conversion of a parent into a subtask; a plain `BEFORE UPDATE OF key` trigger is enough to freeze the project key without a race against a concurrent task insert.

**Primary recommendation:** Build `app/Domain/Tasks/` as Actions + models + policies + a `TaskBoard` mover service; add one migration set (tasks, task_billing, task_comments, task_checklist_items, users.notification_preferences, project-key freeze trigger); store `tasks.reference` (`KEY-N`) as a unique column and use it as the Filament route key and global search attribute; serialise every write of `(status, position)` behind one `pg_advisory_xact_lock`; ship the board as an Admin-only page with a Partner-facing read-only list resource; sanitise all rich text on write with a dedicated strict Symfony `HtmlSanitizer` (never the permissive Filament default).

## Corrections to the planning documents

| # | Statement in the docs | Reality in the code | Impact |
|---|-----------------------|---------------------|--------|
| C1 | CONTEXT.md and 04-CONTEXT.md: counter is `projects.next_task_number` | The `projects` table has no such column. The counter is a `number_sequences` row. Quote, `database/migrations/2026_10_07_000100_create_number_sequences_table.php:13-16`: "A key is "kind:qualifier", for example invoice:2026 or task:<project uuid>." Quote, `app/Domain/Settings/Numbering/DocumentNumbering.php:74`: `$number = $this->allocator->next(DocumentKind::Task->sequenceKind().':'.$projectId);` `[VERIFIED: those files]` | No migration adds a counter column. `CreateTask` calls `DocumentNumbering::nextTaskNumber($project->id, $project->key)` inside its transaction. |
| C2 | PR-02 "key frozen after first task" listed as complete in REQUIREMENTS.md | Phase 4 deliberately left the freeze to Phase 5 (04-CONTEXT D-14 "Freezing after the first task is Phase 5"). Quote, `app/Domain/Projects/Actions/UpdateProject.php:121-122`: `if (isset($data['key'])) {` / `$attributes['key'] = Str::upper($data['key']);` `[VERIFIED: UpdateProject.php:121-123]` | Phase 5 owns: DB trigger, `UpdateProject` field error, disabled key field once a task exists, tests. |
| C3 | "Notifications ... Filament bell for both sides" | Quote, `app/Providers/Filament/AdminPanelProvider.php:73`: `->databaseNotifications(static fn (): bool => app(PartnerContext::class)->isAdmin())` and line 80: `->globalSearch(false)`. `[VERIFIED: AdminPanelProvider.php:73,80]` | The bell condition must admit Partners; global search must be enabled with resource opt-in (see Pattern 8). |
| C4 | "tags: same Spatie tags as projects" | Quote, `app/Domain/Shared/Tags/TagType.php:18-19`: `case Client = 'client';` / `case Project = 'project';`. `Tag::constrainForPartner` only matches `TagType::Project` tags attached to visible projects. `[VERIFIED: TagType.php:18-19]` | Add `case Task = 'task';`. Partner constraint stays project-only (task tags remain invisible to a Partner, see A1). |
| C5 | D-09 URL `/tasks/KEY-N` | Every resource lives under the panel path (`->path('admin')`, `AdminPanelProvider.php`). A resource with slug `tasks` yields `/admin/tasks/KEY-N`. | Treated as `/admin/tasks/KEY-N`. Optional plain redirect route `/tasks/{reference}` is an Open Question (A2). |
| C6 | Spike draft: "Partner reachable board" | KB-03 and ROADMAP criterion 5 say Partner gets a read-only **list** and cannot move cards. | No Partner board page. Forged-move test targets the Admin-only board page (403 at Livewire boot) and the Partner pages (method not callable). |
| C7 | Spike: "require `spatie/eloquent-sortable` directly" | Locked at 5.0.1 only through `spatie/laravel-tags` (`composer why`). Its config defaults are `'sort_when_creating' => true` and `'ignore_timestamps' => false` (`vendor/spatie/eloquent-sortable/config/eloquent-sortable.php:13,19`). `[VERIFIED: vendor file lines 7-19 and composer why]` | `composer require spatie/eloquent-sortable:^5.0`; publish config with `ignore_timestamps => true` so reorders do not bump `updated_at`; model-level `$sortable` overrides the rest. |

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| `KEY-N` allocation and key freeze | Database / Storage | API / Backend (Action) | Counter row lock + unique indexes + trigger are the guarantee; the Action only orders the calls in one transaction |
| One-level subtasks, same-project parent | Database / Storage | API / Backend | Composite FK on a generated column; Action gives the friendly error |
| Partner isolation of tasks, comments, checklist, billing | API / Backend (global scope + Policy) | Database (separate Admin-only tables) | `PartnerScope` constrains reads; Admin-only data lives in separate tables so no column can leak |
| Rich text sanitisation | API / Backend (on write and on render) | Browser (editor is untrusted input) | The browser value can be forged; sanitise server-side in the Action |
| Board ordering and moves | API / Backend (Livewire handler + service) | Database (lock, exclusion constraint) | Livewire only sends item id, index and group id; the server decides order |
| Drag and drop interaction | Browser / Client (Livewire-bundled SortableJS) | — | No custom JS or build step |
| Notification fan-out and preferences | API / Backend (Actions + queued Notifications) | Database (users.notification_preferences, notifications) | Recipients computed before queueing; worker has no user context |
| Global search by key | Frontend Server (Filament panel) | Database (unique index on `reference`) | Filament resource search over `reference` and `title` |
| Rate/billing resolution | API / Backend (read-time resolver) | — | D-14: no copy; Phase 6/10 call the resolver |

## Standard Stack

### Core
| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| laravel/framework | v13.35.0 | Framework | Installed `[VERIFIED: composer.lock]` |
| filament/filament | v5.10.0 | Panel, Resources, RichEditor, bell, global search | Installed `[VERIFIED: composer.lock]` |
| livewire/livewire | v4.4.7 | `wire:sort` drag and drop, components | Installed; `wire:sort`, `wire:sort:item`, `wire:sort:group`, `wire:sort:group-id`, `wire:sort:config`, `wire:sort:ignore` are present in `vendor/livewire/livewire/dist/livewire.js` `[VERIFIED: grep of dist/livewire.js]` |
| spatie/eloquent-sortable | 5.0.1 (add as direct requirement) | Dense position writes (`setNewOrder`) | Decided in 03-SPIKE-KANBAN.md; MIT, locked via spatie/laravel-tags `[VERIFIED: composer show, packagist API]` |
| spatie/laravel-tags | 4.12.0 | Task tags | Installed; `Project` already uses `HasTags` `[VERIFIED: composer.lock]` |
| spatie/laravel-activitylog | 5.1.1 | History via `LogsAllowlistedActivity` wrapper | Installed |
| symfony/html-sanitizer | v8.1.8 | Strict rich-text sanitiser | Already locked (Filament dependency); Filament binds `HtmlSanitizerConfig` + `HtmlSanitizerInterface` in `SupportServiceProvider.php:110-134` `[VERIFIED: composer.lock, vendor file]` |
| pestphp/pest | v5.3.0 | Tests (`ddev exec vendor/bin/pest`) | Installed |

### Supporting
| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| filament/spatie-laravel-tags-plugin | ^5.10 | `SpatieTagsInput/Column/Entry` | Task form, list, card |
| larastan/larastan | v3.13.0, level 8 | CI static analysis (`phpstan.neon` `level: 8`) | All new code must pass |
| laravel/horizon | ^5.50 | Queue worker (DDEV daemon) | Queued notifications |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Custom `wire:sort` board | Flowforge | Rejected by the spike (no authorization hook, needs theme build) |
| Stored `tasks.reference` | Join on `projects.key` + `number` | Join works because the key is frozen, but a stored unique column gives a one-index lookup, a plain Filament route key and trivial search; consistency is guaranteed by the freeze trigger |
| `users.notification_preferences` jsonb | Normalised `notification_preferences` table | Table is more relational but needs a model, a morph alias, a registry line and a Partner-scope decision; a validated jsonb column on `users` (a `NotPartnerScoped` model) has none of that cost |

**Installation:**
```bash
ddev composer require spatie/eloquent-sortable:^5.0
ddev artisan vendor:publish --tag=eloquent-sortable-config   # set ignore_timestamps => true
```

**Version verification:** `composer show spatie/eloquent-sortable` returned version 5.0.1, released 2026-02-21, MIT; packagist API returned the same version and licence. No other package is added.

## Package Legitimacy Audit

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| spatie/eloquent-sortable | Packagist | 5.0.1 released 2026-02-21 (long-lived package, major 5) | already installed transitively | github.com/spatie/eloquent-sortable | OK (already in `composer.lock` through spatie/laravel-tags; MIT, AGPL-compatible; `composer licenses` lists it) | Approved: move from transitive to direct requirement |

The `gsd-tools package-legitimacy check` seam only accepts `npm|pypi|crates` (its usage line: `--ecosystem <npm|pypi|crates>`), so it cannot check a Composer package; the verdict above rests on the lockfile, `composer licenses` and the packagist API. **Packages removed due to SLOP:** none. **Packages flagged SUS:** none. No other package is introduced (Flowforge, a purifier package and a rich-text package are all explicitly not needed).

## Architecture Patterns

### System Architecture Diagram

```
Admin (browser)                              Partner (browser)
  |  create/edit task, comment, drag card       |  create task, comment, escalate (no edit, no move)
  v                                             v
Filament panel (SPA)  ---------------------------------------------------------------
  | TaskResource (Admin)  | Board pages (Admin only)  | PartnerTaskResource (read-only list + create)
  | #[AccessRule] + Enforces* traits + TaskPolicy (Partner: explicit grants only)
  v
Domain Actions (one transaction each)                         Livewire handler moveCard($id,$pos,$group)
  CreateTask / UpdateTask / AddTaskComment /                    1 whitelist status (422)
  EscalateTask / ClearEscalation / SaveChecklist                2 scoped lookup (404) + Gate::authorize('update') (403)
  |                                                             3 pg_advisory_xact_lock  <-- same lock as CreateTask
  |  CreateTask order inside DB::transaction:                   4 recompute destination order (filter-aware)
  |   1 board advisory lock                                     5 $task->update(status, completed_at)  -> activity log
  |   2 SELECT project ... FOR SHARE  (blocks key change)       6 setNewOrder(suffix, startIndex)
  |   3 DocumentNumbering::nextTaskNumber -> number_sequences   7 DB constraints = last line
  |      row "task:<project uuid>" FOR UPDATE
  |   4 INSERT task (number, reference, position = max+1)
  v
PostgreSQL 18: tasks (unique reference, unique (project_id, number), composite FK parent,
               deferred partial EXCLUDE (status, position)), task_billing, task_comments,
               task_checklist_items, number_sequences, projects_key_frozen_guard trigger
  |
  v  after commit (notifications use afterCommit)
TaskNotifier: recipients computed from scalars (actor role, is_internal, assignee, requester)
  -> queued Notification (scalars only) -> via() = mail/database filtered by users.notification_preferences
  -> Horizon worker (no user context) -> mail + notifications table (Filament bell, now for Partners too)
```

### Recommended Project Structure
```
app/Domain/Tasks/
├── Actions/            # CreateTask, UpdateTask, MoveTask, AddTaskComment, EscalateTask, ClearEscalation, SaveChecklist, RestoreTask
├── Billing/            # TaskBillingResolver, EffectiveBilling (value object)
├── Board/              # TaskBoard (query + order math), BoardFilters
├── Enums/              # TaskBillingType (inherit, hourly, fixed_price, non_billable)
├── Models/             # Task, TaskBilling, TaskComment, TaskChecklistItem
├── Notifications/      # NotificationEvent, NotificationChannel, NotificationPreferences, TaskNotifier, *Notification classes
└── Policies/           # TaskPolicy, TaskCommentPolicy
app/Domain/Shared/Text/RichText.php        # strict sanitiser (write + render)
app/Filament/Resources/TaskResource.php (+ Pages/, RelationManagers/ TaskCommentsRelationManager, SubtasksRelationManager, TaskHistoryRelationManager)
app/Filament/Partner/Resources/PartnerTaskResource.php (+ Pages/List, Create, View; PartnerTaskCommentsRelationManager)
app/Filament/Pages/TaskBoardPage.php        # global board, Admin only
app/Filament/Resources/ProjectResource/Pages/ProjectBoard.php   # per-project board
app/Filament/Concerns/ManagesTaskBoard.php  # shared Livewire handler + computed columns
app/Filament/Pages/Auth/EditProfile.php     # notification preferences section
resources/views/filament/pages/task-board.blade.php (+ partials for column and card)
database/migrations/2026_10_10_0001xx_*     # tasks, task_billing, task_comments, task_checklist_items, users prefs, key-freeze trigger
database/factories/TaskFactory.php
```

### Reuse map (exact extension points)

| Existing asset | What Phase 5 adds | Path |
|----------------|-------------------|------|
| `PartnerScope`, `IsolatesPartners`, `PartnerIsolated` | `Task` and `TaskComment` implement `PartnerIsolated` with real `constrainForPartner`; `use IsolatesPartners` | `app/Domain/Shared/Auth/` |
| `DeniesPartners` | `TaskBilling`, `TaskChecklistItem` (Admin-only) | same |
| `AccessRule`, `Audience`, `Enforces*AccessRule` traits | Every new Resource, Page, RelationManager declares `#[AccessRule]`; Resource uses `EnforcesResourceAccessRule`, Page `EnforcesPageAccessRule`, RelationManager `EnforcesRelationManagerAccessRule` | `app/Filament/Concerns/` |
| `KokpitPolicy` | `TaskPolicy`, `TaskCommentPolicy`; Admin passes in `before()`, Partner needs explicit overrides | `app/Domain/Shared/Auth/KokpitPolicy.php` |
| `AccessServiceProvider::boot` | One `Gate::policy(...)` line per model: `Task` -> `TaskPolicy`, `TaskComment` -> `TaskCommentPolicy`, `TaskBilling` and `TaskChecklistItem` -> `AdminOnlyPolicy` (existing lines for Project, ProjectBilling are at `AccessServiceProvider.php:61-64`) | `app/Providers/AccessServiceProvider.php` |
| `MorphMap::MAP` | Four lines: `'task'`, `'task_billing'`, `'task_comment'`, `'task_checklist_item'` (existing pattern at `MorphMap.php:43-44`: `'project' => Project::class,` / `'project_billing' => ProjectBilling::class,`) `[VERIFIED: MorphMap.php:43-44]` | `app/Domain/Shared/Database/MorphMap.php` |
| `LogsAllowlistedActivity` + `#[LoggedAttributes]` | `Task` and `TaskBilling` (not `TaskComment`, not checklist) | `app/Domain/Audit/` |
| `ActivityHistoryRelationManager` | `TaskHistoryRelationManager` (3-line subclass, own `#[AccessRule(Audience::AdminOnly ...)]`) | `app/Filament/RelationManagers/` |
| `ActivityPresenter::subjectLabel` | Add `kokpit.activity.subjects.task` and `.task_billing` Czech labels in `lang/cs/kokpit.php` (existing keys `client`, `project`, `project_billing`, `contact` at lines 206-211) | `lang/cs/kokpit.php` |
| `Tag` model | `Tag::constrainForPartner` unchanged (project-only). `TagType::Task` added | `app/Domain/Shared/Tags/TagType.php` |
| `SequenceAllocator`, `DocumentNumbering::nextTaskNumber` | Called from `CreateTask`; returns the string `KEY-N`; the integer is the suffix after the last `-` | `app/Domain/Shared/Sequences/`, `app/Domain/Settings/Numbering/` |
| `ProjectInput` (money, estimateSeconds, requireFixedPrice) | Reused by `UpdateTask`/`CreateTask` for the billing fields (it throws `ValidationException` keyed by data key) | `app/Domain/Projects/Actions/ProjectInput.php` |
| `Project::scopeSelectable()` | Project picker of every task form; Partner scope still applies on top | `app/Domain/Projects/Models/Project.php` |
| `ProjectStatus`, `ProjectPriority` | Task `status` and `priority` casts (D-01); labels `lang/cs/enums.php` keys `project_status`, `project_priority` (lines 49, 58) | `app/Domain/Projects/Enums/` |
| `PartnerProjectResource` | Template for `PartnerTaskResource` (`canAccess` override requires Partner with client, no Admin nav duplicate) | `app/Filament/Partner/Resources/` |
| `ProjectResource` + `Create/UpdateProject` + `RethrowsDomainValidation` | Template for `TaskResource` pages delegating to Actions | `app/Filament/Resources/` |
| `PartnerInvitation` notification | Template: `ShouldQueue`, `afterCommit()`, constructor of scalars only | `app/Domain/Clients/Notifications/PartnerInvitation.php` |
| `OperationalAlert::toDatabase` | Template for the Filament-format database payload (`FilamentNotification::make()->...->getDatabaseMessage()`) | `app/Domain/Operations/Alerts/OperationalAlert.php` |
| `KokpitJob` / `#[Idempotent]` | **Not needed**: notifications are queued Notification classes (not Dispatchable jobs). `tests/Arch/JobContractTest.php:26` keeps `EXPECTED_APPLICATION_JOBS = [RecordWorkerHeartbeat::class]`; adding no job keeps it green `[VERIFIED: JobContractTest.php:26]` | — |
| `Canary` / `CanaryRegistry` | One fixture line per `PartnerIsolated` model: `Task`, `TaskComment`, `TaskBilling`, `TaskChecklistItem` | `tests/Support/CanaryRegistry.php` |
| Registry tests | Update the pinned lists (see "Registry lines to touch" below) | `tests/Arch`, `tests/Isolation` |
| `config/kokpit.php` | Add `'board' => ['done_limit' => 20]` with the same comment style as `alerts` and `health` | `config/kokpit.php` |

### Registry lines to touch (tests fail until done)

| Test | Current pinned content | Change |
|------|------------------------|--------|
| `tests/Arch/ModelDeclarationTest.php:~30-48` "finds every model" | exact list incl. `Project::class`, `ProjectBilling::class` | Add `Task`, `TaskBilling`, `TaskComment`, `TaskChecklistItem` |
| same file, "gives every PartnerIsolated model a policy that extends KokpitPolicy" | `toContain(CanaryRecord::class, Client::class, ..., ProjectBilling::class, ...)` | Add the four models |
| `tests/Arch/ActivityAllowlistTest.php:45` | `expect(AuditDeclaration::loggingModels())->toBe([Client::class, Contact::class, Project::class, ProjectBilling::class]);` `[VERIFIED: ActivityAllowlistTest.php:45]` | Add `Task::class`, `TaskBilling::class` (sorted) |
| `tests/Isolation/CanaryRegistryTest.php` "does not pass vacuously" (list near line 87-100) | exact list of PartnerIsolated models | Add the four models |
| `tests/Support/CanaryRegistry.php::fixtures()` | one closure per model | Four closures (canary in title/description, comment body, billing note... see Validation) |
| `tests/Isolation/RouteWalkTest.php:114-118` `walkedResourceMap` | `'my-projects' => ['partner' => true, ...]`, `'projects' => ['partner' => false, ...]`, `'clients' => ['partner' => false, ...]` `[VERIFIED: RouteWalkTest.php:114-118]` | Add `'tasks' => ['partner' => false, 'a' => <reference A>, 'b' => <reference B>]` and `'my-tasks' => ['partner' => true, ...]`. Record ids passed are task **references**, not uuids |
| `tests/Isolation/PartnerSafeColumnsTest.php` | pins `projects` columns | Add the same pin for `tasks` and a suspicious-name check |
| `tests/Arch/PanelRegistryTest.php` | scans all of `app/Filament` | No edit; it fails until each new class has `#[AccessRule]` |
| `tests/Feature/Localisation/EnumLabelsTest.php` | scans all `HasLabel` enums | No edit; `TaskBillingType` needs `lang/cs/enums.php` keys |
| `tests/Feature/Schema/SchemaConventionsTest.php` | R1 to R8 generic | No edit; new tables must follow uuid/`uuidv7()`/`timestamptz`; `tests/Support/PgSchema.php` EXEMPT map is not touched |

### Pattern 1: `tasks` schema (database is the guarantee)

**What:** One table for tasks and subtasks. The one-level rule and the same-project rule are a composite foreign key over a generated column (measured in PostgreSQL 18, see Evidence block 1).

**Columns (Partner-readable table, column list pinned by a test):**
`id` uuid pk default `uuidv7()`; `project_id` uuid NOT NULL FK `projects` RESTRICT; `parent_id` uuid NULL; `depth` smallint NOT NULL; `parent_depth` smallint GENERATED ALWAYS AS (`CASE WHEN parent_id IS NULL THEN NULL ELSE 0::smallint END`) STORED; `number` integer NOT NULL; `reference` varchar(12) NOT NULL; `title` varchar(255) NOT NULL; `description` text NULL (sanitised HTML); `status` varchar(24) default `planned`; `priority` varchar(8) default `normal`; `position` integer NOT NULL; `start_date` date NULL; `due_date` date NULL; `completed_at` timestamptz NULL; `assignee_id` uuid NOT NULL FK `users` RESTRICT; `requester_id` uuid NOT NULL FK `users` RESTRICT; `escalated_at` timestamptz NULL; `escalated_by_id` uuid NULL FK `users`; `softDeletesTz()`; `timestampsTz()`.

**Constraints:**
```php
// Source: pattern verified by psql experiment in this session (PostgreSQL 18.6), shape follows projects migration style
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('planned', 'to_clarify', 'in_progress', 'in_review', 'ready_to_release', 'done'))");
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_priority_check CHECK (priority IN ('low', 'normal', 'high', 'urgent'))");
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_depth_check CHECK (depth = CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END)');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_ident_unique UNIQUE (id, project_id, depth)');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_parent_fk FOREIGN KEY (parent_id, project_id, parent_depth) REFERENCES tasks (id, project_id, depth) ON DELETE RESTRICT');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_number_check CHECK (number >= 1)');
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_reference_check CHECK (reference ~ '^[A-Z]{2,6}-[0-9]+\$')");
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_dates_check CHECK (start_date IS NULL OR due_date IS NULL OR due_date >= start_date)');
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_completed_check CHECK ((status = 'done') = (completed_at IS NOT NULL))");
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_escalation_pair_check CHECK ((escalated_at IS NULL) = (escalated_by_id IS NULL))');
DB::statement('ALTER TABLE tasks ADD CONSTRAINT tasks_position_check CHECK (position >= 0)');
// Position: unique per active, non-done column, deferred so sequential rewrites inside one transaction are legal.
DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_position_exclusion EXCLUDE USING btree (status WITH =, position WITH =) WHERE (deleted_at IS NULL AND status <> 'done') DEFERRABLE INITIALLY DEFERRED");
$table->unique('reference', 'tasks_reference_unique');            // includes trashed rows on purpose (keys stay valid references)
$table->unique(['project_id', 'number'], 'tasks_project_number_unique');
$table->index(['project_id', 'status']); $table->index('assignee_id'); $table->index('requester_id'); $table->index('due_date'); $table->index('parent_id');
```
The status and priority value lists above are the verbatim lists of the existing project constraints (quote, `database/migrations/2026_10_09_000300_create_projects_table.php:54-55`: `CHECK (status IN ('planned', 'to_clarify', 'in_progress', 'in_review', 'ready_to_release', 'done'))` and `CHECK (priority IN ('low', 'normal', 'high', 'urgent'))`) `[VERIFIED: create_projects_table.php:54-55]`. The enum cases they must match: `ProjectStatus` `Planned = 'planned'`, `ToClarify = 'to_clarify'`, `InProgress = 'in_progress'`, `InReview = 'in_review'`, `ReadyToRelease = 'ready_to_release'`, `Done = 'done'` (`ProjectStatus.php:18-23`); `ProjectPriority` `Low = 'low'`, `Normal = 'normal'`, `High = 'high'`, `Urgent = 'urgent'` (`ProjectPriority.php:18-21`) `[VERIFIED: enum files]`. Add a test that pins the task CHECK lists against the enum cases (same as the existing project tests).

**Why these choices**
- Composite FK: `MATCH SIMPLE` skips the check when `parent_id` is NULL because the generated `parent_depth` is then NULL. For a subtask `parent_depth = 0`, so the referenced row must have `depth = 0` (a root) and the same `project_id`. A parent that already has children cannot be turned into a subtask (the FK from the children blocks the update). Evidence block 1.
- Position: dense order is **not** a DB invariant; uniqueness among active non-done rows is. Done rows are ordered by `completed_at`, so they carry no constraint; trashed rows hold no slot. Evidence blocks 2 and 3.
- `completed_at` couples to `status = 'done'` in the database, so a Done card always has a completion time for D-02's ordering. Every code path that changes status must set both in one update (the model `saving` hook or the Action).
- No `client_id` on `tasks`. The Partner constraint needs the project's visibility rules (client-visible, not archived, client not archived) anyway, so it delegates to `Project`'s own scope (see Pattern 3). The "client" list filter and board filter go through `project.client_id`.

### Pattern 2: Create with gap-free `KEY-N`, project key frozen

**What:** Allocation and insert share one transaction; every lock is taken in one fixed order (board advisory lock, project row share lock, counter row lock) so creates, moves and key edits cannot deadlock.

```php
// Source: composition of existing SequenceAllocator (FOR UPDATE on the counter row) and DocumentNumbering::nextTaskNumber
final class CreateTask
{
    public function handle(User $actor, Project $project, array $data): Task
    {
        return DB::transaction(function () use ($actor, $project, $data): Task {
            app(TaskBoard::class)->lockBoard();                                  // select pg_advisory_xact_lock(...)
            // FOR SHARE conflicts with the row lock a key UPDATE takes; the trigger then sees this task.
            $locked = Project::query()->whereKey($project->getKey())->sharedLock()->firstOrFail();  // scoped: Partner gets 404
            $reference = app(DocumentNumbering::class)->nextTaskNumber($locked->id, $locked->key);   // "ABC-12"
            $number = (int) substr($reference, strrpos($reference, '-') + 1);

            $task = new Task($attributes);                                       // fillable: title, description, status, priority, dates
            $task->forceFill(['project_id' => $locked->id, 'number' => $number, 'reference' => $reference,
                'depth' => $parent === null ? 0 : 1, 'parent_id' => $parent?->id,
                'requester_id' => ..., 'assignee_id' => ..., 'position' => app(TaskBoard::class)->nextPosition($status)])->save();
            // tags, billing row, notifications (after commit) ...
            return $task->refresh();
        });
    }
}
```
- `SequenceAllocator::next()` throws `LogicException` outside a transaction and takes `SELECT next_value FROM number_sequences WHERE scope_key = ? FOR UPDATE` (`SequenceAllocator.php`, read in full) `[VERIFIED]`. A rolled-back caller gives the number back, a deleted task never does (the counter only moves forward), so "deleted numbers are never reused" holds without extra code; a test must still prove it.
- `DocumentNumbering::nextTaskNumber(string $projectId, string $projectKey): string` validates the key with `NumberPattern::PROJECT_KEY_PATTERN` (`/^[A-Z]{2,6}$/D`) and returns the formatted `KEY-N` (`DocumentNumbering.php:68-78`) `[VERIFIED: DocumentNumbering.php:68-78]`.
- DB guarantees: `tasks_project_number_unique`, `tasks_reference_unique`, `number_sequences_next_value_check CHECK (next_value >= 1)` and `number_sequences_scope_key_format_check CHECK (scope_key ~ '^[a-z][a-z0-9_]*:[A-Za-z0-9._-]+$')` (`create_number_sequences_table.php:32-33`) `[VERIFIED]`. The scope key `task:<uuid>` matches that pattern.
- Existing concurrency harness (`tests/Concurrency/worker.php`) only drives a probe table; Phase 5 needs a task-creation worker (new `tests/Concurrency/task-worker.php` or a mode switch) that calls `CreateTask` in N processes behind the same start barrier, plus the mutation run with `UnlockedSequenceAllocator` (`tests/Support/UnlockedSequenceAllocator.php`) to show the harness can fail.

**Key freeze (PR-02):**
```sql
-- migration (same file as tasks): freeze the key as soon as ANY task row exists, trashed ones included
CREATE FUNCTION kokpit_guard_project_key() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
  IF NEW.key IS DISTINCT FROM OLD.key AND EXISTS (SELECT 1 FROM tasks WHERE project_id = OLD.id) THEN
    RAISE EXCEPTION 'project key % is frozen: the project has tasks', OLD.key USING ERRCODE = 'KP002';
  END IF;
  RETURN NEW;
END $$;
CREATE TRIGGER projects_key_frozen_guard BEFORE UPDATE OF key ON projects FOR EACH ROW EXECUTE FUNCTION kokpit_guard_project_key();
```
The existing guard function uses `ERRCODE = 'KP001'` (`create_kokpit_guard_frozen_row_function.php`, seen in grep output at lines 40 and 46); use a different code. The race (task insert in flight while the key is updated) is closed without extra locking in the trigger: Evidence block 4. Application side: `UpdateProject` throws a `ValidationException` on field `key` when the key differs and `$project->tasks()->withTrashed()->exists()`, new Czech line `kokpit.projects.errors.key_frozen`; `ProjectResource` form disables the `key` field when tasks exist (the existing `key` input is built in `ProjectResource::form`).

### Pattern 3: Partner scope for Task and TaskComment

```php
// Source: follows Tag::constrainForPartner, which delegates to Project::query() so the project rules live in one place
public function constrainForPartner(Builder $query, string $clientId): void
{
    $query->whereIn($this->qualifyColumn('project_id'), Project::query()->select('projects.id'));
}
// TaskComment
public function constrainForPartner(Builder $query, string $clientId): void
{
    $query->where($this->qualifyColumn('is_internal'), false)
          ->whereIn($this->qualifyColumn('task_id'), Task::query()->select('tasks.id'));
}
```
`Project::query()` inside a Partner request already applies `PartnerScope` -> `constrainForPartner` (own client, `client_visible`, client not archived; soft-deleted projects are excluded by `SoftDeletingScope`) (`Project.php` read in full) `[VERIFIED]`. `Task::query()` inside `TaskComment` nests the same way. `TaskBilling` and `TaskChecklistItem` use `DeniesPartners` (`WHERE 1 = 0`).

`TaskPolicy` (extends `KokpitPolicy`; Admin is admitted by `before()`):
`viewAny` true, `view` true when the task's project is in the Partner's scope and `project.client_id === $user->client_id`, `create` true (the project-level guard is the scoped lookup inside `CreateTask`), `update` false, `delete` false, plus custom abilities `comment` and `escalate` (true for a Partner who can view). Every other ability keeps the base denial. `TaskCommentPolicy`: `viewAny/view` true for non-internal comments of a viewable task, `create` true, `update/delete` false (append-only, see A6).

### Pattern 4: Strict rich-text sanitiser

Filament's `RichEditor` does **not** sanitise on write (its class comment: "Attackers can intercept the value of the component and send a different raw HTML string to the backend" and "The default sanitizer permits inline `style` attributes - configure a restrictive one for untrusted user content", `RichEditor.php:49-54`) `[VERIFIED: RichEditor.php:49-54]`. The globally bound config allows `style` and `class` on every element (`SupportServiceProvider.php:110-126`) `[VERIFIED]`, and `TextColumn/TextEntry::html()` use that global config. So:

- Create `App\Domain\Shared\Text\RichText` holding its **own** `HtmlSanitizer` with a strict config; call `RichText::clean($html)` in `CreateTask`, `UpdateTask`, `AddTaskComment`, `EscalateTask` before persisting, for Admin and Partner alike (D-10). Do not rebind Filament's global `HtmlSanitizerConfig` (the editor and other screens rely on `style`/`data-*`).
- Render stored HTML through `RichText::render()` (clean again, cheap) in Blade/infolist; keep Filament `->html()` only on already-cleaned values.
- Editor: `RichEditor::make('description')->fileAttachments(false)->toolbarButtons([...explicit list without attachFiles...])`. The default toolbar adds `attachFiles` when `hasFileAttachments(default: true)` (`RichEditor.php:1051-1066`), and uploads would land on the public disk by default; `fileAttachments(false)` exists (`HasFileAttachments.php:304-314`) `[VERIFIED: vendor lines]`. The sanitiser also drops `img`, so even a forged value stores no image.

Strict config measured in this session (output of the probe script):
```php
$strict = (new HtmlSanitizerConfig)->allowSafeElements()->dropElement('img')
    ->allowLinkSchemes(['https','http','mailto'])->allowRelativeLinks(false)
    ->forceAttribute('a','rel','noopener noreferrer nofollow')->forceAttribute('a','target','_blank')
    ->withMaxInputLength(100000);
```
Probe input `<p style="position:fixed;background:url(x)" class="x" onclick="alert(1)">Hi <b>there</b></p><script>alert(1)</script>` produced `<p>Hi <b>there</b></p>`; a `javascript:` link lost its `href`; `<img onerror>`, `<svg onload>` and `<iframe>` produced nothing; tables survived. `[VERIFIED: php probe against vendor/symfony/html-sanitizer, this session]`. The `withMaxInputLength` value is a choice (Filament uses 500000); `[ASSUMED]` 100000 characters is enough for task text.

XSS canary test shape (Partner input): submit a description and a comment containing `<script>`, `onerror`, `javascript:` and a `style="position:fixed"` fragment assembled from fragments at runtime (project rule: no single line looks like a payload constant); assert the stored column and the rendered HTML for Admin and for Partner contain none of the dangerous substrings and keep the benign text.

### Pattern 5: Board mover (guarded, filter-aware)

`wire:sort` calls the handler with `($item, $position, $groupId)` (Livewire dist `livewire.js:16741-16748`: `let params = [this.$item, position]; ... params.push(sortId)`; docs: "handler receives `$id`, `$position`, optional `$groupId`") `[VERIFIED: dist/livewire.js 16736-16749; CITED: livewire.laravel.com/docs/4.x/wire-sort]`. `position` is the index among the `wire:sort:item` children of the destination container, i.e. an index into the **rendered, possibly filtered and capped** list. Dense integer positions stored for the whole column therefore cannot be written from the index directly. Algorithm:

```php
// inside DB::transaction, after the board advisory lock; Task::query() is Partner-scoped, Gate::authorize runs first
$status  = ProjectStatus::tryFrom($groupId) ?? abort(422);                    // 1 whitelist
$task    = Task::query()->findOrFail($id);                                    // 2 scoped lookup (404)
Gate::authorize('update', $task);                                             // 3 policy (403)
$board->lockBoard();                                                          // 4 pg_advisory_xact_lock
$task->refresh();                                                             // re-read under the lock
$full    = Task::query()->where('status', $status->value)->whereKeyNot($task->id)->orderBy('position')->orderBy('id')->pluck('id')->all();
$visible = $board->applyFilters(Task::query()->where('status', $status->value)->whereKeyNot($task->id))->orderBy('position')->orderBy('id')->pluck('id')->all();
$insertAt = $position <= 0
    ? (($first = $visible[0] ?? null) === null ? count($full) : array_search($first, $full, true))
    : array_search($visible[min($position, count($visible)) - 1], $full, true) + 1;
array_splice($full, $insertAt, 0, [$task->id]);
// 5 status through the model so the `updated` event reaches the activity allowlist; completed_at set/cleared in the same update
$task->update(['status' => $status, 'completed_at' => $status === ProjectStatus::Done ? now() : null]);
// 6 positions, only from the first changed index; Done is ordered by completed_at and needs no rewrite
if ($status !== ProjectStatus::Done) { Task::setNewOrder(array_slice($full, $from), $from); }  // startOrder 0-based
```
- `setNewOrder` is static, issues one `UPDATE ... WHERE id = ?` per id with `static::withoutGlobalScope(SoftDeletingScope::class)` and returns after dispatching `EloquentModelSortedEvent` (`SortableTrait.php:44-80`) `[VERIFIED: SortableTrait.php:44-80]`. It writes through the query builder, so a pure reorder fires no model event and logs nothing (spike measurement). Start from the first changed index to cut writes.
- Every writer of `(status, position)` must take the same lock: `CreateTask` (append), `MoveTask`, `UpdateTask` when `status` changes (reuse `MoveTask` with "append to end"), `RestoreTask` (re-append; the old position may be taken now, the deferred constraint would reject it at commit).
- A card dropped into Done keeps no meaningful position (any value; use 0). A drop *inside* Done cannot reorder (order is `completed_at`); the page re-renders and the card snaps back; this is a UI note, not a bug.
- Concurrency evidence from the spike: no lock corrupted 20 of 20 rounds, the advisory lock gave 60 of 60 clean rounds (03-SPIKE-KANBAN.md "Concurrency", `[CITED: 03-SPIKE-KANBAN.md]`). Re-run the spike's two-process test against the real service with the mutation run.
- The advisory lock key: `SELECT pg_advisory_xact_lock(hashtextextended('kokpit:task_board', 0))` (`DB::select`, not `DB::table`, to stay clear of `QueryEscapeHatchTest`).

Board query/rendering rules:
- Columns = `ProjectStatus::cases()` (six). Non-done columns render all cards (show count); the Done column renders `orderByDesc('completed_at')->limit(config('kokpit.board.done_limit'))` and shows "N of M". Add `'board' => ['done_limit' => 20]` to `config/kokpit.php`.
- Eager load `project:id,key,name,client_id`, `assignee:id,name`, `parent:id,reference,title`, `tags`, and `withCount` checklist items to avoid N+1; expose columns through `#[Computed]` arrays, not public Eloquent collections (Livewire would serialise the models into the snapshot).
- Filters (global board: client, assignee, tag, priority; project board: assignee, tag, priority) are `#[Url]` public scalar properties bound to Filament selects; the same `applyFilters` is used for rendering and for the mover.
- Cards: title is a plain `<a href>` to the task URL inside the card, never a `wire:navigate` card element (livewire/livewire issue 10662, `[CITED: 03-SPIKE-KANBAN.md]`); buttons inside cards sit in `wire:sort:ignore`; every column container carries `wire:sort="moveCard"`, `wire:sort:group="board"`, `wire:sort:group-id="{{ $status->value }}"` and `min-height` so an empty column is a drop target; the handler is synchronous (no `.async` modifier), satisfying "persists immediately".
- Styling: inline `style`/a scoped `<style>` block, as `resources/views/filament/pages/system-page.blade.php` does (no Node/Vite in the repo; spike conclusion).
- Slide-over preview: a Filament `Action::make('preview')->slideOver()->modalSubmitAction(false)->modalContent(...)` mounted from the card with `wire:click="mountAction('preview', { task: '...' })"` (`CanOpenModal::slideOver`, `modalContent`, `modalSubmitAction` exist in `vendor/filament/actions/src/Concerns/CanOpenModal.php:183,261,299` `[VERIFIED]`). Resolve the argument through the scoped model query and `Gate::authorize('view')`; never trust the argument.
- Quick creation modal (title, project): header `CreateAction` with a two-field form; `successRedirectUrl` to the new task's view page.

### Pattern 6: Resources and pages

- `TaskResource` (Admin): `#[AccessRule(Audience::AdminOnly, ...)]`, `EnforcesResourceAccessRule`; `protected static ?string $recordRouteKeyName = 'reference';` (property exists in `HasRoutes.php`, declared `protected static ?string $recordRouteKeyName = null;`) and `Task::getRouteKeyName()` returning `'reference'`; override `Task::resolveRouteBindingQuery` to upper-case the incoming value so `/admin/tasks/abc-12` works. Pages: index, create (quick modal + full form), view (`/{record}`), edit; relation managers: comments, subtasks, history. Forms and Actions follow `ProjectResource`/`CreateProject` (Action receives the form state; errors keyed by data key through `RethrowsDomainValidation`).
- Global search by key: enable `->globalSearch()->globalSearchResourceOptIn()` on the panel (replacing `->globalSearch(false)` at `AdminPanelProvider.php:80`). With opt-in, a resource is searchable only if **its own class** declares `$isGloballySearchable` (`HasGlobalSearch.php:36-49`: reflection on the declaring class) `[VERIFIED: HasGlobalSearch.php:32-49; Panel/Concerns/HasGlobalSearch.php:126-131]`. Only `TaskResource` declares `protected static bool $isGloballySearchable = true;` plus `getGloballySearchableAttributes(): ['reference', 'title']`; `ProjectResource`/`PartnerProjectResource` already set it to `false` and stay closed. `getGlobalSearchResultDetails` returns project key and status only (no money). Never put `id` in the searchable attributes (uuid ILIKE error, PITFALLS Pitfall 1). Test that a Partner's search returns nothing and its search box is absent or empty.
- `PartnerTaskResource`: `#[AccessRule(Audience::PartnerAllowed, ...)]`, slug `my-tasks`, `canAccess()` also requires `partnerClientId() !== null` (copy `PartnerProjectResource::canAccess`); table built from a `TaskColumns::partnerColumns()` builder (pinned name list like `ProjectColumns::PARTNER_COLUMN_NAMES`); create page (project, title, description only; status `planned` and priority `normal` set by the Action; assignee = the Admin; requester = the Partner); view page with infolist plus comments relation manager (create-only) and the Escalate action. No edit page, no bulk actions, no export, no global search, no tags column, no checklist.
- Comments UI: relation managers (`TaskCommentsRelationManager` Admin with the internal toggle; `PartnerTaskCommentsRelationManager` without it). A Partner's form has no `is_internal` field **and** `AddTaskComment` ignores the flag for a Partner.
- Subtasks: relation manager on the task view with a create action (calls `CreateTask` with the parent); a subtask cannot offer a subtask action (parent has `depth = 1`).
- Checklist: `task_checklist_items` (`id`, `task_id` FK RESTRICT, `text` varchar(500), `is_done` boolean default false, `position` integer, timestamps). Edit with a Filament `Repeater` bound to a `hasMany` relationship with `orderColumn('position')` on the Admin edit page; card shows done/total via `withCount`. Items need no uniqueness on position. Admin-only (A1).
- Billing section on the Admin form mirrors `ProjectResource` (billing type select now with four values, hourly rate override, fixed price, estimate hours) handled in `UpdateTask` through `ProjectInput::money/estimateSeconds/requireFixedPrice`; the row is created lazily (zero rows means everything inherits).

### Pattern 7: `task_billing` and read-time resolution

Table mirrors `project_billing` (`create_project_billing_table.php`, read in full): own uuid pk (`uuidv7()` default required by schema rule R6), `task_id` uuid NOT NULL FK `tasks` RESTRICT with `unique('task_id')`, `billing_type` varchar(16) NOT NULL default `inherit`, `hourly_rate_minor`/`hourly_rate_currency`, `fixed_price_minor`/`fixed_price_currency`, `estimate_seconds` integer, `timestampsTz()`; checks copied: pair checks (`(x_minor IS NULL) = (x_currency IS NULL)`), `>= 0`, currency regex `'^[A-Z]{3}$'`, currencies match, estimate `>= 0`, and `CHECK (billing_type IN ('inherit', 'hourly', 'fixed_price', 'non_billable'))` and `CHECK (billing_type <> 'fixed_price' OR fixed_price_minor IS NOT NULL)`. New enum `App\Domain\Tasks\Enums\TaskBillingType` (cases `Inherit = 'inherit'`, `Hourly = 'hourly'`, `FixedPrice = 'fixed_price'`, `NonBillable = 'non_billable'`, `HasLabel`, Czech labels under `enums.task_billing_type.*`). The existing `BillingType` enum stays two-valued for projects (its doc: "The values equal the `project_billing_billing_type_check` constraint").
Model `TaskBilling`: `DeniesPartners`, `LogsAllowlistedActivity` with `#[LoggedAttributes]` (`task_id`, `billing_type`, money pairs, `estimate_seconds`), `task_id` not fillable (created via `$task->billing()->create()`), `MoneyCast` virtual `hourly_rate` and `fixed_price` exactly like `ProjectBilling`. The currency equals the client currency (Action-enforced, same as projects).

`TaskBillingResolver::resolve(Task): EffectiveBilling` (no copy, D-14): walk `[task, parent, project billing, client]`; the first level with a non-default value wins per field: type (`inherit` or no row defers; the project always supplies `hourly` or `fixed_price`), hourly rate (task override -> parent -> `ProjectBilling.hourly_rate` -> `Client.hourly_rate`, a NOT NULL column per `create_clients_table.php`), fixed price and estimate (own, then parent, then project). Return the value plus its source level so Phase 6/10 can show provenance. Estimate inheritance is literal D-14; whether Phase 6 should compare against an inherited estimate is for Phase 6 (A9).

### Pattern 8: Notifications, bell, preferences

- **Bell for Partners:** change `AdminPanelProvider.php:73` to admit any signed-in panel user (for example `static fn (): bool => app(PartnerContext::class)->user() !== null`). Nothing else pins "Partner has no bell" (grep of `tests/`). Test that a Partner sees only own rows.
- **Preferences storage:** migration `ALTER TABLE users ADD COLUMN notification_preferences jsonb NOT NULL DEFAULT '{}'` plus `CHECK (jsonb_typeof(notification_preferences) = 'object')`. Value object `NotificationPreferences` (events `task_created`, `comment`, `escalation`, `assignment_change`; channels `mail`, `database`; a missing key means on). Not mass-assignable (`User` has `#[Fillable(['name', 'email', 'password'])]`, `User.php`); written only by an `UpdateNotificationPreferences` Action. `users` is `#[NotPartnerScoped]` and a Partner only edits own row.
- **Profile page:** `->profile()` currently uses Filament's `Filament\Auth\Pages\EditProfile`; replace with `->profile(App\Filament\Pages\Auth\EditProfile::class)`, a subclass declaring `#[AccessRule(Audience::PartnerAllowed, ...)]` (the registry test governs every `Page` subclass under `app/Filament`). Extension points in the base class: `form(Schema)` (components at `EditProfile.php:421-433`), `mutateFormDataBeforeFill`, `mutateFormDataBeforeSave`, `handleRecordUpdate` (lines 148-160, 236) `[VERIFIED: grep of EditProfile.php]`. Add a "Notifications" section with one row per event and two toggles; strip the preference keys in `mutateFormDataBeforeSave` and persist them in `handleRecordUpdate` through the Action (base `update($data)` would otherwise try to mass-assign them).
- **Recipients (D-07), computed in `TaskNotifier` before queueing, from scalars** `[ASSUMED]` interpretation (A5):

| Event | Actor | Recipients | Notes |
|-------|-------|------------|-------|
| `task_created` | Partner | the Admin(s) (assignee is always the Admin for Partner-created tasks) | |
| `comment` | Partner | Admin, plus the assignee when that is an active user other than the author (the Admin or a Partner of the client; adopted in plan 05-15) | author excluded |
| `comment` (non-internal) | Admin | active Partner requester and/or assignee of the task | author excluded |
| `comment` (internal) | Admin | **nobody** | unconditional |
| `escalation` | Partner | the assignee (the Admin or an active Partner of the client); the Admin only when the assignee cannot receive it (deactivated, a Partner of an archived client, or the escalating Partner) | locked D-07, not assumed; see Open Questions (RESOLVED) item 3 |
| `assignment_change` | Admin | Partner requester/assignee on status, priority or assignee change; the new assignee when assigned | one notification per save, coalesced |

Deactivated accounts (`users.deactivated_at` set) and Partners of archived clients are skipped. The rule "an internal event never reaches a Partner" is enforced twice: the recipient builder takes `bool $internal` and returns no Partner, and the notification constructor refuses to be built for an internal comment addressed to a Partner (throws `LogicException`).
- **Notification classes:** `Illuminate\Notifications\Notification implements ShouldQueue`, `use Queueable`, constructor of scalars only, `afterCommit()` (as `PartnerInvitation`), `via($notifiable)` returns `['mail', 'database']` filtered by the recipient's preferences evaluated at send time. `toMail` uses `MailMessage` with Czech lines; `toDatabase` returns `FilamentNotification::make()->title()->body()->actions([...url...])->getDatabaseMessage()` (the bell reads `data->>'format' = 'filament'`; the `data` column is jsonb since `make_notifications_data_jsonb`). The comment excerpt is `Str::limit(strip_tags(sanitised body), 300)`; the URL is computed at dispatch time as a string (a worker has no current panel), per recipient audience (`filament.admin.resources.tasks.view` for the Admin, `filament.admin.resources.my-tasks.view` for a Partner). Never put rates, prices, billing type, estimate, internal comments or the checklist into any body.
- **Where dispatched:** explicitly from the Actions, after the work, inside the transaction (the notifications use `afterCommit`). Not from model observers.

### Pattern 9: Escalation

Columns `escalated_at`, `escalated_by_id` on `tasks` (Partner-readable: it is the Partner's own action). `EscalateTask` (policy ability `escalate`): requires a non-empty comment, creates a non-internal `TaskComment` flagged `is_escalation = true`, sets the pair, refuses when already escalated, sends the `escalation` notification. `ClearEscalation`: the assignee (the Admin or a Partner who is the task's assignee) and the Admin, per locked D-06 (see Open Questions (RESOLVED) item 3); clearing writes only the escalation pair. Priority is never touched, and a Partner never changes priority or status (TA-07). Add `escalated_at` to `#[LoggedAttributes]`.

### Anti-Patterns to Avoid
- **Computing the new position from the drop index alone** on a filtered or capped list: positions would be written against the wrong neighbours. Recompute with the full column (Pattern 5).
- **Rebinding Filament's global `HtmlSanitizerConfig`** to tighten rich text: breaks editor features elsewhere; use a dedicated sanitiser.
- **Relying on `QUEUE_CONNECTION=sync` in tests** to prove notification safety: `phpunit.xml:44` sets `sync`, so the notification runs inside the request with the acting user and hides scope bugs that appear on a worker. Test the render path with no authenticated user.
- **`wire:navigate` on the card element** (livewire issue 10662).
- **Model observers for fan-out:** explicit Action calls only.
- **A shared Resource with `visible()` toggles for Partners:** separate classes (PITFALLS Pitfall 3, followed in Phase 4).
- **Hard `exists:` validation rules** for project/user pickers (existence oracle): use scoped lookups.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| HTML sanitising | regex/strip_tags | `symfony/html-sanitizer` with a strict `HtmlSanitizerConfig` | Edge cases (entities, `javascript:` obfuscation) |
| Position writes | own reorder SQL | `spatie/eloquent-sortable` `setNewOrder` (+ the advisory lock) | Decided in the spike |
| Drag and drop JS | own SortableJS wiring | Livewire `wire:sort` directives | Bundled, no build step |
| Counters | `MAX(number)+1`, DB sequences | `SequenceAllocator` / `DocumentNumbering::nextTaskNumber` | Gap-free under rollback, already tested |
| One-level hierarchy / same project | application checks only | composite FK on generated column | Measured, survives any writer |
| Rich text editor | custom editor | Filament `RichEditor` | Already in the stack |
| Notifications | custom mail table | Laravel Notifications + Filament database format | Bell already wired |
| Money | floats | `Money`/`MoneyCast`, `ProjectInput` helpers | Project precedent |
| Activity log | manual audit rows | `LogsAllowlistedActivity` | Allowlist tests |
| Search | custom query box | Filament global search with resource opt-in | Existing UI |

**Key insight:** every cross-cutting rule in this phase (isolation, immutability of keys, numbering) already has a tested home; the risk is not missing a library, it is bypassing the home with a second code path.

## Runtime State Inventory

Not a rename/refactor/migration phase. Greenfield additions only. One data-affecting item: existing projects have no tasks, so the key-freeze trigger changes no existing behaviour, and no counter rows exist for projects yet (`number_sequences` row is created lazily on first allocation). **Nothing found in the other four categories (live service config, OS-registered state, secrets/env, build artifacts)**, verified by reading `.ddev/config.yaml` daemons (queue-worker runs `php artisan horizon`; after adding notification classes run `ddev artisan horizon:terminate` so the worker reloads code).

## Common Pitfalls

### Pitfall 1: Drop index applied to a filtered or capped column
**What goes wrong:** with a client filter or the Done cap active, index 2 means "third visible card", not "third card of the column"; positions get written against the wrong neighbours.
**How to avoid:** the neighbour recompute in Pattern 5, tested with a filtered board (a card between two cards hidden by the filter must land after the correct visible neighbour).
**Warning signs:** cards jumping after reload only when a filter is on.

### Pitfall 1b: Status change outside the mover
**What goes wrong:** `UpdateTask` changes `status` without the board lock and without a fresh position; the deferred exclusion constraint fails at COMMIT (`conflicting key value violates exclusion constraint "tasks_position_exclusion"`) or two cards share a position.
**How to avoid:** all writers go through `TaskBoard`; the architecture review must grep for `->update(['status'` outside the service. Add a test that edits status through the form and checks that positions stay unique.

### Pitfall 2: Trashed tasks and restore
Soft-deleted tasks hold no position slot (constraint is partial) but keep their old `position`; restoring one must re-append it under the lock, otherwise commit fails. A parent with active subtasks must not be soft-deleted (refuse with a field error; FK RESTRICT only protects hard deletes). `[ASSUMED]` policy: archive only; there is no force delete UI.

### Pitfall 3: Rich editor defaults
Default toolbar offers file attachments (public disk) and Filament's sanitiser keeps inline `style`. Mitigations in Pattern 4. Also `Model::preventSilentlyDiscardingAttributes` is on outside production (`ModelConventionsServiceProvider`): any non-fillable attribute in mass assignment throws in dev/test, so `Task` `#[Fillable]` must list exactly the plain columns and ids are set with `forceFill`.

### Pitfall 4: Queued notifications and scopes
A queued notification is not a `KokpitJob`; it runs without `RunsAsSystem`. Pass scalars, compute recipients and URLs before queueing (as `InvitationMail` does), and never reload tasks or comments inside `toMail`/`toDatabase`.

### Pitfall 5: Key freeze race
Closed by the trigger plus `FOR SHARE` on the project row in `CreateTask`. Without the share lock, `CreateTask` could format `KEY-N` with a key that a concurrent edit is about to change. Keep the lock order (board, project, counter) in every writer to avoid deadlocks.

### Pitfall 6: Partner visibility drift
New columns on `tasks` are readable by every Partner of the client. Pin the column list (like `PartnerSafeColumnsTest` for projects) and keep billing, checklist and internal comments in separate Admin-only tables. The description is shared with the client when the project is client-visible: show a Czech hint on the Admin form (as `kokpit.projects.hints.client_visible` does).

### Pitfall 7: Existence oracles in pickers
Assignee/requester/project options and validation must use scoped queries. For a Partner: no user list at all (assignee fixed to the Admin, requester fixed to self). For the Admin: users are `Admin + active Partners with client_id = project.client_id` (query on `User` with role and `deactivated_at IS NULL`), enforced again in the Action (`TaskPeople::assertAllowed($project, $userId)`), because the form value can be forged.

### Pitfall 8: Livewire snapshot size and N+1 on the board
Keep Eloquent models out of public properties; one query per column with eager loading; test the query count for a 200-card board (spike: ~45 ms, 114 KB at 200 cards).

### Pitfall 9: Notification spam
A Partner can create many tasks/comments and each mails the Admin. Per-user channel switches are the control in scope; a rate limit is `[ASSUMED]` unnecessary now (note for the owner).

## Code Examples

### Board Blade (structure only)
```blade
{{-- Source: Livewire 4 docs for wire:sort + the spike board; inline styles per spike conclusion --}}
@foreach ($this->columns as $status => $column)
  <section wire:key="column-{{ $status }}">
    <h3>{{ $column['label'] }} <span>{{ $column['shown'] }}@if ($column['total'] > $column['shown']) / {{ $column['total'] }}@endif</span></h3>
    <div wire:sort="moveCard" wire:sort:group="board" wire:sort:group-id="{{ $status }}" style="min-height: 4rem;">
      @foreach ($column['cards'] as $card)
        <article wire:key="card-{{ $card['id'] }}" wire:sort:item="{{ $card['id'] }}">
          <a href="{{ $card['url'] }}">{{ $card['reference'] }}</a> {{ $card['title'] }}
          <div wire:sort:ignore><button type="button" wire:click="mountAction('preview', { task: '{{ $card['id'] }}' })">...</button></div>
        </article>
      @endforeach
    </div>
  </section>
@endforeach
```

### Livewire test of the mover (shape)
```php
// Source: pattern of tests/Feature/Projects/*, spike tests listed in 03-SPIKE-KANBAN.md "Tests to port"
Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 1, 'in_progress');
expect($card->fresh()->status->value)->toBe('in_progress');
// forged status
Livewire::test(TaskBoardPage::class)->call('moveCard', $card->id, 0, 'archived')->assertStatus(422);  // or assertForbidden/assertNotFound per abort used
// Partner on the Admin page: 403 at mount; method call on a Partner page: not callable
Livewire::actingAs($partner)->test(TaskBoardPage::class)->assertForbidden();
```

### Notification render with no user (emulates the worker)
```php
// Source: this research (sync queue in phpunit.xml hides context bugs)
auth()->logout();
$mail = (new TaskCommentNotification(/* scalars */))->toMail($partner)->render();
expect((string) $mail)->not->toContain($internalCanary);
```

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Filament global search on/off per panel | Panel `globalSearchResourceOptIn()`; only resources declaring the property on their own class are searchable | Filament 5 | Fail-safe opt-in for tasks only |
| SortableJS via `x-sort` plugin or package | Livewire 4 `wire:sort` directives, bundled | Livewire 4 | No JS build |
| Unique index for ordering | `EXCLUDE ... WHERE ... DEFERRABLE INITIALLY DEFERRED` (unique indexes cannot be deferrable, measured) | PostgreSQL feature, verified on 18.6 | Allows sequential reorder writes |

**Deprecated/outdated:** `projects.next_task_number` (never created; ignore it). `Flowforge` (rejected). Filament `RichEditor` without a server-side sanitiser (never trust the stored value).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | A Partner does not see task tags or the checklist (tags stay Admin-only; `Tag::constrainForPartner` unchanged; checklist table `DeniesPartners`) | Pattern 6, Corrections C4 | If the owner wants Partners to see them: add a task-tag branch to `Tag::constrainForPartner` and a Partner scope on checklist items (small change, schema unaffected) |
| A2 | `/tasks/KEY-N` in D-09 means `/admin/tasks/KEY-N`; a root-level `/tasks/{reference}` redirect is optional | Corrections C5 | Owner may expect the literal root path: add one authenticated redirect route outside the panel |
| A3 | Touch drag works with Livewire's bundled SortableJS (spike expectation, not tested) | Pattern 5 | Fallback is `wire:sort:handle` / `wire:sort:config`; human check at end of phase |
| A4 | The fix for livewire issue 10350 (drop into an empty group) is in 4.4.7 (spike marked it assumed) | Pattern 5 | Empty-column drop fails; human check |
| A5 | Notification recipient matrix (Pattern 8) is the intended reading of D-07; the escalation row is excluded, it follows locked D-07 literally | Pattern 8 | Wrong recipients; cheap to change in `TaskNotifier` |
| A6 | Partners cannot edit tasks after creation; comments are append-only (no edit/delete UI for anybody) | Pattern 3, 6 | Needs edit/delete features and policy grants |
| A7 | Estimate inherits literally per D-14 (parent, then project) | Pattern 7 | Phase 6 may prefer "own estimate only" |
| A8 | Strict sanitiser max input length 100000 characters is sufficient | Pattern 4 | Long pastes silently truncated/rejected; raise the limit |
| A9 | Archive-only deletion for tasks (soft delete, no force delete UI); parent with active subtasks cannot be archived | Pitfall 2 | Different delete semantics |
| A10 | `RichEditor::fileAttachments(false)` also makes the upload handler refuse forged uploads (only the toolbar effect was read in source) | Pattern 4 | A forged Livewire upload could store a file on the default disk; the sanitiser still drops `img`; add a test that attempts it |
| A11 | Done-column position value 0 for all Done rows is acceptable because ordering uses `completed_at` | Pattern 5 | If the owner wants manual order inside Done, positions must be maintained there too |
| A12 | Filament's `modalContent` slide-over can host the preview with an action argument resolved server-side (API read, behaviour not run) | Pattern 5 | Use a dedicated Livewire component instead |

## Open Questions (RESOLVED)

1. **Root-level task URL.** What we know: all resources live under `/admin`. What's unclear: whether `/tasks/KEY-N` must literally exist. Recommendation: ship `/admin/tasks/KEY-N`; add a 5-line redirect only if the owner asks (A2). **RESOLVED:** the recommendation is adopted as an explicit assumption pending owner confirmation (A2): plan 05-03 ships `/admin/tasks/KEY-N` with no root-level redirect, and plan 05-17 lists A2 for the owner.
2. **Partner visibility of tags and checklist.** Recommendation: hide both (A1); revisit in the UI-SPEC step. **RESOLVED:** the recommendation is adopted as an explicit assumption pending owner confirmation (A1): plans 05-03 (tags), 05-06 (checklist) and 05-12 (Partner pages) keep both hidden from Partners, and plan 05-17 lists A1 for the owner.
3. **Who may be assignee when the assignee is a Partner** and escalation then needs "resolve" rights. Recommendation: escalation notification falls back to the Admin (Pattern 8) and only Admin clears the flag. **RESOLVED (follows locked D-06 and D-07; the recommendation is withdrawn, no owner confirmation needed):** the assignee and the Admin may clear the flag, including a Partner who is the task's assignee, from the own task page (plan 05-13); clearing changes only the escalation pair, and priority and status stay Admin-only (TA-07). The escalation notifies the assignee, also an active Partner assignee, with the Admin as fallback only when the assignee cannot receive it: deactivated, a Partner of an archived client, or the escalating Partner (plan 05-16).
4. **Touch drag and scroll conflict** is a human verification item (end-of-phase `human_verify_mode`). **RESOLVED (scheduled human check):** plan 05-11 Task 3 carries the human checks for touch drag at 375 px, drop into an empty column, the scroll conflict and SPA navigation; 05-VALIDATION.md "Manual-Only Verifications" lists them and plan 05-17 collects them for `/gsd-verify-work`.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP | everything | yes | 8.5.11 (host) / 8.5 (DDEV) | — |
| DDEV (web, db, Mailpit, Horizon daemons) | `ddev exec vendor/bin/pest` | yes (`ddev describe`: web OK, db postgres 18) | v1.25.4 | — |
| PostgreSQL | all schema work | yes | 18 (psql client 18.6) | — |
| Composer | add eloquent-sortable | yes | 2 | — |
| Redis (queue/cache) | Horizon in DDEV | yes (DDEV service; tests use sync/array) | — | — |
| Node/npm | not needed | present (24) but unused | — | no build step in this phase |
| Browser automation | drag/touch checks | no | — | human verification items |

**Missing dependencies with no fallback:** none. **Missing with fallback:** browser automation -> human checks.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Pest 5.3.0 on PHPUnit 13 (`pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature')`, `Isolation`; `Concurrency` has no transaction wrapper) |
| Config file | `phpunit.xml`, `tests/Pest.php` (test DB `kokpit_test`, `QUEUE_CONNECTION=sync`, `MAIL_MAILER=array`, `KOKPIT_CANARY_HARNESS=true`) |
| Quick run command | `ddev exec vendor/bin/pest tests/Feature/Tasks tests/Isolation tests/Arch` |
| Full suite command | `ddev exec vendor/bin/pest` (concurrency tests spawn real processes; allow minutes), plus CI parity: `ddev exec vendor/bin/pint --test`, `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| TA-01 | Create task/subtask with all fields; one-level and same-project DB checks; assignee/requester NOT NULL and defaults; forged people rejected | feature + schema | `pest tests/Feature/Tasks/TaskActionsTest.php tests/Feature/Schema/TasksTableTest.php` | no, Wave 0 |
| TA-02 | `KEY-N` sequence, no gaps/duplicates in parallel (with mutation run), deleted number not reused, open by URL and global search, key frozen (trigger + field error) | concurrency + feature | `pest tests/Concurrency/TaskNumberConcurrencyTest.php tests/Feature/Tasks/TaskKeyTest.php` | no, Wave 0 |
| TA-03 | Checklist add/toggle/reorder, Admin-only | feature | `pest tests/Feature/Tasks/TaskChecklistTest.php` | no, Wave 0 |
| TA-04 | Comment internal flag, Partner forced non-internal (forged `is_internal`), Partner never reads internal, XSS canary | feature + isolation | `pest tests/Feature/Tasks/TaskCommentsTest.php tests/Feature/Tasks/RichTextSanitiserTest.php` | no, Wave 0 |
| TA-05 | Filters client, project, status, priority, assignee, tag, due range | feature (Livewire table) | `pest tests/Feature/Tasks/TaskListFiltersTest.php` | no, Wave 0 |
| TA-06 | `task_billing` constraints, Partner never loads it, resolver order subtask -> parent -> project -> client | schema + unit | `pest tests/Feature/Schema/TaskBillingTableTest.php tests/Feature/Tasks/TaskBillingResolverTest.php` | no, Wave 0 |
| TA-07 | Partner create in visible project only, cannot set status/priority/assignee, escalate requires comment, Admin notified, preferences narrow delivery, internal comment notifies no Partner | feature + isolation | `pest tests/Feature/Tasks/PartnerTaskResourceTest.php tests/Feature/Tasks/TaskNotificationsTest.php tests/Feature/Tasks/NotificationPreferencesTest.php` | no, Wave 0 |
| KB-01 | Columns by status, order by position, Done cap from config, per-project and global pages | feature | `pest tests/Feature/Tasks/TaskBoardTest.php` | no, Wave 0 |
| KB-02 | Move persists status+position after reload, one `updated` event, filtered-board neighbour math, forged status 422, parallel moves (with no-lock mutation run) | feature + concurrency | `pest tests/Feature/Tasks/TaskBoardTest.php tests/Concurrency/TaskBoardConcurrencyTest.php` | no, Wave 0 |
| KB-03 | Partner 403 on board pages, forged move cannot be called, read-only list, canary absence on every Partner surface | isolation | `pest tests/Isolation` | partly (extend `PartnerSafeColumnsTest`, `CanaryRegistryTest`, `RouteWalkTest`) |

### Sampling Rate
- **Per task commit:** `ddev exec vendor/bin/pest <the new test files of that task>` (each file < 30 s; concurrency files excluded).
- **Per wave merge:** `ddev exec vendor/bin/pest tests/Feature tests/Isolation tests/Arch tests/Unit` (everything except `tests/Concurrency`), then pint and phpstan.
- **Phase gate:** full `ddev exec vendor/bin/pest` green, pint and phpstan clean, `scripts/check-sensitive.sh` clean, before `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `tests/Feature/Schema/TasksTableTest.php`, `TaskBillingTableTest.php` (pattern: `ProjectBillingTableTest.php`, SQLSTATE assertions 23505/23514/23503; add the exclusion-violation SQLSTATE 23P01 test, commit-time deferral, trashed row and Done row exemptions, composite-FK rejections)
- [ ] `database/factories/TaskFactory.php` (fictional titles built at runtime; references from the factory must go through the allocator or set `number`/`reference` explicitly)
- [ ] `tests/Concurrency/task-worker.php` (or a task mode in `worker.php`) for creation and for moves; reuse `UnlockedSequenceAllocator` and a no-lock mover double for mutation runs
- [ ] Registry edits listed in "Registry lines to touch"
- [ ] `tests/Support/CanaryRegistry.php` four fixtures; RouteWalk map entries `tasks`, `my-tasks`
- [ ] Notification tests using `Notification::fake()` for dispatch and a no-user render for bodies
- [ ] Human verification items: touch drag at 375 px (including drop into an empty column and scroll conflict), Livewire SPA navigation away from and back to the board, Czech copy review, mail rendering in Mailpit

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no (unchanged; profile page extended) | Existing Filament auth + 2FA |
| V3 Session Management | no (unchanged) | Existing |
| V4 Access Control | yes | `PartnerScope`, `TaskPolicy`/`TaskCommentPolicy` extending `KokpitPolicy`, `#[AccessRule]` on every Filament class, Admin-only tables via `DeniesPartners` |
| V5 Input Validation | yes | Domain Actions + scoped lookups (no raw `exists:`), enum whitelist for status, strict `HtmlSanitizer` for rich text |
| V6 Cryptography | no | none introduced |
| V8 Data Protection | yes | Partner-safe columns pinned by test; notification bodies built from non-internal scalars |
| V13 API | no (API is Phase 7) | — |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Stored XSS via description/comment | Tampering / Elevation | `RichText::clean` on write and render; no `img`, `script`, `style`; canary test |
| Forged board move (other client's card, forged status) | Tampering | Admin-only page (403 at Livewire boot), scoped lookup 404, `Gate::authorize('update')`, status whitelist 422, DB CHECK |
| Partner forges `is_internal`, assignee, status, priority in a Livewire payload | Tampering | Fields absent from the Partner form AND ignored by the Action (mutation test: remove the server-side forcing, test must fail) |
| IDOR via project/user picker values | Information disclosure | Scoped lookups; assignee/requester allowed set recomputed in the Action |
| Internal comment leak (UI, search, notification, mail, activity log, export) | Information disclosure | `TaskComment` Partner scope, unconditional recipient guard, comments not activity-logged, no export in scope, canary registry fixture |
| Existence oracle (key taken, user exists) | Information disclosure | Scoped queries; DB unique violations translated only for Admin forms |
| Mail/formula injection from Partner text | Tampering | Mail bodies escaped by the mail renderer; no CSV export in scope (flag if added later) |
| Lost update / duplicate numbers under concurrency | Tampering / DoS | Counter row lock, unique indexes, advisory lock, deferred exclusion constraint |

## Project Constraints (from CLAUDE.md)

- Public AGPL-3.0 repository: **fictional data only** in code, tests, fixtures, docs and `.planning/` (use `example.com`, company ID `12345678`, paths like `/Users/example/`, canary strings assembled at runtime from fragments). Run `scripts/check-sensitive.sh` before committing; the lefthook hook runs it plus gitleaks and must never be bypassed.
- GSD workflow enforcement: file changes go through a GSD command (`/gsd-execute-phase` for planned phase work).
- Stack is fixed: PHP 8.5, Laravel, Filament SPA, PostgreSQL, Sanctum, queue; all dependencies AGPL-compatible, no paid or closed packages.
- UUID v7 keys everywhere; FK, unique, partial indexes and CHECK constraints enforced in the database.
- Partner must never see measured time, rates, prices or finance, nor another client's data - enforced by Policies and global query scopes, not UI hiding.
- Number sequences gap-free and duplicate-free under concurrent creation.
- Czech UI via `lang/cs`; code, tests, docs and `.planning/` in English.
- No project skills are configured (`.claude/skills/` etc. absent per CLAUDE.md).

## Evidence (measured this session)

**Block 1, composite FK on a generated column (PostgreSQL 18.6, temp table):** with `UNIQUE (id, project_id, depth)` and `FOREIGN KEY (parent_id, project_id, parent_depth) REFERENCES tk (id, project_id, depth) ON DELETE RESTRICT`: root insert OK; subtask of a root OK; sub-subtask -> `ERROR: insert or update on table "tk" violates foreign key constraint "tk_parent_fk"`; subtask whose parent is in another project -> same error; turning a root with children into a subtask -> `update or delete on table "tk" violates foreign key constraint "tk_parent_fk" on table "tk"`. `[VERIFIED: psql experiment]`

**Block 2, deferred unique:** `UNIQUE (status, position) DEFERRABLE INITIALLY DEFERRED` accepted three sequential per-row rewrites that reordered a column (commit OK, order 3,1,2) and rejected a duplicate at COMMIT (`duplicate key value violates unique constraint "t1_pos"`, transaction rolled back). The same constraint non-deferred fails on the first conflicting UPDATE. `CREATE UNIQUE INDEX ... WHERE ... DEFERRABLE` is a syntax error. `[VERIFIED: psql experiment]`

**Block 3, partial deferred exclusion:** `EXCLUDE USING btree (status WITH =, position WITH =) WHERE (deleted_at IS NULL AND status <> 'done') DEFERRABLE INITIALLY DEFERRED` allowed two `done` rows at position 0, a trashed row sharing an active row's position, the sequential reorder, and rejected an active duplicate at COMMIT with `conflicting key value violates exclusion constraint "t5_pos"`. `[VERIFIED: psql experiment]`

**Block 4, key freeze race:** scratch schema with `projects` (unique index on `key`), `tasks` (FK to projects) and a `BEFORE UPDATE OF key` trigger. Session A: `BEGIN; INSERT INTO tasks ...; SELECT pg_sleep(3); COMMIT;`. Session B, 1 s later: `UPDATE projects SET key='BBB'`. Both a trigger without extra locking and one that first runs `SELECT ... FOR UPDATE` ended with `ERROR: frozen` and `key=AAA tasks=1`: the key update waited for the in-flight insert and then saw the committed task. The scratch schema was dropped afterwards (confirmed 0 schemata). `[VERIFIED: two-session psql experiment; note this is a single run, the test suite must repeat it with real processes]`

**Block 5, sanitiser probe:** see Pattern 4 (PHP probe against the locked `symfony/html-sanitizer`). `[VERIFIED]`

## Sources

### Primary (HIGH confidence)
- Repository files read this session: `app/Domain/Shared/Auth/*`, `app/Domain/Projects/**`, `app/Domain/Shared/Sequences/SequenceAllocator.php`, `app/Domain/Settings/Numbering/DocumentNumbering.php` and `DocumentKind.php`, `app/Domain/Audit/*`, `app/Domain/Operations/**`, `app/Domain/Clients/Notifications/PartnerInvitation.php`, `app/Domain/Clients/InvitationMail.php`, `app/Providers/*`, `app/Filament/**`, `database/migrations/*`, `config/kokpit.php`, `phpunit.xml`, `tests/Arch|Isolation|Support|Concurrency|Feature/*`, `lang/cs/*`
- Vendor sources read: `filament/forms` `RichEditor.php`, `Concerns/HasFileAttachments.php`; `filament/support` `SupportServiceProvider.php`; `filament/filament` `Resources/Resource/Concerns/HasGlobalSearch.php` and `HasRoutes.php`, `Panel/Concerns/HasGlobalSearch.php`, `Auth/Pages/EditProfile.php`; `filament/actions` `CanOpenModal.php`; `spatie/eloquent-sortable` `SortableTrait.php` and config; `livewire/livewire` `dist/livewire.js`
- `.planning/phases/03-operations-foundation/03-SPIKE-KANBAN.md` (measurements cited, not re-run)
- PostgreSQL 18.6 experiments listed in Evidence

### Secondary (MEDIUM confidence)
- `https://livewire.laravel.com/docs/4.x/wire-sort` (handler signature and directives; page does not cover `wire:sort:config`, touch, scrolling)
- `https://filamentphp.com/docs/5.x/advanced/security` (summarised by the fetch tool: sanitiser customisation by `extend()`, `dropAttribute()` or rebinding `HtmlSanitizerConfig`; warning that removing `style`, `data-*` can break rich text rendering)

### Tertiary (LOW confidence)
- None used as a basis for a decision.

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - all packages installed, one direct requirement added, versions read from `composer.lock`
- Architecture: HIGH - reuse points read in code; DB mechanisms measured on the project's PostgreSQL major version
- Pitfalls: MEDIUM-HIGH - board edge cases (filtered drop, trashed rows) derived from measured constraints and read library source, not yet executed in the app
- Notifications and Partner visibility of tags/checklist: MEDIUM - design recommendations (A1, A5)

**Research date:** 2026-10-08
**Valid until:** 2026-11-07 (Filament/Livewire minor releases move fast; re-check `wire:sort` and `RichEditor` APIs if `composer.lock` changes)
