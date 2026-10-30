---
phase: 09-documents-and-finance
verified: 2026-10-29T16:00:00Z
status: passed
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
covered_digest: "v4:sha256:792b5e8dc571d12bdfe1d63e587c9d3c71f2123a016140ece10dbf8e59c384e5"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 5/5
  gaps_closed: []
  gaps_remaining: []
  regressions: []
gaps: []
deferred: []
---

# Phase 9: Documents and Finance Verification Report

**Phase Goal:** Files live on private storage under Admin control with correct Partner visibility, and Admin can record income and expenses and see the money picture in CZK
**Verified:** 2026-10-29
**Status:** passed
**Re-verification:** Yes. The previous report (2026-10-16, human_needed) went stale because four covered files were changed by later phases (10, 15, 17). The three human checks are now recorded as passed in 09-UAT.md (3 passed, 0 issues, completed 2026-10-29).

## Goal Achievement

### Observable Truths (ROADMAP success criteria)

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | Admin uploads files to clients, projects, tasks, comments, transactions or leaves them unattached, including the files on the project and task forms | VERIFIED | Upload path untouched by the later phases; `AttachDocumentsTest`, `CommentAttachmentsTest`, `DocumentsRelationManagerTest` are green in the 604-test run below |
| 2 | Partner sees and downloads only visible files on accessible projects and tasks, never internal-comment attachments, never another client's files | VERIFIED | `tests/Isolation/DocumentVisibilityTest.php` and `DocumentFinanceLeakTest.php` green, including the mutation self-checks and the new "preview of a file (lightbox and new tab)" cases. No later change touched `Document::constrainForPartner()` or the policy |
| 3 | Admin has a central listing with filters and sorting, downloads single files or a ZIP built in the background, bulk-deletes files, sees storage totals overall and per client | VERIFIED | `DocumentActions` still wires bulk delete to `DeleteDocument` per file. Phase 10 added one rule: a file that is the PDF of an issued document is skipped in bulk and counted in the notification (`$skipped`), never silently deleted. `ArchiveEntryNames`, `BuildDocumentArchive` and `PruneExpiredDocumentArchives` are unchanged since the gap closure; their tests are green |
| 4 | Every download goes through the app with a permission check and a short-lived signed URL (no public bucket URL); deleting a record also removes its stored object | VERIFIED | `DocumentFileController` unchanged and still behind the signed, user-bound route. `DeleteDocument` keeps the WR-01 shape (locked row, media rows deleted by query builder, `deletePreservingMedia()`, objects removed in `DB::afterCommit()`, refused objects queued to `RemoveDocumentObjects`). Later phases only added refusals before anything changes: the explicit flag for an issued PDF (Phase 10) and a protected stored cash-receipt PDF (Phase 15). Phase 17 changed preview: an image opens in a lightbox whose link is minted at modal render after the `view` check; a PDF is a real `target="_blank"` link, signed, bound to the viewer (`u`), short-lived (`kokpit.documents.signed_url_ttl_seconds`) and served by the same guarded route. This is a documented owner decision (Phase 17), not a public URL, and keeps the "short-lived signed URL" criterion |
| 5 | Admin records income and expense transactions with hierarchical categories, currency, CZK amount, optional client and project and receipts, and sees an overview per month and year, per category and per client in CZK | VERIFIED | `TransactionCzk` still resolves CZK from a typed figure, an own rate or the CNB list; the Phase 9.1 change only converts an invalid own rate into a `exchange_rate` validation error instead of an exception. `DeleteTransaction` still deletes the transaction and its receipts in one transaction through `DeleteDocument`, after a Phase 10 refusal for the income of an invoice payment raised on the locked row before any receipt is touched. `FinanceOverview` sums stored `czk_minor`. `TransactionCzkTest`, `CategoryRulesTest`, `TransactionReceiptsTest`, `FinanceOverviewTest` green. The "Finance pass" browser check passed in UAT |

**Score:** 5/5 truths verified (0 present, behavior-unverified)

### Re-check of the four changed files

| File | Later change | Effect on Phase 9 must-haves |
|------|--------------|------------------------------|
| `app/Domain/Documents/Actions/DeleteDocument.php` | Phase 10 (10-36) explicit flag for an issued PDF; Phase 15 (15-12) protection of the stored cash-receipt PDF | Additive guards inside the same locked transaction, thrown before any change. Delete order, `afterCommit` object removal and retry job intact (read in full) |
| `app/Domain/Finance/Actions/DeleteTransaction.php` | Phase 10 (10-19) payment-income refusal | Additive refusal on the locked row; receipts still delegated to `DeleteDocument`, rollback keeps rows and objects (`TransactionReceiptsTest` WR-01 cases green) |
| `app/Domain/Finance/TransactionCzk.php` | Phase 9.1 own-rate source and its validation | Extends `resolve()` with a custom rate; the weekend/Friday CNB rule and the manual figure rule are unchanged |
| `app/Filament/Support/DocumentActions.php` | Phase 10 (issued-PDF confirmation, bulk skip); Phase 17 (lightbox preview) | Delete, bulk delete, download and ZIP request unchanged in effect; preview behaviour documented above |

