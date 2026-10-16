---
phase: 09-documents-and-finance
verified: 2026-10-16T16:00:00Z
status: human_needed
score: 5/5 must-haves verified
covered_files:
  - .planning/phases/09-documents-and-finance/09-01-PLAN.md
  - .planning/phases/09-documents-and-finance/09-01-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-02-PLAN.md
  - .planning/phases/09-documents-and-finance/09-02-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-03-PLAN.md
  - .planning/phases/09-documents-and-finance/09-03-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-04-PLAN.md
  - .planning/phases/09-documents-and-finance/09-04-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-05-PLAN.md
  - .planning/phases/09-documents-and-finance/09-05-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-06-PLAN.md
  - .planning/phases/09-documents-and-finance/09-06-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-07-PLAN.md
  - .planning/phases/09-documents-and-finance/09-07-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-08-PLAN.md
  - .planning/phases/09-documents-and-finance/09-08-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-09-PLAN.md
  - .planning/phases/09-documents-and-finance/09-09-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-10-PLAN.md
  - .planning/phases/09-documents-and-finance/09-10-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-11-PLAN.md
  - .planning/phases/09-documents-and-finance/09-11-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-12-PLAN.md
  - .planning/phases/09-documents-and-finance/09-12-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-13-PLAN.md
  - .planning/phases/09-documents-and-finance/09-13-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-14-PLAN.md
  - .planning/phases/09-documents-and-finance/09-14-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-15-PLAN.md
  - .planning/phases/09-documents-and-finance/09-15-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-16-PLAN.md
  - .planning/phases/09-documents-and-finance/09-16-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-17-PLAN.md
  - .planning/phases/09-documents-and-finance/09-17-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-18-PLAN.md
  - .planning/phases/09-documents-and-finance/09-18-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-19-PLAN.md
  - .planning/phases/09-documents-and-finance/09-19-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-20-PLAN.md
  - .planning/phases/09-documents-and-finance/09-20-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-21-PLAN.md
  - .planning/phases/09-documents-and-finance/09-21-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-22-PLAN.md
  - .planning/phases/09-documents-and-finance/09-22-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-23-PLAN.md
  - .planning/phases/09-documents-and-finance/09-23-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-24-PLAN.md
  - .planning/phases/09-documents-and-finance/09-24-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-25-PLAN.md
  - .planning/phases/09-documents-and-finance/09-25-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-26-PLAN.md
  - .planning/phases/09-documents-and-finance/09-26-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-27-PLAN.md
  - .planning/phases/09-documents-and-finance/09-27-SUMMARY.md
  - .planning/phases/09-documents-and-finance/09-28-PLAN.md
  - .planning/phases/09-documents-and-finance/09-28-SUMMARY.md
  - app/Domain/Documents/Actions/DeleteDocument.php
  - app/Domain/Documents/Jobs/BuildDocumentArchive.php
  - app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php
  - app/Domain/Documents/Jobs/RemoveDocumentObjects.php
  - app/Domain/Documents/Support/ArchiveEntryNames.php
  - app/Domain/Finance/Actions/DeleteTransaction.php
  - app/Domain/Finance/Queries/FinanceOverview.php
  - app/Domain/Finance/TransactionCzk.php
  - app/Filament/Support/DocumentActions.php
  - app/Http/Controllers/DocumentFileController.php
  - tests/Feature/Documents/DeleteDocumentTest.php
  - tests/Feature/Documents/DocumentArchiveCleanupTest.php
  - tests/Feature/Documents/DocumentArchivePruneTest.php
  - tests/Feature/Documents/DocumentArchiveTest.php
  - tests/Feature/Documents/RemoveDocumentObjectsTest.php
  - tests/Feature/Finance/TransactionReceiptsTest.php
  - tests/Unit/Documents/ArchiveEntryNamesTest.php
covered_digest: "v3:sha256:a9d7d4934fa07f5937cdb013e9599cc50522ce03ed4df958e5ead486d0aea0fe"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: gaps_found
  previous_score: 4/5
  gaps_closed:
    - "CR-01: ArchiveEntryNames::unique() threw on a.pdf, a.pdf, a (2).pdf (plan 09-25)"
    - "WR-01: stored objects destroyed inside the DB transaction (plans 09-27, 09-28)"
    - "WR-02: prune and failed builds left an orphan archive ZIP (plan 09-26)"
  gaps_remaining: []
  regressions: []
gaps: []
deferred: []
human_verification:
  - test: "DDEV browser pass (LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local in .env)"
    expected: "As Admin: upload two files on a project's Soubory tab, preview the PDF, download the other, build a ZIP from the central listing (include two files that share a name) and download it from the bell link; archive the project, its files leave the default listing and show under the archived-owners filter, restore and they return. As a Partner of that client: see the shared project file, upload a file while creating a task, delete it, and never see the Admin's hidden file, an internal comment's file or the files of an archived project."
    why_human: "Needs a real browser (Livewire upload, inline preview, bell link, Filament modals)"
  - test: "Deploy rehearsal of the browser-to-bucket upload (D-18)"
    expected: "With at least two web containers and LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK unset: the bucket CORS rule allows PUT from the instance origin only, a 10 MB file uploads straight to the bucket and a submit served by another container stores it as a document, the livewire-tmp/ object is gone after the store, the one-day expiry rule exists, and a download streams through the app with no 413 and no bucket URL"
    why_human: "Needs the production-like bucket and several containers; the DDEV bucket is not browser-reachable"
  - test: "Finance pass"
    expected: "Record a EUR expense dated on a weekend, the CZK figure equals the Friday CNB list, switch it to a manual bank figure, and see the month and the year on the finance overview"
    why_human: "Visual check of the overview and of the form behaviour in a browser; the rules themselves are covered by tests"
