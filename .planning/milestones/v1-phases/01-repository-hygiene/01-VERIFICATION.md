---
phase: 01-repository-hygiene
verified: 2026-10-07T22:00:00Z
status: passed
score: 8/8 must-haves verified
covered_files:
  - ".claude/CLAUDE.md"
  - ".gitattributes"
  - ".github/CODEOWNERS"
  - ".github/dependabot.yml"
  - ".github/workflows/hygiene.yml"
  - ".gitignore"
  - ".gitleaks.toml"
  - ".planning/phases/01-repository-hygiene/01-01-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-01-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-02-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-02-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-03-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-03-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-04-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-04-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-05-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-05-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-06-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-06-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-07-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-07-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-08-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-08-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-09-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-09-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-10-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-10-SUMMARY.md"
  - ".planning/phases/01-repository-hygiene/01-11-PLAN.md"
  - ".planning/phases/01-repository-hygiene/01-11-SUMMARY.md"
  - "CONTRIBUTING.md"
  - "lefthook.yml"
  - "scripts/check-sensitive.sh"
  - "scripts/install-hooks.sh"
  - "scripts/lib/diff2tsv.awk"
  - "scripts/lib/fold.awk"
  - "scripts/lib/scan.awk"
  - "scripts/sensitive-allowlist.txt"
  - "scripts/tests/lib.sh"
  - "scripts/tests/run-in-ubuntu.sh"
  - "scripts/tests/run.sh"
  - "scripts/tests/test-attributes.sh"
  - "scripts/tests/test-check-sensitive.sh"
  - "scripts/tests/test-content.sh"
  - "scripts/tests/test-denylist.sh"
  - "scripts/tests/test-docs.sh"
  - "scripts/tests/test-gitignore.sh"
  - "scripts/tests/test-gitleaks.sh"
  - "scripts/tests/test-lefthook.sh"
  - "scripts/tests/test-modes.sh"
  - "scripts/tests/test-workflow.sh"
covered_digest: "v3:sha256:2b77b53de0102b53ef5d3f181d0f58451f03edee30c6c34a28a92c2eb212376c"
behavior_unverified: 0
overrides_applied: 0
re_verification:
  previous_status: human_needed
  previous_score: 7/8
  gaps_closed:
    - "SC5 enablement half (secret scanning and push protection enabled): closed by UAT tests 1-4 (all pass) and independently re-read through gh api in this run"
    - "Human items 1-4 of the previous report (first CI run, GitHub settings, owner decisions, real denylist run): all resolved in 01-UAT.md"
  gaps_remaining: []
  regressions: []
---

# Phase 1: Repository Hygiene Verification Report

**Phase Goal:** Nothing sensitive can enter the public repository, and the tooling that guarantees it exists before any application code or planning docs are committed
**Verified:** 2026-10-07T22:00:00Z
**Status:** passed
**Re-verification:** Yes. The previous report (human_needed, 7/8) went stale because phase 02 changed covered files (allowlist, CONTRIBUTING.md, .gitignore, .gitattributes, the CI workflow, a new workflow test). This run re-checked every truth against the current tree rather than relying on the previous report, SUMMARY files or UAT notes.

## Summary Verdict

The goal holds in the current tree, including after the phase 02 changes. I re-ran the real scripts, the real `.gitleaks.toml` and the real lefthook configuration, and rebuilt the key scenarios in a scratch repository outside the checkout (fakes assembled from fragments). Nothing regressed. The only item that was UNCERTAIN last time (SC5, enablement of GitHub secret scanning and push protection) is now evidenced: UAT 4/4 passed, and I read the live repository settings through `gh api` (secret scanning, push protection and non-provider patterns `enabled`; `sha_pinning_required: true`).

The phase 02 edits touched the guarantee surface in three places, and I checked each:

