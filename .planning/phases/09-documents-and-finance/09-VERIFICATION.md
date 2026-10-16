---
phase: 09-documents-and-finance
verified: 2026-10-16T14:00:00Z
status: gaps_found
score: 4/5 must-haves verified
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
  - app/Domain/Documents/Actions/DeleteDocument.php
  - app/Domain/Documents/Jobs/BuildDocumentArchive.php
  - app/Domain/Documents/Support/ArchiveEntryNames.php
  - app/Domain/Finance/Actions/DeleteTransaction.php
  - app/Domain/Finance/Queries/FinanceOverview.php
  - app/Domain/Finance/TransactionCzk.php
  - app/Http/Controllers/DocumentFileController.php
  - tests/Unit/Documents/ArchiveEntryNamesTest.php
covered_digest: "v3:sha256:a0a7caaf082524f812f9d71696e78e8ad00726200f46fd3d9b545098a5767e6d"
behavior_unverified: 0
overrides_applied: 0
re_verification: false
gaps:
  - truth: "Admin downloads single files or a ZIP built in the background (ROADMAP SC3, DO-02)"
    status: failed
    reason: "ArchiveEntryNames::unique() throws an undefined array key on a legitimate selection (a.pdf, a.pdf, a (2).pdf). BuildDocumentArchive calls it for every entry, so the queued ZIP build fails on every attempt for such a selection and the Admin gets only the generic failure bell. Confirmed by execution, not just by reading the review (see Behavioral Spot-Checks). The existing unit tests do not cover the order that breaks."
    artifacts:
      - path: "app/Domain/Documents/Support/ArchiveEntryNames.php"
        issue: "Lines 72-101: $counts is set only when a name is handed out unchanged; a numbered candidate is added to $taken but not to $counts, so line 90 reads a missing key when a later document is genuinely named like that candidate"
      - path: "app/Domain/Documents/Jobs/BuildDocumentArchive.php"
        issue: "Line 238 calls $names->unique(...) without guarding against the exception, so the whole archive fails"
      - path: "tests/Unit/Documents/ArchiveEntryNamesTest.php"
        issue: "No regression test for the order ['a.pdf', 'a.pdf', 'a (2).pdf']"
    missing:
      - "Treat a missing counter as 1 (`$this->counts[$key] ?? 1`) and register `$this->counts[$candidateKey] = 1` for every handed-out candidate (fix sketched in 09-REVIEW.md CR-01)"
      - "Regression test for the order a.pdf, a.pdf, a (2).pdf (and the reverse order), plus a BuildDocumentArchive feature test with that file set"
deferred: []
human_verification:
  - test: "DDEV browser pass (LIVEWIRE_TEMPORARY_FILE_UPLOAD_DISK=local in .env)"
    expected: "As Admin: upload two files on a project's Soubory tab, preview the PDF, download the other, build a ZIP from the central listing and download it from the bell link; archive the project, its files leave the default listing and show under the archived-owners filter, restore and they return. As a Partner of that client: see the shared project file, upload a file while creating a task, delete it, and never see the Admin's hidden file, an internal comment's file or the files of an archived project."
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
**Status:** gaps_found
**Re-verification:** No, initial verification

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin uploads files to clients, projects, tasks, comments, transactions or leaves them unattached, including files offered by the project and task forms | VERIFIED | `documents` migration plus `AttachDocuments`; relation managers for client, project, task and transaction (`Client`/`Project`/`Task`/`TransactionDocumentsRelationManager`), comment attachments in `RendersTaskCommentThread`; `AttachDocumentsTest`, `CommentAttachmentsTest`, `DocumentsRelationManagerTest` green. The task/project files are offered through the Soubory tab (TaskResource comment states files go through `AttachDocuments`, not the editor) |
| 2 | Partner sees and downloads only visible files on accessible projects and tasks, never internal-comment attachments, never another client's files | VERIFIED | `Document::constrainForPartner()`, `DocumentPolicy`, Partner relation managers with pinned columns; `tests/Isolation/DocumentVisibilityTest.php`, `DocumentFinanceLeakTest.php` green including a mutation self-check; independent code review found no Partner leak |
| 3 | Admin has a central listing with filters and sorting, downloads single files or a ZIP built in the background, bulk-deletes files, and sees storage totals overall and per client | FAILED | Listing, totals, bulk delete and single download exist and pass their tests. The queued ZIP breaks on a realistic file set (CR-01, confirmed by running the real class) |
| 4 | Every download goes through the app with a permission check and short-lived signed URL; deleting a record also removes its stored object | VERIFIED | `DocumentFileController` behind a signed, user-bound route (`signed_url_ttl_seconds` in `config/kokpit.php`); `DocumentFileRouteTest`, `DeleteDocumentTest`, `DocumentStorageConsistencyTest` green. Caveat: WR-01 (see Anti-Patterns) |
| 5 | Admin records income and expense transactions with hierarchical categories, currency, CZK amount, optional client and project and receipts, and sees an overview per month and year, per category and per client in CZK | VERIFIED | `transactions` and `transaction_categories` migrations, `TransactionCzk::resolve()` (identity / manual / CNB snapshot of rate, unit amount and rate date), `FinanceOverview` sums stored `czk_minor` only, `FinanceOverviewPage` with stats, month chart, category and client tables; `TransactionCzkTest`, `CategoryRulesTest`, `TransactionReceiptsTest`, `FinanceOverviewTest` green |

