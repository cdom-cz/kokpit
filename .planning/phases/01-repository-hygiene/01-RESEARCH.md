# Phase 1: Repository Hygiene - Research

**Researched:** 2026-10-06
**Domain:** Bash/awk sensitive-content scanning, gitleaks, lefthook-managed git hooks, hardened GitHub Actions, GitHub repo security settings (no application code)
**Confidence:** HIGH (all tool versions, CLI behaviour, workflow lint results and portability claims were verified empirically this session; a few GitHub-setting names are `[ASSUMED]`)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions
- **D-01:** Plain POSIX-compatible Bash (no PHP/Node dependency, since the app does not exist yet and the hook must run on a bare checkout). Scans staged content (`git diff --cached`, added lines only) by default; accepts an explicit file list / `--all` mode so CI can run it over the working tree.
- **D-02:** Generic patterns: non-example e-mails (allowed domains: `example.com`, `example.org`, `example.net`, `*.test`, `*.invalid`, `*.localhost`), 8-digit company-ID-like numbers (allowlist `12345678`, `00000000`), IBAN and Czech account-number formats, public IPv4 addresses (RFC 1918, loopback, documentation ranges and `0.0.0.0` allowed), hosting hostnames (Zerops, S3 provider endpoints), key/token prefixes (`sk_live_`, `rk_live_`, `pk_live_`, `whsec_`, `plink_`, `acct_`, `ghp_`, AWS `AKIA`), and personal absolute home paths (`/Users/<name>/`, `/home/<name>/`).
- **D-03:** On a hit the script prints `file:line: <rule name>` (never the matched secret value in full - truncate/mask) and exits non-zero. Clean input exits 0.
- **D-04:** Exemptions through a committed allowlist file (`scripts/sensitive-allowlist.txt`, reviewed in PRs) with path-scoped and pattern-scoped entries; no inline "ignore" magic comments.
- **D-05:** The script excludes itself, the allowlist and the gitleaks config from scanning (they necessarily contain the patterns).
- **D-06:** `KOKPIT_DENYLIST` points to a file outside the repo, one term per line (`#` comments, blank lines ignored), matched case-insensitively as fixed strings. If unset or file missing: print a one-line notice and run generic patterns only (exit code unaffected) - this is the CI path. If set but unreadable: fail loudly.
- **D-07:** A documented example denylist format lives in docs with fictional terms only; the real denylist is never committed (path suggestion in docs: under the user's home config directory).
- **D-08 (revised by owner):** Local git hooks are managed by **lefthook**: a versioned `lefthook.yml` at the repo root defines the `pre-commit` job(s), which run `scripts/check-sensitive.sh` and then gitleaks on staged changes. `scripts/install-hooks.sh` becomes a thin idempotent wrapper that checks lefthook is installed (install hint otherwise) and runs `lefthook install`. No `.githooks/` directory and no `core.hooksPath`. lefthook is a local developer tool only (not a Composer/npm dependency); CI never depends on it.
- **D-09:** If `lefthook` or `gitleaks` is not installed locally, `scripts/install-hooks.sh` and the hook fail with an install hint rather than silently skipping (fail-closed); CI remains the authoritative gate because `--no-verify` bypasses hooks. Researcher to confirm how lefthook runs staged-file jobs and how to pin/document the lefthook version.
- **D-10:** `.gitleaks.toml` extends the default ruleset (`[extend] useDefault = true`) and adds custom rules for Stripe ids/secrets, Zerops tokens, S3 endpoints, absolute home paths and IČO-like numbers outside the allowlist; allowlist section reviewed in PRs.
- **D-11:** GitHub Actions workflow `.github/workflows/hygiene.yml`: triggers `push` and `pull_request` only (never `pull_request_target`), `permissions: contents: read`, `actions/checkout` with `fetch-depth: 0`, runs `scripts/check-sensitive.sh --all` (no denylist in CI) and gitleaks over full history.
- **D-12:** All actions and tool installs pinned (actions by commit SHA, gitleaks binary by version + checksum); gitleaks is run as a pinned binary, not `gitleaks-action`, to avoid the organisation-licence-key issue. No `${{ github.event.* }}` interpolation inside `run:`. `actionlint` (and zizmor if cheap) runs in the same workflow.
- **D-13:** CI is proven with a planted-fake-secret test: a fixture-driven script test (`scripts/tests/`) shows the checker fails on planted bad input and passes on clean input, without ever committing a real-looking secret (fake values built at test time from string fragments).
- **D-14:** Fictional-data-only rule added to `.claude/CLAUDE.md` (project instructions) and a new `CONTRIBUTING.md` hygiene section, including the review procedure `git status` -> `git diff --staged` -> `scripts/check-sensitive.sh`, and the leak runbook pointer (rotate first, rewrite history, notify; full runbook lands in SECURITY.md in Phase 2).
- **D-15:** GitHub secret scanning + push protection and branch protection are manual settings; documented as a checklist in CONTRIBUTING (HYG-07). Cannot be verified by code; flagged as a human-verify item.
- **D-16:** `.planning/codebase/` is treated as unreliable and already published (it was committed before hygiene tooling). Phase 1 runs the new checker over it and over `.claude/CLAUDE.md`; findings (notably personal absolute paths such as home directories) are sanitized or the files removed from tracking before anything is pushed publicly. History rewrite is out of scope unless the scan finds a real secret (then: rotate first, ask the owner).
- **D-17:** `.gitignore` is extended where the review finds gaps (e.g. coverage output, `.claude/` machine-local files, `.idea/` already ignored, `.ddev/` stays versioned but `.ddev/.env*`/override files ignored) and gets a per-phase review note in CONTRIBUTING. Planning docs (`.planning/*`) stay uncommitted until the hygiene check passes on them (`commit_docs` remains false until then).

### Claude's Discretion
Exact regex tuning and false-positive balance, file layout inside `scripts/`, test harness choice (plain Bash assertions vs `bats`; prefer plain Bash to avoid a dependency), wording of docs, and whether to add zizmor.

### Deferred Ideas (OUT OF SCOPE)
- Full leak-response runbook in `SECURITY.md` - Phase 2
- Dependency licence allowlist CI step - Phase 2 (CI foundation)
- Deploy workflow hardening (protected `production` environment, release-tag ancestry check) - Phase 3
- `.ddev/` config hygiene specifics - Phase 2 when DDEV is introduced
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| HYG-01 | `.gitignore` excludes env files, local AI/IDE settings, storage, logs, dumps, exports, local-data dir; reviewed every phase | Current `.gitignore` gap analysis (esp. untracked `.claude/` framework tree), verified `.claude/*` + `!.claude/CLAUDE.md` pattern |
| HYG-02 | `scripts/check-sensitive.sh` fails on non-example e-mails, 8-digit IDs, IBAN/account numbers, public IPs, hosting hostnames, key prefixes in staged files | Validated portable awk scanner (BWK awk, mawk, gawk), diff parser with file:line, masking, allowlist format |
| HYG-03 | Local denylist via `KOKPIT_DENYLIST`, generic-only when absent | `grep -F -i -f` approach, sanitisation pitfalls, locale behaviour verified |
| HYG-04 | Versioned pre-commit hook runs the check and gitleaks on staged changes | **lefthook 2.1.17** `lefthook.yml`, install idempotence, partial commit, bypass and fail-closed behaviour all tested; `gitleaks git --pre-commit --staged` |
| HYG-05 | `.gitleaks.toml` + CI step over full history with same checks | gitleaks 8.30.1 config schema tested, hardened pinned workflow linted clean by actionlint + zizmor, `--history` mode for the shell checker |
| HYG-06 | `CLAUDE.md` + CONTRIBUTING document fictional-data rule | CLAUDE.md has GSD-managed marker blocks: put the rule outside them |
| HYG-07 | GitHub secret scanning/push protection + review procedure documented | Current repo settings read via `gh api` (already enabled); org ruleset requires a status check named `CI Passed` |
</phase_requirements>

## Summary

Phase 1 is pure tooling: Bash + awk scripts, a gitleaks config, a lefthook config, one GitHub Actions workflow, and Markdown docs. Everything was prototyped and executed in this session on macOS (bash 3.2 at `/bin/bash`, BSD grep, BWK awk 20200816) and on Ubuntu 24.04 in Docker (bash 5.2, GNU grep 3.11, **mawk 1.3.4**, git 2.43). The most important portability finding: a single awk program (POSIX ERE only) gives identical results on BWK awk, mawk and gawk, but **mawk panics on anchors inside groups and on some `(group){m,n}` forms**, so boundary checks must be done in awk code, not in the regex.

Key facts that change the plan relative to CONTEXT.md: (1) the repository is **public** and sits in a GitHub **organization** that has an **org-level ruleset on `main`** requiring a pull request and a required status check literally named `CI Passed`; the workflow must therefore expose a job named `CI Passed` or no PR can ever merge. (2) The two local commits (`docs: map existing codebase`, `chore: add .gitignore`) are **not pushed** - `git ls-remote` shows only the initial commit on `origin/main` - so D-16's "already published" premise is false and a local history rewrite is cheap and safe before the first push (needs owner confirmation). (3) GitHub secret scanning and push protection are **already enabled** on the repo (read via `gh api`); HYG-07 is mostly documentation. (4) `gitleaks protect`/`detect` still work but are deprecated and hidden; use `gitleaks git --pre-commit --staged` for the hook and `gitleaks git` for history. (5) Bare 8-digit numbers will also match the project's own invoice-number examples (`YYYYNNNN`), so the allowlist needs path-scoped entries.

**Primary recommendation:** Build `scripts/check-sensitive.sh` as a thin Bash driver around two awk programs (`scripts/lib/diff2tsv.awk`, `scripts/lib/scan.awk`, reference implementations below), wire it and `gitleaks git --pre-commit --staged` into a `lefthook.yml` with `assert_lefthook_installed: true`, run the same script plus pinned-binary gitleaks in a SHA-pinned `hygiene.yml` that includes an aggregator job named `CI Passed`, and prove everything with plain-Bash tests that build fake secrets from string fragments at runtime.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Staged-content scanning (HYG-02/03) | Local dev tooling (Bash/awk script) | CI (same script, `--all`/`--history`) | Must run on a bare checkout with no PHP/Node; CI re-runs it because hooks are bypassable |
| Secret scanning in staged diff (HYG-04) | Local git hook via lefthook | CI full-history gitleaks | Fast local feedback; CI is authoritative |
| Hook installation (HYG-04) | Developer machine (`scripts/install-hooks.sh` -> `lefthook install`) | - | Hooks live in `.git/hooks`, never cloned |
| Full-history secret scan (HYG-05) | CI (GitHub Actions, pinned gitleaks binary) | - | Only place the whole history is guaranteed available (`fetch-depth: 0`) |
| Workflow security lint (HYG-05) | CI (actionlint + zizmor, pinned) | - | Pitfall 15 mitigation |
| Merge gating | GitHub org ruleset (required check `CI Passed`) | CI aggregator job | The ruleset exists already; workflow must satisfy it |
| Secret scanning + push protection (HYG-07) | GitHub platform settings | Docs (CONTRIBUTING checklist) | Settings cannot be set from code in CI (admin scope needed) |
| Fictional-data rule (HYG-06) | Docs (`CLAUDE.md`, `CONTRIBUTING.md`) | Denylist (local) | Policy + human review |

## Standard Stack

### Core
| Tool | Version | Purpose | Why Standard |
|------|---------|---------|--------------|
| gitleaks | 8.30.1 (published 2026-03-21) | Secret scanning of staged diff and full history | MIT, single static binary, config-driven; `[VERIFIED: gh api repos/gitleaks/gitleaks/releases/latest]` |
| lefthook | 2.1.17 (published 2026-10-05) | Local git hook manager (owner decision D-08) | Single Go binary, `lefthook.yml` versioned in repo; `[VERIFIED: gh api repos/evilmartians/lefthook/releases/latest]`, also `lefthook version` = 2.1.17 locally |
| actionlint | 1.7.12 (2026-03-30) | Workflow syntax + embedded shellcheck | `[VERIFIED: gh api]`, ran clean on the draft workflow |
| zizmor | 1.30.1 (2026-09-09) | Workflow security audit (injection, unpinned uses, permissions) | `[VERIFIED: gh api]`; flagged a deliberately bad workflow with 5 findings, exit 14, and passed the draft with 0 findings (regular + pedantic) |
| actions/checkout | v7.0.1 = `3d3c42e5aac5ba805825da76410c181273ba90b1` | Repo checkout in CI | `[VERIFIED: gh api repos/actions/checkout/git/ref/tags/v7.0.1]` (lightweight tag, commit SHA) |
| Bash + POSIX awk + git | bash 3.2-compatible; awk = BWK/mawk/gawk | The checker | No extra dependency; tested on all three awks |
| shellcheck | 0.11.0 locally; 0.9.0 preinstalled on `ubuntu-24.04` runners `[CITED: actions/runner-images Ubuntu2404-Readme.md]` | Lint scripts (optional but recommended in CI via actionlint integration) | |

### Supporting
| Tool | Version | Purpose | When to Use |
|------|---------|---------|-------------|
| mise | 2026.10.3 (already on the owner's machine) | Optional local version pin file `mise.toml` for lefthook + gitleaks | `mise ls-remote` lists lefthook 2.1.17 and gitleaks 8.30.1 `[VERIFIED: mise ls-remote]` |
| gh CLI | present locally | Read-only settings audit (`gh api repos/{owner}/{repo}`) | Manual audit helper only (needs admin token; not for CI) |
| Docker (OrbStack) | 29.4.0 | Run Ubuntu 24.04 to test GNU/mawk behaviour locally | Local verification of portability; not a project dependency |

### Alternatives Considered
| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `gitleaks/gitleaks-action` | pinned binary | Action needs a (free) `GITLEAKS_LICENSE` for organization-owned repos `[CITED: github.com/gitleaks/gitleaks-action README]`; the repo is org-owned -> use the binary (D-12) |
| awk scanner | `grep -P` / `bats` | `grep -P` is absent on macOS BSD grep; `bats` adds a dependency (discretion: plain Bash) |
| `.githooks` + `core.hooksPath` | lefthook | Owner decision D-08 supersedes; lefthook refuses to install when `core.hooksPath` is set (see Pitfall 9) |
| `pre-commit` framework (Python) | lefthook | Adds Python; not chosen by owner |
| zizmor online mode | `--offline` | Online adds audits needing a token; offline needs none and passed clean; add `GH_TOKEN: ${{ secrets.GITHUB_TOKEN }}` (read-only perms) later if wanted |

**Installation (local, developer):**
```bash
brew install lefthook gitleaks        # or: mise use lefthook@2.1.17 gitleaks@8.30.1
scripts/install-hooks.sh              # checks both tools, then runs `lefthook install`
```
Neither tool is a Composer/npm dependency. Locally **gitleaks is currently not installed** on the owner's machine (`command -v gitleaks` empty); lefthook 2.1.17 is installed at the Homebrew prefix.

**Version verification (done this session):** `gh api repos/<owner>/<repo>/releases/latest` for all tools above; tarballs for gitleaks, actionlint and zizmor were downloaded into clean directories and their SHA-256 matched the pinned values below; `shasum -a 256 -c` printed `OK` for each.

### Pinned CI artefacts (SHA-256 of the exact tarballs, all verified by download)
| Artefact | URL stem (release tag) | SHA-256 |
|----------|------------------------|---------|
| gitleaks 8.30.1 linux x64 | `github.com/gitleaks/gitleaks/releases/download/v8.30.1/gitleaks_8.30.1_linux_x64.tar.gz` | `551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb` |
| actionlint 1.7.12 linux amd64 | `github.com/rhysd/actionlint/releases/download/v1.7.12/actionlint_1.7.12_linux_amd64.tar.gz` | `8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8` |
| zizmor 1.30.1 linux x86_64 | `github.com/zizmorcore/zizmor/releases/download/v1.30.1/zizmor-x86_64-unknown-linux-gnu.tar.gz` | `e65324f4430c2717591937edcec90ccbefaf14c174f8ec9415e03ca875b46e1a` |

Hard-code the digests in the workflow (do **not** download a checksums file from the same release and trust it: that verifies nothing if the release is tampered with).

## Package Legitimacy Audit

No npm/PyPI/crates/Composer packages are installed by this phase. All tools are prebuilt release binaries from the official upstream GitHub repositories, verified by SHA-256 against hard-coded digests (and, for gitleaks and actionlint, additionally against the upstream `checksums.txt`).

| Package | Registry | Age | Downloads | Source Repo | Verdict | Disposition |
|---------|----------|-----|-----------|-------------|---------|-------------|
| gitleaks | GitHub release | multi-year (v8.27.2 2025-06 ... v8.30.1 2026-03) | n/a | github.com/gitleaks/gitleaks | n/a (not a registry package) | Approved, checksum-pinned |
| lefthook | GitHub release / Homebrew / mise | multi-year | n/a | github.com/evilmartians/lefthook | n/a | Approved (local only) |
| actionlint | GitHub release | multi-year | n/a | github.com/rhysd/actionlint | n/a | Approved, checksum-pinned |
| zizmor | GitHub release | multi-year | n/a | github.com/zizmorcore/zizmor | n/a | Approved, checksum-pinned |

**Packages removed due to [SLOP] verdict:** none. **Packages flagged [SUS]:** none. The `gsd-tools package-legitimacy` seam targets npm/PyPI/crates and was not applicable.

## Architecture Patterns

### System Architecture Diagram

```
 developer: git commit
        |
        v
 .git/hooks/pre-commit (generated by `lefthook install`)
        |  LEFTHOOK=0 or --no-verify -> bypass (CI still catches)
        v
 lefthook run pre-commit  (reads lefthook.yml, cwd = repo root)
   |-- job sensitive-content: scripts/check-sensitive.sh
   |        |
   |        |  git diff --cached -U0 --diff-filter=ACMR
   |        v
   |   diff2tsv.awk  -> path<TAB>line<TAB>added-text
   |        |-> scan.awk (generic rules + allowlist) -> "path:line: rule [masked]"
   |        |-> grep -F -i -f <sanitised KOKPIT_DENYLIST>   (only if set)
   |        v
   |   exit 1 on any hit (2 on usage/unreadable denylist)
   '-- job gitleaks: gitleaks git --pre-commit --staged --redact --config .gitleaks.toml
            -> exit 1 on leak; exit 127 if binary missing (fail_text prints install hint)

 push / pull_request  -> .github/workflows/hygiene.yml  (permissions: {} ; job: contents: read)
   job scan:  checkout (fetch-depth 0, no creds) -> install pinned gitleaks (sha256sum -c)
              -> scripts/tests/run.sh   (planted fakes built at runtime)
              -> scripts/check-sensitive.sh --all [--history]   (no denylist)
              -> gitleaks git --config .gitleaks.toml --redact   (full history)
   job workflow-lint: pinned actionlint + zizmor --offline
   job CI Passed (needs: scan, workflow-lint; if: always()) -> required status check of the org ruleset
```

### Recommended Project Structure
```
scripts/
  check-sensitive.sh          # driver: modes --staged (default) | --all | --history | <files...>; denylist; exit codes
  lib/diff2tsv.awk            # unified diff -> TSV of added lines
  lib/scan.awk                # generic rules, allowlist, masking
  sensitive-allowlist.txt     # reviewed exemptions (rule ;; path-ERE ;; value-ERE)
  install-hooks.sh            # checks lefthook + gitleaks, runs `lefthook install`
  tests/run.sh                # runs every test-*.sh, prints summary, non-zero on failure
  tests/lib.sh                # assert helpers + fake-value builders
  tests/test-check-sensitive.sh
  tests/test-denylist.sh
  tests/test-gitleaks.sh      # skips with notice if gitleaks absent locally; mandatory in CI
  tests/test-lefthook.sh      # local only; skips with notice if lefthook absent; NOT run in CI
lefthook.yml
.gitleaks.toml
.github/workflows/hygiene.yml
.github/dependabot.yml        # ecosystem github-actions, weekly (updates SHA pins + version comments)
CONTRIBUTING.md               # hygiene section, review procedure, GitHub settings checklist
mise.toml (optional)          # [tools] lefthook = "2.1.17", gitleaks = "8.30.1"
```
D-05 exclusions should be **exact paths** (`scripts/check-sensitive.sh`, `scripts/lib/scan.awk`, `scripts/lib/diff2tsv.awk`, `scripts/sensitive-allowlist.txt`, `.gitleaks.toml`), not directories, so a secret cannot hide in a new file under `scripts/`. Put `.github/CODEOWNERS` on these paths and on `.github/workflows/`. (The sources of the scanner do not themselves trip the generic rules; the allowlist file and tests with literal examples do - hence the exemptions and the fragment technique.)

### Pattern 1: Two-stage awk pipeline (TSV records)
**What:** Producers emit `path<TAB>line<TAB>text`; one scanner consumes it. Producers: staged diff (default), `git grep --cached -I -n -z -e ''` piped through `tr '\0' '\t'` (`--all`; skips binaries; verified output shape is `path\0line\0text\n`), `git log --all -p -U0 ...` through the same diff parser (`--history`), or explicit files via `awk '{print FILENAME "\t" FNR "\t" $0}'`.
**When to use:** Always; it keeps file:line reporting identical in every mode.

`scripts/lib/diff2tsv.awk` (verified on macOS awk, mawk, gawk; handles filenames with spaces and added lines that begin with `++`):
```awk
# diff2tsv.awk - unified diff (git diff -U0 / git log -p -U0) -> "path<TAB>line<TAB>text" for added lines.
# Hunk line counts are tracked so an added line whose text starts with "++" is never mistaken for a "+++" header.
BEGIN { OFS = "\t"; ro = 0; rn = 0; ln = 0; path = "" }
{
  if (ro > 0 || rn > 0) {
    c = substr($0, 1, 1)
    if (c == "+") { if (path != "") print path, ln, substr($0, 2); ln++; rn--; next }
    if (c == "-") { ro--; next }
    if (c == "\\") next
    if (c == " ") { ro--; rn--; ln++; next }
    ro = 0; rn = 0
  }
  if ($0 ~ /^@@ /) {
    match($0, /\+[0-9]+(,[0-9]+)?/); spec = substr($0, RSTART + 1, RLENGTH - 1)
    n = split(spec, a, ","); ln = a[1] + 0; rn = (n == 2) ? a[2] + 0 : 1
    match($0, /-[0-9]+(,[0-9]+)?/); spec = substr($0, RSTART + 1, RLENGTH - 1)
    n = split(spec, a, ","); ro = (n == 2) ? a[2] + 0 : 1
    next
  }
  if ($0 ~ /^\+\+\+ /) { p = substr($0, 5); sub(/\t.*$/, "", p); if (p == "/dev/null") path = ""; else { sub(/^b\//, "", p); path = p }; next }
  if ($0 ~ /^diff --git /) { path = ""; next }
}
```
Invoke with explicit prefixes so user git config (`diff.noprefix`, `diff.mnemonicPrefix`) cannot change the format:
```bash
git -c core.quotepath=off diff --cached -U0 --no-color --no-ext-diff \
    --src-prefix=a/ --dst-prefix=b/ --diff-filter=ACMR | LC_ALL=C awk -f scripts/lib/diff2tsv.awk
```
For `--history` use `git log --all -p -U0 --no-color --no-ext-diff --src-prefix=a/ --dst-prefix=b/ --diff-filter=ACMR --format='commit %H'` through the same parser (verified); prefix the reported path with the short commit hash (extend the parser to capture `^commit `) so a finding names the commit.

### Pattern 2: The scanner (`scripts/lib/scan.awk`) - reference implementation
Verified identical output on BWK awk 20200816 (macOS), mawk 1.3.4 20240123 (Ubuntu 24.04 default awk) and gawk. All fake values in the fixtures were assembled from fragments. Constraints that shaped it (each was hit during testing):
- **mawk panics** (`REcompile() - panic`) on `^`/`$` inside groups, on `( ?[A-Z0-9]{1,4})?`-style quantified groups with intervals, and mis-matches `([0-9]{2,6}-)?[0-9]{6,10}/...`. mawk also rejected one very long (~270 char) string literal ("runaway string constant"). Therefore: simple character-class cores (`[0-9]{8}`, `[A-Z0-9 ]+`), boundary checks in code (`scan_re` below), validation in code (IBAN mod 97, CZ account shape, IPv4 range), and short regex literals.
- Pass the allowlist path via `ENVIRON["CS_ALLOWLIST"]`, not `-v` (backslash processing in `-v`).
- Run with `LC_ALL=C` so classes like `[A-Z]` are byte-ASCII.

```awk
# scan.awk - input TSV "path<TAB>line<TAB>text"; output "path:line: rule [masked]"; exit 1 if any finding.
function mask(s,   n) { n = length(s); if (n <= 4) return "****"; return substr(s, 1, 2) "*** (" n " chars)" }
function allowed(rule, p, val,   i) {
  for (i = 1; i <= na; i++)
    if (ar[i] == rule && (ap[i] == "*" || p ~ ap[i]) && (av[i] == "*" || val ~ av[i])) return 1
  return 0
}
function hit(rule, val) { if (allowed(rule, path, val)) return; printf "%s:%s: %s [%s]\n", path, ln, rule, mask(val); found = 1 }
function ipv4_public(ip,   o, a, b, c) {
  split(ip, o, "."); a = o[1] + 0; b = o[2] + 0; c = o[3] + 0
  if (o[1] + 0 > 255 || o[2] + 0 > 255 || o[3] + 0 > 255 || o[4] + 0 > 255) return 0
  if (a == 0 || a == 10 || a == 127) return 0                      # this-net, RFC1918, loopback
  if (a == 169 && b == 254) return 0                                # link-local
  if (a == 172 && b >= 16 && b <= 31) return 0                      # RFC1918
  if (a == 192 && b == 168) return 0                                # RFC1918
  if (a == 192 && b == 0 && c == 2) return 0                        # TEST-NET-1
  if (a == 198 && b == 51 && c == 100) return 0                     # TEST-NET-2
  if (a == 203 && b == 0 && c == 113) return 0                      # TEST-NET-3
  if (a == 100 && b >= 64 && b <= 127) return 0                     # CGNAT / Tailscale
  if (a == 198 && (b == 18 || b == 19)) return 0                    # benchmarking
  if (a >= 224) return 0                                            # multicast / reserved / broadcast
  return 1
}
function iban_len_ok(s,   cc) {
  cc = substr(s, 1, 2)
  return index(" CZ24 SK24 DE22 AT20 PL28 HU28 GB22 FR27 IT27 ES24 NL18 BE16 CH21 LU20 DK18 SE24 NO15 FI18 IE22 PT25 RO24 BG22 HR21 SI19 LT20 LV21 EE20 GR27 CY28 MT31 IS26 LI21 ", " " cc length(s) " ") > 0
}
function iban_valid(s,   i, ch, r, rearr, n, pos) {       # ISO 13616 mod 97, digit by digit (no bignum)
  rearr = substr(s, 5) substr(s, 1, 4); r = 0; n = length(rearr)
  for (i = 1; i <= n; i++) {
    ch = substr(rearr, i, 1)
    if (ch ~ /[0-9]/) r = (r * 10 + ch) % 97
    else { pos = index("ABCDEFGHIJKLMNOPQRSTUVWXYZ", ch); if (pos == 0) return 0; r = (r * 100 + pos + 9) % 97 }
  }
  return r == 1
}
function email_ok(addr,   dom) {
  dom = tolower(substr(addr, index(addr, "@") + 1))
  if (dom ~ /(^|\.)example\.(com|org|net)$/) return 1
  if (dom ~ /\.(test|invalid|localhost|example)$/) return 1
  return dom == "localhost"
}
# Leftmost non-overlapping matches of core regex `re`; accept only if the char before does not match `lbad`
# and the char after does not match `rbad` (boundaries in code - mawk panics on anchors inside groups).
function scan_re(rule, re, lbad, rbad, s,   off, rest, tok, st, en, cb, ca, cn, ok) {
  off = 1
  while (off <= length(s)) {
    rest = substr(s, off); if (!match(rest, re)) break
    st = off + RSTART - 1; en = st + RLENGTH; tok = substr(s, st, RLENGTH)
    cb = (st > 1) ? substr(s, st - 1, 1) : ""; ca = (en <= length(s)) ? substr(s, en, 1) : ""
    cn = (en + 1 <= length(s)) ? substr(s, en + 1, 1) : ""
    ok = 1
    if (cb != "" && lbad != "" && cb ~ lbad) ok = 0
    if (ca != "" && rbad != "" && ca ~ rbad) ok = 0
    if (ca == "." && cn ~ /[0-9]/ && rbad != "") ok = 0       # "1.2.3.4.5" / "20260001.2" are version-like
    if (ok) { handle(rule, tok); off = en } else off = st + 1
  }
}
function handle(rule, tok,   t, ip, n, w, k, m, q) {
  if (rule == "email") { if (!email_ok(tok)) hit(rule, tok) }
  else if (rule == "company-id") { t = tok; gsub(/[^0-9]/, "", t); hit(rule, t) }
  else if (rule == "public-ip") {
    match(tok, /[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/); ip = substr(tok, RSTART, RLENGTH)
    if (ipv4_public(ip)) hit(rule, ip)
  } else if (rule == "cz-account") {      # loose core regex, shape check here: [prefix-]number/bank
    split(tok, w, "/"); m = split(w[1], q, "-")
    if (m == 1 && length(q[1]) >= 6 && length(q[1]) <= 10) hit(rule, tok)
    else if (m == 2 && length(q[1]) >= 2 && length(q[1]) <= 6 && length(q[2]) >= 2 && length(q[2]) <= 10) hit(rule, tok)
  } else if (rule == "iban") {            # run of [A-Z0-9 ]; longest whole-word prefix of exact country length that passes mod 97
    n = split(tok, w, " ")
    for (k = n; k >= 1; k--) { t = ""; for (m = 1; m <= k; m++) t = t w[m]; if (iban_len_ok(t) && iban_valid(t)) { hit(rule, t); break } }
  } else if (rule == "home-path") {
    if (tok !~ /\/(Users|home)\/(example|user|username|name|you|runner|vagrant|ubuntu|www-data|ddev|Shared)\//) hit(rule, tok)
  } else hit(rule, tok)
}
BEGIN {
  FS = "\t"; found = 0; na = 0; al = ENVIRON["CS_ALLOWLIST"]
  if (al != "") {
    while ((getline line < al) > 0) {
      if (line ~ /^[ \t]*(#|$)/) continue
      n = split(line, f, / ;; /); na++; ar[na] = f[1]; ap[na] = (n >= 2 ? f[2] : "*"); av[na] = (n >= 3 ? f[3] : "*")
    }
    close(al)
  }
}
{
  path = $1; ln = $2; text = $0; sub(/^[^\t]*\t[^\t]*\t/, "", text)
  scan_re("email",        "[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\\.[A-Za-z][A-Za-z]+", "[A-Za-z0-9._%+-]", "", text)
  scan_re("company-id",   "[0-9]{8}", "[0-9A-Za-z_./-]", "[0-9A-Za-z_-]", text)
  scan_re("public-ip",    "[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}\\.[0-9]{1,3}", "[0-9A-Za-z_.-]", "[0-9A-Za-z_-]", text)
  scan_re("iban",         "[A-Z][A-Z][0-9][0-9][A-Z0-9 ]+", "[A-Za-z0-9]", "", text)
  scan_re("cz-account",   "[0-9][0-9-]*[0-9]/[0-9]{4}", "[0-9A-Za-z_./-]", "[0-9A-Za-z_-]", text)
  scan_re("hosting-host", "[A-Za-z0-9.-]+\\.(zerops\\.app|amazonaws\\.com|r2\\.cloudflarestorage\\.com)", "", "[A-Za-z0-9_-]", text)
  scan_re("hosting-host", "[A-Za-z0-9.-]+\\.(backblazeb2\\.com|wasabisys\\.com|digitaloceanspaces\\.com|linodeobjects\\.com)", "", "[A-Za-z0-9_-]", text)
  scan_re("key-prefix",   "(sk|rk|pk)_live_[A-Za-z0-9]{8,}|whsec_[A-Za-z0-9]{8,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "plink_[A-Za-z0-9]{8,}|acct_[A-Za-z0-9]{8,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "gh[pousr]_[A-Za-z0-9]{20,}|github_pat_[A-Za-z0-9_]{20,}", "[A-Za-z0-9_]", "", text)
  scan_re("key-prefix",   "AKIA[0-9A-Z]{16}", "[A-Za-z0-9_]", "", text)
  scan_re("home-path",    "/(Users|home)/[A-Za-z0-9._-]+/", "", "", text)
}
END { exit(found ? 1 : 0) }
```
Design notes (all verified by the fixture run): key rules **require a body** after the prefix, so documentation that merely names `sk_live_`, `plink_` etc. does not trip the scanner (the three planning docs that name prefixes produced zero key-prefix hits); UUIDs (`12345678-aaaa-...`) and long digit runs are excluded by the boundary classes; month/year strings like `10/2026` do not look like accounts (6+ digit number required without prefix); `v1.2.3.4`-style version strings preceded by a letter, and octets above 255, are ignored; the IBAN rule only fires on checksum-valid IBANs of the exact country length, so random uppercase identifiers do not match.

Allowlist file format (recommended; third field matches the **matched value**, not the whole line, so exemptions stay narrow):
```
# rule ;; path-ERE ;; matched-value-ERE      (third field optional; '*' = any)
company-id ;; * ;; ^(12345678|00000000)$
company-id ;; ^docs/ ;; ^20[0-9]{6}$
email ;; * ;; ^git@github\.com$
```
Verified: path-scoped entry exempts `docs/...` but the same value under `src/` still fails.

### Pattern 3: Driver script conventions (bash 3.2 safe)
Exit codes: `0` clean, `1` findings, `2` usage/unreadable denylist/not a git repo. Denylist stage (D-06):
```bash
# sanitise: strip CR, trim, drop blanks and comments. An empty pattern in `grep -f` matches EVERYTHING (verified: 4/4 lines).
sed -e 's/\r$//' -e 's/^[[:space:]]*//' -e 's/[[:space:]]*$//' "$KOKPIT_DENYLIST" | grep -v -e '^#' -e '^$' > "$tmp/deny"
# match case-insensitively against path+line+text rows; print only path:line (never the term or the text)
[ -s "$tmp/deny" ] && LC_ALL=C.UTF-8 grep -F -i -f "$tmp/deny" "$tmp/rows.tsv" | cut -f1,2 | tr '\t' ':' | sed 's/$/: denylist/'
```
Locale: under `LC_ALL=C` `-i` does **not** fold non-ASCII (`NOVÁK` was missed for term `novák`, verified on BSD and GNU grep); under `LC_ALL=C.UTF-8` it does (verified: macOS has `C.UTF-8`, Ubuntu has `C.UTF-8`). Use `C.UTF-8` for the denylist grep only; keep `LC_ALL=C` for the awk scanner. Unset or missing denylist: print one notice line to stderr, continue. Set but unreadable: print an error and `exit 2`.

### Pattern 4: lefthook configuration (D-08/D-09)
`lefthook.yml` (validated with `lefthook validate` = "All good"; behaviour tested end to end, see below):
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
`gitleaks git --pre-commit --redact --staged --verbose` is exactly what upstream's own `.pre-commit-hooks.yaml` runs `[VERIFIED: raw.githubusercontent.com/gitleaks/gitleaks/v8.30.1/.pre-commit-hooks.yaml]`. Neither job uses `{staged_files}`: both tools read the index themselves, so the job always runs and no glob/quoting problem arises (lefthook docs do not state how `{staged_files}` is quoted; avoid it). Jobs run sequentially by default and both run even when the first fails (verified), so a developer sees all findings at once.

Verified behaviour (lefthook 2.1.17, scratch repo; hooks installed into `.git/hooks`, `core.hooksPath` stays unset):
| Scenario | Result |
|----------|--------|
| `lefthook install` twice | Output `sync hooks: pre-commit` both times; hook file md5 identical -> idempotent. `lefthook check-install` exits 0. |
| Commit with a staged hit | Both jobs run, findings printed as `path:line: rule [masked]`, commit aborted (exit 1) |
| `git commit B.txt` while A.txt (with a hit) is staged | Passes: git uses a temporary index for the path-limited commit, `git diff --cached` inside the hook sees only B. A stays staged and is caught by the next full commit and by CI |
| Unstaged modification containing a hit | Not scanned, not stashed (lefthook does not stash by default); working tree untouched after the commit |
| `git commit --no-verify` | Hook skipped (commit succeeds). Also `LEFTHOOK=0 git commit` skips (hook script checks the variable `[VERIFIED: generated .git/hooks/pre-commit]`). Both are why CI is authoritative |
| Commit from a sub-directory | Job runs from the repo root; paths in findings are repo-relative |
| `gitleaks` missing | Job exits 127 (`gitleaks: command not found`), commit blocked, `fail_text` shows the install hint -> natively fail-closed |
| `lefthook` missing, `assert_lefthook_installed: true` | Generated hook prints "ERROR: Operation is aborted due to lefthook settings" and `exit 1`. **Without** the option the hook merely prints "Can't find lefthook in PATH" and continues (fail-open) - verified by regenerating the hook without the option |
| `core.hooksPath` already set | `lefthook install` refuses (exit 1) and prints `lefthook install --force` / `--reset-hooks-path` hint |
| `min_version: 99.0.0` | `validate`/`install` still succeed, but `lefthook run` aborts: "required lefthook version (99.0.0) is higher than current (2.1.17)" |

`lefthook run pre-commit` runs the same jobs manually. lefthook re-syncs hooks automatically when a run notices a changed config (it printed `sync hooks` during a commit).

`scripts/install-hooks.sh` (thin wrapper): `set -eu`; `command -v lefthook` else print `brew install lefthook` / `mise use lefthook@2.1.17` hint and `exit 1`; same for `gitleaks`; `lefthook install`; `lefthook check-install`; optionally warn (not fail) if `gitleaks version` differs from the documented version. Run from any directory by `cd "$(dirname "$0")/.."` without `readlink -f` (absent in macOS bash 3.2 era tooling).

**Pinning/documenting the lefthook version (D-09):** (1) `min_version: 2.1.0` inside `lefthook.yml` (enforced at `lefthook run`, i.e. in the hook); (2) document "tested with lefthook 2.1.17, gitleaks 8.30.1" in CONTRIBUTING; (3) optional `mise.toml` `[tools] lefthook = "2.1.17"  gitleaks = "8.30.1"` (the owner already uses mise; both versions exist in `mise ls-remote`); Homebrew cannot pin an exact historic version, so brew users get latest. Install options (from lefthook's published channels `[CITED: lefthook.dev/print.html via search]`): `brew install lefthook`, `mise use lefthook`, `go install github.com/evilmartians/lefthook/v2@v2.1.17`, npm/gem/pip packages, distro packages - use brew/mise only; avoid the npm package (its postinstall auto-installs hooks and implies a Node dependency). Never reference lefthook in CI.

### Pattern 5: gitleaks configuration (`.gitleaks.toml`, tested)
Candidate verified against planted fakes (4 rules fired, allowlisted values/placeholders did not):
```toml
title = "Kokpit gitleaks config"

[extend]
useDefault = true

[[rules]]
id = "kokpit-stripe-publishable-or-webhook"
description = "Stripe publishable live key or webhook signing secret"
regex = '''\b(?:pk_live_|whsec_)[A-Za-z0-9]{16,}'''
keywords = ["pk_live_", "whsec_"]

[[rules]]
id = "kokpit-stripe-object-id"
description = "Stripe account id or Payment Link id"
regex = '''\b(?:acct|plink)_[A-Za-z0-9]{12,}'''
keywords = ["acct_", "plink_"]

[[rules]]
id = "kokpit-home-path"
description = "Personal absolute home directory path"
regex = '''/(?:Users|home)/[A-Za-z0-9._-]+/'''
keywords = ["/users/", "/home/"]
[[rules.allowlists]]
regexes = ['''/(?:Users|home)/(?:example|user|username|name|you|runner|vagrant|ubuntu|www-data)/''']

[[rules]]
id = "kokpit-ico-like"
description = "Company-ID-like 8-digit number next to an ID keyword"
regex = '''(?i)\b(?:ico|ič|ičo|company[_ -]?id)\b["']?\s*[:=]\s*["']?(\d{8})\b'''
secretGroup = 1
keywords = ["ico", "ič", "company"]
[[rules.allowlists]]
stopwords = ["12345678", "00000000"]
```
Schema facts `[CITED: github.com/gitleaks/gitleaks README, v8.30.1 default config]`: `[extend] useDefault = true` (also `path`, `disabledRules`); rule fields `id, description, regex, secretGroup, entropy, path, keywords, tags`; allowlists `[[rules.allowlists]]` / global `[[allowlists]]` with `condition, commits, paths, regexes, regexTarget, stopwords` (global also `targetRules`); inline `gitleaks:allow` comment, `.gitleaksignore` (fingerprint), baseline via `--baseline-path`. Policy: D-04 forbids inline ignore magic for the shell checker; for gitleaks, pass `--ignore-gitleaks-allow` is available if the owner wants the same strictness there (`[VERIFIED: gitleaks --help]`).
- Default ruleset already covers `sk_`/`rk_` live+test Stripe tokens (`stripe-access-token`, entropy 2) and `ghp_` PATs (`github-pat`, entropy 3) `[CITED: v8.30.1 config/gitleaks.toml]`; the custom rules add what it lacks (`pk_live_`, `whsec_`, `acct_`, `plink_`, home paths, IČO context).
- Bare 8-digit numbers are deliberately **not** a gitleaks rule (would flood on invoice numbers); the keyword-anchored IČO rule is the gitleaks layer, the bare rule lives in the shell checker where path-scoped allowlisting is easy.
- Zerops token format is not documented with a stable prefix (docs only describe generated personal access tokens `[CITED: docs.zerops.io/zcp/security/tokens-and-project-access]`); cover it with a keyword rule on assignments such as `ZEROPS_TOKEN` (`[ASSUMED]` pattern; no public format to anchor on) and rely on default `generic-api-key`. Zerops subdomains end in `.zerops.app` `[CITED: docs.zerops.io public-access]`; the shell checker's `hosting-host` rule covers this; other Zerops suffixes are `[ASSUMED]`.
- **Always pass `--redact`** in CI and hooks: without it, `gitleaks git -v` prints the secret value (verified).
- Always pass `--config .gitleaks.toml` explicitly (auto-discovery order: `--config`, `GITLEAKS_CONFIG`, `GITLEAKS_CONFIG_TOML`, `<target>/.gitleaks.toml`).
- CLI today (v8.30.1): commands `git`, `dir`, `stdin` (+ `completion`, `version`); `protect` and `detect` are **deprecated since v8.19.0, hidden from `--help`, still functional** (verified: `gitleaks protect --staged` ran). Use `gitleaks git --pre-commit --staged` (hook), `gitleaks git` (full history; default log range covers all refs of the checkout; verified a secret present only in an old, later-removed commit is still reported, exit 1), `gitleaks dir` (working tree). Exit codes: 0 clean, 1 leak (configurable `--exit-code`), 126 unknown flag.

### Pattern 6: Hardened workflow (`.github/workflows/hygiene.yml`)
This exact file was linted clean by actionlint 1.7.12 (with shellcheck 0.11.0 integration) and zizmor 1.30.1 (`--offline`, default and `--persona=pedantic`: "No findings"). A negative control (`pull_request_target`, `@v4`, `${{ github.event.pull_request.title }}` in `run:`, no permissions) produced 5 findings and exit 14, proving the lint bites.
```yaml
name: Hygiene

on:
  push:
    branches: [main]
  pull_request:
  workflow_dispatch:

permissions: {}

concurrency:
  group: hygiene-${{ github.workflow }}-${{ github.ref }}
  cancel-in-progress: true

jobs:
  scan:
    name: Sensitive-content and secret scan
    runs-on: ubuntu-24.04
    timeout-minutes: 10
    permissions:
      contents: read
    env:
      GITLEAKS_VERSION: "8.30.1"
      GITLEAKS_SHA256: "551f6fc83ea457d62a0d98237cbad105af8d557003051f41f3e7ca7b3f2470eb"
    steps:
      - name: Check out full history
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          fetch-depth: 0
          persist-credentials: false

      - name: Install gitleaks (pinned version, pinned checksum)
        run: |
          set -euo pipefail
          archive="${RUNNER_TEMP}/gitleaks.tar.gz"
          curl -fsSL --retry 3 -o "${archive}" \
            "https://github.com/gitleaks/gitleaks/releases/download/v${GITLEAKS_VERSION}/gitleaks_${GITLEAKS_VERSION}_linux_x64.tar.gz"
          echo "${GITLEAKS_SHA256}  ${archive}" | sha256sum --check --strict -
          mkdir -p "${RUNNER_TEMP}/bin"
          tar -xzf "${archive}" -C "${RUNNER_TEMP}/bin" gitleaks
          echo "${RUNNER_TEMP}/bin" >> "${GITHUB_PATH}"

      - name: Script self-tests (planted fake secrets built at runtime)
        run: bash scripts/tests/run.sh

      - name: Sensitive-content check (tree, no local denylist)
        run: scripts/check-sensitive.sh --all

      - name: gitleaks over full history
        run: gitleaks git --config .gitleaks.toml --redact --no-banner --exit-code 1 .

  workflow-lint:
    name: Workflow lint
    runs-on: ubuntu-24.04
    timeout-minutes: 10
    permissions:
      contents: read
    env:
      ACTIONLINT_VERSION: "1.7.12"
      ACTIONLINT_SHA256: "8aca8db96f1b94770f1b0d72b6dddcb1ebb8123cb3712530b08cc387b349a3d8"
      ZIZMOR_VERSION: "1.30.1"
      ZIZMOR_SHA256: "e65324f4430c2717591937edcec90ccbefaf14c174f8ec9415e03ca875b46e1a"
    steps:
      - name: Check out
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          persist-credentials: false

      - name: Install actionlint and zizmor (pinned versions, pinned checksums)
        run: |
          set -euo pipefail
          mkdir -p "${RUNNER_TEMP}/bin"
          curl -fsSL --retry 3 -o "${RUNNER_TEMP}/actionlint.tar.gz" \
            "https://github.com/rhysd/actionlint/releases/download/v${ACTIONLINT_VERSION}/actionlint_${ACTIONLINT_VERSION}_linux_amd64.tar.gz"
          echo "${ACTIONLINT_SHA256}  ${RUNNER_TEMP}/actionlint.tar.gz" | sha256sum --check --strict -
          tar -xzf "${RUNNER_TEMP}/actionlint.tar.gz" -C "${RUNNER_TEMP}/bin" actionlint
          curl -fsSL --retry 3 -o "${RUNNER_TEMP}/zizmor.tar.gz" \
            "https://github.com/zizmorcore/zizmor/releases/download/v${ZIZMOR_VERSION}/zizmor-x86_64-unknown-linux-gnu.tar.gz"
          echo "${ZIZMOR_SHA256}  ${RUNNER_TEMP}/zizmor.tar.gz" | sha256sum --check --strict -
          tar -xzf "${RUNNER_TEMP}/zizmor.tar.gz" -C "${RUNNER_TEMP}/bin" zizmor
          echo "${RUNNER_TEMP}/bin" >> "${GITHUB_PATH}"

      - name: actionlint
        run: actionlint -color

      - name: zizmor (offline, no token)
        run: zizmor --offline .github/workflows

  ci-passed:
    name: CI Passed
    if: ${{ always() }}
    needs: [scan, workflow-lint]
    runs-on: ubuntu-24.04
    timeout-minutes: 2
    permissions: {}
    env:
      RESULTS: ${{ join(needs.*.result, ' ') }}
    steps:
      - name: Require every gated job to have succeeded
        run: |
          set -eu
          for r in ${RESULTS}; do
            [ "${r}" = "success" ] || { echo "A required job finished as: ${r}"; exit 1; }
          done
```
Notes:
- D-11 says triggers `push` and `pull_request` only; `branches: [main]` on push avoids double runs on PR branches, and `workflow_dispatch` is optional (drop it if the owner wants literal D-11). Never `pull_request_target`, `workflow_run` or `issue_comment`.
- `ubuntu-24.04` (not `ubuntu-latest`): `ubuntu-latest` moves to Ubuntu 26.04 in November 2026 `[CITED: actions/runner-images Ubuntu2404-Readme.md announcement]`.
- Runner has bash 5.2.21, jq 1.7, git, shellcheck 0.9.0 preinstalled `[CITED: runner-images]`; mawk is the default awk on Ubuntu, which is exactly the awk the scanner was tested on.
- `fetch-depth: 0` is required so `gitleaks git` sees every commit; on `pull_request` the checkout is the merge commit with full history.
- `needs.*.result` is workflow data, not attacker-controlled event text; it is still passed via `env:` (zizmor-clean pattern).
- `ci-passed` must use `if: always()` and reject `skipped`/`cancelled`, otherwise a failed upstream job would leave the required check green.
- Add `.github/dependabot.yml` (`package-ecosystem: github-actions`) so SHA pins are bumped with the `# vX.Y.Z` comment; Dependabot does **not** track the curl-installed tool versions - document a manual bump procedure (new version + new SHA-256 from `gh api repos/<o>/<r>/releases/latest --jq '.assets[]|[.name,.digest]|@tsv'`, verify against upstream checksums).

### Anti-Patterns to Avoid
- **Using `gitleaks-action`:** needs a licence key for org-owned repos; a binary is simpler and pinned.
- **Downloading the checksum from the same release page as the binary and trusting it:** hard-code digests.
- **Regex anchors/boundaries inside awk regex groups or `grep -P`:** breaks mawk / BSD grep. Do boundaries in code.
- **Echoing matched values:** mask in the script; run gitleaks with `--redact`; for the denylist print only `path:line`, never the term.
- **Setting `core.hooksPath` alongside lefthook:** lefthook refuses to install; the owner chose lefthook, so no `.githooks/`.
- **Scanning only the PR diff in CI:** misses history; always `fetch-depth: 0` + full-history gitleaks.
- **Ignoring directories wholesale in `.gitignore` then negating:** `.claude/` + `!.claude/CLAUDE.md` does not work: "It is not possible to re-include a file if a parent directory of that file is excluded" `[CITED: git-scm.com/docs/gitignore]`. Use `.claude/*` + `!.claude/CLAUDE.md` (verified with `git check-ignore`).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Secret detection (provider token formats, entropy) | Own regex set for AWS/GitHub/Stripe `sk_` tokens | gitleaks default ruleset via `useDefault = true` | Maintained, entropy-checked; custom rules only for project-specific items |
| Hook management/installation | Own `.githooks` + `core.hooksPath` installer | lefthook (owner decision) | Idempotent install, partial-commit semantics handled by git, `fail_text`, `min_version` |
| Workflow security review | Manual review | actionlint + zizmor | Caught all 5 injected problems in the negative control |
| Checksum-verified downloads | Custom verifier | `sha256sum --check --strict -` (Linux CI) / `shasum -a 256 -c` (macOS) | Standard |
| Diff line-number tracking | `grep -n` on files | `diff2tsv.awk` hunk-count parser | `git diff -U0` + hunk counts give true post-image line numbers, handles `++`-prefixed content |
| Test framework | `bats` or similar | plain Bash assertion helpers | No dependency; the whole suite is a few dozen lines |

**Key insight:** the only genuinely custom code is project-specific pattern knowledge (Czech IČO/accounts, Zerops/S3 hostnames, home paths, denylist). Everything else (secret formats, hooks, lint) is delegated.

## Common Pitfalls

### Pitfall 1: mawk regex engine panics (Ubuntu runners)
**What goes wrong:** the scanner works on macOS and dies in CI with `REcompile() - panic: parser returns ERR_7`; or silently mis-matches.
**Why:** Ubuntu's default awk is mawk 1.3.4; it rejects `^`/`$` inside groups, some `(group){m,n}?` combinations, and very long string literals.
**How to avoid:** simple char-class cores, code-level boundaries, short literals (see Pattern 2); test in `ubuntu:24.04` (Docker) or via CI itself.
**Warning signs:** passes locally, fails only in `scan` job.

### Pitfall 2: Bash 3.2 gaps on macOS `/bin/bash`
**What goes wrong:** script dies with `declare: -A: invalid option`, `mapfile: command not found`, `bad substitution`.
**Why:** macOS ships bash 3.2.57 (verified); `#!/usr/bin/env bash` picks Homebrew bash 5 only if it is first on PATH, so never rely on it.
**How to avoid:** no `declare -A`, `mapfile`/`readarray`, `${var,,}`/`${var^^}`, `|&`, `&>>`; `read -r -d ''` and `[[ =~ ]]` work (verified); guard empty arrays under `set -u` (`"${arr[@]}"` on an empty array errors before bash 4.4 - use `${arr[@]+"${arr[@]}"}` or avoid arrays); use `printf` not `echo -e`; no `sed -i`, `readlink -f`, `xargs -r`, `grep -P`, `sort -V`, `date -d`; temp dir via `mktemp -d "${TMPDIR:-/tmp}/kokpit.XXXXXX"` with a `trap ... EXIT`; checksum tool differs (`sha256sum` vs `shasum -a 256`) - only CI needs it.
**Warning signs:** shellcheck with `--shell=bash` will not catch version gaps; run the suite once under `/bin/bash`.

### Pitfall 3: Interactive `grep` is not the script's `grep`
**What goes wrong:** in the owner's interactive shell `grep` is a shell function (here a wrapper around another grep implementation), while non-interactive scripts and git hooks resolve `grep` from `PATH` (BSD grep on macOS, GNU on Linux).
**How to avoid:** use only POSIX `grep -E/-F/-i/-n/-v/-c/-f`; test with `/usr/bin/grep`; never depend on extensions.

### Pitfall 4: 8-digit rule vs the product's own numbers
**What goes wrong:** invoice numbers (`YYYYNNNN`, variable symbols, SPAYD strings) are exactly 8 digits; the planning docs already contain 5 such examples in `.planning/research/FEATURES.md` and the allowlist values themselves in two other docs.
**How to avoid:** allowlist by **path + matched-value pattern** (e.g. `^docs/`, `^20[0-9]{6}$`), exempt `12345678`/`00000000` globally; use boundary classes so UUID groups, version strings and longer digit runs do not hit; tests that need invoice-like numbers should construct them at runtime. An optional refinement (not recommended as default): mod-11 IČO checksum filter (weights 8..2 on the first 7 digits; `12345678` is checksum-invalid, which is why it is a safe placeholder) - it makes hit/no-hit look random for 2026NNNN numbers (about 1 in 11 pass), confusing reviewers.

### Pitfall 5: Fixtures that trip the scanners themselves
**What goes wrong:** the test file contains literal fake e-mails/IDs and fails the repo's own CI, or a literal fake token triggers GitHub push protection.
**How to avoid:** assemble every fake from fragments at runtime (`"jane${AT}corp-fake.cz"`, `"2712""3456"`, `"gh""p_"` + body); generate IBANs with a function (below) instead of pasting well-known example IBANs; add a CI assertion that `scripts/tests/` itself passes `check-sensitive.sh --all`. Fake values should avoid real-looking provider formats where possible; GitHub push protection can block even test tokens at push time, so the planted-secret CI proof must be the runtime-built temp-repo test, **not** a pushed branch containing a fake token.
```bash
iban_cz() {  # $1 = 20-digit BBAN -> checksum-valid CZ IBAN (verified under /bin/bash 3.2; scanner flags it)
  local bban=$1 num r=0 i d
  num="${bban}123500"                      # 'C'=12 'Z'=35 then 00
  for (( i = 0; i < ${#num}; i++ )); do d=${num:i:1}; r=$(( (r * 10 + d) % 97 )); done
  printf 'CZ%02d%s\n' $(( 98 - r )) "$bban"
}
# iban_cz 00000000000000000000  ->  an all-zero-bank-code (non-existent bank) IBAN, safe as a fixture
```

### Pitfall 6: Empty or whitespace denylist line matches everything
**What goes wrong:** one blank line in `KOKPIT_DENYLIST` makes `grep -F -f` flag every line (verified: 4 of 4).
**How to avoid:** sanitise (strip CR, trim, drop `#` and blank lines) into a temp file before use; also guard `-s` (non-empty) before calling grep.

### Pitfall 7: Case-insensitive match fails for Czech capitals under `LC_ALL=C`
See Pattern 3 (verified). IDE-launched commits may run hooks with a bare environment, so set `LC_ALL=C.UTF-8` explicitly in the script rather than inheriting.

### Pitfall 8: Required status check never appears
**What goes wrong:** the org ruleset requires context `CI Passed`; without a job with exactly that name no PR can merge (and it is not auto-created).
**How to avoid:** the aggregator job above; Phase 2 may restructure into `ci.yml` calling `hygiene.yml` through `workflow_call` (add `workflow_call:` to `hygiene.yml` triggers if reuse is wanted - not tested here).

### Pitfall 9: lefthook install blocked or silently fail-open
**What goes wrong:** (a) a global/local `core.hooksPath` (e.g. from another tool) makes `lefthook install` exit 1; (b) without `assert_lefthook_installed: true` a machine lacking lefthook skips the hook silently; (c) `LEFTHOOK=0` and `--no-verify` bypass.
**How to avoid:** set the option; `install-hooks.sh` surfaces lefthook's hint rather than forcing; document the two bypass knobs as "never use; CI will fail"; in CONTRIBUTING state the re-install command after a fresh clone.

### Pitfall 10: Org-level ruleset and default branch
The ruleset (source: organization) blocks deletion and force-push (`non_fast_forward`) on `main` and requires a pull request. Local `main` cannot be pushed directly after the first commit; plan the first push of this phase's work as a PR from `base-crm-erp`. History rewrites on `main` after publication are blocked by the ruleset (another reason to sanitize **before** the first push).

## Runtime State Inventory

Not a rename/refactor/migration phase in the strict sense, but D-16 sanitises existing content. Findings:

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | None - no datastores exist | none |
| Live service config | GitHub repo: public, org-owned, secret scanning + push protection already enabled, org ruleset on `main` (see HYG-07) | document; no code |
| OS-registered state | None. lefthook writes `.git/hooks/pre-commit` (untracked, per clone) | `scripts/install-hooks.sh` per clone |
| Secrets/env vars | None in repo; new env var `KOKPIT_DENYLIST` (local only) | document in CONTRIBUTING |
| Build artifacts | None | none |

### Existing tracked/untracked content (item 7: categories only, no values copied)
Scanner prototype + gitleaks candidate config were run over `.planning/**`, `.claude/CLAUDE.md` and the git history.
| File | Category of hit | Detail |
|------|-----------------|--------|
| `.planning/codebase/STACK.md` line 50 (tracked, commit `5554573`) | personal absolute home path | A GSD-generated line naming the user's Node shim path; also found by gitleaks in **history** (commit 5554573) |
| `.claude/CLAUDE.md` line 51 (untracked) | personal absolute home path | Same line, copied into the GSD-managed `stack` block |
| `.planning/research/FEATURES.md` line 548 (untracked) | personal absolute home path | An absolute path to a project planning file |
| `.planning/research/FEATURES.md` (5 hits), `PITFALLS.md` (3), `01-CONTEXT.md` (2) | 8-digit numbers | Invoice-number examples (`YYYYNNNN`) and the allowlisted placeholder values themselves - false positives, handled by allowlist |
| everything scanned | e-mails, public IPs, IBAN/accounts, hosting hostnames, key-prefix **values** | **No hits.** Docs that merely name `sk_live_` etc. do not match because the rule needs a body |

Other facts relevant to D-16/D-17:
- `.planning/codebase/*` describes the **GSD framework's own JS hooks**, not Kokpit (it says "JavaScript/TypeScript ... no web framework"); the GSD-managed `stack`, `conventions` and `architecture` blocks in `.claude/CLAUDE.md` are generated from it and are therefore also wrong for a PHP/Laravel project. Recommendation: remove `.planning/codebase/` from tracking (`git rm -r --cached`) or sanitise STACK.md, and fix/remove the generated blocks, so they cannot be regenerated with the path (whether GSD regeneration preserves hand-written text outside `<!-- GSD:...-start/end -->` markers is `[ASSUMED]`).
- **D-16's "already published" is not true:** `git ls-remote --heads origin` returned only `main` at the initial commit (a50bf89); `origin` is a public GitHub repo; commits `5554573` and `0ea5eaf` exist only locally. Cheapest clean outcome: before the first push, amend/rebase the local commit that introduced the path (or squash local history) so no history scan ever sees it. This is a history rewrite of **unpushed** commits - needs explicit owner confirmation (`checkpoint:human-verify`). Fallback if the owner declines: a committed `.gitleaksignore` fingerprint (shape `file:rule:line`, e.g. the fingerprint gitleaks printed for a finding) or a baseline file, plus `check-sensitive.sh --history` allowlist entry.
- Untracked `.claude/` contains the full GSD framework (`agents/`, `commands/`, `hooks/`, `scripts/`, `gsd-core/`, manifests, `settings.local.json`); only `settings.local.json` is ignored today, so a casual `git add -A` would stage the framework. Verified `git check-ignore`: `.claude/CLAUDE.md`, `gsd-install-state.json`, `gsd-file-manifest.json`, `hooks`, `scripts`, `commands`, `agents`, `gsd-core` are all NOT ignored right now.
- Commit author identity in history is a real name/e-mail by design; it is metadata, not scanned. Mention GitHub's "Keep my email addresses private" + "Block command line pushes that expose my email" in the HYG-07 checklist as an owner choice.

### `.gitignore` gap list (HYG-01)
Current file covers env files, `*.pem`/`*.key`, `.claude/settings.local.json`, `.idea/`, `.vscode/`, `.DS_Store`, `/local/`, `*.sql`/`*.dump`/`*.sql.gz`/`*.csv`/`*.xlsx`, storage and logs, `vendor`, `node_modules`, `public/build`, and planning session artefacts. Recommended additions (each verified only as patterns; apply and re-check with `git check-ignore -v`):
```gitignore
# Local AI tooling: only CLAUDE.md is versioned (".claude/" would make the negation impossible)
.claude/*
!.claude/CLAUDE.md
CLAUDE.local.md
.cursor/
.aider*
# Exports and local databases
*.xls
*.ods
*.sqlite
*.sqlite3
/exports/
# Credentials and signing material
auth.json
*.p12
*.pfx
*.keystore
id_rsa*
# Test/coverage/tool caches
/coverage/
.phpunit.cache/
.phpunit.result.cache
.php-cs-fixer.cache
```
`.ddev/` specifics stay deferred to Phase 2 (deferred list), although D-17 mentions them. Keep `!.env.example`. A review note per phase goes in CONTRIBUTING.

## Code Examples

### Test harness (plain Bash, bash 3.2 safe)
```bash
# scripts/tests/lib.sh
fail=0; pass=0
assert_exit() {  # assert_exit <expected> <description> <command...>
  local want=$1 desc=$2; shift 2
  "$@" >"$TEST_TMP/out" 2>&1; local got=$?
  if [ "$got" -eq "$want" ]; then pass=$((pass + 1)); else fail=$((fail + 1)); echo "FAIL: $desc (exit $got, want $want)"; sed 's/^/    /' "$TEST_TMP/out"; fi
}
assert_out_has()  { grep -q -e "$1" "$TEST_TMP/out" && pass=$((pass + 1)) || { fail=$((fail + 1)); echo "FAIL: output lacks: $1"; }; }
assert_out_lacks() { if grep -q -e "$1" "$TEST_TMP/out"; then fail=$((fail + 1)); echo "FAIL: output leaks: $1"; else pass=$((pass + 1)); fi; }
new_repo() { d=$(mktemp -d "${TMPDIR:-/tmp}/kokpit-test.XXXXXX"); ( cd "$d" && git init -q -b main . && git config user.email t@example.com && git config user.name t ); printf '%s\n' "$d"; }
```
Fake builders (each value split so the test file itself has no literal match): `fake_email() { printf 'jane%scorp-fake.cz' '@'; }`, `fake_ico() { printf '%s%s' 2712 3456; }`, `fake_ghp() { printf '%s%s%s' gh p_ aB3dE5fG7hJ9kL1mN3pQ5rS7tU9vW1xY3zA5; }`, `fake_iban() { iban_cz 00000000000000000000; }`. Test cases per success criterion are listed in the Validation Architecture.

### Staged-mode driver core
```bash
git -c core.quotepath=off diff --cached -U0 --no-color --no-ext-diff \
    --src-prefix=a/ --dst-prefix=b/ --diff-filter=ACMR \
  | LC_ALL=C awk -f "$here/lib/diff2tsv.awk" > "$tmp/rows.tsv"
CS_ALLOWLIST="$here/sensitive-allowlist.txt" LC_ALL=C awk -f "$here/lib/scan.awk" "$tmp/rows.tsv"
```
Exclude the D-05 exact paths before scanning (a small `awk -F'\t' '!(\$1 in skip)'` filter on the TSV).

### Planted-secret proof for gitleaks (CI and local)
`scripts/tests/test-gitleaks.sh`: create temp repo; copy repo's `.gitleaks.toml`; commit a clean file; `gitleaks git --config ... --redact --no-banner .` -> assert exit 0; add a file containing `fake_ghp` and a home path built from fragments, commit; run again -> assert exit 1 and output contains `RuleID`/`github-pat` or `kokpit-home-path` but **not** the secret body; then `git rm` it, commit, and assert it is **still** reported (history scan proves the "secret deleted later" case). Skip with a notice if `gitleaks` is not on PATH locally; in CI it is mandatory (the step runs after the pinned install).

## State of the Art

| Old Approach | Current Approach | When Changed | Impact |
|--------------|------------------|--------------|--------|
| `gitleaks detect` / `gitleaks protect --staged` | `gitleaks git [--staged\|--pre-commit]`, `gitleaks dir`, `gitleaks stdin` | v8.19.0 deprecated old commands (hidden but working) | Use new commands; CONTEXT D-08 text `gitleaks protect --staged` should read `gitleaks git --pre-commit --staged` |
| `[allowlist]` single table | `[[allowlists]]` / `[[rules.allowlists]]` with `condition`, `targetRules`, `regexTarget` | gitleaks 8.2x | Use array-of-tables form |
| Floating action tags (`@v4`) | Full 40-char commit SHA with version comment; repo setting "require SHA pinning" | GitHub guidance `[CITED: docs.github.com secure-use]` | Pin all; `gh api` shows `sha_pinning_required: false` on this repo - turn it on |
| `ubuntu-latest` | pinned `ubuntu-24.04` | `ubuntu-latest` -> 26.04 in Nov 2026 | Avoid surprise image change |

**Deprecated/outdated:** `gitleaks-action` for org repos (licence key); `core.hooksPath` hand-rolled hooks (owner replaced by lefthook).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | GSD regeneration of `.claude/CLAUDE.md` preserves text outside `<!-- GSD:*-start/end -->` markers | Runtime State / HYG-06 | Rule could be overwritten; mitigation: keep it in CONTRIBUTING as well (already required) |
| A2 | Zerops personal tokens have no stable prefix; only `.zerops.app` subdomains are covered by the hostname rule; other Zerops suffixes unknown | Pattern 5 | Some Zerops hostnames/tokens slip past generic rules; denylist covers instance values |
| A3 | Fork-PR approval setting value naming (`all_external_contributors`) and that "approve PR reviews by Actions" should be disabled | HYG-07 checklist | Wrong option label in docs |
| A4 | Extra `.gitignore` entries are desirable (list above); owner may want a narrower set | `.gitignore` gaps | Over-ignoring legitimate files (e.g. `*.xls` fixtures) - reviewed per phase |
| A5 | Extending `hygiene.yml` with `workflow_call` for Phase 2 reuse works as sketched | Pitfall 8 | Phase 2 restructure effort; not needed for Phase 1 |
| A6 | The org ruleset named `main` is intended to apply to this repo and its required context `CI Passed` is the owner's convention | Pitfall 8/10 | If not intended, aggregator job is harmless |
| A7 | lefthook npm package postinstall behaviour (auto-installs hooks, skips in CI) as described by search results | Pattern 4 | Only affects advice to avoid npm install; irrelevant if brew/mise used |

## Open Questions

1. **Rewrite unpushed local history to drop the home-path line?**
   - Known: remote has only the initial commit; local commit `5554573` contains the path; gitleaks reports it in history.
   - Unclear: owner's willingness to rewrite local, unpushed commits (D-16 said out of scope).
   - Recommendation: ask once (human-verify checkpoint). Prefer rewrite before first push; fallback `.gitleaksignore` fingerprint.
2. **Keep `.planning/codebase/` at all?** It documents the GSD framework, not Kokpit. Recommend `git rm -r --cached` and re-map later when real code exists; owner decision.
3. **Is the org ruleset's `CI Passed` context meant for this repo?** Aggregator job satisfies it either way.
4. **Include `--history` mode in `check-sensitive.sh`?** Recommended: success criterion 4 says "the same sensitive-content checks" scan full history; gitleaks custom rules cover most categories, but bare 8-digit/e-mail/IP rules exist only in the shell checker. The existing local history would pass it once the path is removed.
5. **Phone numbers** (listed in Pitfall 14 research) are not in D-02; add a rule only if wanted.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| bash 3.2 (`/bin/bash`) | scripts must run here | yes | 3.2.57 | - |
| bash 5 (Homebrew) | dev convenience | yes | 5.3.20 | - |
| git | everything | yes | 2.56.0 | - |
| awk (BWK) | scanner | yes | 20200816 | - |
| BSD grep | denylist | yes | 2.6.0-FreeBSD (interactive `grep` is a wrapper function; scripts use PATH grep) | - |
| lefthook | HYG-04 | yes | 2.1.17 | install via brew/mise |
| gitleaks | HYG-04/05 local | **no** | - | `brew install gitleaks` or `mise use gitleaks@8.30.1`; downloaded 8.30.1 darwin arm64 into scratch for testing (checksum OK) |
| shellcheck | script lint | no locally (0.11.0 binary fetched to scratch) | CI runner has 0.9.0 | `brew install shellcheck`; optional |
| actionlint / zizmor | workflow lint | no locally (fetched to scratch) | 1.7.12 / 1.30.1 | CI only; optional local via brew |
| gh CLI | settings audit | yes (authenticated) | - | manual UI |
| Docker (OrbStack) | GNU/mawk verification | yes | 29.4.0 | CI is the check |
| jq | optional report parsing | yes | - | - |
| mise | optional pin file | yes | 2026.10.3 | brew |

**Missing with no fallback:** none. **Missing with fallback:** gitleaks (blocks the hook until installed - by design fail-closed; the plan needs an explicit "install gitleaks" step before `install-hooks.sh`), shellcheck/actionlint/zizmor (CI covers).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | Plain Bash assertion scripts (no dependency; bats rejected per discretion) |
| Config file | none - `scripts/tests/run.sh` discovers `scripts/tests/test-*.sh` |
| Quick run command | `bash scripts/tests/run.sh --quick` (generic-pattern + denylist tests, < 5 s, no gitleaks/lefthook needed) |
| Full suite command | `bash scripts/tests/run.sh` (adds gitleaks tests; lefthook tests auto-skip when lefthook absent) |
| Also run under | `/bin/bash scripts/tests/run.sh` once (bash 3.2) and in CI (`ubuntu-24.04`, mawk) |
| Static checks | `shellcheck scripts/*.sh scripts/tests/*.sh`; `lefthook validate`; `actionlint`; `zizmor --offline .github/workflows` |

### Phase Requirements -> Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| HYG-01 | `.claude/*` (except `CLAUDE.md`), env files, `/local/`, dumps, exports, storage, logs, coverage stay untracked; `.env.example` and `.claude/CLAUDE.md` trackable | unit | `bash scripts/tests/test-gitignore.sh` (temp repo with the real `.gitignore` copied; `git check-ignore -q` per path; includes the `.claude/` negation check) | Wave 0 |
| HYG-02 | Each rule fires with `file:line` and rule name; masked output never contains the full value; clean staged change exits 0; example domains/RFC1918/doc ranges/placeholder paths pass | unit | `bash scripts/tests/test-check-sensitive.sh` (staged mode in temp repo; fixtures built from fragments; asserts exit 1/0 and `assert_out_lacks <full value>`) | Wave 0 |
| HYG-02 | Allowlist path- and value-scoping works; `--all` and `--history` find a hit that exists only in an old commit | unit | same file, extra cases | Wave 0 |
| HYG-03 | With `KOKPIT_DENYLIST` a fictional term (case-folded incl. diacritics) blocks and the term is not echoed; unset -> notice + generic only, exit unaffected; set-but-unreadable -> exit 2; blank/CRLF/comment lines ignored | unit | `bash scripts/tests/test-denylist.sh` | Wave 0 |
| HYG-04 | `scripts/install-hooks.sh` idempotent, hook installed, planted staged secret rejected, partial commit and `--no-verify` behaviour as documented, missing gitleaks blocks with hint | integration (local only) | `bash scripts/tests/test-lefthook.sh` (skips with notice if lefthook/gitleaks absent; never run in CI) | Wave 0 |
| HYG-04 | `lefthook.yml` valid, contains `assert_lefthook_installed: true`, jobs call both tools | static | `lefthook validate && grep -q 'assert_lefthook_installed: true' lefthook.yml` | Wave 0 |
| HYG-05 | gitleaks with repo `.gitleaks.toml`: fails on planted fakes (default + custom rules), passes on clean repo, still fails when the secret was deleted in a later commit, output redacted | integration | `bash scripts/tests/test-gitleaks.sh` | Wave 0 |
| HYG-05 | Workflow is lint-clean, SHA-pinned, no `pull_request_target`, no `${{ github.event` in `run:`, aggregator named `CI Passed` | static | `actionlint && zizmor --offline .github/workflows` plus `grep -L 'pull_request_target' ...`; first real run: open a PR and observe the `CI Passed` check | Wave 0 |
| HYG-05 | Planted-secret proof in CI | CI | `scan` job step "Script self-tests" (runtime-built temp repos) | Wave 0 |
| HYG-06 | `.claude/CLAUDE.md` and `CONTRIBUTING.md` contain the fictional-data rule and the review procedure strings | static | `grep -q 'fictional' CONTRIBUTING.md .claude/CLAUDE.md && grep -q 'git diff --staged' CONTRIBUTING.md && grep -q 'check-sensitive.sh' CONTRIBUTING.md` | Wave 0 |
| HYG-07 | CONTRIBUTING documents secret scanning + push protection + branch ruleset checklist; actual settings enabled | static + manual | `grep -q 'push protection' CONTRIBUTING.md`; live state: `gh api repos/<owner>/<repo> --jq .security_and_analysis` (needs admin token, run manually, currently shows `secret_scanning` and `secret_scanning_push_protection` = `enabled`) | Wave 0 / manual |
| D-16 | The new checker and gitleaks pass over the final tracked content and over `.claude/CLAUDE.md`, `.planning/**` before any push | integration | `scripts/check-sensitive.sh --all && scripts/check-sensitive.sh --history && gitleaks git --config .gitleaks.toml --redact --no-banner .` | after implementation |

### Sampling Rate
- **Per task commit:** `bash scripts/tests/run.sh --quick` and `shellcheck` on touched scripts.
- **Per wave merge:** full `bash scripts/tests/run.sh` under `/bin/bash` and under the Homebrew bash, plus the Ubuntu container run (`docker run --rm -v "$PWD":/w ubuntu:24.04 ...` after `apt-get install git`) when awk code changes.
- **Phase gate:** all of the above green, CI `CI Passed` green on a PR, manual human-verify items done (below), then `/gsd-verify-work`.

### Wave 0 Gaps
- [ ] `scripts/tests/run.sh`, `scripts/tests/lib.sh` (harness, fake builders, `iban_cz`)
- [ ] `scripts/tests/test-gitignore.sh`, `test-check-sensitive.sh`, `test-denylist.sh`, `test-gitleaks.sh`, `test-lefthook.sh`
- [ ] Local install of gitleaks (and optionally shellcheck) so the hook and integration tests can run on the owner's machine
- [ ] Human-verify: GitHub settings checklist; owner decision on history rewrite; first PR shows green `CI Passed`

## Security Domain

`security_enforcement` is enabled (ASVS level 1, block on high).

### Applicable ASVS Categories
| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | no | - |
| V3 Session Management | no | - |
| V4 Access Control | partly | Org ruleset + CODEOWNERS on `.github/workflows/`, scripts and allowlist; workflow `permissions` least privilege |
| V5 Input Validation | yes | Scripts treat file paths/content as untrusted: no `eval`, quoted expansions, `--` before paths, `ENVIRON` not `-v`, no event-data interpolation in workflows |
| V6 Cryptography | no (checksum verification only) | `sha256sum --check` against hard-coded digests; never hand-roll |
| V14 Configuration / supply chain | yes | SHA-pinned actions, checksum-pinned binaries, zizmor/actionlint, Dependabot for actions |

### Known Threat Patterns
| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Secret or real data committed to a public repo | Information disclosure | Layered: lefthook pre-commit (check + gitleaks), CI full-history scan, GitHub push protection, denylist for real names/IDs; CI authoritative because hooks are bypassable |
| Secret echoed into CI/hook logs | Information disclosure | Mask in scanner, `--redact` in gitleaks, print only `path:line` for denylist hits, build test fakes at runtime |
| Workflow script injection / `pull_request_target` abuse | Elevation of privilege | Only `pull_request`/`push`; `permissions: {}` + per-job `contents: read`; `persist-credentials: false`; no event data in `run:`; zizmor gate |
| Compromised third-party action or tool binary | Tampering | Full-SHA pin; hard-coded SHA-256 for binaries; no `gitleaks-action` |
| Allowlist/exemption abuse hiding a leak | Tampering / Repudiation | Exact-path exclusions only, narrow value-scoped entries, CODEOWNERS review on allowlist and `.gitleaks.toml` |
| Hook bypass (`--no-verify`, `LEFTHOOK=0`) | Repudiation | CI is the gate; org ruleset requires PR + `CI Passed` |
| Filename/content tricks (spaces, `++` lines, binary) | Tampering | Hunk-count diff parser, `git grep -I`, `core.quotepath=off`, explicit diff prefixes |
| Real author e-mail exposure in commit metadata | Information disclosure | Owner choice: GitHub e-mail privacy settings (documented) |

## Sources

### Primary (HIGH confidence)
- GitHub API (`gh api`) this session: releases for gitleaks 8.30.1, lefthook 2.1.17, actionlint 1.7.12, zizmor 1.30.1, shellcheck 0.11.0, actions/checkout v7.0.1 (SHA); repo visibility, `security_and_analysis`, rulesets, Actions permissions; asset SHA-256 digests (verified by re-hashing downloads).
- Executed experiments (this session): gitleaks 8.30.1 CLI/`--help`/config/history/redaction; lefthook 2.1.17 install/validate/commit/partial/bypass/min_version/hooksPath; mawk/gawk/BWK awk scanner runs; Docker `ubuntu:24.04` (bash 5.2.21, grep 3.11, git 2.43); bash 3.2 feature probes; actionlint + zizmor on the draft and a negative control.
- github.com/gitleaks/gitleaks README and v8.30.1 `.pre-commit-hooks.yaml`, `config/gitleaks.toml` (raw files) - commands, deprecation note, config schema, default rules.
- git-scm.com/docs/githooks (hook cwd, `--no-verify`), git-scm.com/docs/gitignore (negation limitation).
- docs.github.com secure-use (SHA pinning, intermediate env vars, `pull_request_target`, least privilege, Dependabot, CODEOWNERS).
- github.com/actions/runner-images `Ubuntu2404-Readme.md` (installed tools, `ubuntu-latest` migration notice).

### Secondary (MEDIUM confidence)
- github.com/gitleaks/gitleaks-action README (licence key for org accounts).
- docs.zizmor.sh installation/usage (exit codes 11-14, `--offline`, personas).
- docs.github.com about-secret-scanning (free on public repos) and push-protection pages.
- lefthook.dev configuration page (option names); installation channels from web search of lefthook docs/README.
- docs.zerops.io pages (subdomain `.zerops.app`, access tokens).

### Tertiary (LOW confidence)
- Fork-PR approval option naming, extra `.gitignore` entries (see Assumptions).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH - versions and checksums verified live; every pinned digest re-verified by download.
- Architecture: HIGH - pipeline, hook and workflow behaviours executed on macOS and Ubuntu.
- Pitfalls: HIGH - mawk/bash 3.2/locale/blank-pattern issues were each reproduced.
- GitHub settings: MEDIUM - current values read by API as admin; UI paths from docs (push protection: Settings -> Advanced Security -> Secret Protection -> Push protection, `[CITED: docs.github.com enabling-push-protection]`).

### HYG-07 documentation content (GitHub settings checklist for CONTRIBUTING)
Current state read via `gh api` (this session): repo public; `secret_scanning`, `secret_scanning_push_protection`, `secret_scanning_non_provider_patterns`, `secret_scanning_validity_checks` = enabled; AI detection, delegated bypass/dismissal and Dependabot security updates = disabled; Actions: `allowed_actions: all`, `sha_pinning_required: false`, default workflow permissions `read`, `can_approve_pull_request_reviews: true`, fork PR approval `first_time_contributors`; no classic branch protection, but an active org ruleset on `main` (no bypass actors): block deletion, block non-fast-forward, require pull request (0 approvals, thread resolution required), required status check `CI Passed`. Checklist to document (all manual): keep secret scanning + push protection enabled; enable "Require actions to be pinned to a full-length commit SHA"; disable "Allow GitHub Actions to create and approve pull requests" (`[ASSUMED]` label); consider "Require approval for all outside collaborators" (`[ASSUMED]`); enable Dependabot alerts/security updates; keep 2FA; optional author e-mail privacy; secret scanning "bypass" events are alerts the owner must review `[CITED: docs.github.com about-push-protection]`. A local audit helper (`gh api repos/{owner}/{repo} --jq .security_and_analysis`) may be documented but must not run in CI (admin scope).

**Research date:** 2026-10-06
**Valid until:** ~2026-11-05 for tool versions (re-run the `gh api` version/digest commands before pinning); the scanner/portability findings are stable.
