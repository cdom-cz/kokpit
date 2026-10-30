---
phase: 09-documents-and-finance
plan: 01
subsystem: documents
tags: [documents, medialibrary, partner-scope, postgres, uuidv7, morph-map, canary-harness]

# Dependency graph
requires:
  - phase: 02-platform-conventions-isolation
    provides: PartnerIsolated scope and canary harness, morph map enforcement, DeniesPartners Media
  - phase: 05-tasks
    provides: Partner-scoped Task and TaskComment queries (internal comments dropped)
  - phase: 06-time-tracking
    provides: projects_id_client_unique key reused as the target of the documents composite FK
provides:
  - documents table (one row per file, owning exactly one media row on the private disk)
  - Document model with the Partner scope delegating to the scoped Project, Task and TaskComment queries
  - AttachDocuments, the single upload entry point, and DocumentOwnerResolver for every owner kind
  - DocumentPolicy (Admin through before(), no Partner grant yet) and the 'document' morph alias
  - kokpit.documents settings block (size, count, signed URL lifetime, download rate, archive limits)
  - Document canary fixture with four hidden twins and the DocumentFixtures test helper
affects: [09-02 upload rules, 09-03 download route, 09-05 tabs, 09-08 Partner read, 09-09 transactions, 09-14 listing, 09-17 ZIP, 09-21 schema proofs and archived owners]

actuals:
  tokens: 11200
  tasks: 3
  commits: 3
plan_head_before: bab07256353877918a364d597c1c8acd6da9fca9
plan_head_after: 09f2e05fd2f8951c509883c1bafefb0d2f4d9627
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Partner scope that delegates to already-scoped Project/Task/TaskComment id subqueries keyed by owner_type alias"
    - "Server-generated object name <document uuid>.<ext>; media write as system after the policy (Media stays DeniesPartners)"
    - "Owner resolution once at upload into denormalized client_id/project_id, guarded by a composite FK"
    - "Canary fixture with hidden twins written by system forceFill where the Action would refuse the state"

key-files:
  created:
    - database/migrations/2026_10_16_000100_create_documents_table.php
    - app/Domain/Documents/Models/Document.php
    - app/Domain/Documents/Enums/DocumentOwnerType.php
    - app/Domain/Documents/Policies/DocumentPolicy.php
    - app/Domain/Documents/Actions/AttachDocuments.php
    - app/Domain/Documents/Support/DocumentOwnerResolver.php
    - tests/Support/DocumentFixtures.php
    - tests/Feature/Documents/AttachDocumentsTest.php
  modified:
    - app/Domain/Shared/Database/MorphMap.php
    - app/Providers/AccessServiceProvider.php
    - config/kokpit.php
    - tests/Arch/ModelDeclarationTest.php
    - tests/Support/CanaryRegistry.php
    - tests/Isolation/CanaryRegistryTest.php

key-decisions:
  - "projects_id_client_unique already exists (created by the time entries migration); the documents migration reuses it and neither adds nor drops it"
  - "DocumentPolicy declares attach() returning false: the Gate skips before() for an ability the policy has no method for, so the Admin would otherwise be refused too"
  - "Owner resolution re-reads the owner as system with a shared lock, archived rows included, so a stale instance cannot yield a wrong client"
  - "A blank or over-long client file name is reduced to a safe display name (255 chars, fallback 'file') so the table CHECK cannot fail; strict name rules stay in plan 09-02"

patterns-established:
  - "Document owner kinds: project, task (incl. subtask), task_comment (hidden when internal), client (never shared), null (unattached, never shared)"
  - "Stored visibility = shareable && (requested ?? owner default); the CHECK documents_visible_owner_check is the backstop"

requirements-completed: [DO-01]

