---
phase: 09-documents-and-finance
plan: 06
subsystem: finance
tags: [finance, categories, filament, domain-actions, admin-only, czech-copy, partner-isolation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-04 transaction_categories table, TransactionCategory model (sortable siblings, selectable scope, system row guard), TransactionType enum"
provides:
  - "SaveTransactionCategory, ArchiveTransactionCategory, DeleteTransactionCategory, MoveTransactionCategory: the only writers of categories, each authorizing the Admin"
  - "TransactionCategoryResource: Admin-only 'Kategorie' screen (index page only, create and edit in modals, tree order, row actions move/archive/restore/delete, type and archived filters)"
  - "lang/cs/finance.php: categories copy and the transactions.errors.* contract that plan 09-22 reads"
  - "App\\Domain\\Finance\\Support\\SqlState: SQLSTATE reader shared by the finance Actions"
affects: [09-09 transactions, 09-22 transaction input, 09-21 schema proofs]

actuals:
  tokens: 19000
  tasks: 3
  commits: 3
plan_head_before: d3682bb09335744cd406092a6aca9d86eaded976
plan_head_after: 273298e6b139827cfa7f39533262df55faf08777
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Field-less DomainException (Czech message) for refusals of archive and delete, shown as a danger notification; field errors (ValidationException keyed by data key) for save"
    - "Database refusals translated outside the transaction: unique index name -> duplicate_name, 23503 -> in_use / type_locked, KP001 -> system_locked"
    - "Tree list order without a join: type, root position via correlated subselect, root id, depth, position, Czech name collation"

key-files:
  created:
    - app/Domain/Finance/Actions/SaveTransactionCategory.php
    - app/Domain/Finance/Actions/ArchiveTransactionCategory.php
    - app/Domain/Finance/Actions/DeleteTransactionCategory.php
    - app/Domain/Finance/Actions/MoveTransactionCategory.php
    - app/Domain/Finance/Support/SqlState.php
    - app/Filament/Resources/TransactionCategoryResource.php
    - app/Filament/Resources/TransactionCategoryResource/Pages/ListTransactionCategories.php
    - lang/cs/finance.php
    - tests/Feature/Finance/CategoryRulesTest.php
    - tests/Feature/Finance/TransactionCategoryResourceTest.php
  modified: []

key-decisions:
  - "Creating a child takes the type of its parent whatever the data carries; moving an existing category never retypes it by itself (a parent of another type is parent_invalid), reconciling the Task 1 and Task 3 wording of the plan"
  - "The screen sends the type of the chosen parent (the type select is disabled but dehydrated while a parent is chosen), so retyping an unused root by moving it under a root of the other type works from the modal and a used one is refused by the database (23503 -> type_locked)"
  - "Archive and delete of the system category are hidden on the screen and refused by the Actions; move up and down stay available because position is a mutable column of the guard"
  - "Sibling group is locked in id order inside the move transaction, so two concurrent moves cannot interleave"
  - "Pre-check for 'used by transactions' is the database backstop only until 09-09 adds the transactions table; proven with probe tables carrying the same kind of foreign keys"

patterns-established:
  - "Admin-only list resource with modal create/edit: header CreateAction and table EditAction both call the domain Action and map ValidationException keys to mountedActions.0.data.<key>"

requirements-completed: [FI-01]

coverage:
  - id: D1
    description: "The Admin creates a root with a type and a child under it that takes the parent's type, and the list shows each child directly under its parent"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCategoryResourceTest.php#the tracer: open the screen, build the tree"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#saving a category"
        status: pass
    human_judgment: false
  - id: D2
    description: "Archive hides a category from selectable() and restore brings it back; delete removes an unused category and refuses a parent with children or a row the database still references with a Czech message; move swaps only inside the sibling group"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#archiving and restoring"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#deleting"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#moving inside the siblings"
        status: pass
    human_judgment: false
  - id: D3
    description: "The system income category can be renamed and moved but the screen offers neither archive nor delete, and the Actions refuse both plus any change of its type or parent"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCategoryResourceTest.php#the system category on the screen"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#edits that would break the tree"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner gets 403 on the screen and an AuthorizationException from every Action"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/TransactionCategoryResourceTest.php#Partner access"
        status: pass
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#refuses a Partner"
        status: pass
    human_judgment: false
  - id: D5
    description: "Wrong edits of the tree (child under child, archived or unknown parent, parent for a category with children, type change of a parent, duplicate names, blank name) end in a Czech field error"
    requirement: FI-01
    verification:
      - kind: integration
        ref: "tests/Feature/Finance/CategoryRulesTest.php#edits that would break the tree"
        status: pass
    human_judgment: false
  - id: D6
    description: "The screen reads well in Czech and the tree indentation, badges and row action layout look right in the browser"
    requirement: FI-01
    human_judgment: true
    rationale: "Visual adequacy of indentation, badge colours and the icon-button row actions is not asserted by any test; the panel was not opened in a browser during execution"

# Metrics
duration: 25min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 06: Category Management Summary

**Admin-only 'Kategorie' screen over four authorizing domain Actions: two-level income/expense tree with modal create/edit, move, archive, restore and delete, database refusals translated into Czech, plus the finance copy file with the transaction error contract for 09-22**

## Performance

- **Duration:** about 25 min
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 10 (all created)

## Accomplishments

- `SaveTransactionCategory` creates and edits categories: a new child takes its parent's type, a parent must be an active root (not itself, not a child, not archived, not unknown), a category with children cannot become a child or change type, the system category accepts only a new name, duplicate names (any case, per type for roots, per parent for children) are translated from the partial unique indexes, blank and oversize names are field errors. A move into a new sibling group goes to the end of it.
- `ArchiveTransactionCategory`, `DeleteTransactionCategory` and `MoveTransactionCategory` authorize through `AdminOnlyPolicy`; delete runs under a row lock and maps 23503 to `in_use` and KP001 to `system_locked`; move locks the sibling group and delegates to eloquent-sortable.
- `TransactionCategoryResource` (slug `transaction-categories`, sort 62, no record routes): list in tree order with indented children, type badge, parent, system/archived badges, type and archived filters, edit modal, move up/down icon buttons, archive (with confirmation), restore, delete (with confirmation and a danger notification explaining a refusal). Archive and delete are hidden for the system row.
- `lang/cs/finance.php` with the full `categories.*` key set (plus filters, notifications, placeholders) and the `transactions.errors.*` contract (`czk_required_missing_rate` takes `:currency` and `:date`), NFC-checked by the existing encoding test.

## Task Commits

1. **Task 1: Tracer - the Admin creates a root and a child on the Kategorie screen and the child inherits the type** - `ca6e095` (feat)
2. **Task 2: Archive, restore, delete and move, with the system category protected** - `15d6e0a` (feat, TDD)
3. **Task 3: Edits that would break the tree are refused with a Czech message, not a database error** - `273298e` (feat, TDD)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer gate: the tracer `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the tracer's targeted tests, panel registry, route walk, encoding test, Pint and PHPStan were re-run end to end (green) before expansion.

## Files Created/Modified

- `app/Domain/Finance/Actions/SaveTransactionCategory.php` - create/edit with every tree rule and the database translations
- `app/Domain/Finance/Actions/ArchiveTransactionCategory.php`, `DeleteTransactionCategory.php`, `MoveTransactionCategory.php` - the other three writers
- `app/Domain/Finance/Support/SqlState.php` - SQLSTATE reader (23503, KP001)
- `app/Filament/Resources/TransactionCategoryResource.php`, `.../Pages/ListTransactionCategories.php` - the screen
- `lang/cs/finance.php` - Czech copy and the transaction error contract
- `tests/Feature/Finance/CategoryRulesTest.php` (31 cases), `TransactionCategoryResourceTest.php` (13 cases)

## Decisions Made

- The plan words two different behaviours for "a child under a parent of the other type": Task 1 says a created child takes the parent's type whatever the data carries, Task 3 says a parent of the other type raises `parent_invalid`. Resolved as: creation takes the parent's type (data type ignored); moving an existing category requires the parent to have the type the category has (or the type the data names), otherwise `parent_invalid`.
- The edit modal's type select stays dehydrated while a parent locks it (the screen has set it to the parent's type), so an unused root can be retyped by choosing a parent of the other type; a used category is refused by the composite key and reported as `type_locked`.
- Added a small `Support\SqlState` helper (not in the plan's file list) so three Actions share one SQLSTATE reader instead of copying it.
- The "used by transactions" case relies on the foreign key until plan 09-09 creates `transactions`; the translation paths are proven with probe tables whose keys have the same shape (`category_id` alone for delete, `(category_id, type)` for retype).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] Reconciled the Task 1 / Task 3 type wording**
- **Found during:** Task 3
- **Issue:** Task 1 requires a child created under an expense root with data type income to be stored as expense; Task 3 requires a parent of the other type to raise `parent_invalid`. Both cannot hold for the same call.
- **Fix:** Create ignores the data type; update (move) refuses a parent whose type differs from the category's effective type. Message `parent_invalid` now says "stejného typu".
- **Files modified:** `app/Domain/Finance/Actions/SaveTransactionCategory.php`, `lang/cs/finance.php`
- **Commit:** `273298e`

**2. [Rule 3 - Blocking] Extra support file**
- **Found during:** Task 1
- **Issue:** Three Actions need the same SQLSTATE reader; the plan lists no shared place.
- **Fix:** `app/Domain/Finance/Support/SqlState.php`.
- **Commit:** `ca6e095`

**Total deviations:** 2 auto-fixed (both Rule 3). **Impact:** none on the contract; one added file.

TDD note: Task 1 tests were written before the code but first run green together with the implementation (tracer). In Task 2 the behaviour tests were written before the Actions existed; Task 3 had one observed red (move under a root of the other type) before the fix, the remaining cases already held because Task 1 delivered the pre-checks.

## Issues Encountered

- PHPStan flagged the generic `Builder<TransactionCategory>` return docblock on `getEloquentQuery()`; removed it (as `TaskResource` does).
- `assertNotified(Notification object)` did not match the failure notification of `DeleteAction`; the test asserts the title string instead (as the contacts test does), so the danger colour of that notification is not asserted.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None - the new surface is the Admin-only screen and Actions covered by T-09-20 and T-09-21 (AccessRule plus EnforcesResourceAccessRule, policy authorization in every Action, Partner 403 and AuthorizationException tests, Action pre-checks with database backstops). No change to `composer.json` or `composer.lock` (T-09-SC).

## Next Phase Readiness

- Plan 09-09 creates `transactions` with the composite key to `transaction_categories (id, type)` and a restrict key; delete and retype of a used category are then refused by the database and already translated (`in_use`, `type_locked`). It may add a transactions pre-check in `DeleteTransactionCategory` for the same messages without a round trip.
- Plan 09-22 can read `finance.transactions.errors.*` from `lang/cs/finance.php` without editing the file.
- Navigation: "Kategorie" has sort 62 and no navigation group; the Transactions (60) and Finance overview (61) resources of later plans may add a group to all three at once.

## Self-Check: PASSED

- Created files exist on disk (four Actions, SqlState, resource, list page, finance.php, two test files).
- Commits `ca6e095`, `15d6e0a`, `273298e` are ancestors of HEAD; `git rev-list --count d3682bb..HEAD` is 3 before this SUMMARY.
- Full suite `ddev exec vendor/bin/pest`: 4145 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