### Required Artifacts

| Artifact | Status | Details |
|----------|--------|---------|
| `app/Domain/Documents/Actions/DeleteDocument.php` | VERIFIED | Substantive, used by `DocumentActions`, `RendersTaskCommentThread`, `DeleteTransaction` |
| `app/Domain/Documents/Jobs/RemoveDocumentObjects.php` | VERIFIED | Dispatched from `DeleteDocument::removeObjects()` |
| `app/Domain/Documents/Jobs/BuildDocumentArchive.php`, `PruneExpiredDocumentArchives.php`, `app/Domain/Documents/Support/ArchiveEntryNames.php` | VERIFIED | Unchanged since gap closure; tests green |
| `app/Domain/Finance/Actions/DeleteTransaction.php`, `app/Domain/Finance/TransactionCzk.php`, `app/Domain/Finance/Queries/FinanceOverview.php` | VERIFIED | Wired as above |
| `app/Http/Controllers/DocumentFileController.php` | VERIFIED | Signed, user-bound streaming route |
| `app/Filament/Support/DocumentActions.php` | VERIFIED | Wired to the policy, the Actions and the URL minting |

### Key Link Verification

| From | To | Status |
|------|----|--------|
| `DocumentActions` single and bulk delete | `DeleteDocument` (`deleteOne()`) | WIRED |
| `DeleteTransaction` | `DeleteDocument` (nested transaction, `afterCommit` joins the outer commit) | WIRED |
| `DeleteDocument` | `RemoveDocumentObjects` (dispatch of the standing objects) | WIRED |
| `DocumentActions::preview()` | `DocumentUrl::preview()` after the `view` check | WIRED |
| `BuildDocumentArchive` | `ArchiveEntryNames::unique()` | WIRED |
| Partner tabs | `Document::constrainForPartner()` | WIRED |
| `FinanceOverview` | `transactions.czk_minor` | WIRED |

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Phase 9 feature, unit and leak tests | `ddev exec php -d memory_limit=1G vendor/bin/pest tests/Feature/Documents tests/Feature/Finance tests/Unit/Documents tests/Isolation/DocumentFinanceLeakTest.php tests/Isolation/DocumentVisibilityTest.php` | 604 passed (9463 assertions), up from 523 at the previous verification | PASS |
| Full Pest suite excluding Audit | run by the orchestrator in DDEV | exit 0 | PASS |

### Probe Execution

No phase-declared probe scripts. SKIPPED.

### Requirements Coverage

| Requirement | Source Plans | Description | Status | Evidence |
|-------------|--------------|-------------|--------|----------|
| DO-01 | 09-01, 02, 05, 08, 10, 12, 13, 14, 20, 21, 24, 27 | Files on all owners; Partner visibility | SATISFIED | SC1, SC2 |
| DO-02 | 09-14, 16 to 20, 23, 25, 26, 28 | Central listing, single and ZIP download, bulk delete, totals | SATISFIED | SC3 |
| DO-03 | 09-03, 05, 07, 10, 13, 18, 20, 21, 27, 28 | Signed app downloads, object removal on delete, explicit confirmation for an issued PDF | SATISFIED | SC4; the issued-PDF confirmation is now in `DeleteDocument` and `DocumentActions` (Phase 10) |
| FI-01 | 09-04, 06, 09, 11, 13, 20, 22, 27, 28 | Transactions with categories, currency, CZK, receipts | SATISFIED | SC5 (invoice reference delivered in Phase 10) |
| FI-02 | 09-15, 20 | Overview per month/year, category, client in CZK | SATISFIED | SC5 |

All five IDs appear in PLAN frontmatter and in REQUIREMENTS.md; none is orphaned (FI-03 belongs to Phase 10). Housekeeping, not a verification gap: the REQUIREMENTS.md checkboxes for DO-01, DO-02, DO-03 and FI-02 are still unchecked and the traceability table still reads "Gaps Found" for them; this report supports marking all five Complete.

### Anti-Patterns Found

None blocking. CR-01, WR-01 and WR-02 are closed in 09-REVIEW-DISPOSITION.md (verified in the previous report and unaffected by the later changes). IN-01 to IN-09 remain Info by disposition.

### Human Verification

All three items of the previous report are recorded as passed in 09-UAT.md (DDEV browser pass, deploy rehearsal of the browser-to-bucket upload, finance pass; 3 passed, 0 issues). No open human items.

### Gaps Summary

No gaps. The later changes to the four covered files are additive guards or documented owner decisions and do not weaken any Phase 9 success criterion; the phase tests and the full suite are green.

---

_Verified: 2026-10-29_
_Verifier: Claude (gsd-verifier)_
