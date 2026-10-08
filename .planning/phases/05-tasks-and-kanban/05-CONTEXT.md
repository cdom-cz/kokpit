# Phase 5: Tasks and Kanban - Context

**Gathered:** 2026-10-08
**Status:** Ready for planning

<domain>
## Phase Boundary

Admin organises work as tasks and one-level subtasks with per-project `KEY-N` numbers, a todo checklist, comments, a filtered list and drag-and-drop boards (per project and global). A Partner creates tasks and comments in visible projects, can escalate a task, and sees a read-only list. Delivers TA-01 to TA-07 and KB-01 to KB-03.

Not in this phase: time entries and timer on tasks (Phase 6), file uploads on tasks (Phase 9, only a placeholder in the UI), billing from tasks (Phase 10), API endpoints for tasks (Phase 7).

</domain>

<decisions>
## Implementation Decisions

Already fixed in earlier phases (not re-opened): Partner isolation via `users.client_id`, fail-closed `#[AccessRule]` / `#[NotPartnerScoped]` / `#[DeniesPartners]` (Phase 2); typed settings, activity log allowlist on the model, queued mail pattern (Phase 3); statuses and priorities switch freely with no workflow, optional dates, billing data in a separate Admin-only 1:1 table (Phase 4 D-05, D-16); custom `wire:sort` kanban board with `spatie/eloquent-sortable` and an advisory lock around moves, no Flowforge (Phase 3 spike `03-SPIKE-KANBAN.md`); `projects.next_task_number` counter and sequence allocator; project key frozen after the first task.

### Statuses and kanban board
- **D-01:** Task status is the same enum as the project status (Planned, To clarify, In progress, In review, Ready to release, Done; Czech labels from Phase 4 D-16). One enum, one set of labels, free switching, no workflow.
- **D-02:** The board has one column per status. The Done column shows only the most recent N cards (default 20, ordered by completion time, value in `config/kokpit.php`); older done tasks are reachable through the list. Position is stored per status column.
- **D-03:** Subtasks are cards of their own: a subtask has its own status, sits in its own column and can be dragged. The card shows `KEY-N`, title, priority, due date, tags and a link or label of its parent. Parent and subtask statuses are independent (no automatic roll-up).

### People: assignee and requester
- **D-04:** Every task and subtask has both an assignee (řešitel) and a requester (zadavatel), both NOT NULL in the DB. Each may be the Admin or a Partner account. A task created by Admin defaults to requester = Admin, assignee = Admin (overridable). A task created by a Partner defaults to requester = the Partner and assignee = Admin; the Partner cannot choose the assignee.
- **D-05:** The pickers offer only the Admin and active Partner accounts of the client that owns the project. A Partner never sees accounts of another client; a forged value is rejected by validation and Policy.

### Partner escalation, notifications and comments
- **D-06:** A Partner escalates a task with an "Escalate" action that requires a comment. It creates a non-internal comment and sets an escalation flag on the task (who, when). Priority is never changed by the Partner or automatically; the assignee resolves the flag and raises priority manually. The flag can be cleared.
- **D-07:** Notifications are queued e-mail plus a database notification (Filament bell) for both sides. Admin or assignee is notified of a task, comment or escalation by a Partner. Partner (requester or assignee) is notified of non-internal comments and relevant changes by Admin. The escalation flag notifies the assignee, falling back to the Admin. Internal comments never notify a Partner and never appear in any notification body, e-mail, activity log or export visible to a Partner.
- **D-08:** Comments exist on tasks and subtasks, with an internal flag. A Partner comment is never internal (forced server-side, not just hidden in the form). A Partner never sees internal comments.
- **D-15:** Notification delivery is configurable per user in their profile: for each event type (task created by a Partner, comment, escalation, assignment or change) the user switches the e-mail and the bell channel on or off. Defaults are all on. This applies to Admin and Partner accounts alike. The preferences only narrow delivery: the rule that internal comments never reach a Partner is unconditional and not a preference. Exact event list and profile page layout are Claude's discretion.

### Task detail and text format
- **D-09:** A task opens as a full page at a stable URL by key (`/tasks/KEY-N`, found by key in global search). From the board a card opens a slide-over preview. Quick creation is one modal asking for title and project, then continues on the detail page.
- **D-10:** Description and comments use the Filament rich text editor, stored as sanitised HTML. Partner input passes the same sanitisation (XSS) and needs a canary test.
- **D-11:** A subtask has the same fields as a task (own status, assignee, requester, dates, priority, tags, checklist, comments, billing) but cannot have subtasks itself (DB check). It takes its number from the same project counter. File attachments are deferred to Phase 9; the UI only reserves the place.

