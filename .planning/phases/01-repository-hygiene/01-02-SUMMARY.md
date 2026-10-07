---
phase: 01-repository-hygiene
plan: 02
subsystem: infra
tags: [bash, awk, mawk, secret-scanning, allowlist, iban]

requires:
  - phase: 01-repository-hygiene (plan 01)
    provides: scan.awk core (mask, allowed, hit, scan_re, allowlist loader, key-prefix), lib.sh fake_* builders, installed lefthook pre-commit hook
provides:
  - All D-02 rule families in scripts/lib/scan.awk (email, company-id, iban, cz-account, public-ip, hosting-host, home-path plus existing key-prefix)
  - scripts/sensitive-allowlist.txt (rule ;; path-ERE ;; matched-value-ERE) with three reviewed entries
  - scripts/tests/run-in-ubuntu.sh (quick suite in ubuntu:24.04, mawk and GNU grep, read-only mount, exit 2 without Docker)
  - Hit and no-hit test cases for every rule family (124 assertions in test-check-sensitive.sh)
affects: [01-03, 01-05, 01-06, phase-02-ci]

plan_head_before: 37e5c71294131877ab20fcdd2260081a3239e39c
plan_head_after: 1ec38368d02cf6250eb22fbe04522ae9b777f420

actuals:
  tokens: 5900
  tasks: 3
  commits: 5

tech-stack:
  added: []
  patterns:
    - "Per-rule acceptance logic in handle(); regex cores stay simple, shape and checksum validation in awk code"
    - "Path-scoped, value-scoped allowlist entries; the third field matches the matched value, not the line"
    - "Per-rule test helpers (case_hit, case_pass, path_hit, path_pass) over one temp repository per case"

key-files:
  created:
    - scripts/sensitive-allowlist.txt
    - scripts/tests/run-in-ubuntu.sh
  modified:
    - scripts/lib/scan.awk
    - scripts/tests/test-check-sensitive.sh

key-decisions:
  - "company-id exemptions are path-scoped (^\\.planning/ with ^20[0-9]{6}$) so the same invoice-like number in code still fails; placeholders 12345678 and 00000000 are the only global exemption"
  - "run-in-ubuntu.sh installs git with apt-get inside a throwaway container only; the host repository is mounted read-only so the container can never write to it"

requirements-completed: [HYG-02]

coverage:
  - id: D1
    description: "A staged non-example e-mail fails as email with file and line and masked value; example.com/org/net, .test, .invalid, .localhost and localhost pass; the git@github.com SSH notation passes while another user at github.com fails"
    requirement: HYG-02
    verification:
      - kind: unit
        ref: "scripts/tests/test-check-sensitive.sh#(m)-(o)"
        status: pass
    human_judgment: false
  - id: D2
    description: "8-digit company IDs, checksum-valid IBANs (also in 4-character groups) and Czech account numbers fail; placeholders, invoice examples under .planning/, UUID-like, 9-digit, dotted-version, wrong-checksum and month/year strings pass"
    requirement: HYG-02
    verification:
      - kind: unit
        ref: "scripts/tests/test-check-sensitive.sh#(p)-(u)"
        status: pass
    human_judgment: false
  - id: D3
    description: "Public IPv4 addresses, Zerops and six S3-provider hostnames and personal home paths fail; private, loopback, link-local, documentation, CGNAT, multicast addresses and placeholder home names pass"
    requirement: HYG-02
    verification:
      - kind: unit
        ref: "scripts/tests/test-check-sensitive.sh#(v)-(z)"
        status: pass
    human_judgment: false
  - id: D4
    description: "The suite behaves identically under /bin/bash 3.2 with BWK awk, Homebrew bash, and ubuntu:24.04 with mawk 1.3.4 and GNU grep; on this repository --all reports only the known STACK.md home-path finding"
    requirement: HYG-02
    verification:
      - kind: integration
        ref: "bash scripts/tests/run.sh --quick; /bin/bash scripts/tests/run.sh --quick; bash scripts/tests/run-in-ubuntu.sh"
        status: pass
    human_judgment: false