- `scripts/sensitive-allowlist.txt` gained two `composer.lock`-scoped entries (`email`, `public-ip`) and a narrowed `.planning/` entry. They are path-scoped to one generated file and approved at a recorded checkpoint. The same values elsewhere still fail (verified: an e-mail in `composer.json` is reported). Residual note below.
- `.github/workflows/hygiene.yml` gained `tests`, `static-analysis` and `dependencies` jobs. The `scan` job is unchanged (self-tests, `--all`, `--history`, gitleaks with the exact `--log-opts`), and `ci-passed` still carries the exact name `CI Passed`, `if: always()`, and `needs` listing all five other jobs. A new `test-workflow.sh` asserts this and mutation-checks it (7 assertions pass).
- `.gitignore` gained Laravel entries; all sensitive classes remain ignored (checked below) and `.claude/CLAUDE.md` stays trackable.

## Goal Achievement

### Observable Truths

| # | Truth | Status | Evidence |
|---|-------|--------|----------|
| 1 | SC1: staging a non-example e-mail, 8-digit company-ID-like number, IBAN or account number, public IP, hosting hostname or key prefix makes `scripts/check-sensitive.sh` fail naming file and line; a clean staged change passes | VERIFIED | Scratch repo, copied real scripts: staged key prefix -> `a.txt:1: key-prefix [sk*** (32 chars)]` rc 1; staged non-example e-mail -> `b.txt:1: email` rc 1; public IP -> `d.txt:1: public-ip` rc 1; a line with `jane@example.com`, `12345678`, `/Users/example/x` -> rc 0. Value is masked in every finding. Company-ID, IBAN, account and hosting-host rules are exercised by `test-check-sensitive.sh` inside `run.sh`, which passes (PASS 10 FAIL 0) |
| 2 | SC2: with `KOKPIT_DENYLIST` pointing outside the repository, listed terms block; without it (CI) the script runs generic-only | VERIFIED | Term file outside the repo, a line containing the term written with diacritics: `f.txt:1: denylist` rc 1, term not printed. Without the variable: `KOKPIT_DENYLIST not set; generic patterns only` and the run proceeds (observed on the real tree: `clean (all)` rc 0). `test-denylist.sh` passes in `run.sh` |
| 3 | SC3: after `scripts/install-hooks.sh`, committing a staged secret is rejected by the lefthook pre-commit hook (sensitive-content plus gitleaks); env files, local AI/IDE settings, storage, logs, dumps, exports and the local-data dir stay untracked | VERIFIED | `.git/hooks/pre-commit` present and executable; `lefthook validate` "All good"; `test-lefthook.sh` (real hook, real commits) passes in `run.sh`. `git check-ignore --no-index` reports ignored: local settings file, `storage/app/x`, `storage/logs/laravel.log`, `dump.sql`, `data.csv`, `app.log`, `.cursor/`, `.idea/`, `auth.json`, `*.pem`, `id_rsa`, `local/`, `exports/`; `.claude/CLAUDE.md` is not ignored. `git ls-files` shows only `.env.example` as an env file and no dump, csv, log, pem or settings file. The tracked `storage/**/.gitignore` files are the stock Laravel placeholders (scanner clean) |
| 4 | SC4: the CI step scanning full history with `.gitleaks.toml` plus the same sensitive-content checks fails on a planted fake secret and passes on a clean branch | VERIFIED | `hygiene.yml` `scan` job runs `run.sh`, `--all`, `--history` and gitleaks with `--log-opts="--all --diff-merges=first-parent --text --no-textconv"`. Locally: `--all` rc 0, `--history` rc 0, the same gitleaks command "81 commits scanned, no leaks found". Planted-fake cases (history-only secret, other branch, NUL-only and merge-only commits) are asserted by `test-modes.sh` / `test-gitleaks.sh` / `test-content.sh` (all pass). UAT 1: the workflow ran on GitHub (PR #1 run 37642214531): both original jobs and `CI Passed` green, gitleaks 42 commits, no leaks |
| 5 | SC5: CLAUDE.md and CONTRIBUTING state the fictional-data-only rule and the review procedure; GitHub secret scanning with push protection is enabled and documented | VERIFIED | Both documents contain "Fictional data only" and the numbered procedure (`git status`, `git diff --staged`, `scripts/check-sensitive.sh`); `test-docs.sh` passes (it gained 11 lines in phase 02 and still passes). CONTRIBUTING documents the settings checklist (push protection at line 194). Enablement read live: `gh api` returns `secret_scanning: enabled`, `secret_scanning_push_protection: enabled`, `secret_scanning_non_provider_patterns: enabled`, Actions `sha_pinning_required: true`. UAT test 2 records the ruleset requiring `CI Passed` |
| 6 | Derived (plan 01-07): the scanner sees every added, tracked and historical line regardless of git attributes, NUL bytes, encoding, textconv, type change or merge | VERIFIED (no regression) | Producer flags and `check_attributes` unchanged since the last verification (`git diff` shows no change to `check-sensitive.sh` or `scripts/lib/*`); `test-attributes.sh` and `test-content.sh` pass. The phase 02 `.gitattributes` addition passes the scanner (`--all` clean) |
| 7 | Derived (plan 01-03/01-09): denylist matches with Czech diacritics folded | VERIFIED (no regression) | Reproduced here: denylist `Fiktivni Klient` (ASCII) blocks `Fiktivní Klient` in a staged file, rc 1. `fold.awk` unchanged; `test-denylist.sh` passes |
| 8 | Derived (plan 01-02/01-10): personal home paths are reported as `home-path` | VERIFIED (no regression) | `scan.awk` and `.gitleaks.toml` rules unchanged since the last verification (25 paired probes then); `test-check-sensitive.sh` and `test-gitleaks.sh` pass. The `/Users/example/x` placeholder stays quiet (reproduced) |

**Score:** 8/8 truths verified (0 present, behavior-unverified).

### Deferred Items

None. The residual warnings below are not must-have failures and are not covered by a later roadmap phase's goal; they are listed as follow-up candidates only.

### Required Artifacts

| Artifact | Expected | Status | Details |
|----------|----------|--------|---------|
| `scripts/check-sensitive.sh`, `scripts/lib/{scan,diff2tsv,fold}.awk` | scanner: staged, `--all`, `--history`, files, denylist | VERIFIED (exists, substantive, wired) | Unchanged since last verification; invoked by `lefthook.yml` and `hygiene.yml`; all modes run here |
| `scripts/sensitive-allowlist.txt` | narrow reviewed exemptions | VERIFIED | Six live entries (two new path-scoped to `composer.lock`, one narrowed `.planning/`); scoping reproduced |
| `lefthook.yml`, `scripts/install-hooks.sh` | fail-closed pre-commit, installer | VERIFIED | Hook installed in the checkout; `lefthook validate` OK; real-commit test passes |
| `.gitleaks.toml` | default ruleset plus Kokpit rules | VERIFIED | Real history scan with the CI options: 81 commits, no leaks |
| `.github/workflows/hygiene.yml` | pinned scan, workflow lint, `CI Passed` aggregator | VERIFIED | Ran on GitHub (UAT 1). Now 6 jobs; `test-workflow.sh` proves every job is in `ci-passed.needs` and the name is exact |
| `.github/CODEOWNERS`, `dependabot.yml` | ownership and pin upkeep | VERIFIED (exists) | Code-owner review requirement deliberately off (recorded owner decision, UAT 3) |
| `.gitignore` | negation-safe `.claude/*`, credential, state, storage, log patterns | VERIFIED | 13 sensitive-class paths probed, all ignored; `.claude/CLAUDE.md` trackable |
| `CONTRIBUTING.md`, `.claude/CLAUDE.md` | rule, procedure, checklist | VERIFIED | Required phrases present; `test-docs.sh` passes |
| `scripts/tests/*` | self-tests with fakes built from fragments | VERIFIED | `bash scripts/tests/run.sh`: PASS 10 FAIL 0 SKIP 0 (includes the new `test-workflow.sh`); working tree unchanged by the run |

### Key Link Verification

| From | To | Via | Status | Details |
|------|----|-----|--------|---------|
| `lefthook.yml` | scanner and gitleaks | jobs `sensitive-content`, `gitleaks` | WIRED | Hook installed; `test-lefthook.sh` rejects real commits |
| `hygiene.yml` `scan` | `run.sh`, scanner `--all`/`--history`, gitleaks | step commands | WIRED | Command strings read in the workflow; identical `--log-opts` asserted by `test-gitleaks.sh`; same command run locally |
| `hygiene.yml` `ci-passed` | all five gated jobs | `needs`, `always()`, success-only loop | WIRED | Asserted with mutation checks by `test-workflow.sh`; green on the GitHub run (UAT 1) |
| scanner denylist stage | `fold.awk` | same fold on terms and text | WIRED | Diacritic match reproduced |
| `scan.awk` home-path | `.gitleaks.toml` home-path rules | shared boundaries | WIRED | Unchanged; paired tests pass |
| `sensitive-allowlist.txt` | scanner | exact-path read, value-scoped ERE | WIRED | Out-of-scope e-mail in `composer.json` is still reported |

### Data-Flow Trace (Level 4)

Not applicable: the phase produces command-line tooling and configuration, no rendered dynamic data. The equivalent trace (git output to rows to rules to findings) is covered by the behavioural probes.

### Behavioral Spot-Checks

| Behavior | Command | Result | Status |
|----------|---------|--------|--------|
| Full self-test suite | `bash scripts/tests/run.sh` | PASS 10 FAIL 0 SKIP 0 | PASS |
| Real tree | `scripts/check-sensitive.sh --all` | clean, rc 0 | PASS |
| Real history | `scripts/check-sensitive.sh --history` | clean, rc 0 | PASS |
| Staged (empty) | `scripts/check-sensitive.sh` | clean, rc 0 | PASS |
| Planning docs and CLAUDE.md as explicit operands | `scripts/check-sensitive.sh .planning/... .claude/CLAUDE.md` | clean (files) | PASS |
| CI-form gitleaks over real history | `gitleaks git --config .gitleaks.toml ... --log-opts="--all --diff-merges=first-parent --text --no-textconv" .` | 81 commits, no leaks | PASS |
| Hook config | `lefthook validate` | All good | PASS |
| Scratch repo: key prefix, e-mail, public IP, clean line, e-mail outside `composer.lock`, diacritic denylist | real scripts copied to a throwaway repo | rc 1, 1, 1, 0, 1, 1 as expected | PASS |
| Ignore verdicts | `git check-ignore --no-index` over 13 paths | all ignored except `.claude/CLAUDE.md` | PASS |
| GitHub settings | `gh api repos/<repo>` and `.../actions/permissions` | secret scanning, push protection, non-provider patterns enabled; SHA pinning required | PASS |

### Probe Execution

The phase declares no `probe-*.sh` scripts. Its probes are the self-test suites, run directly above.

### Requirements Coverage

All seven IDs appear in the plan frontmatter (HYG-01 to HYG-07 across plans 01-01 to 01-11) and in REQUIREMENTS.md, where all are marked complete and traced to Phase 1. No orphaned requirement is mapped to Phase 1.

| Requirement | Source Plan | Description | Status | Evidence |
|-------------|-------------|-------------|--------|----------|
| HYG-01 | 01-01, 01-04, 01-11 | `.gitignore` for env, local AI/IDE settings, storage, logs, dumps, exports, local-data dir, reviewed per phase | SATISFIED | Truth 3; `test-gitignore.sh`; per-phase review section in CONTRIBUTING |
| HYG-02 | 01-01, 01-02, 01-07, 01-10 | `check-sensitive.sh` fails on e-mails, company IDs, IBAN/accounts, public IPs, hosting hosts, key prefixes in staged files | SATISFIED | Truths 1, 6 |
| HYG-03 | 01-03, 01-09 | Local denylist via `KOKPIT_DENYLIST`, generic-only when absent | SATISFIED | Truths 2, 7; UAT 4 run with the real list |
| HYG-04 | 01-01, 01-05 | lefthook pre-commit (versioned config, installer) runs the check and gitleaks | SATISFIED | Truth 3 |
| HYG-05 | 01-05, 01-08 | `.gitleaks.toml` with extra rules and a CI full-history scan | SATISFIED | Truth 4; green GitHub run (UAT 1) |
| HYG-06 | 01-04, 01-11 | CLAUDE.md and CONTRIBUTING document the rule | SATISFIED | Truth 5 |
| HYG-07 | 01-04, 01-11 | Secret scanning with push protection and review procedure documented | SATISFIED | Truth 5; settings enabled per live `gh api` |

### Anti-Patterns Found

Debt-marker scan (`TBD`, `FIXME`, `XXX`) over the phase implementation files: the only match is the `mktemp ...kokpit.XXXXXX` template in `scripts/check-sensitive.sh` line 79, which is a mktemp pattern, not a debt marker. No stubs, placeholders or empty implementations.

| File | Line | Pattern | Severity | Impact |
|------|------|---------|----------|--------|
| `scripts/check-sensitive.sh` | 79 | `XXXXXX` mktemp template | Info (false positive) | None |

Residual items carried from the previous report and `01-REVIEW-DISPOSITION.md` (all open, none a must-have failure; unchanged by phase 02 except where noted):

| ID | Judgment |
|----|----------|
| WR-10 denylist silently inactive in the hook; a set-but-missing path fails open | WARNING. Weakest remaining control for the highest-value class (client names); roadmap criterion and HYG-03 allow the variable to be absent, and both hold. UAT 4 confirmed the owner's list is active and exported. Fix first: exit 2 on a set-but-missing path and surface the notice in the hook |
| WR-11 commit messages not scanned | WARNING. Documents list commit messages in the rule but never claim they are scanned. Add a `commit-msg` job or state the limit |
| WR-12 JSON-escaped (`\/Users\/name`) and WSL home paths | WARNING. Relevant because PHP `json_encode` escapes slashes; now that PHP code exists in the tree, worth closing soon |
| WR-13 VAT number (`CZ` plus company ID) not flagged | WARNING. Relevant for an invoicing product |
| WR-03 right-boundary false negatives (`<8 digits>-name`) | WARNING |
| WR-07 `.planning/` allowlist entry hides company IDs starting 202 or 203 | WARNING. The entry was narrowed to years 2020-2039 in phase 02 but still gives a standing hole inside `.planning/` |
| WR-15 BOM on the first denylist term | WARNING, one-line fix |
| New (phase 02) `composer.lock` allowlist entries: `email` accepts any address and `public-ip` any dotted number in that one file | WARNING, low. Path-scoped to a generated, dependency-mirroring file at a recorded maintainer checkpoint; a real address planted in `composer.lock` would not be reported by the scanner. Mitigated by review (CODEOWNERS covers the allowlist) and by the file being machine-generated. Consider tightening to a value shape that excludes owner addresses, or reviewing `composer.lock` diffs for author fields |
| WR-14 `.gitignore` vs Laravel storage placeholders | Resolved in practice: the placeholder `.gitignore` files are tracked and storage content is still ignored |

Why these do not undermine the goal: each is a different spelling of a sensitive value or an operator-visibility defect in a layered heuristic control (scanner, gitleaks, ignore rules, CI over full history, push protection) that the roadmap criteria and requirements do not promise to be exhaustive. The stated classes are blocked, content-hiding bypasses (attributes, NUL, encodings, merges) are closed, the gate ran green on GitHub, and the platform-side protections are enabled.

### Human Verification Required

None outstanding. The four items from the previous report were completed in `01-UAT.md` (4/4 pass), and `01-SECURITY.md` reports `threats_open: 0`. Two UAT notes remain with the owner and do not block the phase: the optional live push-protection test was not run, and ticking the CONTRIBUTING.md settings checklist is left to the owner.

### Gaps Summary

No gaps. All five roadmap success criteria and the three derived truths are verified against the current tree, requirements HYG-01 to HYG-07 are all accounted for and satisfied, and phase 02 changes to the allowlist, `.gitignore`, `CONTRIBUTING.md`, `.gitattributes` and the CI workflow did not weaken any guarantee. Recommended follow-up (not blocking): WR-10, WR-12, WR-13 and the `composer.lock` allowlist scope, in a quick task or the next phase's hygiene review.

---

_Verified: 2026-10-07T22:00:00Z_
_Verifier: Claude (gsd-verifier)_
