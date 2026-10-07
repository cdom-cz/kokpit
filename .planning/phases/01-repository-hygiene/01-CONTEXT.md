# Phase 1: Repository Hygiene - Context

**Gathered:** 2026-10-06
**Status:** Ready for planning

<domain>
## Phase Boundary

Tooling that keeps secrets, real data and instance-specific values out of the public repository, in place before application code or planning docs are committed. Delivers: `.gitignore` review (HYG-01), `scripts/check-sensitive.sh` (HYG-02), local denylist via `KOKPIT_DENYLIST` (HYG-03), lefthook-managed pre-commit hook (`lefthook.yml`) + `scripts/install-hooks.sh` (HYG-04), `.gitleaks.toml` + CI full-history scan (HYG-05), fictional-data rule in `CLAUDE.md` and CONTRIBUTING (HYG-06), documented GitHub secret scanning / push protection and review procedure (HYG-07).

No application code, no Laravel, no DDEV in this phase.

</domain>

<decisions>
## Implementation Decisions

All decisions below were auto-selected (`--auto`) using the recommended default, grounded in `.planning/research/PITFALLS.md` (Pitfalls 14, 15, 28) and `.planning/research/SUMMARY.md`.

### Sensitive-content script (`scripts/check-sensitive.sh`)
- **D-01:** Plain POSIX-compatible Bash (no PHP/Node dependency, since the app does not exist yet and the hook must run on a bare checkout). Scans staged content (`git diff --cached`, added lines only) by default; accepts an explicit file list / `--all` mode so CI can run it over the working tree.
- **D-02:** Generic patterns: non-example e-mails (allowed domains: `example.com`, `example.org`, `example.net`, `*.test`, `*.invalid`, `*.localhost`), 8-digit company-ID-like numbers (allowlist `12345678`, `00000000`), IBAN and Czech account-number formats, public IPv4 addresses (RFC 1918, loopback, documentation ranges and `0.0.0.0` allowed), hosting hostnames (Zerops, S3 provider endpoints), key/token prefixes (`sk_live_`, `rk_live_`, `pk_live_`, `whsec_`, `plink_`, `acct_`, `ghp_`, AWS `AKIA`), and personal absolute home paths (`/Users/<name>/`, `/home/<name>/`).
- **D-03:** On a hit the script prints `file:line: <rule name>` (never the matched secret value in full — truncate/mask) and exits non-zero. Clean input exits 0.
- **D-04:** Exemptions through a committed allowlist file (`scripts/sensitive-allowlist.txt`, reviewed in PRs) with path-scoped and pattern-scoped entries; no inline "ignore" magic comments.
- **D-05:** The script excludes itself, the allowlist and the gitleaks config from scanning (they necessarily contain the patterns).

