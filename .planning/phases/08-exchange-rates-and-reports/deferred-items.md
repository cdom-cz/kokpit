# Phase 8 deferred items

Out-of-scope discoveries logged during execution. Not fixed by the plan that found them.

## Time report fails for clients in different currencies when no client is chosen

- **Found during:** plan 08-16, task 3 (the export tests that read the report without a client filter).
- **Where:** `app/Domain/Reports/TimeReport.php` line 75 (`$total->add($entry, $priced)`) into `ReportBucket::add()`, which sums the per-entry amount into the root bucket with `Money::plus()`.
- **Symptom:** `InvalidArgumentException: Money in different currencies cannot be combined.` as soon as the filtered set holds priced entries of two clients with different currencies (for example one EUR and one CZK client). The page `Report času` then fails to render for "Všichni klienti", and `TimeReport::build()` throws for any caller (the plan 08-16 tests that compare the export with the report use one currency for that reason).
- **Not caused by 08-16:** the CSV job never sums amounts across currencies; it prices each entry on its own. The bug is in the plan 08-09 aggregate.
- **Owner:** plan 08-14 already lists "a EUR client and a USD client: the summary lists a EUR and a USD line, the footer lists both amounts, the CZK total is the sum" as an acceptance case and may fix the reader or aggregate. The root bucket must not sum `amount` across currencies (only `czk` and the seconds are summed at the root; per-currency amounts are taken from the client buckets and the per-currency summary arrays).

**Resolved by plan 08-14 (task 3):** the root bucket of `TimeReport` no longer sums client-currency amounts (`ReportBucket` `sumsAmount: false` for the root); the report over a EUR, a USD and a CZK client builds, and the summary, the footer and the CZK total are pinned by `TimeReportTest` ("several currencies in one report").
