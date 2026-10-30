---
phase: 12-partner-audit
plan: 04
subsystem: testing
tags: [pest, audit, filament, livewire, authorization, permission-matrix, field-allowlist]

requires:
  - phase: 12-partner-audit
    provides: Audit suite from plan 12-01; PartnerMatrix, PanelInventory (relation manager owners, view pages) from plan 12-03; CanaryRegistry fixtures
provides:
  - PartnerMatrix::partnerAbilities(), abilityRows() and adminOnlyModels(), the literal action matrix of 8 Partner-visible entities and 30 Admin-only entities
  - PartnerMatrix::partnerFields(), FORBIDDEN_FIELD_PATTERN, fieldExceptions() and fieldSurfaceExemptions(), the literal field list of 25 Partner surfaces
  - tests/Audit/PartnerActionsTest.php, 171 tests: ability rows per record state, Admin-only refusals, entity completeness, per-surface field equality, forbidden-name check with planted offenders
affects: [12-06, 12-08]

actuals:
  tokens: 13100
  tasks: 2
  commits: 2

plan_head_before: f50e8209f5eb20aa2592a62a172bc06a8ea4384a
plan_head_after: 59775cbd00c8e631f9236b10cd13ff65904055a3
commits: 2

tech-stack:
  added: []
  patterns:
    - "Record states for an ability matrix are built once per test through the domain Actions and the canary fixtures, and read again through the scoped query as Partner A so the policies meet the relations a request would load"
    - "A pure difference helper (auditActionsDifferences, auditActionsForbiddenNames, auditActionsSurfaceGaps) that the self-tests feed planted classes and planted names"
    - "Surface key {ShortClass}.{table|infolist|form|filters|rows}; the runtime names of a surface are read by one function with one branch per kind of screen"

key-files:
  created:
    - tests/Audit/PartnerActionsTest.php
  modified:
    - tests/Support/PartnerMatrix.php

key-decisions:
  - "A Partner-visible task of another person is assigned to the Admin, not left unassigned, because tasks.assignee_id is NOT NULL; the row still proves editDescription and clearEscalation are refused on a visible task in another status that is not the Partner's"
  - "The ability set is exactly the plan's: viewAny and create on the class, view, update, delete and the custom abilities on records; restore, forceDelete and replicate are not asserted here"
  - "Field exceptions are keyed {surface}#{name}, so one surface can excuse several names and a stale exception is itself a declaration problem"
  - "A relation manager with an inline comment form (the comments tab) is read through its commentForm; the others through form(), and the thread view that replaces the comments table is declared as an empty table"
  - "The test-only canary resource is exempt from the field surfaces with a written reason; the Dashboard is exempt because it holds only the widgets, which carry their own surfaces"

patterns-established:
  - "Adding a column, entry, form field or filter to a Partner screen fails the Audit suite until the name is added to PartnerMatrix::partnerFields() in the same pull request"
  - "A name matching FORBIDDEN_FIELD_PATTERN needs a written reason in PartnerMatrix::fieldExceptions(); measured-time words can never be declared quietly"

requirements-completed: [US-01, AUD-01]

