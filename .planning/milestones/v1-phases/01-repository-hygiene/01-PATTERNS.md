# Phase 1: Repository Hygiene - Pattern Map

**Mapped:** 2026-10-06
**Files analyzed:** 25 (new or modified)
**Analogs found:** 2 in-repo (partial) / 25. The repository has no application code. For all other files the reference implementation is the verified excerpt in `01-RESEARCH.md` (cited by section and line range below).

Tracked-source gate: `git ls-files` shows only `.gitignore`, `LICENSE` and `.planning/codebase/*.md`. No analog below is a gitignored mirror. `.claude/CLAUDE.md` is currently untracked, so it is a modify-target, not an analog.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|---|---|---|---|---|
| `.gitignore` (modify) | config | n/a | itself (`.gitignore` lines 1-50, sectioned with `#` headers) | exact (self) |
| `scripts/check-sensitive.sh` | utility (driver) | batch / transform (diff -> TSV -> findings) | none in repo; RESEARCH Pattern 3 + "Staged-mode driver core" | no analog |
| `scripts/lib/diff2tsv.awk` | utility | transform | none; RESEARCH Pattern 1 (lines 194-218) | no analog |
| `scripts/lib/scan.awk` | utility | transform | none; RESEARCH Pattern 2 (lines 232-334) | no analog |
| `scripts/sensitive-allowlist.txt` | config | n/a | none; RESEARCH allowlist format (lines 337-344) | no analog |
| `scripts/install-hooks.sh` | utility | request-response (one-shot CLI) | none; RESEARCH Pattern 4 last paragraph (line 391) | no analog |
| `lefthook.yml` | config | event-driven (git hook) | none; RESEARCH Pattern 4 (lines 358-372) | no analog |
| `.gitleaks.toml` | config | n/a | none; RESEARCH Pattern 5 (lines 398-431) | no analog |
| `.github/workflows/hygiene.yml` | config (CI) | event-driven | none; RESEARCH Pattern 6 (lines 443-547) | no analog |
| `.github/dependabot.yml` | config | n/a | none; RESEARCH line 555 (github-actions, weekly) | no analog |
| `.github/CODEOWNERS` | config | n/a | none; paths listed in RESEARCH line 188 | no analog |
| `scripts/tests/run.sh` | test (runner) | batch | none; RESEARCH "Test harness" (lines 691-704) | no analog |
| `scripts/tests/lib.sh` | test (helpers) | n/a | none; RESEARCH lines 693-704 and `iban_cz` (605-610) | no analog |
| `scripts/tests/test-check-sensitive.sh` | test | request-response | none; RESEARCH Validation map (line 788-789) | no analog |
| `scripts/tests/test-denylist.sh` | test | request-response | none; RESEARCH Validation map (line 790) | no analog |
| `scripts/tests/test-gitleaks.sh` | test | request-response | none; RESEARCH lines 716 | no analog |
| `scripts/tests/test-lefthook.sh` | test (local only) | event-driven | none; behaviour table RESEARCH lines 375-387 | no analog |
| `scripts/tests/test-gitignore.sh` | test | request-response | none; RESEARCH line 787 | no analog |
| `CONTRIBUTING.md` | doc | n/a | none; content in RESEARCH lines 865-866 and D-14/D-15/D-07 | no analog |
| `.claude/CLAUDE.md` (modify) | doc/config | n/a | itself: GSD marker blocks (`<!-- GSD:*-start/end -->`) | exact (self) |
| `mise.toml` (optional) | config | n/a | none; RESEARCH line 393 | no analog |
| `.planning/codebase/*` (D-16: sanitise or `git rm -r --cached`) | doc | n/a | n/a | n/a |
| `SECURITY.md` | out of scope (Phase 2) | | | |

## Pattern Assignments

### `.gitignore` (config, modify)

**Analog:** the existing file. Keep its convention: one `# Section` comment line, then patterns, blank line between sections (current lines 1-50). Existing sections: "Secrets and environment" (`.env`, `.env.*`, `!.env.example`, `*.pem`, `*.key`), "Local AI tooling and IDE settings", "Local-only data, imports, exports and dumps", "Runtime artefacts", "Dependencies and build output", "Planning: risky session artefacts".