duration: 7min
completed: 2026-10-06
status: complete
---

# Phase 1 Plan 02: Generic Sensitive-Content Rule Families Summary

**All D-02 rule families (e-mail, company ID, IBAN with mod-97, Czech account, public IPv4, hosting hostnames, home paths) in the awk scanner with a narrow rule+path+value allowlist, proven on bash 3.2, BWK awk and mawk in ubuntu:24.04**

## Performance

- **Duration:** 7 min
- **Started:** 2026-10-06T22:21:00Z
- **Completed:** 2026-10-06T22:28:43Z
- **Tasks:** 3 (Task 1 tracer, Tasks 2 and 3 TDD)
- **Files modified:** 4 (2 created, 2 modified)

## Accomplishments

- A staged non-example e-mail now fails as `path:line: email [xx*** (N chars)]`. The local part and domain are never printed. Example and reserved domains pass. The single reviewed allowlist entry covers the SSH notation `git@github.com`, while any other user at that domain still fails.
- Company IDs (8 digits), IBANs (exact country length, mod 97 digit by digit, also in 4-character groups) and Czech account numbers (with or without prefix) fail. UUID-like tokens, 9-digit runs, dotted version strings, wrong-checksum IBANs, random uppercase identifiers and month/year strings do not.
- Public IPv4 addresses, Zerops and S3-provider hostnames (amazonaws.com, r2.cloudflarestorage.com, backblazeb2.com, wasabisys.com, digitaloceanspaces.com, linodeobjects.com) and `/Users/<name>/` or `/home/<name>/` paths fail. Private, loopback, link-local, documentation, CGNAT, benchmarking and multicast ranges and placeholder home names pass.
- `scripts/sensitive-allowlist.txt` documents D-04 and D-05 in its header and carries three entries: `git@github.com` (email), the placeholder IDs `12345678` and `00000000` (everywhere) and `20YYNNNN` invoice examples (only under `^\.planning/`). A test proves the path scope does not leak: the same number under `src/` still fails.
- `scripts/tests/run-in-ubuntu.sh` runs the quick suite in `ubuntu:24.04` with the repository mounted `:ro`.

## Task Commits

1. **Task 1: Tracer - email rule, allowlist, run-in-ubuntu.sh** - `74e41b4` (feat)
2. **Task 2 RED: failing tests for company-id, iban, cz-account** - `bbbb632` (test)
3. **Task 2 GREEN: company-id, iban, cz-account rules and allowlist entries** - `33b5881` (feat)
4. **Task 3 RED: failing tests for public-ip, hosting-host, home-path** - `5aca8c9` (test)
5. **Task 3 GREEN: public-ip, hosting-host, home-path rules** - `1ec3836` (feat)

**Plan metadata:** not committed (`commit_docs: false`, `.planning/` stays untracked).

## Verification Evidence

- `bash scripts/tests/run.sh` PASS 2 FAIL 0 SKIP 0 (full suite including the lefthook test); `--quick` under `bash` and `/bin/bash` 3.2: PASS 1 FAIL 0 SKIP 1. `test-check-sensitive.sh` has 124 assertions, none failing.
- `bash scripts/tests/run-in-ubuntu.sh` exit 0. Evidence line: `mawk 1.3.4 20240123`. Summary line: `PASS 1 FAIL 0 SKIP 1`. No `panic`, `REcompile` or `FAIL` lines.
- `scripts/check-sensitive.sh --all` on this repository exits 1 with exactly one finding: `.planning/codebase/STACK.md:50: home-path [/U*** (16 chars)]`. This is the known pre-existing finding that plan 01-06 resolves. The file was not touched. The plan's exact-match verify command printed `VERIFY-OK`.
- `shellcheck scripts/tests/*.sh scripts/*.sh` clean. Every rule name appears in `scan.awk`; `"hosting-host"` appears twice (two short suffix literals).
- Every commit of this plan went through the live lefthook pre-commit hook (`sensitive-content` passed on each); no `--no-verify`.

## TDD Notes (Tasks 2 and 3)