### Task billing fields (TA-06)
- **D-12:** Task billing type values: inherit (default), hourly, fixed price, non-billable. Non-billable pre-sets the billable flag to false on time entries (Phase 6). Projects keep only hourly and fixed (04 D-15); non-billable exists on tasks only.
- **D-13:** Billing type, fixed price, rate override and estimate live in a separate 1:1 table `task_billing`, Admin-only (`#[DeniesPartners]`), same pattern as `project_billing`. A Partner query on tasks never loads these columns (selects, search, exports, error output, activity log). — **Reversibility:** costly — every task form, list and the later billing read goes through this relation.
- **D-14:** Empty values mean inherit at read time (no copy): subtask empty falls back to the parent task, then the project, then the client (rate resolution order from the brief). A subtask has its own `task_billing` row when it overrides anything.

### Claude's Discretion
Class and file names, exact number of cards shown in the Done column if config differs, escalation flag column names, notification and mailable wording, Czech label wording (including "Escalate"), sanitiser configuration, card layout details, list column set and default sort, checklist storage shape, tag handling (same Spatie tags as projects).

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Planning
- `.planning/ROADMAP.md` — Phase 5 goal and five success criteria
- `.planning/REQUIREMENTS.md` — TA-01 to TA-07, KB-01 to KB-03, PR-02 (key freeze)
- `.planning/PROJECT.md` — constraints (Partner isolation, gap-free numbering, UUID v7, DB-enforced integrity)
- `.planning/phases/02-platform-foundation/02-CONTEXT.md` — Partner scope, access rules, sequence allocator
- `.planning/phases/03-operations-foundation/03-CONTEXT.md` — activity log allowlist, queued mail, job base class
- `.planning/phases/04-clients-and-projects/04-CONTEXT.md` — project billing table pattern (D-05), status and priority enums (D-16), Partner project view (D-07), deferred escalation note

### Research and spikes
- `.planning/phases/03-operations-foundation/03-SPIKE-KANBAN.md` — decided build of custom `wire:sort` board, locking and Partner-forgery findings
- `.planning/research/FEATURES.md` — tasks, comments, notifications and Partner permission matrix
- `.planning/research/PITFALLS.md` — Partner leakage surfaces (notification bodies, activity log, exports)
- `.planning/research/STACK.md` — package versions, tags

### Repository rules
- `.claude/CLAUDE.md` — fictional data only
- `scripts/check-sensitive.sh` — must pass on every commit

No external ADRs.

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `app/Domain/Shared/Auth/` (`PartnerScope`, `IsolatesPartners`, `DeniesPartners`, `AccessRule`, `KokpitPolicy`): isolation primitives for tasks, comments, task_billing.
- `app/Domain/Projects/` and the `project_billing` model: pattern for `task_billing`.
- `app/Domain/Shared/Models/Tag.php`: tags for tasks.
- `app/Domain/Audit/LogsAllowlistedActivity.php` and `ActivityHistoryRelationManager`: history on tasks.
- `app/Domain/Operations/Alerts`, `Jobs/KokpitJob`: queued mail pattern for notifications.
- `app/Filament/Resources/ProjectResource` and `ClientResource`: Resource and `Enforces*AccessRule` patterns.
- Sequence allocator from Phase 2 and `projects.next_task_number`: per-project counter.

### Established Patterns
- Every Filament class declares `#[AccessRule]`; every model declares Partner scope or `#[NotPartnerScoped]`; architecture tests enforce both.
- UUID v7, `timestamptz`, DB constraints for invariants (CHECK, partial and unique indexes).
- Canary harness (two fictional clients) gets one line per new Partner-visible model.
- Czech via `lang/cs`; code, tests and docs in English.

### Integration Points
- Phase 6 attaches time entries and timer to tasks and uses the `task_billing` billing type.
- Phase 7 exposes task lookup in the API; Phase 9 attaches files; Phase 10 reads `task_billing`.

</code_context>

<specifics>
## Specific Ideas

- The user wants assignee and requester on every task and subtask, and either may be a Partner account.
- Partners receive both e-mail and the bell notification, not only e-mail, and every user can switch channels per event type in their profile (D-15).

</specifics>

<deferred>
## Deferred Ideas

- File attachments on tasks and comments: Phase 9.
- Time entries, timer start from a task: Phase 6.

</deferred>

---

*Phase: 5-Tasks and Kanban*
*Context gathered: 2026-10-08*
