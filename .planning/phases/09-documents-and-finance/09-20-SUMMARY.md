---
phase: 09-documents-and-finance
plan: 20
subsystem: testing
tags: [isolation, leak-sweep, storage-consistency, contributing, operator-notes, phase-gate, validation]

# Dependency graph
requires:
  - phase: 09-documents-and-finance
    provides: "09-01..09-19 and 09-21..09-24: documents, receipts, ZIP archives, transactions, categories, finance overview, archived-owner scopes, CZK rule, temporary uploads on the bucket"
  - phase: 08-time-reports-and-exchange-rates
    provides: "the Phase 8 Partner sweep in tests/Isolation/TimeLeakTest.php (style copied)"
provides:
  - "tests/Isolation/DocumentFinanceLeakTest.php: Partner sweep over every Phase 9 surface, each refusal paired with an Admin positive"
  - "tests/Feature/Documents/DocumentStorageConsistencyTest.php: a reusable, read-only consistency check (consistencyViolations()) proven clean through a mixed scenario and proven to name every kind of drift"
  - "CONTRIBUTING Documents and Finance bullets, completed Storage and Rich text bullets, the Phase 9 hand-over replaced and the Phase 10 hand-over extended"
  - "README section Documents and finance with the bucket CORS rule, the one-day livewire-tmp/ expiry rule, ZIP retention and the system income category"
  - "RepositoryFilesTest guards for every named Phase 9 class, method and enforcing test, and for the operator notes"
  - "09-VALIDATION.md signed off (status approved, nyquist_compliant, wave_0_complete)"
affects: [phase 9 verification, deploy rehearsal, phase 10 invoicing, phase 11 payments]

actuals:
  tokens: 27400
  tasks: 3
  commits: 3
plan_head_before: fbe1ee7ea215bd90de13de04d0a1c00d66099c45
plan_head_after: cb4eb69a56f1d07903be6f7ee75758065f044f5f
commits: 3

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "A consistency check is a pure read function in a test (consistencyViolations()) that returns named violations; the same test seeds each kind of drift by hand to prove the check names it"
    - "A Partner sweep pairs every refusal and every negative content check with an Admin-side positive on the same fixtures"

key-files:
  created:
    - tests/Isolation/DocumentFinanceLeakTest.php
    - tests/Feature/Documents/DocumentStorageConsistencyTest.php
  modified:
    - CONTRIBUTING.md
    - README.md
    - tests/Feature/Repo/RepositoryFilesTest.php
    - lang/cs/kokpit.php
    - tests/Feature/Tasks/SubtasksTest.php
    - .planning/phases/09-documents-and-finance/09-VALIDATION.md

key-decisions:
  - "No reconcile command: the consistency check is a test whose consistencyViolations() a later health indicator can reuse (research Open Question 4)"
  - "The Phase 9 hand-over note is removed, not rewritten: what Phase 9 leaves for Phase 10 lives in the Phase 10 note (invoice owner kind and archived-owner behaviour, invoice PDF through AttachDocuments hidden from the Partner tab, issued-PDF delete confirmation, TransactionCategory::invoicePayments() for FI-03, invoice_id on transactions, payment-transaction edit restriction)"
  - "The toCzk() hand-over wording disappeared with the Phase 9 note; the Exchange rates bullet still names ExchangeRateConverter::toCzk() because that method still exists and Phase 8 guards it, while the transaction rule is documented as TransactionCzk with rateFor()"
  - "kokpit.tasks.attachments.* removed: no application code read them; the only reader was one assertDontSee in SubtasksTest, which now asserts the old placeholder sentence literally so it cannot pass vacuously"
  - "Per-phase .gitignore review: no change needed"

patterns-established:
  - "Documentation test: the Phase 9 cases follow the Phase 8 style (names map, methods map with the spelling the text uses, phrases, enforcing tests map)"

requirements-completed: [DO-01, DO-02, DO-03, FI-01, FI-02]

