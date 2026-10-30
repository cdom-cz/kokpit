---
phase: 01
review: 01-REVIEW.md
titles: json
findings:
  - id: WR-03
    severity: warning
    disposition: open
    title: "Right-hand boundary of `company-id`, `public-ip` and `cz-account` hides real values"
  - id: WR-07
    severity: warning
    disposition: open
    title: "Planning invoice exemption hides any real company ID starting 202 or 203"
  - id: WR-10
    severity: warning
    disposition: open
    title: "The local denylist is silently inactive in the hook, and a mistyped path fails open"
  - id: WR-11
    severity: warning
    disposition: open
    title: "Commit messages are in the hygiene rule's scope but no layer scans them"
  - id: WR-12
    severity: warning
    disposition: open
    title: "Home paths in JSON-escaped, WSL and msys spellings are not reported"
  - id: WR-13
    severity: warning
    disposition: open
    title: "A VAT number (`CZ` plus the company ID) is not reported by either layer"
  - id: WR-15
    severity: warning
    disposition: open
    title: "A UTF-8 byte order mark silently disables the first denylist term"
  - id: WR-16
    severity: warning
    disposition: open
    title: "`.gitleaks.toml` is invisible to both scanning layers (a secret can be hidden in it)"
  - id: WR-17
    severity: warning
    disposition: open
    title: "The `composer.lock` allowlist entries switch two rules off for that file, and `composer.json` and `composer.lock` have no code owner"
  - id: WR-18
    severity: warning
    disposition: open
    title: "Protection of DDEV snapshots and local DDEV config depends on an untracked, self-ignoring file"
  - id: IN-01
    severity: info
    disposition: open
    title: "Tool versions are duplicated in five places with no consistency check"
  - id: IN-02
    severity: info
    disposition: open
    title: "Feature-branch pushes are not scanned and `main` runs can be cancelled"
  - id: IN-04
    severity: info
    disposition: open
    title: "No IPv6 rule"
  - id: IN-05
    severity: info
    disposition: open
    title: "`*.sql` and `*.csv` ignore rules will hide legitimate tracked files"
  - id: IN-06
    severity: info
    disposition: open
    title: "Container test helper uses an unpinned image tag"
  - id: IN-07
    severity: info
    disposition: open
    title: "A retina image name (name, at-sign, 2x, extension) is reported as an e-mail address"
  - id: IN-08
    severity: info
    disposition: open
    title: "File paths are never checked by the generic rules"
  - id: IN-09
    severity: info
    disposition: open
    title: "The gitleaks company-ID rule needs `:` or `=` after the keyword (narrowed)"
  - id: IN-10
    severity: info
    disposition: open
    title: "Remaining credential-shaped file names are not ignored"
  - id: IN-11
    severity: info
    disposition: open
    title: "Allowlist entry exists only to excuse a sample in a previous review document"
  - id: IN-12
    severity: info
    disposition: open
    title: "File mode bits of the test scripts are inconsistent"
  - id: IN-13
    severity: info
    disposition: open
    title: "Paths that git quotes are reported with the quotes and the `b/` prefix"
  - id: IN-14
    severity: info
    disposition: open
    title: "CONTRIBUTING claims everything is pinned, but several inputs are not"
  - id: WR-14
    severity: warning
    disposition: open
    title: "`.gitignore` makes the Laravel storage skeleton untrackable"
  - id: CR-01
    severity: critical
    disposition: fixed
  - id: CR-02
    severity: critical
    disposition: fixed
  - id: CR-03
    severity: critical
    disposition: fixed
  - id: WR-01
    severity: warning
    disposition: fixed
  - id: WR-02
    severity: warning
    disposition: fixed
  - id: WR-04
    severity: warning
    disposition: fixed
  - id: WR-05
    severity: warning
    disposition: fixed
  - id: WR-06
    severity: warning
    disposition: fixed
  - id: WR-08
    severity: warning
    disposition: fixed
  - id: WR-09
    severity: warning
    disposition: fixed
  - id: IN-03
    severity: info
    disposition: fixed
open: 24
total: 35
recorded: 2026-10-07T21:57:51.369Z
---

# Phase 01: Code Review Disposition

| Finding | Severity | Disposition | Source |
|---------|----------|-------------|--------|
| WR-03 | warning | open | - |
| WR-07 | warning | open | - |
| WR-10 | warning | open | - |
| WR-11 | warning | open | - |
| WR-12 | warning | open | - |
| WR-13 | warning | open | - |
| WR-15 | warning | open | - |
| WR-16 | warning | open | - |
| WR-17 | warning | open | - |
| WR-18 | warning | open | - |
| IN-01 | info | open | - |
| IN-02 | info | open | - |
| IN-04 | info | open | - |
| IN-05 | info | open | - |
| IN-06 | info | open | - |
| IN-07 | info | open | - |
| IN-08 | info | open | - |
| IN-09 | info | open | - |
| IN-10 | info | open | - |
| IN-11 | info | open | - |
| IN-12 | info | open | - |
| IN-13 | info | open | - |
| IN-14 | info | open | - |
| WR-14 | warning | open | - (not in the current review) |
| CR-01 | critical | fixed | 01-REVIEW.md; fixed by 01-07 (content-complete producers) and 01-08 (gitleaks log options, git-attributes rule, CODEOWNERS) (not in the current review) |
| CR-02 | critical | fixed | 01-REVIEW.md; fixed by 01-09 (fold on both sides) and 01-11 (CONTRIBUTING) (not in the current review) |
| CR-03 | critical | fixed | 01-REVIEW.md; fixed by 01-10 (both layers, Windows form covered) (not in the current review) |
| WR-01 | warning | fixed | 01-REVIEW.md; fixed by 01-09 (denylist scans the five exempt paths) (not in the current review) |
| WR-02 | warning | fixed | 01-REVIEW.md; fixed by 01-07 (shell history scan) and 01-08 (gitleaks log options) (not in the current review) |
| WR-04 | warning | fixed | 01-REVIEW.md; fixed by 01-10 (IC and IČ keys in gitleaks) (not in the current review) |
| WR-05 | warning | fixed | 01-REVIEW.md; fixed by 01-07 (explicit-file mode has no binary skip) (not in the current review) |
| WR-06 | warning | fixed | 01-REVIEW.md; fixed by 01-08 (CODEOWNERS) and 01-11 (checklist item); enabling code-owner review stays an owner decision, human verification item 3 (not in the current review) |
| WR-08 | warning | fixed | 01-REVIEW.md; fixed by 01-09 (locale dependency removed) (not in the current review) |
| WR-09 | warning | fixed | 01-REVIEW.md; fixed by 01-11 (credential and state patterns in .gitignore with test verdicts) (not in the current review) |
| IN-03 | info | fixed | 01-REVIEW.md; fixed by 01-10 (interval self-test in scan.awk, exit 3 and driver exit 2) (not in the current review) |

Dispositions: `open` (recorded, not yet triaged), `fixed`, `skipped`, `deferred`.
Set `deferred` by hand and put the reason in the Source cell; both are preserved. A `|` in the reason is kept as prose and escaped on the next run.
Re-running the gate keeps every row it can. A row the current review no longer reports is kept and its Source cell flagged, so a finding does not leave this record silently. ONE exception: when a finding id is REUSED by a different finding, the earlier decision cannot keep a row — the id is taken — and it is dropped. A RECORDED decision (anything but `open`) is named on the console when that happens; a row still at `open` is replaced silently, because `open` records no decision to lose.
