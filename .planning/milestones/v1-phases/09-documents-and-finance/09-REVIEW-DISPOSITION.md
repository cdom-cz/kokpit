---
phase: 09-documents-and-finance
review: 09-REVIEW.md
---

# Phase 9 review disposition

One row per finding in 09-REVIEW.md. Default is `open`; a finding is only closed when a commit or a decision says so.

| ID | Severity | Disposition | Note |
|----|----------|-------------|------|
| CR-01 | Critical | closed | Fixed in plan 09-25 (commits b6d4fc9, 3719a52): `ArchiveEntryNames::unique()` is total for every order of names, a name equal to a generated one is numbered from it. Proven by the CR-01 cases of `DocumentArchiveTest.php` (the queued ZIP of `a.pdf`, `a.pdf`, `a (2).pdf` is built) and the permutation case of `ArchiveEntryNamesTest.php`. |
| WR-01 | Warning | closed | Fixed in plan 09-27 (commits cc293c3, 7e636be, da86cf6) and proven across the stores in plan 09-28 Task 1 (commit a67c124): rows are deleted in the database transaction, objects only after the outermost commit (`DB::afterCommit()`), and an object the disk refuses is queued to `RemoveDocumentObjects`. Proven by the WR-01 cases of `DeleteDocumentTest.php`, `RemoveDocumentObjectsTest.php`, `TransactionReceiptsTest.php` and `DocumentStorageConsistencyTest.php`. Deviation from D-09 accepted by the user (D-23): the DO-03 guarantee is removed after commit, retried 3 times by `RemoveDocumentObjects`, then the failed-job alert reaches the Admin. |
| WR-02 | Warning | closed | Fixed in plan 09-26 (commits 68ee519, f55476a): `PruneExpiredDocumentArchives` deletes `storagePath()` of every expired record whatever its status, and `BuildDocumentArchive` removes the ZIP of a record that failed or vanished during the build after the commit, never the ZIP of a ready record. Proven by `DocumentArchivePruneTest.php` and `DocumentArchiveCleanupTest.php`. |
| IN-01 | Info | open | Zero-byte upload gets a misleading message |
| IN-02 | Info | open | Served files carry no CSP / X-Frame-Options |
| IN-03 | Info | open | ZIP entry names keep Windows-unsafe characters |
| IN-04 | Info | open | Showing a file to the client exposes its description |
| IN-05 | Info | open | Transaction delete action catches only `RuntimeException` |
| IN-06 | Info | open | Upload side effects not compensated on outer rollback |
| IN-07 | Info | open | Direct bucket uploads have no byte cap |
| IN-08 | Info | open | Duplicated constants and mixed clock sources |
| IN-09 | Info | open | Stale comment and a category position race |