coverage:
  - id: D1
    description: "A Partner who tries every Phase 9 Admin surface (documents listing and widgets, archives list, transactions, categories, finance overview and widgets, the four Admin Soubory tabs) gets 403, and no page, bell or file link a Partner can open carries another client's canary, a hidden file name, an archive summary or a finance figure"
    requirement: DO-01
    verification:
      - kind: integration
        ref: "tests/Isolation/DocumentFinanceLeakTest.php (12 tests)"
        status: pass
    human_judgment: false
  - id: D2
    description: "Every document has exactly one media row, every media row its document and object, every object a media row, every file's client and project equal what its owner resolves to; archiving and restoring a task, project and client change neither rows nor objects; each kind of drift is named"
    requirement: DO-03
    verification:
      - kind: integration
        ref: "tests/Feature/Documents/DocumentStorageConsistencyTest.php (8 tests)"
        status: pass
    human_judgment: false
  - id: D3
    description: "CONTRIBUTING documents the Phase 9 conventions and the extended Phase 10 hand-over; the documentation test guards every named class, method and enforcing test"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#only names Phase 9 classes in CONTRIBUTING.md that exist in app/"
        status: pass
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#names the Phase 9 enforcing tests, and each of them exists"
        status: pass
    human_judgment: false
  - id: D4
    description: "README tells the operator about the bucket CORS rule, the one-day livewire-tmp/ expiry rule, the unset temporary disk in production, streamed downloads, ZIP retention and the system income category"
    verification:
      - kind: unit
        ref: "tests/Feature/Repo/RepositoryFilesTest.php#tells the operator what the Phase 9 documents need from the bucket"
        status: pass
    human_judgment: true
    rationale: "The wording is checked by the documentation test, but whether the bucket rules work against the real bucket and browser is verified only in the deploy rehearsal (D-18)"
  - id: D5
    description: "The phase gate is green: full Pest suite, s3 group, Pint, Larastan, licence check, .gitignore self-test, shell self-tests, sensitive-content scan"
    verification:
      - kind: other
        ref: "ddev exec vendor/bin/pest (4550 passed, 3 skipped); --group=s3 (11 passed); pint --test; phpstan analyse; composer check-licenses; scripts/tests/test-gitignore.sh; scripts/tests/run.sh; scripts/check-sensitive.sh --all"
        status: pass
    human_judgment: false
  - id: D6
    description: "The DDEV browser pass over files, the deploy rehearsal of the browser-to-bucket upload and the finance pass"
    verification: []
    human_judgment: true
    rationale: "Three human checks of Task 3 (human_verify_mode end-of-phase) need a real browser and, for the bucket path, the production bucket; they are listed under Human checks below"

# Metrics
duration: 15min
completed: 2026-10-16
status: complete
---

# Phase 9 Plan 20: Leak sweep, storage consistency, conventions and phase gate Summary

**A Partner leak sweep over every Phase 9 surface, a reusable storage consistency check that also names orphan objects, the Documents and Finance conventions with the operator bucket notes guarded by the documentation test, and a green phase gate with the validation signed off.**

## Performance

- **Duration:** 15 min
- **Started:** 2026-10-16T16:40:13Z
- **Completed:** 2026-10-16T16:56:00Z
- **Tasks:** 3
- **Files modified:** 8 (2 created)

## Accomplishments

