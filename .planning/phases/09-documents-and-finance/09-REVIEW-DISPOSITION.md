---
phase: 09-documents-and-finance
review: 09-REVIEW.md
---

# Phase 9 review disposition

One row per finding in 09-REVIEW.md. Default is `open`; a finding is only closed when a commit or a decision says so.

| ID | Severity | Disposition | Note |
|----|----------|-------------|------|
| CR-01 | Critical | open | `ArchiveEntryNames::unique()` throws on `a.pdf`, `a.pdf`, `a (2).pdf`; the ZIP build fails |
| WR-01 | Warning | open | Stored objects deleted inside the DB transaction; move to `DB::afterCommit()` |
| WR-02 | Warning | open | ZIP of a failed archive is never pruned; delete `storagePath()` always |
| IN-01 | Info | open | Zero-byte upload gets a misleading message |
| IN-02 | Info | open | Served files carry no CSP / X-Frame-Options |
| IN-03 | Info | open | ZIP entry names keep Windows-unsafe characters |
| IN-04 | Info | open | Showing a file to the client exposes its description |
| IN-05 | Info | open | Transaction delete action catches only `RuntimeException` |
| IN-06 | Info | open | Upload side effects not compensated on outer rollback |
| IN-07 | Info | open | Direct bucket uploads have no byte cap |
| IN-08 | Info | open | Duplicated constants and mixed clock sources |
| IN-09 | Info | open | Stale comment and a category position race |
