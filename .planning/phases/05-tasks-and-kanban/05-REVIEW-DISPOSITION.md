---
phase: 05
review: 05-REVIEW.md
titles: json
findings:
  - id: WR-01
    severity: warning
    disposition: open
    title: "Markdown backslash escapes show literally in the plain-text alternative of every task mail"
  - id: IN-01
    severity: info
    disposition: open
    title: "The excerpt line is documented as a quote but renders as literal `&gt;` text"
  - id: IN-02
    severity: info
    disposition: open
    title: "`TaskChangedNotification` with an empty `changedLabels` leaks a raw translation key into the bell"
  - id: IN-03
    severity: info
    disposition: open
    title: "Two silent edge cases in the escaping helpers"
  - id: IN-04
    severity: info
    disposition: open
    title: "A kept person with no resolvable name gets an empty option label"
  - id: IN-05
    severity: info
    disposition: open
    title: "The `CreateTask` class docblock was edited into over-long, hard-to-read lines"
  - id: CR-01
    severity: critical
    disposition: fixed
  - id: WR-02
    severity: warning
    disposition: fixed
  - id: WR-03
    severity: warning
    disposition: open
  - id: WR-04
    severity: warning
    disposition: open
  - id: IN-06
    severity: info
    disposition: open
  - id: IN-07
    severity: info
    disposition: open
open: 10
total: 12
recorded: 2026-10-09T06:07:33.948Z
---

# Phase 05: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| WR-01 | warning | open | - |
| IN-01 | info | open | - |
| IN-02 | info | open | - |
| IN-03 | info | open | - |
| IN-04 | info | open | - |
| IN-05 | info | open | - |
| CR-01 | critical | fixed | Fixed by plan 05-18 (commits cfae73c, 9d6c8a5; TaskResource::peopleOptions keeps the stored person). Deactivated assignee or requester makes the Admin edit page reject every save of that task (Select options list active people only) (not in the current review) |
| WR-02 | warning | fixed | Fixed by plan 05-19 (commits fc767ca, 2c3c63b; escaping in the TaskNotification base class). Unescaped title and actor name also reach mails to Partner recipients (widens T-05-44 / U-1 of 05-SECURITY.md) (not in the current review) |
| WR-03 | warning | open | AddTaskComment never re-reads the task; a comment can land on a task archived after page load (not in the current review) |
| WR-04 | warning | open | fileAttachments(false) is pinned by test on one editor only; four other editors unpinned (SECURITY claim overstated) (not in the current review) |
| IN-06 | info | open | setNewOrder leaves its static ignore-timestamps list set if it throws (not in the current review) |
| IN-07 | info | open | Board has no non-pointer way to move a card; a drop inside Done is silently ignored (not in the current review) |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved. A `|` in the reason is kept as prose and escaped on the next run.
Re-running the gate keeps every row it can. A row the current review no longer reports is kept and its Source cell flagged, so a finding does not leave this record silently. ONE exception: when a finding id is REUSED by a different finding, the earlier decision cannot keep a row — the id is taken — and it is dropped. A RECORDED decision (anything but `open`) is named on the console when that happens; a row still at `open` is replaced silently, because `open` records no decision to lose.
