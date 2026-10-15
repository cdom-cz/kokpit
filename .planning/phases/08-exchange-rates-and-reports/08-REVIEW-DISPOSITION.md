---
phase: 08-exchange-rates-and-reports
review: 08-REVIEW.md
recorded: 2026-10-15
---

# Phase 8 code review disposition

One row per finding of `08-REVIEW.md`. Default disposition is `open`; update a row when a finding is fixed, deferred or rejected.

| Finding | Severity | Title | Disposition |
|---------|----------|-------|-------------|
| CR-01 | critical | A client with money in two currencies crashes the report, the dashboard widget and the exports | open |
| WR-01 | warning | A report file whose record ends failed or pending is never deleted from the private disk | open |
| WR-02 | warning | A failed gap heal after a successful daily download raises a false rates alert | open |
| WR-03 | warning | A legal client and project name can make the export request fail with a database error | open |
| IN-01 | info | Leading-apostrophe neutralisation also alters legitimate text in the XLSX | open |
| IN-02 | info | An upper-case UUID in the URL filters correctly but the select shows all clients | open |
| IN-03 | info | TimeReportPage::filters() revalidates against the database on every call | open |
| IN-04 | info | Work report rows can disagree with their day subtotal | open |
| IN-05 | info | The backfill job restarts from the first year on every attempt | open |
| IN-06 | info | The holiday calendar applies Dec 24 for years before 2016 | open |
| IN-07 | info | ExchangeRateConverter has no production caller and its arithmetic is duplicated | open |
| IN-08 | info | The prune ignores the result of a failed delete on a disk that does not throw | open |
| IN-09 | info | A non-retryable validation failure in the export job is retried three times | open |
