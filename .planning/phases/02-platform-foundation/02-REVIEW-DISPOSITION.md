---
phase: 02
review: 02-REVIEW.md
titles: json
findings:
  - id: WR-01
    severity: warning
    disposition: open
    title: "Escape-hatch scanner misses most ways around PartnerScope, yet the test claims to prove there are none"
  - id: WR-02
    severity: warning
    disposition: open
    title: "Model-declaration scan only sees `app/Domain/**/Models/`, so a model elsewhere escapes the \"no model without an isolation decision\" rule"
  - id: WR-03
    severity: warning
    disposition: open
    title: "Media library defaults to the public disk, contradicting the private-bucket constraint"
  - id: WR-04
    severity: warning
    disposition: open
    title: "Fail-closed scope silently turns package maintenance commands into no-ops"
  - id: WR-05
    severity: warning
    disposition: open
    title: "Session cookie has no `Secure` flag unless an env variable is set, and nothing enforces it in production"
  - id: WR-06
    severity: warning
    disposition: open
    title: "`ProductionConfigGuard` only runs when `APP_ENV` is exactly `production`"
  - id: WR-07
    severity: warning
    disposition: open
    title: "E-mail identity is case-sensitive in the database, while the 2FA reset command matches case-insensitively and takes the first hit"
  - id: WR-08
    severity: warning
    disposition: open
    title: "2FA reset leaves existing sessions and remember tokens valid"
  - id: WR-09
    severity: warning
    disposition: open
    title: "Redis queue connection dispatches inside open transactions"
  - id: WR-10
    severity: warning
    disposition: open
    title: "`Money::convert` accepts a zero or negative exchange rate"
  - id: IN-01
    severity: info
    disposition: open
    title: "Migrations without `down()`"
  - id: IN-02
    severity: info
    disposition: open
    title: "Redis eviction policy can silently drop queue jobs and sessions"
  - id: IN-03
    severity: info
    disposition: open
    title: "Dead and dangling configuration left over from the skeleton"
  - id: IN-04
    severity: info
    disposition: open
    title: "`Immutability` helper can only freeze a table by its own state column"
  - id: IN-05
    severity: info
    disposition: open
    title: "Partner-visible route walk covers GET routes only"
  - id: IN-06
    severity: info
    disposition: open
    title: "Hard-coded database name in a test that the harness allows to differ"
  - id: IN-07
    severity: info
    disposition: open
    title: "Broken or machine-translated Czech strings in the published action labels"
  - id: IN-08
    severity: info
    disposition: open
    title: "Smaller test-robustness and tooling notes"
open: 18
total: 18
recorded: 2026-10-07T20:36:45.980Z
---

# Phase 02: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| WR-01 | warning | open | - |
| WR-02 | warning | open | - |
| WR-03 | warning | open | - |
| WR-04 | warning | open | - |
| WR-05 | warning | open | - |
| WR-06 | warning | open | - |
| WR-07 | warning | open | - |
| WR-08 | warning | open | - |
| WR-09 | warning | open | - |
| WR-10 | warning | open | - |
| IN-01 | info | open | - |
| IN-02 | info | open | - |
| IN-03 | info | open | - |
| IN-04 | info | open | - |
| IN-05 | info | open | - |
| IN-06 | info | open | - |
| IN-07 | info | open | - |
| IN-08 | info | open | - |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved. A `|` in the reason is kept as prose and escaped on the next run.
Re-running the gate keeps every row it can. A row the current review no longer reports is kept and its Source cell flagged, so a finding does not leave this record silently. ONE exception: when a finding id is REUSED by a different finding, the earlier decision cannot keep a row — the id is taken — and it is dropped. A RECORDED decision (anything but `open`) is named on the console when that happens; a row still at `open` is replaced silently, because `open` records no decision to lose.