**Edit pattern.** Replace the single `.claude/settings.local.json` line in the "Local AI tooling" section with the negation-safe form, and add the gap-list entries as new sections (RESEARCH lines 662-686):
```gitignore
# Local AI tooling: only CLAUDE.md is versioned (".claude/" would make the negation impossible)
.claude/*
!.claude/CLAUDE.md
CLAUDE.local.md
```
Keep `!.env.example`. Do not ignore the `.claude/` directory itself (git cannot re-include under an excluded parent). Verify with `git check-ignore -v`. `.ddev/` stays deferred to Phase 2.

---

### `scripts/check-sensitive.sh` (utility, batch/transform)

**Analog:** none in repo. Use RESEARCH Pattern 3 (lines 346-354) and "Staged-mode driver core" (lines 706-713).

**Conventions to apply:**
- Shebang `#!/usr/bin/env bash`, `set -eu`, bash 3.2 safe (no `declare -A`, `mapfile`, `${v,,}`, `readlink -f`, `sed -i`, `grep -P`, `echo -e`). RESEARCH Pitfall 2, lines 587-591.
- Temp dir: `mktemp -d "${TMPDIR:-/tmp}/kokpit.XXXXXX"` with `trap 'rm -rf "$tmp"' EXIT`.
- Locate sibling files with `here=$(cd "$(dirname "$0")" && pwd)`.
- Exit codes: 0 clean, 1 findings, 2 usage / unreadable denylist / not a git repo.
- Modes: `--staged` (default), `--all` (`git grep --cached -I -n -z -e ''` piped through `tr '\0' '\t'`), `--history`, explicit files.

**Core excerpt (staged mode):**
```bash
git -c core.quotepath=off diff --cached -U0 --no-color --no-ext-diff \
    --src-prefix=a/ --dst-prefix=b/ --diff-filter=ACMR \
  | LC_ALL=C awk -f "$here/lib/diff2tsv.awk" > "$tmp/rows.tsv"
CS_ALLOWLIST="$here/sensitive-allowlist.txt" LC_ALL=C awk -f "$here/lib/scan.awk" "$tmp/rows.tsv"
```
Exclude the D-05 exact paths (`scripts/check-sensitive.sh`, `scripts/lib/scan.awk`, `scripts/lib/diff2tsv.awk`, `scripts/sensitive-allowlist.txt`, `.gitleaks.toml`) with an awk filter on the TSV before scanning. Use exact paths, never directories.

**Denylist (D-06):**
```bash
sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$KOKPIT_DENYLIST" | grep -v -e '^#' -e '^$' > "$tmp/deny"
[ -s "$tmp/deny" ] && LC_ALL=C.UTF-8 grep -F -i -f "$tmp/deny" "$tmp/rows.tsv" | cut -f1,2 | tr '\t' ':' | sed 's/$/: denylist/'
```
Unset or missing file: one notice line on stderr, continue. Set but unreadable: error and `exit 2`. Print only `path:line`, never the term or the text.

---

### `scripts/lib/diff2tsv.awk` and `scripts/lib/scan.awk` (utility, transform)

**Analog:** none in repo. Copy the reference programs verbatim from RESEARCH lines 196-217 (diff parser) and 233-333 (scanner), then adapt. Binding constraints:
- POSIX ERE only, simple character-class cores (mawk panics on anchors inside groups and on some quantified groups). Boundary checks live in code (`scan_re`).
- Allowlist path via `ENVIRON["CS_ALLOWLIST"]`, not `-v`.
- Run with `LC_ALL=C`.
- Output shape `path:line: rule [masked]`, with `mask()` showing at most 2 characters plus a length.
- Key-prefix rules require a body after the prefix so docs that only name `sk_live_` do not hit.
- For `--history`, extend the parser to capture `^commit ` lines (RESEARCH line 224).

---

### `scripts/sensitive-allowlist.txt` (config)

**Analog:** none. Format (RESEARCH lines 339-343), fictional entries only:
```
# rule ;; path-ERE ;; matched-value-ERE      (third field optional; '*' = any)
company-id ;; * ;; ^(12345678|00000000)$
company-id ;; ^docs/ ;; ^20[0-9]{6}$
email ;; * ;; ^git@github\.com$
```
Path-scoped plus value-scoped. No inline-ignore comments (D-04). Pitfall 4: invoice-number examples in `.planning/research/*` need path-scoped entries.

---

### `scripts/install-hooks.sh` (utility, one-shot)

