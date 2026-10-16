---
phase: "09"
slug: "documents-and-finance"
status: approved
nyquist_compliant: true
wave_0_complete: true
created: "2026-10-16"
---

# Phase 9 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution. Source: `09-RESEARCH.md` § Validation Architecture.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Pest 5.3 on PHPUnit 13.3 (`pestphp/pest-plugin-laravel` 5) |
| **Config file** | `phpunit.xml` (pins `FILESYSTEM_DISK` and `MEDIA_DISK` to `local`); `tests/Pest.php` |
| **Quick run command** | `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Finance` |
| **Full suite command** | `ddev exec vendor/bin/pest` |
| **S3 group** | `ddev exec vendor/bin/pest --group=s3` |
| **Estimated runtime** | ~30 seconds per focused file |

---

## Sampling Rate

- **After every task commit:** the focused test file(s) of that task (`ddev exec vendor/bin/pest <path>`)
- **After every plan wave:** `ddev exec vendor/bin/pest tests/Feature/Documents tests/Feature/Finance tests/Isolation tests/Arch tests/Feature/Schema` plus `ddev exec composer stan`
- **Before `/gsd-verify-work`:** full suite, `composer lint`, `composer stan`, `composer check-licenses`, `scripts/check-sensitive.sh`, and the `s3` group green
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

| Req | Behavior | Test Type | Automated Command | File Exists |
|-----|----------|-----------|-------------------|-------------|
| DO-01 | Attach a file to client/project/task/comment/transaction/none | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/AttachDocumentsTest.php` | ✅ green (plan 09-02) |
| DO-01 | Partner sees only visible files, never internal-comment or other client's | isolation | `ddev exec vendor/bin/pest tests/Isolation/DocumentVisibilityTest.php` | ✅ green (plan 09-08) |
| DO-01 | Partner upload rules, cannot change flag | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/PartnerUploadTest.php` | ✅ green (plan 09-10) |
| DO-01 | Canary registry and route walk extended | isolation | `ddev exec vendor/bin/pest tests/Isolation/CanaryRegistryTest.php tests/Isolation/RouteWalkTest.php` | ✅ green (plan 09-01) |
| DO-01 | Pinned column and model lists extended | arch | `ddev exec vendor/bin/pest tests/Isolation/PartnerSafeColumnsTest.php tests/Arch` | ✅ green (plan 09-01) |
| DO-02 | Listing filters, sorting, search | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DocumentResourceTest.php` | ✅ green (plan 09-14) |
| DO-02 | Storage totals overall and per client | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/StorageTotalsTest.php` | ✅ green (plan 09-16) |
| DO-02 | Background ZIP | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DocumentArchiveTest.php` | ✅ green (plan 09-17) |
| DO-02 | Bulk delete removes rows and objects | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DeleteDocumentTest.php` | ✅ green (plan 09-07) |
| DO-03 | Signed download route and headers | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DocumentFileRouteTest.php` | ✅ green (plan 09-03) |
| DO-03 | Object removed on delete (S3) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DeleteDocumentTest.php --group=s3` | ✅ green (plan 09-07) |
| DO-03 | Upload config (temp disk on the private bucket per D-18, limits, no previews) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/UploadConfigTest.php` | ✅ green (plan 09-24) |
| DO-01 | Temporary upload held on the real bucket stored with content MIME (D-18) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/TemporaryUploadS3Test.php --group=s3` | ✅ green (plan 09-24) |
| DO-01 | Archiving hides files, restore brings them back, objects kept (D-21) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/ArchivedOwnerDocumentsTest.php tests/Feature/Documents/DocumentOwnerDeletionTest.php` | ✅ green (plan 09-21) |
| FI-01 | Transaction CRUD and DB-enforced integrity | schema | `ddev exec vendor/bin/pest tests/Feature/Schema/TransactionTablesTest.php` | ✅ green (plan 09-09) |
| FI-01 | CZK conversion rules | feature | `ddev exec vendor/bin/pest tests/Feature/Finance/TransactionCzkTest.php` | ✅ green (plan 09-22) |
| FI-01 | Category rules | feature | `ddev exec vendor/bin/pest tests/Feature/Finance/CategoryRulesTest.php` | ✅ green (plan 09-04) |
| FI-02 | Overview per month/year, category, client in CZK | feature | `ddev exec vendor/bin/pest tests/Feature/Finance/FinanceOverviewTest.php` | ✅ green (plan 09-15) |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

Every row is green in the full suite of the phase gate (plan 09-20, 4550 passed, 3 skipped, the same three as before the phase) and, for the rows that name `--group=s3`, against RustFS. The Partner leak sweep over all Phase 9 surfaces is `tests/Isolation/DocumentFinanceLeakTest.php` and the storage consistency check is `tests/Feature/Documents/DocumentStorageConsistencyTest.php` (both plan 09-20).

---

## Wave 0 Requirements

- [x] `tests/Feature/Documents/*` and `tests/Feature/Finance/*` test files listed above
- [x] `tests/Feature/Schema/DocumentsTableTest.php`, `TransactionTablesTest.php` (raw-SQL helper `tests/Support/RawSql.php`)
- [x] Canary fixtures for Document (visible, hidden, internal-comment, client-owned, unattached) in `tests/Support/CanaryRegistry.php`
- [x] Pinned lists updated: ModelDeclaration, ActivityAllowlist, PartnerSafeColumns, JobDeclaration, schedule, PanelRegistry, RouteWalk, DeniedModels
- [x] Fictional uploaded-file helper and media-disk fake helper
- [x] Mutation self-check that the Partner scope test fails when the visibility or internal-comment condition is removed

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Multi-container temp upload behaviour | DO-01 | Browser-to-bucket upload needs the production bucket (CORS, expiry rule); the DDEV bucket is not browser-reachable | Confirm in the deploy rehearsal listed in plan 09-20 Task 3 (D-18, research Open Question 1 resolved) |

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 30s
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** approved (plan 09-20, 2026-10-16). The browser-to-bucket upload path (CORS, one-day expiry, two web containers) and the human checks of plan 09-20 Task 3 stay for the end-of-phase verification and the deploy rehearsal.
