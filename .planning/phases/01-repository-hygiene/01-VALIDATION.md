---
phase: "1"
slug: repository-hygiene
status: validated
nyquist_compliant: true
wave_0_complete: true
created: "2026-10-06"
---

# Phase 1 — Validation Strategy

> Per-phase validation contract for feedback sampling during execution.

---

## Test Infrastructure

| Property | Value |
|----------|-------|
| **Framework** | Plain Bash assertion scripts (no dependency; bats rejected) |
| **Config file** | none — `scripts/tests/run.sh` discovers `scripts/tests/test-*.sh` (Wave 0 creates it) |
| **Quick run command** | `bash scripts/tests/run.sh --quick` |
| **Full suite command** | `bash scripts/tests/run.sh` |
| **Estimated runtime** | ~5 s quick, ~30 s full |

Also run once under `/bin/bash` (3.2) and in CI (`ubuntu-24.04`, mawk). Static checks: `shellcheck scripts/*.sh scripts/tests/*.sh`, `lefthook validate`, `actionlint`, `zizmor --offline .github/workflows`.

---

## Sampling Rate

- **After every task commit:** Run `bash scripts/tests/run.sh --quick`
- **After every plan wave:** Run `bash scripts/tests/run.sh` (under `/bin/bash` and Homebrew bash)
- **Before `/gsd-verify-work`:** Full suite green plus CI `CI Passed` green on a PR
- **Max feedback latency:** 30 seconds

---

## Per-Task Verification Map

Task IDs are `{phase}-{plan}-{task}`. Threat refs point to the `<threat_model>` registers in the PLAN files.

