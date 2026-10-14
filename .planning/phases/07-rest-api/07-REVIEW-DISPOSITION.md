# Phase 07 — Review disposition

Source: 07-REVIEW.md (standard depth, 68 files; 0 critical, 3 warning, 4 info). Advisory ledger, default disposition `open`.

| Finding | Severity | Disposition | Note |
|---------|----------|-------------|------|
| WR-01 | warning | open | PATCH of started_at alone to a far-past instant gives 500 (integer overflow); same threat as T-07-26 in 07-SECURITY.md |
| WR-02 | warning | open | Instants outside UTC years 1 to 9999 give 500 (create, update, filter[from]); a bad batch item can leave earlier items committed |
| WR-03 | warning | open | Malformed or wrong-typed cursor gives 500; the sample cursor in ApiExamples is itself malformed |
| IN-01 | info | open | CONTRIBUTING.md ordering rule needs an exception for lookup lists |
| IN-02 | info | open | Scribe intro hard-codes limits that live in config/kokpit.php |
| IN-03 | info | open | PROJECT_KEY and TASK_REFERENCE regexes should end in \z |
| IN-04 | info | open | Docs tests regenerate the real docs output with a placeholder host |
