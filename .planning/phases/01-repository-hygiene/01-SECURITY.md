---
phase: "1"
slug: repository-hygiene
status: verified
# threats_open = count of OPEN threats at or above workflow.security_block_on severity (the blocking gate)
threats_open: 0
asvs_level: 1
block_on: high
created: "2026-10-07"
---

# Phase 1 — Security

> Per-phase security contract: threat register, accepted risks, and audit trail.
> Register built from the `<threat_model>` blocks of plans 01-01 to 01-11 (authored at plan time). Verification depth: ASVS level 1 (grep depth), block threshold `high`.

---

## Trust Boundaries

| Boundary | Description | Data Crossing |
|----------|-------------|---------------|
| Developer machine to local git | Pre-commit hook (lefthook) runs the in-house scanner and gitleaks on staged content | Staged file content, local denylist (client names), never printed |
| Local repository to GitHub | Push, pull request and CI (`Hygiene` workflow, `CI Passed` aggregator) rescan the full tree and history | Source, tests, docs, history |
| CI runner to release downloads | Pinned gitleaks, actionlint and zizmor binaries fetched by hard-coded SHA-256 | Third-party binaries |
| Owner to GitHub settings | Secret scanning, push protection, ruleset, Actions policy (owner-only, not automatable) | Repository configuration |
| Planning documents to public repository | `.planning/` stays untracked while `commit_docs` is false | Plans, summaries, reviews |

---

## Threat Register

Evidence paths are repository-relative; line numbers are as of the audit on 2026-10-07. Component names are in the PLAN files (the audit verdict did not restate them).