| Task ID | Plan | Wave | Requirement | Threat Ref | Secure Behavior | Test Type | Automated Command | File Exists | Status |
|---------|------|------|-------------|------------|-----------------|-----------|-------------------|-------------|--------|
| 01-01-01 | 01 | 1 | HYG-02, HYG-05 | T-01-01, T-01-02, T-01-04..07 | Staged fake key fails as `path:line: key-prefix [masked]`; clean change passes; `--all` works; CI scan job and `CI Passed` aggregator lint-clean | unit + static | `bash scripts/tests/run.sh --quick`; `lefthook validate && actionlint .github/workflows/hygiene.yml && zizmor --offline .github/workflows` | ✅ (tracer) | ✅ green |
| 01-01-02 | 01 | 1 | HYG-04 | T-01-03 | Hook installed idempotently; real commit with a fake rejected; missing lefthook blocks; hooksPath override refused | integration (local) | `bash scripts/tests/test-lefthook.sh` | ✅ | ✅ green |
| 01-02-01 | 02 | 2 | HYG-02 | T-01-08, T-01-10, T-01-11 | Non-example e-mail fails; example domains and reviewed allowlist entry pass; mawk parity | unit | `bash scripts/tests/run.sh --quick && bash scripts/tests/run-in-ubuntu.sh` | ✅ | ✅ green |
| 01-02-02 | 02 | 2 | HYG-02 | T-01-09 | company-id, iban, cz-account fail; placeholders and `.planning/` invoice examples pass | unit | `bash scripts/tests/test-check-sensitive.sh` | ✅ | ✅ green |
| 01-02-03 | 02 | 2 | HYG-02 | T-01-09, T-01-11 | public-ip, hosting-host, home-path fail; real repo shows only the known STACK.md item | unit + integration | `bash scripts/tests/run-in-ubuntu.sh`; `scripts/check-sensitive.sh --all` (single known finding) | ✅ | ✅ green |
| 01-03-01 | 03 | 2 | HYG-03 | T-01-13, T-01-14, T-01-15 | Denylist term blocks without echo; unset/missing → notice + generic only; unreadable or in-repo → exit 2 | unit | `bash scripts/tests/test-denylist.sh` | ✅ | ✅ green |
| 01-03-02 | 03 | 2 | HYG-05 | T-01-16, T-01-17 | Explicit files and `--history` report through the same rules; CI runs `--history` | unit + static | `bash scripts/tests/test-modes.sh`; `actionlint && zizmor --offline .github/workflows` | ✅ | ✅ green |
| 01-04-01 | 04 | 2 | HYG-06 | T-01-18, T-01-21, T-01-22 | Fictional-data rule and review procedure in both docs; CLAUDE.md sanitised and trackable | static | `bash scripts/tests/test-docs.sh` | ✅ | ✅ green |
| 01-04-02 | 04 | 2 | HYG-07 | T-01-20 | Secret scanning, push protection, ruleset checklist documented; read-only settings audit | static + manual | `bash scripts/tests/test-docs.sh` | ✅ | ✅ green |
| 01-04-03 | 04 | 2 | HYG-01 | T-01-19 | Env files, `.claude/*` (except `CLAUDE.md`), `/local/`, dumps, exports, storage, logs, coverage ignored; order of negation proven | unit | `bash scripts/tests/test-gitignore.sh` | ✅ | ✅ green |
| 01-05-01 | 05 | 3 | HYG-04, HYG-05 | T-01-23, T-01-24, T-01-26, T-01-27 | gitleaks fails on planted fakes incl. deleted-in-later-commit, redacted; hook gitleaks job fails closed; CI pinned binary | integration | `bash scripts/tests/test-gitleaks.sh`; `bash scripts/tests/run.sh` | ✅ | ✅ green |
| 01-05-02 | 05 | 3 | HYG-05 | T-01-25, T-01-28 | Workflow lint gate (actionlint + zizmor) with negative control; Dependabot; CODEOWNERS | static | `actionlint && zizmor --offline .github/workflows` | ✅ | ✅ green |
| 01-06-01 | 06 | 4 | HYG-01..07 (gate) | T-01-32 | Real repo tree, history and planning docs scanned; only the known pre-tooling item remains | integration | `bash scripts/tests/run.sh`; explicit-file scan of `.planning/**/*.md` | ✅ | ✅ green |
| 01-06-02 | 06 | 4 | D-16 | T-01-29 | Owner chooses rewrite or fingerprint and untrack or sanitise | manual (checkpoint:decision, blocking-human) | — | n/a | ✅ green |
| 01-06-03 | 06 | 4 | HYG-05 (D-16) | T-01-29, T-01-30, T-01-31, T-01-33 | Tree and full history clean on this repository; nothing pushed | integration + manual | `scripts/check-sensitive.sh --all && scripts/check-sensitive.sh --history && gitleaks git --config .gitleaks.toml --redact --no-banner --ignore-gitleaks-allow --exit-code 1 .` | ✅ | ✅ green |
| 01-07-01 | 07 | 1 | HYG-02, HYG-05 | CR-01 | Staged content hidden by attributes, NUL bytes, UTF-16, textconv or type change is still scanned and reported | unit | `bash scripts/tests/test-content.sh` | ✅ | ✅ green |
| 01-07-02 | 07 | 1 | HYG-02, HYG-05 | CR-01, WR-02, WR-05 | `--all`, `--history` and explicit files read every file in full, merge-only and renamed content included | unit + integration | `bash scripts/tests/test-content.sh && bash scripts/tests/test-modes.sh` | ✅ | ✅ green |
| 01-08-01 | 08 | 1 | HYG-02, HYG-05 | CR-01 | A `.gitattributes` line that hides content from gitleaks or diverts it from git is blocked (`git-attributes` rule) | unit | `bash scripts/tests/test-attributes.sh` | ✅ | ✅ green |
| 01-08-02 | 08 | 1 | HYG-04, HYG-05 | CR-01, WR-02, WR-06 | CI gitleaks reads binary-classified and merge-only content with the exact CI `--log-opts`; CODEOWNERS covers hygiene files | integration + static | `bash scripts/tests/test-gitleaks.sh`; `lefthook validate && actionlint && zizmor --offline .github/workflows` | ✅ | ✅ green |
| 01-09-01 | 09 | 2 | HYG-03 | CR-02 | Denylist term with diacritics blocks the same name without them and the reverse, locale-free | unit | `bash scripts/tests/test-denylist.sh` | ✅ | ✅ green |
| 01-09-02 | 09 | 2 | HYG-03 | CR-02, WR-01, WR-08 | Decomposed forms, the five exempt paths, empty folds and locale independence | unit | `bash scripts/tests/test-denylist.sh && bash scripts/tests/test-modes.sh` | ✅ | ✅ green |
| 01-10-01 | 10 | 2 | HYG-02, HYG-05 | CR-03 | Bare personal home path reported by scanner and gitleaks; placeholders and URL paths pass | unit + integration | `bash scripts/tests/test-check-sensitive.sh && bash scripts/tests/test-gitleaks.sh` | ✅ | ✅ green |
| 01-10-02 | 10 | 2 | HYG-02, HYG-05 | CR-03, WR-04 | Windows home paths in both layers; IC and IČ keys in gitleaks | unit + integration | `bash scripts/tests/test-check-sensitive.sh && bash scripts/tests/test-gitleaks.sh` | ✅ | ✅ green |
| 01-10-03 | 10 | 2 | HYG-02, HYG-05 | WR-03, WR-07, IN-03 | Boundary false negatives, ASIA keys, interval self-test, narrowed planning exemption | unit + integration | `bash scripts/tests/test-check-sensitive.sh && bash scripts/tests/run.sh` | ✅ | ✅ green |
| 01-11-01 | 11 | 3 | HYG-01, HYG-03, HYG-06, HYG-07 | CR-02 | CONTRIBUTING states the shipped contract and the docs test enforces it (mutation loop) | static | `bash scripts/tests/test-docs.sh` | ✅ | ✅ green |
| 01-11-02 | 11 | 3 | HYG-01 | WR-09 | Credential files and infrastructure state are ignored; trackable neighbours stay trackable | unit | `bash scripts/tests/test-gitignore.sh` | ✅ | ✅ green |
| 01-11-03 | 11 | 3 | HYG-01..07 (gate) | review ledger | Every review finding has a recorded disposition; full phase gate on the real repository | integration | `bash scripts/tests/run.sh && /bin/bash scripts/tests/run.sh --quick && bash scripts/tests/run-in-ubuntu.sh`; `scripts/check-sensitive.sh --all && scripts/check-sensitive.sh --history` | ✅ | ✅ green |