coverage:
  - id: D1
    description: "For every Partner-visible entity (Project, Task, TaskComment, Document, Invoice, InvoiceItem, Tag, CanaryRecord) every declared action per record state is answered by the Gate for Partner A exactly as declared, and the Admin is allowed"
    requirement: US-01
    verification:
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#answers {1} on {0} in state {2} for a Partner as declared and for the Admin as allowed (107 dataset rows)"
        status: pass
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#builds every declared record state"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every other CanaryRegistry entity is declared Admin-only and Partner A is refused viewAny, create, view, update and delete on its rows of both clients; the declared entities equal the registry fixtures in both directions"
    requirement: US-01
    verification:
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#refuses a Partner every action on the Admin-only {0} (30 dataset rows)"
        status: pass
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#declares every isolated entity exactly once"
        status: pass
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#reports an entity without a declaration, a declaration without an entity and an entity declared twice"
        status: pass
    human_judgment: false
  - id: D3
    description: "The runtime names of every Partner table, detail, form, filter, widget and the profile page equal the declared list, and every Partner screen has at least one declared surface"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#shows a Partner exactly the declared fields on {0} (25 dataset rows)"
        status: pass
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#declares the fields of every Partner surface"
        status: pass
    human_judgment: false
  - id: D4
    description: "No declared name matches the finance or measured-time pattern without a written exception, planted offenders are reported, and timestamp names stay outside the pattern"
    requirement: AUD-01
    verification:
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#never declares a rate, price, estimate, billing, internal, measured-time or finance field for a Partner without a written exception"
        status: pass
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#reports a planted rate, tracked time, measured seconds and time entry total as forbidden unless excused"
        status: pass
      - kind: unit
        ref: "tests/Audit/PartnerActionsTest.php#keeps timestamp names outside the forbidden pattern"
        status: pass
    human_judgment: false
  - id: D5
    description: "The draft actions and field rules reflect what the owner wants; the amounts printed on the own issued invoices and the findings below are presented for sign-off"
    requirement: US-01
    verification: []
    human_judgment: true
    rationale: "Whether the declared Partner actions, the printed-document exceptions (research A5) and the paid-amount entry match the owner's intent is a judgment; plan 12-08 presents the findings table (D-02)"

duration: about 45 min
completed: 2026-10-24
status: complete
---

# Phase 12 Plan 04: Partner Actions and Fields Matrix Summary

**A literal action matrix (8 Partner-visible and 30 Admin-only entities, every action per record state) and a literal field list of 25 Partner surfaces, both checked against the running application as Partner A, with a finance and measured-time name pattern that needs a written reason for every exception.**

## Performance

- **Duration:** about 45 min
- **Completed:** 2026-10-24T00:14Z
- **Tasks:** 2 (1 tracer, 1 auto)
- **Files modified:** 2 (1 created, 1 modified)

## Accomplishments

- Task 1 (tracer): `partnerAbilities()` declares, per entity, the class abilities (viewAny, create) and each record ability per record state (own_visible, own_visible_other, own_upload, own_hidden, foreign), including editDescription, clearEscalation, comment, escalate and attach. `adminOnlyModels()` holds the other 30 registry entities with a reason. The test builds the states through `CreateProject`, `CreateTask`, `AddTaskComment` and, for the Partner's own file, `AttachDocuments` as Partner A, and compares the Gate answer for Partner A with the declared value and for the Admin with true: 107 ability rows and 30 refusal rows (viewAny and create on the class; view, update and delete on every row of both clients, read as system). Every one passed on the first complete run, so no leak and no stricter-than-draft behaviour was found.
- Task 2: `partnerFields()` lists 25 surfaces (3 Partner Resources x table, infolist, form, filters; the 3 Partner relation managers x table, filters, form; the Partner projects widget x table, filters; the Partner tasks widget rows; the profile form). `FORBIDDEN_FIELD_PATTERN` is the one expression of R-12-04-3. `fieldExceptions()` excuses four names on the invoice detail, each with a reason. Tests: runtime equals declared on every surface, the forbidden-name check on the real declaration and on five planted offenders (`hourly_rate`, `tracked_time`, `measured_seconds`, `timeEntries_sum_minutes`, `spent_minutes`), timestamp names outside the pattern (and `time_entries`, `total_time`, `estimate_hours` inside it), and surface completeness over `PartnerMatrix::panelClasses()` with a planted-gap self-test.
- Mutation checks by hand, each restored (nothing left in the tree): `editDescription` flipped to allowed on a task in another status and `DocumentPolicy::update` returning true each fail the exact rows with the entity, ability and state named; a `billing.hourly_rate` column added to the Partner project table fails `PartnerProjectResource.table` with the name reported as not declared.
- Audit suite 487 tests green (316 before this plan plus 171); `tests/Isolation` and `tests/Feature/Projects` 455 tests green; Pint and PHPStan clean.

## Task Commits

1. **Task 1: Tracer, Partner actions per record state and Admin-only refusals** - `0fa06f7` (test)
2. **Task 2: Partner field lists and the forbidden-name pattern** - `59775cb` (test)

**Plan metadata:** committed separately as docs(12-04).

## Findings for the owner