**Score:** 4/5 truths verified

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `database/migrations/2026_10_16_0001..0005_*` | documents, categories, seed, transactions, archives tables | VERIFIED | all five present |
| `app/Domain/Documents/*` | actions, jobs, policy, queries, support | VERIFIED, one defect | `ArchiveEntryNames` defective (CR-01) |
| `app/Domain/Finance/*` | actions, CZK rule, overview query | VERIFIED | used by `TransactionResource` and `FinanceOverviewPage` |
| `app/Http/Controllers/DocumentFileController.php`, `DocumentArchiveFileController.php` | signed streaming routes | VERIFIED | covered by route walk and route tests |
| `app/Filament/Resources/DocumentResource.php`, `TransactionResource.php`, `TransactionCategoryResource.php`, `FinanceOverviewPage.php` | Admin UI | VERIFIED | resources registered, tests green |
| `routes/console.php` | schedule of `PruneExpiredDocumentArchives` | VERIFIED | line 95 |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `BuildDocumentArchive` | `ArchiveEntryNames::unique()` | line 238 | WIRED, BROKEN FOR INPUT | throws on the CR-01 order |
| Partner tabs | `Document::constrainForPartner()` | scoped queries | WIRED | leak sweep green |
| `TransactionResource` | `TransactionCzk` | `RecordTransaction` / `UpdateTransaction` | WIRED | snapshot written once |
| `FinanceOverview` | `transactions.czk_minor` | SQL sums | WIRED | no re-conversion |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 9 feature, unit and leak tests | `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Finance tests/Unit/Documents tests/Isolation/DocumentFinanceLeakTest.php tests/Isolation/DocumentVisibilityTest.php` | 485 passed (3790 assertions) | PASS |
| ZIP entry numbering for `a.pdf`, `a.pdf`, `a (2).pdf` | run the real `ArchiveEntryNames` with warnings turned into exceptions | `a.pdf`, `a (2).pdf`, then `ErrorException: Undefined array key "a (2).pdf"` | FAIL |

The passing suite does not contradict the failure: the unit test exercises only the orders that work (it asserts `unique('x (2).txt')` on a fresh instance).

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| DO-01 | 09-01, 02, 05, 08, 10, 12, 13, 14, 20, 21, 24 | Files on all owners; Partner visibility | SATISFIED | SC1, SC2 |
| DO-02 | 09-14, 16, 17, 18, 19, 20, 23 | Central listing, single and ZIP download, bulk delete, totals | BLOCKED | ZIP build fails for a realistic selection (CR-01) |
| DO-03 | 09-03, 05, 07, 10, 13, 18, 20, 21 | Signed app downloads, object removal on delete | SATISFIED | SC4; the issued-invoice-PDF confirmation part is proven in Phase 10 per ROADMAP |
| FI-01 | 09-04, 06, 09, 11, 13, 20, 22 | Transactions with categories, currency, CZK, receipts | SATISFIED | SC5 (invoice reference is Phase 10 per ROADMAP) |
| FI-02 | 09-15, 20 | Overview per month/year, category, client in CZK | SATISFIED | SC5 |

All five phase IDs (DO-01, DO-02, DO-03, FI-01, FI-02) appear in PLAN frontmatter and in REQUIREMENTS.md. No orphaned requirements: FI-03 is mapped to Phase 10.

### Anti-Patterns Found

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `app/Domain/Documents/Support/ArchiveEntryNames.php` | 90 | Undefined array key on a reachable input (CR-01) | BLOCKER | ZIP build fails (DO-02) |
| `app/Domain/Finance/Actions/DeleteTransaction.php`, `app/Domain/Documents/Actions/DeleteDocument.php` | 38-52, 43-92 | Stored objects destroyed inside the DB transaction (WR-01) | WARNING | A later failure rolls back rows but not objects; receipts can end up without their file |
| `app/Domain/Documents/Jobs/PruneExpiredDocumentArchives.php` | n/a | Prune deletes only `path`, not a failed archive's ZIP (WR-02) | WARNING | Orphan ZIP stays on the bucket |
| Other | n/a | IN-01 to IN-09 in 09-REVIEW.md | INFO | Hardening and cleanup; none blocks a success criterion |

09-REVIEW-DISPOSITION.md records every finding as `open`; no fix commit exists for CR-01 on the branch.

### Human Verification Required

1. **DDEV browser pass.** Test: Admin uploads, previews, downloads, builds a ZIP and downloads it from the bell link; archives and restores a project; a Partner sees only the shared file and uploads while creating a task. Expected: as listed in the frontmatter. Why human: real browser. Run it after CR-01 is fixed, because the ZIP step is part of it.
2. **Deploy rehearsal (D-18).** Test: two web containers, unset temporary disk, 10 MB direct-to-bucket upload, CORS, one-day expiry rule, streamed download. Expected: as listed in the frontmatter. Why human: needs the production-like bucket.
3. **Finance pass.** Test: weekend EUR expense against Friday's CNB list, manual figure override, month and year on the overview. Why human: visual and browser check.

### Gaps Summary

One blocker. The phase is substantially built and well tested (485 focused tests green, Partner isolation proven, CZK snapshot and overview correct), but DO-02 is not fully achieved: the background ZIP crashes when a selection contains a name that equals a generated duplicate-number name (`a.pdf`, `a.pdf`, `a (2).pdf`), which is a normal case when browsers number downloads. The fix is a two-line change in `ArchiveEntryNames::unique()` plus a regression test. WR-01 and WR-02 are not gaps against the success criteria but should be handled in the same closure round. After the gap closure the status can move to `human_needed` for the three human checks above.

---

_Verified: 2026-10-16_
_Verifier: Claude (gsd-verifier)_