coverage:
  - id: D1
    description: "An Admin attaches a file to a project through AttachDocuments: one documents row and one media row, private object named <document uuid>.<ext>, client, project, uploader, detected MIME type and size stored"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it stores a file of a project as one document with one private object"
        status: pass
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it never carries user text in the stored object name"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every owner kind (project, task, subtask, comment, internal comment, client, unattached) resolves its client, project and default visibility; client, unattached and internal-comment files stay hidden even when visible is requested"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php (owner kind cases, D-03 and D-20)"
        status: pass
    human_judgment: false
  - id: D3
    description: "The Partner scope admits a document only through the scoped Project, Task and TaskComment queries; a Partner of the client reads exactly the one visible canary file, never the four hidden twins, never another client's"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/CanaryRegistryTest.php#it shows Partner A zero rows of every deny-all model and exactly the own row of a client-bound model"
        status: pass
      - kind: integration
        ref: "mutation run: removing the visible_to_client rule from constrainForPartner made 2 CanaryRegistryTest cases fail"
        status: pass
    human_judgment: false
  - id: D4
    description: "A Partner calling AttachDocuments is refused and nothing is stored"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it refuses a Partner and stores nothing"
        status: pass
    human_judgment: false
  - id: D5
    description: "Every documents setting is one value in config/kokpit.php (10240 KB, 10 files, 120 s, 120 per minute, archive 7 days / 200 files / 200 MB), pinned against the medialibrary byte ceiling"
    requirement: DO-01
    verification:
      - kind: unit
        ref: "tests/Feature/Documents/AttachDocumentsTest.php#it keeps every documents setting in one config block"
        status: pass
    human_judgment: false

# Metrics
duration: 25min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 01: Document Storage Record Summary

**Documents table with one private medialibrary object per file, a Partner scope that reuses the scoped Project/Task/TaskComment queries, and the single AttachDocuments upload Action resolving owner, client, project and visibility on the server**

## Performance

- **Duration:** about 25 min (the sandbox clock was not trustworthy for an exact start; approximate)
- **Started:** 2026-10-16 (approx. 10:55Z)
- **Completed:** 2026-10-16T11:20Z (approx.)
- **Tasks:** 3 (1 tracer, 2 TDD)
- **Files modified:** 14 (8 created, 6 modified)

## Accomplishments

- `documents` table with owner morph pair, flag, denormalized `client_id` / `project_id` under the composite FK `(project_id, client_id) -> projects (id, client_id)`, six CHECK constraints (including `documents_visible_owner_check`), three indexes and the partial unique index `media_document_unique` (exactly one media row per document).
- `Document` model with `constrainForPartner()` that checks the flag, the own client and the owner inside the already-scoped `Project`, `Task` and `TaskComment` id subqueries (no `withTrashed()`), so internal comments and archived owners drop out on their own (D-04, D-21).
- `AttachDocuments` as the one upload entry point: policy check, owner resolution inside one transaction, content-detected MIME type, server-named object `<uuid>.<ext>`, media write as system after the policy.
- `DocumentOwnerResolver` for project, task, subtask, comment, client and unattached owners; the stored flag is `shareable && (requested ?? default)`.
- Canary harness: the `Document` fixture writes the visible project file plus four hidden twins (hidden file, internal-comment file flagged visible, client-owned, unattached); Partner A reads exactly one row. A mutation run (dropping the flag rule) made two registry tests fail.
- `kokpit.documents` settings block, pinned against `media-library.max_file_size`.

## Task Commits

1. **Task 1: Tracer - an Admin attaches a file to a project and it is stored privately as one document** - `9facc2c` (feat)
2. **Task 2: Every owner kind resolves its client, project and visibility; canary with four hidden twins** - `4840a5c` (feat, TDD: tests written and observed red first, then the resolver)
3. **Task 3: The documents settings are one contract** - `09f2e05` (feat, TDD: config test red first)

**Plan metadata:** committed with this SUMMARY (docs: complete plan)

The tracer gate: `<verify>` is automated-only and `human_verify_mode` is `end-of-phase`, so the tracer's tests, Pint and PHPStan were re-run end to end (green) before expansion.

## Files Created/Modified

- `database/migrations/2026_10_16_000100_create_documents_table.php` - documents table, constraints, indexes, media partial unique index
- `app/Domain/Documents/Models/Document.php` - Partner-scoped document entity owning one media
- `app/Domain/Documents/Enums/DocumentOwnerType.php` - owner aliases and `isShareable()`
- `app/Domain/Documents/Policies/DocumentPolicy.php` - Admin through `before()`, `viewAny` true, `attach` denied for Partners
- `app/Domain/Documents/Actions/AttachDocuments.php` - the upload entry point
- `app/Domain/Documents/Support/DocumentOwnerResolver.php` - owner, client, project, visibility derivation
- `app/Domain/Shared/Database/MorphMap.php`, `app/Providers/AccessServiceProvider.php` - alias and policy registration
- `config/kokpit.php` - `documents` block
- `tests/Support/DocumentFixtures.php`, `tests/Support/CanaryRegistry.php`, `tests/Isolation/CanaryRegistryTest.php`, `tests/Arch/ModelDeclarationTest.php`, `tests/Feature/Documents/AttachDocumentsTest.php` - fixtures, canary, pinned lists, feature tests

