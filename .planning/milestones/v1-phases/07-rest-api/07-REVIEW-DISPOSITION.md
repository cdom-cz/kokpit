# Phase 07 — Review disposition

Source: 07-REVIEW.md (standard depth, 31 files changed by gap plans 07-13 to 07-18; 0 critical, 3 warning, 4 info). Advisory ledger, default disposition `open`.

The findings of the first review (also numbered WR-01 to WR-03 and IN-01 to IN-04, see git history of 07-REVIEW.md at 9f345e4) were closed by plans 07-13 to 07-18; the evidence is in the matching SUMMARY files. The ids below refer to the second review only.

| Finding | Severity | Disposition | Note |
|---------|----------|-------------|------|
| WR-01 | warning | open | Uppercase UUID next to task_id or project_id gives a false 422 inconsistent_context (TimeEntryInput, UpdateTimeEntry); lowercase in id() |
| WR-02 | warning | open | Running-entry span guard checked only at edit time; EXTRACT(EPOCH)::int in TimeEntry can overflow on later reads near the limit |
| WR-03 | warning | open | Batch where every item hits a server fault answers 422; a 5xx would not hide an outage as a client error |
| IN-01 | info | open | D modifier added only to key patterns; instant patterns still end in a bare $ |
| IN-02 | info | open | SORT_VALUES duplicates TimeEntryController::SORTS; ListLookupsRequest repeats the controller match |
| IN-03 | info | open | Prose paragraph between tags in the TimeEntryInput::instant() docblock |
| IN-04 | info | open | Endpoint descriptions omit span and year bounds; one test asserts the same Czech message twice |