- **RED (Task 2):** `bash scripts/tests/test-check-sensitive.sh` before the rules existed: 12 failures, all assertions on the planned behaviour (staged company ID, IBAN, grouped IBAN and account numbers exited 0 instead of 1; the `src/` invoice case exited 0 instead of 1). Placeholder and negative cases already passed, as expected. Setup (temp repository, staging) succeeded, so these are assertion failures and not fixture crashes (semantic assessment: valid RED).
- **RED (Task 3):** 20 failures, all on the planned hit cases (public IP, Zerops host, six S3-provider hosts, `/Users/` and `/home/` paths exiting 0 instead of 1). Allowed-range and placeholder cases passed. Valid RED by the same assessment.
- **GREEN:** both rule sets passed on the first run after implementation (82 then 124 assertions green); no REFACTOR commit was needed.
- The plain-Bash harness emits no TAP or JUnit report, so `gsd_run check tdd-red-evidence` was not applicable (same as plan 01-01); `workflow.tdd_mode` is not enabled.
- Gate commits: `test(01-02)` before `feat(01-02)` for each of Tasks 2 and 3; no gate violation.

## Decisions Made

- Allowlist exemptions stay at one rule + path-ERE + value-ERE per entry. No directory exclusion, inline ignore comment or override variable was added (D-04, D-05; threat T-01-08).
- Fixtures are assembled from fragments (or are themselves allowed values such as `10.1.2.3`, `/home/runner/`). `--all` over the repository, which includes `scripts/tests/`, stays clean, which proves no fixture line matches a rule (T-01-10).

## Deviations from Plan

### Auto-fixed Issues

None needed beyond the plan. Scope notes:

**1. [Plan shape] Tasks 2 and 3 produced two commits each** - both are `tdd="true"`, so each follows RED (`test(01-02)`) then GREEN (`feat(01-02)`) as in plan 01-01. The plan's wording of a single commit per task was superseded by the TDD protocol.

**2. [Extra tests] Cases beyond the plan list** - a masking assertion per rule (full value, local part, user name and hostname absent from output), `.localhost` and `.example` e-mail hosts, an `/home/<name>/` hit, and a 4-character-group IBAN case written with `sed` at runtime.

**3. [Helper refactor] `case_hit`/`case_pass` rebuilt on `path_hit`/`path_pass`** - Task 2 needed path-scoped cases, so the Task 1 helpers were generalised in the Task 2 RED commit (no behaviour change for the e-mail cases).

---

**Total deviations:** 0 auto-fixed, 3 scope or shape notes
**Impact on plan:** None; every change serves the plan's own truths.

## Issues Encountered

None.

## Threat Flags

None. No new network endpoints, auth paths or trust boundaries. `run-in-ubuntu.sh` pulls the official `ubuntu:24.04` image and installs `git` inside a throwaway container (accepted as T-01-12; no host package installs, T-01-SC).

## Known Stubs

None.

## Next Phase Readiness

- Plan 01-03 and later plans can rely on the full D-02 rule set. Tracked `.planning/codebase/STACK.md` line 50 is the only `--all` finding and is handed to plan 01-06 behind the owner decision.
- Plan 01-05 adds CODEOWNERS coverage for `scripts/sensitive-allowlist.txt` and layers gitleaks on top. Instance-specific denylist rules (owner values) remain a separate concern from these generic rules.
- `.planning/` is untracked, so `--all` does not scan it; the `^\.planning/` allowlist entry only matters once planning docs are tracked.

## Self-Check: PASSED

- Files present: `scripts/lib/scan.awk`, `scripts/sensitive-allowlist.txt`, `scripts/tests/test-check-sensitive.sh`, `scripts/tests/run-in-ubuntu.sh` (executable).
- Commits present on the branch: `74e41b4`, `bbbb632`, `33b5881`, `5aca8c9`, `1ec3836`.
- Measured commit count from the ledger (`37e5c71..HEAD`): 5.

---
*Phase: 01-repository-hygiene*
*Completed: 2026-10-06*