| Threat ID | Category | Severity | Disposition | Mitigation evidence | Status |
|-----------|----------|----------|-------------|---------------------|--------|
| T-01-01 | Information disclosure | high | mitigate | Findings masked (2 chars + length), `scripts/lib/scan.awk`; assertions in `scripts/tests/test-check-sensitive.sh` | closed |
| T-01-02 | Tampering | high | mitigate | Five exact exempt paths, `scripts/check-sensitive.sh`; test (h) | closed |
| T-01-03 | Repudiation | high | mitigate | `assert_lefthook_installed`, `scripts/install-hooks.sh`, `scripts/tests/test-lefthook.sh` (missing-tool cases) | closed |
| T-01-04 | Elevation of privilege | high | mitigate | Workflow triggers and permissions, `persist-credentials: false`, no `${{ }}` in run blocks; actionlint and zizmor clean | closed |
| T-01-05 | Tampering | high | mitigate | 40-char action pins in `.github/workflows/hygiene.yml`; pin matches upstream tag commit | closed |
| T-01-06 | Denial of service | medium | mitigate | Scanner status above 1 gives exit 2; test (l) | closed |
| T-01-07 | Tampering | medium | mitigate | `core.quotepath=off`, explicit prefixes, hunk counts in `scripts/lib/diff2tsv.awk`; tests (d), (e) | closed |
| T-01-08 | Tampering | high | mitigate | Reviewed allowlist, driver always sets `CS_ALLOWLIST`, CODEOWNERS entry (`/scripts/`); scoping tests. Plan 02-01 added two entries scoped to `composer.lock`, approved by the maintainer | closed |
| T-01-09 | Information disclosure | high | mitigate | Hit and no-hit case per rule category in `test-check-sensitive.sh`; gitleaks default ruleset extended | closed |
| T-01-10 | Information disclosure | high | mitigate | Test fakes assembled from fragments (`scripts/tests/lib.sh`); real tree and history clean in both layers | closed |
| T-01-11 | Denial of service | medium | mitigate | mawk parity run (`run-in-ubuntu.sh`); interval self-test in `scan.awk` | closed |
| T-01-12 | Tampering | low | accept | See Accepted Risks Log (AR-01) | closed (accepted) |
| T-01-13 | Information disclosure | high | mitigate | Denylist findings print only `path:line: denylist`; `test-denylist.sh`, `test-modes.sh` | closed |
| T-01-14 | Information disclosure | high | mitigate | In-repo denylist refused (`pwd -P` comparison, exit 2); `test-denylist.sh` | closed |
| T-01-15 | Tampering | medium | mitigate | Unreadable denylist gives exit 2; test (d) | closed |
| T-01-16 | Information disclosure | high | mitigate | `--history` in CI with `fetch-depth: 0`; commit suffix `@sha7`; `test-modes.sh` | closed |
| T-01-17 | Tampering | low | mitigate | Options before `--` rejected, `./` prefix for operands; `test-modes.sh` | closed |
| T-01-18 | Information disclosure | high | mitigate | `CONTRIBUTING.md` and `.claude/CLAUDE.md` use fictional values; generated blocks absent; docs test | closed |
| T-01-19 | Information disclosure | high | mitigate | `.gitignore` verdict tests (`test-gitignore.sh`), run in CI | closed |
| T-01-20 | Repudiation | medium | mitigate | Checklist in `CONTRIBUTING.md` and settings audit in `01-04-SUMMARY.md` exist; the owner enabled the three missing settings (UAT test 2) and a read-only `gh api` re-check on 2026-10-07 confirmed Actions SHA pinning required, Actions not allowed to approve pull requests, Dependabot security updates enabled, secret scanning with push protection, non-provider patterns and validity checks enabled, and an active ruleset on `main` | closed |
| T-01-21 | Tampering | medium | mitigate | Generated-block detection, CI `--all`, docs test | closed |
| T-01-22 | Elevation of privilege | low | mitigate | Precondition check recorded in `01-04-SUMMARY.md`; single worktree; `.claude/CLAUDE.md` tracked | closed |
| T-01-23 | Information disclosure | high | mitigate | gitleaks `--redact` in hook, CI and tests; leak assertions in `test-gitleaks.sh` | closed |
| T-01-24 | Tampering | high | mitigate | Hard-coded SHA-256 with `sha256sum --check --strict`; digests match upstream | closed |
| T-01-25 | Elevation of privilege | high | mitigate | Workflow-lint job gates `CI Passed`; negative control rejected by zizmor | closed |
| T-01-26 | Tampering | medium | mitigate | `--ignore-gitleaks-allow` in hook and CI; no `.gitleaksignore` | closed |
| T-01-27 | Repudiation | medium | mitigate | `CI Passed` aggregator; `main` ruleset requires it and blocks force-push and deletion | closed |
| T-01-28 | Tampering | medium | mitigate | `.github/CODEOWNERS`, `.github/dependabot.yml` (code-owner review itself is an owner decision) | closed |
| T-01-29 | Information disclosure | high | mitigate | Owner chose rewrite-untrack (`01-06-SUMMARY.md`); dropped commit unreachable; history clean | closed |
| T-01-30 | Tampering / Denial of service | high | mitigate | Preconditions, backup and old-to-new map in `01-06-SUMMARY.md`; mapped commits are ancestors of HEAD | closed |
| T-01-31 | Tampering | high | mitigate | Wave 4 alone, preconditions in `01-06-PLAN.md`; pre-rewrite hashes unreachable; no merge commits | closed |
| T-01-32 | Information disclosure | medium | mitigate | Explicit scan of all planning Markdown and `.planning/config.json` clean; `.planning/` untracked | closed |
| T-01-33 | Repudiation | medium | mitigate | During phase execution the remote held only `main` and the repository settings equalled the 01-04 audit values; the owner has since pushed the branch and changed the settings deliberately (UAT tests 1 and 2) | closed |
| T-01-34 | Information disclosure | high | mitigate | Text diffs, no textconv or renames, first-parent merges, two NUL readings; `test-content.sh` (a)-(j) | closed |
| T-01-35 | Repudiation | medium | mitigate | No binary skip in explicit-file mode; `test-modes.sh`, `test-content.sh` | closed |
| T-01-36 | Tampering | low | mitigate | Empty tree computed without writing (no `-w`); `test-content.sh` (m) | closed |
| T-01-37 | Denial of service | low | accept | See Accepted Risks Log (AR-02) | closed (accepted) |
| T-01-38 | Tampering | high | mitigate | `git-attributes` rule in `scan.awk`, CODEOWNERS entry; `test-attributes.sh` | closed |
| T-01-39 | Information disclosure | high | mitigate | CI gitleaks `--log-opts="--all --diff-merges=first-parent --text --no-textconv"`; `test-gitleaks.sh` (f)-(h) | closed |
| T-01-40 | Denial of service | medium | mitigate | History rows (`@sha`) skipped by the attribute rule; `test-attributes.sh` (i) | closed |
| T-01-41 | Tampering | medium | mitigate | CODEOWNERS covers attribute file and hygiene documents (effect depends on the owner's ruleset decision) | closed |
| T-01-42 | Information disclosure | high | mitigate | `scripts/lib/fold.awk` applied to both sides; `test-denylist.sh` (l)-(o) | closed |
| T-01-43 | Information disclosure | medium | mitigate | Unfiltered `rows.tsv` for the denylist stage; `test-denylist.sh` (p) | closed |
| T-01-44 | Repudiation | medium | mitigate | `LC_ALL=C` throughout; locale-independence tests | closed |
| T-01-45 | Tampering | medium | mitigate | Terms folding to nothing are dropped; `test-denylist.sh` (q) | closed |
| T-01-46 | Information disclosure | high | mitigate | Denylist output contains no term text; `test-denylist.sh` | closed |
| T-01-47 | Information disclosure | high | mitigate | Home-path rule in `scan.awk` and `.gitleaks.toml` (Unix and Windows forms); tests in both layers | closed |
| T-01-48 | Information disclosure | medium | mitigate | Narrowed left boundaries, case copies, ASIA keys (the right-hand boundary gap is review finding WR-03) | closed |
| T-01-49 | Denial of service | medium | mitigate | Interval self-test in `scan.awk`, driver exit 2; `test-check-sensitive.sh` (B10) | closed |
| T-01-50 | Tampering | medium | mitigate | Planning exemption narrowed to years 2020 to 2039 and one exact entry (review finding WR-07 still calls it broad) | closed |
| T-01-51 | Repudiation | low | accept | See Accepted Risks Log (AR-03) | closed (accepted) |
| T-01-52 | Information disclosure | medium | mitigate | Credential and state patterns in `.gitignore`; neighbours stay trackable; `test-gitignore.sh` | closed |
| T-01-53 | Repudiation | medium | mitigate | `CONTRIBUTING.md` matches the code; docs test with mutation loop | closed |
| T-01-54 | Repudiation | medium | mitigate | Remote unchanged, `commit_docs` false, settings unchanged, only the 01-06 rebase in the reflog | closed |
| T-01-55 | Information disclosure | medium | mitigate | Planning-documents scan clean under the current rules, including plans and summaries 01-07 to 01-11 | closed |
| T-01-SC | Tampering | high | mitigate | Dependency audit in `01-RESEARCH.md`; no package manifests were tracked at audit time (phase 02 has since added `composer.json` and `composer.lock`, covered by its own dependency job); hygiene tool pins re-verified; brew or mise install only | closed |

*Status: open · closed · open, below `high` threshold (non-blocking)*
*Severity: critical > high > medium > low. Only open threats at or above `workflow.security_block_on` count toward `threats_open`.*
*Disposition: mitigate (implementation required) · accept (documented risk) · transfer (third-party)*

### Open Threats

None. T-01-20, the only open threat (medium, below the `high` block threshold), was closed after the owner enabled the three missing repository settings; the re-check on 2026-10-07 confirmed them (see its register row).

### Review Findings Outside the Register

The 2026-10-07 re-review (`01-REVIEW.md`) keeps these open in `01-REVIEW-DISPOSITION.md`. They are not SUMMARY threat flags, so they are not counted above: WR-10 (denylist silently inactive in the hook), WR-11 (commit messages not scanned), WR-12 (JSON-escaped, WSL and msys home paths), WR-13 (CZ VAT number), WR-15 (UTF-8 BOM disables the first denylist term), IN-09 (gitleaks default path allowlist), plus the narrowed WR-03 and WR-07.

---

## Accepted Risks Log

| Risk ID | Threat Ref | Rationale | Accepted By | Date |
|---------|------------|-----------|-------------|------|
| AR-01 | T-01-12 | `ubuntu:24.04` is pulled by tag for a local developer aid only. The repository is mounted read-only (`scripts/tests/run-in-ubuntu.sh`), and CI never uses this image. Residual: image and apt packages are unpinned. Same decision as review item IN-06. | Plan-time disposition `accept` in 01-02-PLAN; logged by the orchestrator | 2026-10-07 |
| AR-02 | T-01-37 | Scanning large binary content costs about 0.2 s per MiB (measured at planning time). A scanner error still exits 2, and the CI job is capped at 10 minutes. | Plan-time disposition `accept` in 01-07-PLAN; logged by the orchestrator | 2026-10-07 |
| AR-03 | T-01-51 | More false positives could push contributors towards broad exemptions. Mitigated by documented workarounds in `CONTRIBUTING.md`, allowlist under CODEOWNERS, decision D-04 unchanged. Residual: review item IN-07 (`<name>@2x.png` flagged as an e-mail address). | Plan-time disposition `accept` in 01-10-PLAN; logged by the orchestrator | 2026-10-07 |

*Accepted risks do not resurface in future audit runs.*

---

## Security Audit Trail

| Audit Date | Threats Total | Closed | Open | Run By |
|------------|---------------|--------|------|--------|
| 2026-10-07 | 56 | 55 | 1 | gsd-security-auditor (ASVS 1, block_on high) |
| 2026-10-07 | 56 | 56 | 0 | gsd-secure-phase re-check (ASVS 1 grep depth, block_on high; read-only `gh api` for T-01-20) |

---

## Sign-Off

- [x] All threats have a disposition (mitigate / accept / transfer)
- [x] Accepted risks documented in Accepted Risks Log
- [x] `threats_open: 0` confirmed
- [x] `status: verified` set in frontmatter

**Approval:** verified 2026-10-07; re-verified 2026-10-07 at ASVS level 1 (grep depth, block threshold `high`): all 56 threats closed, T-01-20 included after the owner changed the repository settings

## Security Audit 2026-10-07

| Metric | Count |
|---|---|
| Threats found | 56 |
| Closed | 56 |
| Open | 0 |
