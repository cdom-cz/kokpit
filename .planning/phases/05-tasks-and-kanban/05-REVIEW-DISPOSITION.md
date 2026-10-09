# Phase 05: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| CR-01 | critical | open | Deactivated assignee or requester makes the Admin edit page reject every save of that task (Select options list active people only) |
| WR-01 | warning | open | CreateTask/UpdateTask do not validate title length and date range the database constrains; unhandled QueryException (500) |
| WR-02 | warning | open | Unescaped title and actor name also reach mails to Partner recipients (widens T-05-44 / U-1 of 05-SECURITY.md) |
| WR-03 | warning | open | AddTaskComment never re-reads the task; a comment can land on a task archived after page load |
| WR-04 | warning | open | fileAttachments(false) is pinned by test on one editor only; four other editors unpinned (SECURITY claim overstated) |
| IN-01 | info | open | Task fillable includes status and priority, contradicting its own docblock |
| IN-02 | info | open | Task number is parsed out of the reference string |
| IN-03 | info | open | Duplicated building blocks (underModal, editor configs, date formats) |
| IN-04 | info | open | Four of six migrations have no down() |
| IN-05 | info | open | CONTRIBUTING hand-over notes name the wrong phases |
| IN-06 | info | open | setNewOrder leaves its static ignore-timestamps list set if it throws |
| IN-07 | info | open | Board has no non-pointer way to move a card; a drop inside Done is silently ignored |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved.
