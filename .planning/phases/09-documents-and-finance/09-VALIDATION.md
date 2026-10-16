---
phase: "09"
slug: "documents-and-finance"
status: draft
nyquist_compliant: false
wave_0_complete: false
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
| DO-01 | Attach a file to client/project/task/comment/transaction/none | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/AttachDocumentsTest.php` | ❌ W0 |
| DO-01 | Partner sees only visible files, never internal-comment or other client's | isolation | `ddev exec vendor/bin/pest tests/Isolation/DocumentVisibilityTest.php` | ❌ W0 |
| DO-01 | Partner upload rules, cannot change flag | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/PartnerUploadTest.php` | ❌ W0 |
| DO-01 | Canary registry and route walk extended | isolation | `ddev exec vendor/bin/pest tests/Isolation/CanaryRegistryTest.php tests/Isolation/RouteWalkTest.php` | ✅ extend |
| DO-01 | Pinned column and model lists extended | arch | `ddev exec vendor/bin/pest tests/Isolation/PartnerSafeColumnsTest.php tests/Arch` | ✅ extend |
| DO-02 | Listing filters, sorting, search | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DocumentResourceTest.php` | ❌ W0 |
| DO-02 | Storage totals overall and per client | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/StorageTotalsTest.php` | ❌ W0 |
| DO-02 | Background ZIP | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DocumentArchiveTest.php` | ❌ W0 |
| DO-02 | Bulk delete removes rows and objects | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DeleteDocumentTest.php` | ❌ W0 |
| DO-03 | Signed download route and headers | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DocumentFileRouteTest.php` | ❌ W0 |
| DO-03 | Object removed on delete (S3) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/DeleteDocumentTest.php --group=s3` | ❌ W0 |
| DO-03 | Upload config (temp disk on the private bucket per D-18, limits, no previews) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/UploadConfigTest.php` | ❌ W0 |
| DO-01 | Temporary upload held on the real bucket stored with content MIME (D-18) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/TemporaryUploadS3Test.php --group=s3` | ❌ W0 |
| DO-01 | Archiving hides files, restore brings them back, objects kept (D-21) | feature | `ddev exec vendor/bin/pest tests/Feature/Documents/ArchivedOwnerDocumentsTest.php tests/Feature/Documents/DocumentOwnerDeletionTest.php` | ❌ W0 |
| FI-01 | Transaction CRUD and DB-enforced integrity | schema | `ddev exec vendor/bin/pest tests/Feature/Schema/TransactionTablesTest.php` | ❌ W0 |
| FI-01 | CZK conversion rules | feature | `ddev exec vendor/bin/pest tests/Feature/Finance/TransactionCzkTest.php` | ❌ W0 |
| FI-01 | Category rules | feature | `ddev exec vendor/bin/pest tests/Feature/Finance/CategoryRulesTest.php` | ❌ W0 |
| FI-02 | Overview per month/year, category, client in CZK | feature | `ddev exec vendor/bin/pest tests/Feature/Finance/FinanceOverviewTest.php` | ❌ W0 |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [ ] `tests/Feature/Documents/*` and `tests/Feature/Finance/*` test files listed above
- [ ] `tests/Feature/Schema/DocumentsTableTest.php`, `TransactionTablesTest.php` (raw-SQL helper `tests/Support/RawSql.php`)
- [ ] Canary fixtures for Document (visible, hidden, internal-comment, client-owned, unattached) in `tests/Support/CanaryRegistry.php`
- [ ] Pinned lists updated: ModelDeclaration, ActivityAllowlist, PartnerSafeColumns, JobDeclaration, schedule, PanelRegistry, RouteWalk, DeniedModels
- [ ] Fictional uploaded-file helper and media-disk fake helper
- [ ] Mutation self-check that the Partner scope test fails when the visibility or internal-comment condition is removed

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| Multi-container temp upload behaviour | DO-01 | Browser-to-bucket upload needs the production bucket (CORS, expiry rule); the DDEV bucket is not browser-reachable | Confirm in the deploy rehearsal of plan 09-20 (D-18, research Open Question 1 resolved) |

---

## Validation Sign-Off

- [ ] All tasks have `<automated>` verify or Wave 0 dependencies
- [ ] Sampling continuity: no 3 consecutive tasks without automated verify
- [ ] Wave 0 covers all MISSING references
- [ ] No watch-mode flags
- [ ] Feedback latency < 30s
- [ ] `nyquist_compliant: true` set in frontmatter

**Approval:** pending