---

# Phase 9: Documents and Finance Verification Report

**Phase Goal:** Files live on private storage under Admin control with correct Partner visibility, and Admin can record income and expenses and see the money picture in CZK
**Verified:** 2026-10-16
**Status:** human_needed
**Re-verification:** Yes, after gap closure plans 09-25 to 09-28 (previous status gaps_found, 4/5)

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin uploads files to clients, projects, tasks, comments, transactions or leaves them unattached, including files offered by the project and task forms | VERIFIED (regression check) | Unchanged since the first verification; `AttachDocumentsTest`, `CommentAttachmentsTest`, `DocumentsRelationManagerTest` in the 523-test run are green |
| 2 | Partner sees and downloads only visible files on accessible projects and tasks, never internal-comment attachments, never another client's files | VERIFIED (regression check) | `tests/Isolation/DocumentVisibilityTest.php` and `DocumentFinanceLeakTest.php` green, mutation self-checks included; the gap-closure plans touched no Partner scope |
| 3 | Admin has a central listing with filters and sorting, downloads single files or a ZIP built in the background, bulk-deletes files, and sees storage totals overall and per client | VERIFIED | CR-01 closed: `ArchiveEntryNames::unique()` now sets a counter for every handed-out name (`$this->counts[$candidateKey] = 1`) and reads a missing counter as 1 (`?? 1`). Re-executed in DDEV with warnings turned into exceptions: `a.pdf, a.pdf, a (2).pdf` gives `a.pdf, a (2).pdf, a (2) (2).pdf`; `a (2).pdf, a.pdf, a.pdf, a.pdf` gives `a (2).pdf, a.pdf, a (3).pdf, a (4).pdf`; a mixed-case set of five names stays distinct case-insensitively. The queued ZIP of the CR-01 set is built in `DocumentArchiveTest.php`. Bulk delete (`DocumentActions` line 338) goes through `DeleteDocument` per file |
| 4 | Every download goes through the app with a permission check and short-lived signed URL; deleting a record also removes its stored object | VERIFIED | `DocumentFileController` behind the signed, user-bound route. WR-01 closed: `DeleteDocument` deletes the document row and media rows in one locked DB transaction and removes objects in `DB::afterCommit()`, so an outer rollback (a later receipt, the transaction row) keeps row, media row and object. An object the disk refuses is reported and queued to `RemoveDocumentObjects` (3 tries, path-shape and media-row guards, then the failed-job alert). `DeleteTransaction` delegates each receipt to `DeleteDocument` inside its own transaction. The removal timing deviates from D-09 by the accepted decision D-23 (object removed after commit, guaranteed by retry plus alert) |
| 5 | Admin records income and expense transactions with hierarchical categories, currency, CZK amount, optional client and project and receipts, and sees an overview per month and year, per category and per client in CZK | VERIFIED (regression check) | `TransactionCzk`, `FinanceOverview` (sums stored `czk_minor`), `FinanceOverviewPage`; `TransactionCzkTest`, `CategoryRulesTest`, `TransactionReceiptsTest` (now also the WR-01 rollback cases), `FinanceOverviewTest` green |

**Score:** 5/5 truths verified (0 present, behavior-unverified). The status is `human_needed` only because of the three browser and deploy checks below.

### Gap Closure Check

| Finding | Plan | Code evidence | Test evidence | Status |
|---------|------|---------------|---------------|--------|
| CR-01 archive entry names | 09-25 | `ArchiveEntryNames::unique()` total for every order | Re-executed above; `ArchiveEntryNamesTest` permutation case; `DocumentArchiveTest` CR-01 cases | CLOSED |
| WR-01 objects removed after commit | 09-27, 09-28 | `DeleteDocument` (afterCommit removal), `DeleteTransaction` (nested delegation), `RemoveDocumentObjects` (guarded retry job) | `DeleteDocumentTest`, `RemoveDocumentObjectsTest`, `TransactionReceiptsTest`, `DocumentStorageConsistencyTest` | CLOSED |
| WR-02 orphan archive ZIP | 09-26 | `PruneExpiredDocumentArchives` deletes `storagePath()` of every expired record whatever its status; `BuildDocumentArchive::removeZipAfterCommit()` on failed, vanished or given-up records, never for a ready one | `DocumentArchivePruneTest`, `DocumentArchiveCleanupTest` | CLOSED |