**Analog:** none. Spec from RESEARCH line 391: `set -eu`; `cd "$(dirname "$0")/.."` (no `readlink -f`); `command -v lefthook` else print `brew install lefthook` / `mise use lefthook@2.1.17` and `exit 1`; same check for `gitleaks`; run `lefthook install`, then `lefthook check-install`; warn (do not fail) on `gitleaks version` mismatch. Idempotent. If `core.hooksPath` is set, surface lefthook's own hint instead of forcing (Pitfall 9).

---

### `lefthook.yml` (config, event-driven)

**Analog:** none. Copy RESEARCH lines 358-372:
```yaml
min_version: 2.1.0
assert_lefthook_installed: true      # hook exits 1 (fail-closed) if the lefthook binary is missing
output:
  - failure
  - summary
pre-commit:
  jobs:
    - name: sensitive-content
      run: scripts/check-sensitive.sh
      fail_text: "Sensitive content found (file:line above). Fix it; see CONTRIBUTING.md. Do not bypass."
    - name: gitleaks
      run: gitleaks git --pre-commit --staged --redact --no-banner --verbose --config .gitleaks.toml
      fail_text: "gitleaks failed or is not installed. Install: brew install gitleaks (see CONTRIBUTING.md)."
```
Do not use `{staged_files}` (both tools read the index). No `.githooks/`, no `core.hooksPath`. Validate with `lefthook validate`. Note the CONTEXT D-08 text mentioning `gitleaks protect --staged` is superseded by `gitleaks git --pre-commit --staged`.

---

### `.gitleaks.toml` (config)

**Analog:** none. Copy RESEARCH lines 398-431: `[extend] useDefault = true`, then `[[rules]]` entries `kokpit-stripe-publishable-or-webhook`, `kokpit-stripe-object-id`, `kokpit-home-path` (with `[[rules.allowlists]]` for placeholder users), `kokpit-ico-like` (with `stopwords = ["12345678", "00000000"]`). Use the array-of-tables allowlist form. Optionally add a keyword-anchored `ZEROPS_TOKEN` assignment rule (the format is `[ASSUMED]`, RESEARCH line 435). Always pass `--config .gitleaks.toml` and `--redact`.

---

### `.github/workflows/hygiene.yml` (config, event-driven)

**Analog:** none in repo. Copy the lint-verified workflow from RESEARCH lines 443-547 (continued at 531-547). Load-bearing points:
- Top-level `permissions: {}`; per-job `contents: read`.
- `actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1`, with `fetch-depth: 0` and `persist-credentials: false`.
- Binaries installed by curl then `sha256sum --check --strict -` against hard-coded digests (table at RESEARCH lines 115-117). Re-verify versions and digests before pinning.
- Jobs `scan` (self-tests, `check-sensitive.sh --all`, `gitleaks git`), `workflow-lint` (actionlint, `zizmor --offline`), and an aggregator job whose `name:` is exactly `CI Passed` (`if: always()`, passes `needs.*.result` via `env:`, rejects anything not `success`).
- `runs-on: ubuntu-24.04`. Triggers `push` (main), `pull_request`, optional `workflow_dispatch`. Never `pull_request_target`. No `${{ github.event.* }}` inside `run:`.

**Companions:** `.github/dependabot.yml` with `package-ecosystem: github-actions`, weekly. `.github/CODEOWNERS` on `.github/workflows/`, `scripts/`, `scripts/sensitive-allowlist.txt`, `.gitleaks.toml`.

---

### `scripts/tests/*.sh` (test)

**Analog:** none. Copy the harness from RESEARCH lines 693-704 (`assert_exit`, `assert_out_has`, `assert_out_lacks`, `new_repo`) and `iban_cz` from lines 605-610 into `lib.sh`.

**Rules:**
- Every fake value is assembled from fragments at runtime: `printf 'jane%scorp-fake.cz' '@'`, `printf '%s%s' 2712 3456`, `printf '%s%s%s' gh p_ <body>`. No literal match in the test source, so the repo's own scan stays clean (Pitfall 5).
- `run.sh` discovers `test-*.sh`, supports `--quick` (no gitleaks/lefthook needed), and exits non-zero on any failure.
- `test-gitleaks.sh` skips with a notice when gitleaks is absent locally and is mandatory in CI. It also covers "secret deleted in a later commit is still reported".
- `test-lefthook.sh` is local only, skips when lefthook or gitleaks is absent, and is never run in CI.
- `test-denylist.sh` covers all three states (unset, set, unreadable), blank and CRLF lines, and a diacritic case-fold with fictional terms.
- `test-gitignore.sh` uses a temp repo with the real `.gitignore` and `git check-ignore -q`, including the `.claude/*` negation.
- Assert that masked output never contains the full value. Add a CI assertion that `scripts/tests/` itself passes `check-sensitive.sh --all`.
- Run once under `/bin/bash` (3.2).