- `DocumentFinanceLeakTest`: 403 on `/admin/documents`, `/admin/document-archives`, `/admin/transactions` (list, create, edit), `/admin/transaction-categories`, `/admin/finance-overview`; 403 on mounting the list pages, the finance page, `DocumentStorageStats`, `DocumentStorageByClient`, the four finance widgets and the four Admin Soubory tabs (own client and the other); a refused signed link for every hidden, internal, client-owned, unattached file of the own client and every file of the other; seven Partner pages free of the Phase 9 Admin strings, hidden file names, the other client and the finance figure; the Partner bell empty while the Admin holds the three Phase 9 bells. Each is paired with an Admin positive (lists show the canaries and the figure, tabs show the hidden files, widgets render, bell shows the three notices).
- `DocumentStorageConsistencyTest`: `consistencyViolations()` reads documents, media rows, objects on the faked disk and `DocumentOwnerResolver::resolve()` and names every disagreement, including the orphan object a delete or a failed commit leaves behind (carry-over from 09-07 and 09-13). Clean after uploads on all six owner kinds, a rename, a delete, a failed upload, a transaction that changed client, and a deleted transaction; rows, media and objects identical before and after archiving and restoring a task, a project and a client (D-21). Five seeded drifts are each named.
- CONTRIBUTING: new Documents and Finance bullets, Storage bullet completed (downloads streamed through the application, never a presigned URL), Rich text bullet updated (`fileAttachments(false)`, D-22), Phase 9 hand-over removed (with the stale `toCzk()` wording), Phase 10 hand-over extended, `documents` added to the pinned tables of the Add-a-model checklist.
- README: "Documents and finance" under Operations: CORS rule for `PUT` from the instance origin only, one-day expiry on `livewire-tmp/` (`php artisan livewire:configure-s3-upload-cleanup`), `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` unset in production, ZIP retention of 7 days, the seeded system income category, how the CZK figure is fixed.
- `RepositoryFilesTest`: three new cases (Phase 9 class and method names, Phase 9 enforcing tests, operator notes) and the hand-over case rewritten for "no Phase 9 note, extended Phase 10 note".
- 09-VALIDATION.md signed off: Wave 0 ticked, every row green with its plan, `status: approved`.

## Task Commits

1. **Task 1: Tracer - a Partner who tries every Phase 9 surface sees nothing of files, archives or money** - `642d6e7` (test)
2. **Task 2: Conventions, hand-over and operator notes, guarded by the documentation test** - `71b21a2` (docs)
3. **Task 3: Per-phase .gitignore review, the phase gate and the validation sign-off** - `cb4eb69` (docs)

**Plan metadata:** committed with this summary (docs: complete plan).

## Files Created/Modified

- `tests/Isolation/DocumentFinanceLeakTest.php` - Phase 9 Partner leak sweep (12 tests)
- `tests/Feature/Documents/DocumentStorageConsistencyTest.php` - storage consistency check and its drift proofs (8 tests)
- `CONTRIBUTING.md` - Documents and Finance bullets, Storage, Rich text, hand-over notes, checklist
- `README.md` - Documents and finance operator section
- `tests/Feature/Repo/RepositoryFilesTest.php` - Phase 9 documentation guards
- `lang/cs/kokpit.php` - removed the unused `tasks.attachments` copy
- `tests/Feature/Tasks/SubtasksTest.php` - the placeholder assertion now uses the literal sentence
- `.planning/phases/09-documents-and-finance/09-VALIDATION.md` - signed off

## Decisions Made

See `key-decisions` above. In short: no reconcile command, the Phase 9 hand-over note is replaced by the Phase 10 note, the `tasks.attachments` copy is removed with its one test reader reworked, and the `.gitignore` needs no change.

## Per-phase .gitignore review (HYG-01)

No change needed. Production keeps temporary uploads on the bucket. In DDEV the local override puts Livewire temporary uploads under `storage/app/private/livewire-tmp`, which `git check-ignore` confirms is ignored through the `storage/app/private/.gitignore` placeholder; upload copies and ZIP temp files live in the system temp directory. `bash scripts/tests/test-gitignore.sh` passes (122 assertions).

## Phase gate results