Continued from plan 12-03. Every row is declared as the code behaves (R-12-03-3); no row shows the code granting a Partner more than the draft, so no leak was found and no production file was touched.

| Class or surface | Draft value | Observed value | Source | Decision |
|---|---|---|---|---|
| Task `own_visible_other` | "no assignee" | The Admin is assigned: `tasks.assignee_id` is NOT NULL, a task without an assignee cannot exist | Failed `forceFill(['assignee_id' => null])` with a not-null violation | Row built with the Admin as requester and assignee; editDescription and clearEscalation are refused on it as drafted |
| `PartnerInvoiceResource.infolist#summary_paid` | Rules list "totals, remaining amount, status" and forbid "payments" | The detail shows a "paid" amount, the sum of the document's payments (the payment rows themselves stay Admin-only) | `InvoiceColumns::partnerEntries()` summary section | Declared as shown, not removed. Total minus remaining gives the same number, so it discloses nothing the Partner cannot compute. Owner to confirm that a paid amount is intended |
| `PartnerInvoiceResource.infolist#unit_price` | Allowed by IN-09 (research A5) | Present, matches the word price | Items table of the detail | Declared with a reason in `fieldExceptions()`. Owner to confirm this reading of "prices" |
| `PartnerInvoiceResource.infolist#payment_method`, `#payment_variable_symbol`, `#payment_due_date` | Printed bank box and pay link allowed | Present, match the word payment, but they are payment instructions printed on the document | Payment section of the detail | Declared with a reason each in `fieldExceptions()` |
| `PartnerTasks.rows` | name of project and assignee, reference, title, status, due date | The query also selects `id`, `project_id`, `assignee_id`, `project.id` and `assignee.id` (record keys, among them the Admin's account id) | `PartnerTasks::tasks()` select list | Declared as selected. The keys are identifiers, not finance or time fields. Owner to decide whether the account id of the assignee should stay in the widget snapshot |
| `PartnerTaskCommentsRelationManager.table` | table with author, text and date | No table: a thread view replaces it, so the table has no column; the inline form has `body` and `files` | `RendersTaskCommentThread` | Declared as an empty table and a two-field form. The author, text and date are rendered by a Blade view that a schema cannot list; the page-level canary walk of plan 12-06 covers what that view shows |
| `CanaryRecordResource` | Partner screen | Test-only resource of the harness, no product screen | `tests/Support/Filament/CanaryRecordResource.php` | Exempt from field surfaces by a written reason in `fieldSurfaceExemptions()` |
| Ability set | list, view, create, update, delete, custom abilities | restore, forceDelete, replicate, reorder and the bulk abilities are not asserted | Plan scope | The base policy denies them; add rows if the owner wants them in the matrix |

Every other draft row (the actions of Project, Task, TaskComment, Document, Invoice, InvoiceItem, Tag, CanaryRecord and the refusal of the 30 Admin-only entities; the field rules of the project, task, file, comment and profile surfaces) matched the code without a difference.

## Filament calls used per surface (verified in vendor/filament)

- Resources, on the list page instance from `Livewire::test($resource::getPages()['index']->getPage())->instance()`: table columns `$page->getTable()->getColumns()`, filters `$page->getTable()->getFilters(withHidden: true)` (`Table\Concerns\HasFilters`), detail `$resource::infolist(Schema::make($page)->record($record))` read with `getFlatComponents(withActions: false, withHidden: true)` filtered to `Filament\Infolists\Components\Entry` and `getName()` (the record is passed so the repeatable item entries appear), form `$resource::form(Schema::make($page))->getFlatFields(withHidden: true)` mapped by `getName()` (`Schemas\Concerns\HasComponents`).
- Relation managers: `Livewire::test($manager, ['ownerRecord' => $own, 'pageClass' => $viewPage])->instance()` with the owner read again through the scoped query as Partner A and the page from `PanelInventory::pageClassOf(PanelInventory::owningResource())`; table and filters as above; form through `$manager->commentForm` where the manager has `commentForm()` (`RendersTaskCommentThread`), else `$manager->form(Schema::make($manager))`.
- Table widget (`Filament\Widgets\TableWidget`): `Livewire::test($widget)->instance()->getTable()` then `getColumns()` and `getFilters(withHidden: true)`.
- Partner tasks widget (a plain Widget): `Livewire::test($widget)->instance()->tasks()->first()`, the model attribute keys plus `relation.attribute` for each loaded relation.
- Profile page: `Livewire::test(EditProfile::class)->instance()->getSchema('form')` (`InteractsWithSchemas::getSchema`, nullable) then `getFlatFields(withHidden: true)`.

## Files Created/Modified

- `tests/Support/PartnerMatrix.php` - added `STATES`, `CLASS_ABILITIES`, `RECORD_ABILITIES`, `partnerAbilities()`, `abilityRows()`, `adminOnlyModels()`, `abilityProblems()`, `FORBIDDEN_FIELD_PATTERN`, `SURFACE_KINDS`, `partnerFields()`, `fieldExceptions()`, `fieldSurfaceExemptions()`, `fieldProblems()`; `problems()` now includes both problem lists. No policy, access-rule reader or production constant is referenced (the literal-matrix guard of plan 12-03 still passes).
- `tests/Audit/PartnerActionsTest.php` - 171 tests and the helpers `auditActionsDifferences`, `auditActionsAllows`, `auditActionsScene`, `auditActionsForbiddenNames`, `auditActionsSurfaceGaps`, `auditActionsRuntimeFields`.

No production file was touched (no D-09 leak fix was needed), and `tests/Isolation` is byte-identical to the baseline `58c5e93` (D-08).

## Decisions Made

See `key-decisions` in the frontmatter. In short: the "other person" task is assigned to the Admin because the column is not nullable; exceptions are keyed `{surface}#{name}`; the comment thread is declared as an empty table plus its inline form; the test-only canary resource and the Dashboard are exempt from field surfaces with reasons.

## Deviations from Plan

None - plan executed exactly as written. Inside the plan's latitude: the task `own_visible_other` is assigned to the Admin instead of unassigned (database constraint, listed in the findings table), every Resource and relation manager declares all its surface kinds (including empty lists) so a column, form field or filter added later is caught, and the Partner tasks widget, which is not a Filament table, is read through the columns its query selects (`rows`).

**Total deviations:** 0 auto-fixed
**Impact on plan:** none.

## Issues Encountered

- The first scene build forced `assignee_id` to null for the "no assignee" task and hit the NOT NULL constraint (138 failures from one cause). The task now keeps the Admin as assignee; a defect of the new test only, not of the application.
- A shell slip while preparing a mutation check chained a `sed` that failed, so the first mutation run ran against unmodified code and passed; it was repeated with a Python edit and the mutations were then seen failing. Both mutated files were restored (`git status` showed no production change).

## User Setup Required

None - no external service configuration required.

## Known Stubs

None.

## Threat Flags

None. The plan adds test code only; no network endpoint, auth path, file access or schema was added.

## Next Phase Readiness

- Plan 12-06 (ui and downloads) can reuse `auditActionsScene()`-style state building; the comment thread Blade view and the rendered pages are its to walk.
- Plan 12-08 presents the "Findings for the owner" table above together with the one of plan 12-03: the printed-document amount exceptions (research A5), `summary_paid`, the assignee id in the tasks widget and the data-dependent `UnresolvedPayments` widget.

## Self-Check: PASSED

- Created and modified files exist: tests/Audit/PartnerActionsTest.php, tests/Support/PartnerMatrix.php
- Commits `0fa06f7` and `59775cb` are ancestors of HEAD; `git rev-list --count f50e820..HEAD` printed 2 before this SUMMARY commit
- Acceptance criteria of both tasks re-run and passing (greps for the declaration functions and surface keys, the `exactly once`, `Admin-only`, `declared fields`, `finance field` and `timestamp names` filters, `tests/Isolation` unchanged against `58c5e93`, no production constant in PartnerMatrix)
- Plan-level verification re-run: Audit suite 487 passed, `tests/Isolation` and `tests/Feature/Projects` 455 passed, `vendor/bin/pint --test` clean, PHPStan no errors

---
*Phase: 12-partner-audit*
*Completed: 2026-10-24*