---

### `CONTRIBUTING.md` (doc) and `.claude/CLAUDE.md` (modify)

**Analog:** none for CONTRIBUTING. For `.claude/CLAUDE.md`, the existing file is split into GSD-managed blocks delimited by `<!-- GSD:<name>-start ... -->` / `<!-- GSD:<name>-end -->` (for example `project`, `stack`, `conventions`, `architecture`). Put the fictional-data rule outside those markers (RESEARCH A1: regeneration may overwrite managed blocks). The `stack`, `conventions` and `architecture` blocks are generated from `.planning/codebase/` and are wrong for this project; fix or remove them (D-16), and remove the personal home-path line from the stack block.

**CONTRIBUTING sections** (content in `01-RESEARCH.md` lines 865-866 and in D-07, D-14, D-15):
- Fictional-data-only rule.
- Review procedure: `git status`, then `git diff --staged`, then `scripts/check-sensitive.sh`.
- `KOKPIT_DENYLIST` usage with a fictional example file.
- Tested tool versions (lefthook 2.1.17, gitleaks 8.30.1) and install via brew or mise.
- Never use `--no-verify` or `LEFTHOOK=0` (CI will fail).
- Leak runbook pointer (rotate first, rewrite history, notify; full runbook in Phase 2 `SECURITY.md`).
- GitHub settings checklist: secret scanning, push protection, SHA-pinning requirement, Dependabot, 2FA, branch ruleset with required check `CI Passed`. These are human-verify items.
- Per-phase `.gitignore` review note.

---

## Shared Patterns

### Never echo sensitive values
**Apply to:** `check-sensitive.sh`, `scan.awk`, gitleaks invocations, tests. Mask in the scanner (`mask()`, RESEARCH line 234), pass `--redact` to gitleaks everywhere, print only `path:line` for denylist hits, and assert on this in tests with `assert_out_lacks`.

### Portability
**Apply to:** all shell and awk. Must work on macOS (bash 3.2, BWK awk, BSD grep) and Ubuntu 24.04 (bash 5.2, mawk, GNU grep). POSIX grep flags only (`-E -F -i -n -v -c -f`), `LC_ALL=C` for awk, `LC_ALL=C.UTF-8` for the denylist grep only. Details in RESEARCH Pitfalls 1-3, 7.

### Fail-closed gating
**Apply to:** `lefthook.yml`, `install-hooks.sh`, CI. Missing lefthook or gitleaks blocks with an install hint. CI is authoritative because hooks are bypassable.

### Fictional data only
**Apply to:** fixtures, docs, allowlist, `CONTRIBUTING.md` examples. Use `example.com`, `12345678`, `/Users/example/`, runtime-built fakes. Never copy personal paths, real IDs or real tokens (RESEARCH Runtime State Inventory lists hit categories only, not values).

### Workflow hardening
**Apply to:** `.github/workflows/*.yml`. Full-SHA pins with a version comment, hard-coded binary digests, least-privilege `permissions`, no event data in `run:`, `actionlint` plus `zizmor --offline` clean.

## No Analog Found

Every new file except the `.gitignore` and `.claude/CLAUDE.md` edits (which modify existing files) has no in-repo analog because the repo contains no scripts, hooks, CI or tests. The planner should use the RESEARCH excerpts cited above as the reference implementations. They were executed and verified on macOS and Ubuntu 24.04.

## Notes for the planner

- Open owner decisions (RESEARCH Open Questions 1-2): rewrite unpushed local history to drop the home-path line (checkpoint:human-verify), and whether to keep `.planning/codebase/`.
- The org ruleset requires a check named `CI Passed`, so the aggregator job name must match exactly.
- gitleaks is not installed locally, so add an explicit install step before `scripts/install-hooks.sh`.

## Metadata

**Analog search scope:** whole repo (`git ls-files`, `.claude/CLAUDE.md` structure, `.gitignore`)
**Files scanned:** 11 tracked/untracked relevant files
**Pattern extraction date:** 2026-10-06