## Decisions Made

- Reuse the existing `projects_id_client_unique` key instead of creating it (see deviation 1); the time entries migration owns its lifecycle.
- `DocumentPolicy::attach()` exists and denies, so the Admin is admitted by `before()` and a Partner is refused (see deviation 2).
- The resolver reads owners as system with a shared lock, archived rows included.
- A minimal display-name guard (basename, 255 characters, non-blank fallback) lives in the Action so the table CHECK cannot fail; the real name and extension rules belong to plan 09-02.
- Activity logging for `Document` is not part of this plan (the plan lists no allowlist); it follows with the rename and visibility flows.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] The plan creates `projects_id_client_unique`, which already exists**
- **Found during:** Task 1 (first migration run: SQLSTATE 42P07, relation already exists)
- **Issue:** Research A12 and the artifact table treat the key as new, but the Phase 6 time entries migration already adds and drops it.
- **Fix:** The documents migration no longer adds or drops it; its docblock names the key as reused. The composite FK targets the existing key.
- **Files modified:** `database/migrations/2026_10_16_000100_create_documents_table.php`
- **Verification:** `ddev exec vendor/bin/pest` full suite green; the plan's acceptance grep for `projects_id_client_unique` still matches (docblock and FK).
- **Committed in:** 9facc2c

**2. [Rule 1 - Bug] The policy needs an explicit `attach` method**
- **Found during:** Task 1 (Admin got "This action is unauthorized")
- **Issue:** The plan says every ability but `viewAny` is absent. Laravel's Gate skips `before()` for an ability the policy has no method for, so the Admin was denied too.
- **Fix:** `DocumentPolicy::attach(User, ?Model): bool` returns false; the Admin passes through `before()`, a Partner is denied.
- **Files modified:** `app/Domain/Documents/Policies/DocumentPolicy.php`
- **Verification:** `it refuses a Partner and stores nothing` and every Admin case pass.
- **Committed in:** 9facc2c

**3. [Rule 2 - Missing Critical] Display-name guard in the Action**
- **Found during:** Task 1
- **Issue:** A blank or over-long client file name would hit `documents_name_check` / the varchar(255) limit as a raw database error.
- **Fix:** `displayName()` takes the basename, caps it at 255 characters and falls back to `file`.
- **Files modified:** `app/Domain/Documents/Actions/AttachDocuments.php`
- **Verification:** covered by the stored-name tests; full strict rules remain plan 09-02.
- **Committed in:** 9facc2c

---

**Total deviations:** 3 auto-fixed (2 bug, 1 missing critical)
**Impact on plan:** All necessary for correctness; no scope creep. Later plans reusing `projects_id_client_unique` (09-09) should treat it as existing.

## Issues Encountered

- A first migration attempt failed on the duplicate key (deviation 1); fixed before any commit.
- Pint reordered the traits in `Document` after the first run; no behaviour change.

## Known Stubs

None.

## Authentication Gates

None.

## User Setup Required

None - no external service configuration required.

## Threat Flags

None - the new surface (a Partner-readable table and the media write path) is the one covered by T-09-01 to T-09-03 of the plan.

## Next Phase Readiness

- Plans 09-02 (upload rules, atomic storage), 09-03 (download route), 09-08 (Partner grants) and 09-21 (raw-SQL constraint proofs, Partner-safe column pin, archived-owner scopes) can build on the table and the Action.
- Open for 09-21: the `PartnerSafeColumnsTest` pin of the documents columns and the raw-SQL refusal proofs of the migration are intentionally not in this plan.

## Self-Check: PASSED

- Created files exist on disk (migration, Document, DocumentOwnerType, DocumentPolicy, AttachDocuments, DocumentOwnerResolver, DocumentFixtures, AttachDocumentsTest).
- Commits `9facc2c`, `4840a5c`, `09f2e05` are ancestors of HEAD; `git rev-list --count bab0725..HEAD` is 3.
- Full suite `ddev exec vendor/bin/pest`: 3953 passed, 3 skipped; Pint and PHPStan clean; `scripts/check-sensitive.sh` clean on every staged set.

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