| Gate | Result |
|---|---|
| `ddev exec vendor/bin/pest` | 4550 passed, 3 skipped (the same three skips as before the plan), 0 failed |
| `ddev exec vendor/bin/pest --group=s3` | 11 passed against RustFS (includes `TemporaryUploadS3Test` and the bucket case of `DeleteDocumentTest`) |
| `ddev exec vendor/bin/pint --test` | pass, 855 files |
| `ddev exec vendor/bin/phpstan analyse --no-progress --memory-limit=1G` | no errors |
| `ddev composer check-licenses` | 225 packages, every one allowed |
| `bash scripts/tests/test-gitignore.sh` | 122 passed, 0 failed |
| `bash scripts/tests/run.sh` | PASS 10, FAIL 0 |
| `scripts/check-sensitive.sh --all` | clean |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] The unused-copy removal broke the only reader of the keys**
- **Found during:** Task 2
- **Issue:** `tests/Feature/Tasks/SubtasksTest.php` asserted `assertDontSee(__('kokpit.tasks.attachments.later'))` and `...heading`. With the keys gone `__()` returns the key string itself, so the assertion would pass vacuously. The plan said to remove the keys when "no code reads them"; no application code does, but this test did.
- **Fix:** The test now asserts the old placeholder sentence literally; the heading assertion was dropped because "Přílohy" is a legitimate label of the comment upload field.
- **Files modified:** `tests/Feature/Tasks/SubtasksTest.php` (a file outside the plan's list)
- **Verification:** `ddev exec vendor/bin/pest tests/Feature/Tasks/SubtasksTest.php` passes; full suite green.
- **Committed in:** `71b21a2`

**2. [Rule 1 - Bug] The hand-over documentation test required the removed Phase 9 note**
- **Found during:** Task 2
- **Issue:** The existing case "hands over to Phases 7, 9 and 10" required `**Phase 9 (` and that the note named `FI-01`, `ExchangeRateConverter` and `WorkReportPdf`. The plan removes the Phase 9 note.
- **Fix:** The case now requires that no Phase 9 note exists (as for Phases 6 and 8) and that the Phase 10 note carries the Phase 9 hand-overs.
- **Files modified:** `tests/Feature/Repo/RepositoryFilesTest.php` (in the plan's list)
- **Committed in:** `71b21a2`

---

**Total deviations:** 2 auto-fixed (1 blocking, 1 bug in a guard that the plan itself outdates)
**Impact on plan:** Both necessary to keep the documentation test and the placeholder assertion honest. No scope creep. No production class needed a fix: the leak sweep and the consistency check passed on the first run, so no leak was found.

## Issues Encountered

None. Both new tests were green on the first run; the Admin-side positives and the seeded-drift cases are what show they do not pass vacuously.

## Human checks (end-of-phase verification, not run here)

`human_verify_mode` is end-of-phase, so these are not a checkpoint. They are for `/gsd-verify-work` and the deploy rehearsal:

1. **DDEV browser pass** (`LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local` in `.env`): as the Admin upload two files on a project's Soubory tab, preview the PDF, download the other, build a ZIP from the listing and download it from the bell link; archive the project, see its files leave the default central listing and appear under the "archived owners" filter, restore it and see them back; as a Partner of that client see the shared project file, upload a file while creating a task, delete it, and confirm the Admin's hidden file, an internal comment's file and the files of an archived project never appear.
2. **Deploy rehearsal (D-18)**: with at least two web containers and `LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK` unset, confirm the bucket CORS rule allows `PUT` from the instance origin only, a 10 MB file uploads straight to the bucket and the submit served by another container stores it as a document, the `livewire-tmp/` object is gone after the store, the one-day expiry rule on `livewire-tmp/` exists, and a download streams through the app without a 413 or a bucket URL. The README section "Documents and finance" is the operator note for it.
3. **Finance pass**: record a EUR expense dated on a weekend, check the CZK figure against Friday's CNB list, switch it to a manual bank figure, and see the month and the year on the finance overview.

## User Setup Required

None - no external service configuration is required by this plan. The bucket CORS and expiry rules are production operator tasks described in the README and checked in the deploy rehearsal.

## Known Stubs

None.

## Threat Flags

None. The plan adds tests and documentation only; it adds no endpoint, auth path, file access pattern or schema change.

## Next Phase Readiness

- Phase 9 is complete and ready for `/gsd-verify-work` and the deploy rehearsal listed above.
- Phase 10 starts from the extended Phase 10 hand-over note in CONTRIBUTING (invoice owner kind, invoice PDF through `AttachDocuments`, `TransactionCategory::invoicePayments()`, `invoice_id` on transactions, payment-transaction edit restriction).

## Self-Check: PASSED

- Created files exist: `tests/Isolation/DocumentFinanceLeakTest.php`, `tests/Feature/Documents/DocumentStorageConsistencyTest.php` (present); modified files present.
- Commits `642d6e7`, `71b21a2`, `cb4eb69` are ancestors of HEAD.
- All acceptance greps of the three tasks pass; the plan verification commands are green (see Phase gate results).

---
*Phase: 09-documents-and-finance*
*Completed: 2026-10-16*