*Status: ⬜ pending · ✅ green · ❌ red · ⚠️ flaky*

---

## Wave 0 Requirements

- [x] `scripts/tests/run.sh`, `scripts/tests/lib.sh` — harness, runtime fake builders, IBAN generator (01-01-01, tracer)
- [x] `test-check-sensitive.sh` (01-01-01), `test-lefthook.sh` (01-01-02), `run-in-ubuntu.sh` (01-02-01), `test-denylist.sh` (01-03-01), `test-modes.sh` (01-03-02), `test-docs.sh` (01-04-01), `test-gitignore.sh` (01-04-03), `test-gitleaks.sh` (01-05-01) — each created by the task that introduces the behaviour
- [x] Local tools confirmed or installed via Homebrew before `scripts/install-hooks.sh` runs (01-01-01 Step 0); at planning time lefthook 2.1.17, gitleaks 8.30.1, shellcheck 0.11.0, actionlint 1.7.12 and zizmor 1.30.1 were already present

---

## Manual-Only Verifications

| Behavior | Requirement | Why Manual | Test Instructions |
|----------|-------------|------------|-------------------|
| GitHub secret scanning + push protection enabled | HYG-07 | Repository settings, needs admin | `gh api repos/<owner>/<repo> --jq .security_and_analysis` and compare with the CONTRIBUTING checklist |
| Decision on rewriting unpushed local history / untracking `.planning/codebase/` | D-16 | Destructive git action needs owner consent | Resolved in 01-06: owner chose rewrite-untrack |
| First PR shows green `CI Passed` | HYG-05 | Requires a real GitHub run | Open a PR and observe the check |

*Outcome: UAT test 2 (GitHub settings, re-verified with `gh api`) and UAT test 1 (first pull request run with `CI Passed` green) passed, see `01-UAT.md`; the history decision was resolved in plan 01-06.*

---

## Validation Sign-Off

- [x] All tasks have `<automated>` verify or Wave 0 dependencies
- [x] Sampling continuity: no 3 consecutive tasks without automated verify
- [x] Wave 0 covers all MISSING references
- [x] No watch-mode flags
- [x] Feedback latency < 30s (quick run 27 s; full suite 33 s, measured on the orchestrating machine at approval; re-measured on the current tree: quick run 33 s, full suite 39 s, a few seconds over the target and not a coverage gap)
- [x] `nyquist_compliant: true` set in frontmatter

**Approval:** validated 2026-10-07 after gap closure (plans 01-07 to 01-11); re-validated 2026-10-07 on the current tree (full suite green under bash 5.3, quick run green under bash 3.2, Ubuntu container run, shellcheck, lefthook validate, actionlint, zizmor, scanner `--all` and `--history`, gitleaks); the manual-only items are confirmed in `01-UAT.md`

## Validation Audit 2026-10-07

| Metric | Count |
|---|---|
| Gaps found | 0 |
| Resolved | 0 |
| Escalated | 0 |