### Local denylist
- **D-06:** `KOKPIT_DENYLIST` points to a file outside the repo, one term per line (`#` comments, blank lines ignored), matched case-insensitively as fixed strings. If unset or file missing: print a one-line notice and run generic patterns only (exit code unaffected) — this is the CI path. If set but unreadable: fail loudly.
- **D-07:** A documented example denylist format lives in docs with fictional terms only; the real denylist is never committed (path suggestion in docs: under the user's home config directory).

### Pre-commit hook
- **D-08:** (Revised by owner after discussion) Local git hooks are managed by **lefthook**: a versioned `lefthook.yml` at the repo root defines the `pre-commit` job(s), which run `scripts/check-sensitive.sh` and then gitleaks on staged changes. `scripts/install-hooks.sh` becomes a thin idempotent wrapper that checks lefthook is installed (install hint otherwise) and runs `lefthook install`. No `.githooks/` directory and no `core.hooksPath`. lefthook is a local developer tool only (not a Composer/npm dependency); CI never depends on it.
- **D-09:** If `lefthook` or `gitleaks` is not installed locally, `scripts/install-hooks.sh` and the hook fail with an install hint rather than silently skipping (fail-closed); CI remains the authoritative gate because `--no-verify` bypasses hooks. Researcher to confirm how lefthook runs staged-file jobs and how to pin/document the lefthook version.

### gitleaks and CI
- **D-10:** `.gitleaks.toml` extends the default ruleset (`[extend] useDefault = true`) and adds custom rules for Stripe ids/secrets, Zerops tokens, S3 endpoints, absolute home paths and IČO-like numbers outside the allowlist; allowlist section reviewed in PRs.
- **D-11:** GitHub Actions workflow `.github/workflows/hygiene.yml`: triggers `push` and `pull_request` only (never `pull_request_target`), `permissions: contents: read`, `actions/checkout` with `fetch-depth: 0`, runs `scripts/check-sensitive.sh --all` (no denylist in CI) and gitleaks over full history.
- **D-12:** All actions and tool installs pinned (actions by commit SHA, gitleaks binary by version + checksum); gitleaks is run as a pinned binary, not `gitleaks-action`, to avoid the organisation-licence-key issue. No `${{ github.event.* }}` interpolation inside `run:`. `actionlint` (and zizmor if cheap) runs in the same workflow.
- **D-13:** CI is proven with a planted-fake-secret test: a fixture-driven script test (`scripts/tests/`) shows the checker fails on planted bad input and passes on clean input, without ever committing a real-looking secret (fake values built at test time from string fragments).

### Documentation and GitHub settings
- **D-14:** Fictional-data-only rule added to `.claude/CLAUDE.md` (project instructions) and a new `CONTRIBUTING.md` hygiene section, including the review procedure `git status` → `git diff --staged` → `scripts/check-sensitive.sh`, and the leak runbook pointer (rotate first, rewrite history, notify; full runbook lands in SECURITY.md in Phase 2).
- **D-15:** GitHub secret scanning + push protection and branch protection are manual settings; documented as a checklist in CONTRIBUTING (HYG-07). Cannot be verified by code; flagged as a human-verify item.

### Existing tracked content
- **D-16:** `.planning/codebase/` is treated as unreliable and already published (it was committed before hygiene tooling). Phase 1 runs the new checker over it and over `.claude/CLAUDE.md`; findings (notably personal absolute paths such as home directories) are sanitized or the files removed from tracking before anything is pushed publicly. History rewrite is out of scope unless the scan finds a real secret (then: rotate first, ask the owner).
- **D-17:** `.gitignore` is extended where the review finds gaps (e.g. coverage output, `.claude/` machine-local files, `.idea/` already ignored, `.ddev/` stays versioned but `.ddev/.env*`/override files ignored) and gets a per-phase review note in CONTRIBUTING. Planning docs (`.planning/*`) stay uncommitted until the hygiene check passes on them (`commit_docs` remains false until then).

### Claude's Discretion
- Exact regex tuning and false-positive balance, file layout inside `scripts/`, test harness choice (plain Bash assertions vs `bats`; prefer plain Bash to avoid a dependency), wording of docs, and whether to add zizmor.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Project scope and requirements
- `.planning/ROADMAP.md` §"Phase 1: Repository Hygiene" — goal and the 5 success criteria
- `.planning/REQUIREMENTS.md` — HYG-01 … HYG-07
- `.planning/PROJECT.md` — Constraints ("Repository hygiene"), Context (public repo, fictional data only)
- `.planning/STATE.md` — Blockers/Concerns: `.planning/codebase/` unreliable, review in Phase 1

### Research
- `.planning/research/PITFALLS.md` Pitfall 14 (secrets/real data), Pitfall 15 (GitHub Actions security), Pitfall 28 (licence drift)
- `.planning/research/SUMMARY.md` — Phase "Repo hygiene" deliverables list
- `.planning/research/STACK.md` — gitleaks (MIT, not a Composer dependency)

### Existing files to review
- `.gitignore` — current ignore rules (HYG-01 baseline)
- `.claude/CLAUDE.md` — project instructions to extend with the fictional-data rule (HYG-06)
- `.planning/codebase/*.md` — tracked, unreliable; scan and sanitize (D-16)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `.gitignore`: already covers env files, `.claude/settings.local.json`, `.idea/`, `/local/`, dumps/CSV/XLSX, storage, logs, vendor, planning session artefacts. Baseline to review, not rewrite.

### Established Patterns
- Repository contains no application code (only `.gitignore`, `LICENSE`, `.planning/codebase/*`); no `scripts/`, `lefthook.yml`, `.github/` yet.
- `.claude/` holds GSD framework files (agents, hooks, gsd-core) and is currently untracked; decide in planning what from it is versioned (only `CLAUDE.md` is intended) vs ignored.

### Integration Points
- Phase 2 CI (tests, static analysis, licence allowlist) will extend the same GitHub Actions setup; keep `hygiene.yml` independent so it works before any PHP exists.
- Phase 2 `SECURITY.md` hosts the full leak runbook; Phase 1 only links/points to it.

</code_context>

<specifics>
## Specific Ideas

- Planted-secret proof per success criterion 4: CI must fail on a planted fake secret and pass on a clean branch.
- Success criterion 2: behaviour with and without `KOKPIT_DENYLIST` must both be demonstrable in tests.

</specifics>

<deferred>
## Deferred Ideas

- Full leak-response runbook in `SECURITY.md` — Phase 2
- Dependency licence allowlist CI step — Phase 2 (CI foundation)
- Deploy workflow hardening (protected `production` environment, release-tag ancestry check) — Phase 3
- `.ddev/` config hygiene specifics — Phase 2 when DDEV is introduced

</deferred>

---

*Phase: 1-Repository Hygiene*
*Context gathered: 2026-10-06*