`09-REVIEW-DISPOSITION.md` records CR-01, WR-01 and WR-02 as closed with commit hashes that exist on the branch (b6d4fc9, 3719a52, cc293c3, 7e636be, da86cf6, 68ee519, f55476a, a67c124). IN-01 to IN-09 stay open as Info and block no success criterion.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `app/Domain/Documents/Support/ArchiveEntryNames.php` | total unique naming | VERIFIED | read and executed |
| `app/Domain/Documents/Actions/DeleteDocument.php` | row delete in transaction, object after commit | VERIFIED | used by `DocumentActions`, `RendersTaskCommentThread`, `DeleteTransaction` |
| `app/Domain/Documents/Jobs/RemoveDocumentObjects.php` | guarded retry of refused objects | VERIFIED | dispatched from `DeleteDocument`; extends `KokpitJob` |
| `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php` | id-derived ZIP delete for every expired record | VERIFIED | scheduled in `routes/console.php` |
| `app/Domain/Documents/Jobs/BuildDocumentArchive.php` | cleanup of failed or vanished archives | VERIFIED | `removeZipAfterCommit()` |
| `app/Domain/Finance/Actions/DeleteTransaction.php` | atomic delete with receipts | VERIFIED | |
| Remaining Phase 9 artifacts (migrations, policy, controllers, resources, finance overview) | as in the first verification | VERIFIED (regression) | covered by the green suite |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `BuildDocumentArchive::add()` | `ArchiveEntryNames::unique()` | line 295 | WIRED | no longer throws on the CR-01 order |
| `DocumentActions` bulk delete | `DeleteDocument` | line 338 | WIRED | per file, own transaction |
| `DeleteTransaction` | `DeleteDocument` | nested transaction, `afterCommit` attaches to the outer commit | WIRED | |
| `DeleteDocument` | `RemoveDocumentObjects` | `dispatch($standing)` after a refused object | WIRED | |
| Partner tabs | `Document::constrainForPartner()` | scoped queries | WIRED | leak sweep green |
| `FinanceOverview` | `transactions.czk_minor` | SQL sums | WIRED | no re-conversion |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 9 feature, unit and leak tests | `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Finance tests/Unit/Documents tests/Isolation/DocumentFinanceLeakTest.php tests/Isolation/DocumentVisibilityTest.php` | 523 passed (9012 assertions), up from 485 before the closure | PASS |
| ZIP entry numbering for the CR-01 orders | run the real `ArchiveEntryNames` with warnings turned into exceptions | three sets, all distinct, no exception | PASS |
| Static analysis of the touched domains | `ddev exec vendor/bin/phpstan analyse app/Domain/Documents app/Domain/Finance` | No errors | PASS |

### Probe Execution

No phase-declared probe scripts. SKIPPED.

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| DO-01 | 09-01, 02, 05, 08, 10, 12, 13, 14, 20, 21, 24, 27 | Files on all owners; Partner visibility | SATISFIED | SC1, SC2 |
| DO-02 | 09-14, 16 to 20, 23, 25, 26, 28 | Central listing, single and ZIP download, bulk delete, totals | SATISFIED | SC3; CR-01 and WR-02 closed |
| DO-03 | 09-03, 05, 07, 10, 13, 18, 20, 21, 27, 28 | Signed app downloads, object removal on delete | SATISFIED | SC4; WR-01 closed under D-23. The issued-invoice-PDF confirmation part is proven in Phase 10 per ROADMAP |
| FI-01 | 09-04, 06, 09, 11, 13, 20, 22, 27, 28 | Transactions with categories, currency, CZK, receipts | SATISFIED | SC5 (invoice reference is Phase 10 per ROADMAP) |
| FI-02 | 09-15, 20 | Overview per month/year, category, client in CZK | SATISFIED | SC5 |

All five phase IDs (DO-01, DO-02, DO-03, FI-01, FI-02) appear in PLAN frontmatter and in REQUIREMENTS.md. No orphaned requirements: FI-03 is mapped to Phase 10. The REQUIREMENTS.md traceability table still shows these five as "Gaps Found" and the checkboxes are unchecked; they are updated at phase completion, after the human checks.

### Anti-Patterns Found

None blocking. The three previous items (CR-01 BLOCKER, WR-01, WR-02) are closed. IN-01 to IN-09 (INFO) remain open by disposition.

### Human Verification Required

1. **DDEV browser pass.** Admin uploads, previews, downloads, builds a ZIP (include two files with the same name) and downloads it from the bell link; archives and restores a project; a Partner sees only the shared file and uploads while creating a task. Why human: real browser.
2. **Deploy rehearsal (D-18).** Two web containers, unset temporary disk, 10 MB direct-to-bucket upload, CORS, one-day expiry rule, streamed download. Why human: needs the production-like bucket.
3. **Finance pass.** Weekend EUR expense against Friday's CNB list, manual figure override, month and year on the overview. Why human: visual and browser check.

### Gaps Summary

No gaps. All five success criteria are achieved in the code and proven by tests; the three gap-closure findings are fixed and verified by reading and by execution. The phase moves to `human_needed` for the three browser and deploy checks that cannot be automated.

---

_Verified: 2026-10-16_
_Verifier: Claude (gsd-verifier)_
