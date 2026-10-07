# Phase 2: Platform Foundation - Pattern Map

**Mapped:** 2026-10-07
**Files analyzed:** 22 groups (about 60 concrete files; PHP files grouped by role)
**Analogs found:** 9 / 22 (all in Phase 1 tooling; application PHP is greenfield)

All analog paths below were confirmed tracked with `git ls-files`. Fictional data only; repo-relative paths only.

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `.github/workflows/hygiene.yml` (add jobs `tests`, `static-analysis`, `dependencies`; extend `ci-passed.needs`) | config (CI) | batch | same file, jobs `scan` / `workflow-lint` / `ci-passed` | exact (modify) |
| `.github/dependabot.yml` (add composer ecosystem) | config | batch | same file, `github-actions` entry | exact (modify) |
| `.github/CODEOWNERS` (only if new paths need owners, e.g. `/composer.lock`, `/.ddev/`) | config | n/a | same file | exact (modify) |
| `.gitignore` (content-ignore storage and bootstrap/cache, phpstan tmp, filament public assets) | config | n/a | same file lines 33-47 | exact (modify) |
| `scripts/tests/test-gitignore.sh` (new verdicts for placeholders and Filament assets) | test (bash) | request-response (verdicts) | same file | exact (modify) |
| `scripts/tests/test-workflow.sh` (new: `ci-passed.needs` covers every job) | test (bash) | transform | `scripts/tests/test-gitignore.sh` + `scripts/tests/lib.sh` | role-match |
| `scripts/tests/test-docs.sh` (keep passing; extend only if new required phrases) | test (bash) | transform | same file | exact (modify) |
| `scripts/sensitive-allowlist.txt` (two `composer.lock` entries, needs maintainer approval) | config | n/a | same file lines 16-28 | exact (modify) |
| `scripts/check-licenses.php` | utility (CLI script) | transform (stdin JSON to exit code) | no PHP script analog; shape follows `scripts/check-sensitive.sh` (stdout findings, stderr notices, exit codes) | partial |
| `CONTRIBUTING.md` (Development section, CI section update) | docs | n/a | same file (sections `## CI`, `## Per-phase .gitignore review`) | exact (modify) |
| `README.md`, `SECURITY.md` | docs | n/a | `CONTRIBUTING.md` tone and required phrases in `scripts/tests/test-docs.sh` | role-match |
| `LICENSE` | docs | n/a | exists; only assert it (no change) | exact |
| `.ddev/config.yaml`, `.ddev/docker-compose.rustfs.yaml`, Redis add-on files | config (infra) | event-driven (daemons) | none in repo | none |
| `.env.example` | config | n/a | `.gitignore` lines 1-4 (`!.env.example` already trackable) | partial |
| `composer.json`, `phpunit.xml`, `phpstan.neon`, `pint.json` | config | n/a | none | none |
| `app/Domain/Shared/Models/*` (HasUuids package subclasses), `KokpitModel` | model | CRUD | none | none |
| `database/migrations/*` (edited package migrations, `number_sequences`, guard function) | migration | CRUD / DDL | none | none |
| `app/Domain/Shared/Money/{Money,MoneyCast}.php` | utility / cast | transform | none | none |
| `app/Domain/Shared/Sequences/SequenceAllocator.php` | service | CRUD (row lock) | none | none |
| `app/Domain/Shared/Auth/*` (PartnerContext, PartnerScope, KokpitPolicy, AccessRule, ...) | middleware / policy / scope | request-response | none | none |
| `app/Console/Commands/{InstallCommand,ResetAdminTwoFactorCommand}.php`, `app/Http/Middleware/EnsureAdminHasTwoFactor.php`, `app/Providers/*`, `app/Filament/**` | command / middleware / provider | request-response | none | none |
| `tests/**` (Pest: Unit, Feature, Concurrency, Arch, Isolation, Support) | test | mixed | no PHP tests; structure analog is the bash harness (`scripts/tests/run.sh`, `lib.sh`) | partial |

## Pattern Assignments

### `.github/workflows/hygiene.yml` (config, batch)

**Analog:** the same file; new jobs copy the `scan` job header and the `ci-passed` aggregator.

**Job header and pinning** (hygiene.yml lines 16-31):
```yaml
  scan:
    name: Sensitive-content and secret scan
    runs-on: ubuntu-24.04
    timeout-minutes: 10
    permissions:
      contents: read
    steps:
      - name: Check out full history
        uses: actions/checkout@3d3c42e5aac5ba805825da76410c181273ba90b1 # v7.0.1
        with:
          fetch-depth: 0
          persist-credentials: false
```
Every new job (`tests`, `static-analysis`, `dependencies`): `runs-on: ubuntu-24.04`, a `timeout-minutes`, job-level `permissions: contents: read`, checkout with `persist-credentials: false`, full 40-char SHA plus `# vX.Y.Z` comment. Resolve `shivammathur/setup-php` (2.37.2) and `actions/cache` SHAs with `gh api repos/<owner>/<repo>/git/ref/tags/<tag>`. Top-level `permissions: {}` (line 10) and the trigger comment (lines 3-9: push to main and pull_request only) stay untouched.

**Pinned tool install with hard-coded checksum** (lines 36-45): reuse the `curl ... && sha256sum --check --strict` pattern for any binary downloaded in CI (none expected for PHP jobs; `composer` comes from setup-php).

**Env-only values, no inline expressions in `run:`** (lines 113-114, 119-121): pass dynamic values through `env:` and reference `"${VAR}"` in `run:` (zizmor-clean). Use this for the `DB_*` env of the `tests` job and for `KOKPIT_ADMIN_PASSWORD`.

**Aggregator to extend** (lines 104-121):
```yaml
  ci-passed:
    name: CI Passed
    if: ${{ always() }}
    needs: [scan, workflow-lint]
    ...
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
Change only the `needs` list to `[scan, workflow-lint, tests, static-analysis, dependencies]`. Keep the check name exactly `CI Passed`. New `tests` job needs a `services: postgres:18` block with `pg_isready` health check (no analog; see RESEARCH.md Pattern 10). The merged file must pass `actionlint` and `zizmor --offline .github/workflows` (the existing `workflow-lint` job runs both).

---

### `.github/dependabot.yml` (config)

**Analog:** same file (lines 4-9). Add a second `updates` entry:
```yaml
  - package-ecosystem: composer
    directory: "/"
    schedule:
      interval: weekly
```
Update the header comment, which currently speaks only of Actions.

---

### `.gitignore` (config)

**Analog:** same file. Edit lines 33-40 (runtime artefacts) from directory-ignore to content-ignore so skeleton placeholder files are trackable (RESEARCH.md Pattern 11, collision "Fresh clone lacks storage/framework/views"):
```gitignore
# current (lines 34-39), these hide the skeleton's tracked placeholder .gitignore files
/storage/*.key
/storage/logs/
/storage/framework/cache/
/storage/framework/sessions/
/storage/framework/views/
/storage/app/
```
Target form: `/storage/framework/{cache,sessions,views,testing}/*` each followed by `!<same dir>/.gitignore`, plus `/bootstrap/cache/*` and `!/bootstrap/cache/.gitignore`. Keep the comment convention at lines 13-15 (negation must follow the wildcard, parent directory never ignored). Add `/public/js/filament/`, `/public/css/filament/`, `/public/fonts/filament/` under "Dependencies and build output" (lines 42-47) and `/storage/framework/phpstan/` under caches (line 85+). Merge, never replace with the Laravel skeleton `.gitignore`. `.env.*` / `!.env.example` (lines 2-4) already make `.env.example` trackable. `.npmrc` (line 6) stays ignored.

---

### `scripts/tests/test-gitignore.sh` (test, bash)

**Analog:** same file. Verdict helpers and loop pattern (lines 17-25 of the file):
```bash
repo=$(new_repo)
cp "$REPO_ROOT/.gitignore" "$repo/.gitignore"

ignored() { assert_exit 0 "ignored: $2" git -C "$1" check-ignore -q --no-index -- "$2"; }
trackable() { assert_exit 1 "trackable: $2" git -C "$1" check-ignore -q --no-index -- "$2"; }

for p in \
  .env .env.local ... ; do
  ignored "$repo" "$p"
done
```
Add `trackable` calls for `storage/framework/views/.gitignore`, `storage/framework/cache/.gitignore`, `storage/framework/sessions/.gitignore`, `storage/app/.gitignore`, `bootstrap/cache/.gitignore`, `.env.example`; add `ignored` calls for `storage/logs/laravel.log`, `storage/app/private/x.pdf`, `public/js/filament/forms/forms.js`, `bootstrap/cache/packages.php`. Paths are never created (verdicts via `--no-index`). Required by CONTRIBUTING "Per-phase .gitignore review": update in the same change as `.gitignore`.

---

### `scripts/tests/test-workflow.sh` (new test, bash)

**Analog:** `scripts/tests/test-gitignore.sh` for structure; `scripts/tests/lib.sh` for helpers; `scripts/tests/run.sh` auto-discovers `test-*.sh` (line 34) so no registration is needed.

**Boilerplate to copy** (test-gitignore.sh lines 1-9):
```bash
#!/usr/bin/env bash
# test-<name>.sh - one-line purpose.
HERE=$(cd "$(dirname "$0")" && pwd)
# shellcheck source=/dev/null
. "$HERE/lib.sh"
```
**Helpers available** (lib.sh lines 27-56): `assert_exit <want> <desc> <cmd...>`, `assert_out_has`, `assert_out_lacks`, `assert_eq <desc> <expected> <actual>`, `count_entries`, `in_dir`, `new_repo`. Pass/fail counters come from the `_ok` / `_fail` functions. Exit 77 from a test file means SKIP (run.sh line 43); use it when a needed tool is absent. Keep bash 3.2 safe (no associative arrays, no `mapfile`). Test body: extract job ids from `.github/workflows/hygiene.yml` (awk or grep on `^  [a-z-]+:$` under `jobs:`), then assert every id other than `ci-passed` appears in the `needs:` line of `ci-passed`. Read the real file via `$REPO_ROOT`; never write to the real repository (run.sh compares `git status` before and after, lines 25-52).

---

### `scripts/sensitive-allowlist.txt` (config)

**Analog:** same file, entry format (lines 3-8 and 17-28):
```
# rule ;; path-ERE ;; matched-value-ERE
company-id ;; ^\.planning/ ;; ^20[23][0-9]{5}$
hosting-host ;; ^\.planning/phases/01-repository-hygiene/01-REVIEW\.md$ ;; ^bucket\.s3\.amazonaws\.com$
```
New entries (RESEARCH.md Pattern 11), each preceded by a comment explaining why, narrowest path, anchored value:
```
email ;; ^composer\.lock$ ;; ^[^@ ]+@[^@ ]+$
public-ip ;; ^composer\.lock$ ;; *
```
Needs explicit maintainer approval (RESEARCH.md Open Question 4); file is covered by CODEOWNERS (`/scripts/`). Re-run `scripts/tests/run.sh` afterwards, since `test-allowlist`-style checks live in the check-sensitive tests.

---

### `CONTRIBUTING.md` (docs)

**Analog:** same file. Existing sections: `## Repository hygiene`, `## Setup`, `## What the checker flags`, `## Exemptions`, `## Local denylist`, `## Never bypass`, `## If something leaks`, `## CI` (line 117), `## GitHub settings checklist`, `## Per-phase .gitignore review` (line 151). Extend: add a `## Development` section (DDEV commands, composer scripts, how to add a model), and update `## CI` to list the new jobs. Never replace. `scripts/tests/test-docs.sh` asserts required phrases (including `SECURITY.md`); read it before editing and keep every phrase.

### `README.md` and `SECURITY.md` (docs)

**Analog:** `CONTRIBUTING.md` for tone; required phrases in `scripts/tests/test-docs.sh`. No e-mail addresses (scanner flags non-example ones); SECURITY.md points to GitHub private vulnerability reporting. Use only `example.com`, company ID `12345678`, `/Users/example/` in examples.

---

### `scripts/check-licenses.php` (utility)

**Analog:** no PHP analog. Copy the lab-verified script from RESEARCH.md "Code Examples" (OR semantics, allowlist array, `JSON_THROW_ON_ERROR`). Borrow the CLI conventions of `scripts/check-sensitive.sh`: findings and the failing list to stderr, exit 0 clean, exit 1 on violations, exit 2 on usage errors. Add a Pest test `tests/Unit/LicenceCheckTest.php` with a synthetic GPL-2.0-only package (fails) and a dual BSD-or-GPL package (passes).

---

### `lefthook.yml` (no change expected)

**Analog:** itself (lines 9-23). Pre-commit already runs `scripts/check-sensitive.sh` and gitleaks over staged content. Do not add Pint or Pest to the hook (CI is the gate; the hook is a hygiene tool). If a job is added, copy the `- name:` / `run:` / `fail_text:` shape.

---

## Shared Patterns

### Hygiene gate on every commit of the phase
**Source:** `lefthook.yml` lines 9-23, `scripts/check-sensitive.sh`, `.claude/CLAUDE.md` "Repository Hygiene".
**Apply to:** every new file (PHP, YAML, docs, fixtures).
- Review procedure: `git status`, `git diff --staged`, `scripts/check-sensitive.sh`. Never `--no-verify`.
- Test fakes are assembled at runtime from fragments (see `scripts/tests/lib.sh` header lines 1-7; RESEARCH.md canary example `'CANARY_'.'B_'.bin2hex(random_bytes(4))`).
- Known skeleton collisions to clean before the first `git add` (RESEARCH.md Pattern 11): `composer.lock` e-mails/IP (allowlist), `welcome.blade.php` (delete), `config/queue.php` SQS URL (remove unused connections), skeleton `README.md`, `CLAUDE.md`, `AGENTS.md` (do not import), `public/{js,css}/filament` (ignore).

### Workflow security conventions
**Source:** `.github/workflows/hygiene.yml` lines 3-10, 28-31, 113-121.
**Apply to:** all new workflow jobs: SHA-pinned actions with version comment, `permissions: {}` at top and `contents: read` per job, `persist-credentials: false`, values via `env:` not inline `${{ }}` in `run:`, no `pull_request_target`.

### Bash test harness
**Source:** `scripts/tests/run.sh` (auto-discovery, exit 77 skip, real-repo `git status` guard) and `scripts/tests/lib.sh` (assert helpers, temp repos, runtime fakes).
**Apply to:** `test-workflow.sh` and any new bash check. PHP-side tests use Pest instead (no analog; recipes in RESEARCH.md "Validation Architecture").

### Per-phase `.gitignore` review
**Source:** `CONTRIBUTING.md` `## Per-phase .gitignore review` and `scripts/tests/test-gitignore.sh`.
**Apply to:** any change to `.gitignore`: add verdicts to the test in the same change.

### Code ownership
**Source:** `.github/CODEOWNERS` (covers `/.github/`, `/scripts/`, `/lefthook.yml`, `/.gitleaks.toml`, `/.gitattributes`, `/.gitignore`, `/CONTRIBUTING.md`, `/.claude/`).
**Apply to:** edits to the allowlist, workflow, scripts and `.gitignore` need maintainer review; mention it in plan notes. Consider adding `/composer.lock` and `/.ddev/` if the maintainer wants them owned.

## No Analog Found

Planner should use the RESEARCH.md recipe named in the last column. No Laravel application code exists in the repository.

| File / Group | Role | Data Flow | RESEARCH.md recipe |
|--------------|------|-----------|--------------------|
| `.ddev/config.yaml`, `.ddev/docker-compose.rustfs.yaml`, Redis add-on files | config (infra) | event-driven | Pattern 9 (DDEV); commit set excludes generated `.ddev/.gitignore` targets |
| `.env.example` | config | n/a | Pattern 9 "`.env.example` keys"; placeholders and DDEV hosts only, `kokpit@example.com` |
| `composer.json`, `phpunit.xml`, `phpstan.neon`, `pint.json` | config | n/a | Installation block, Pattern 10 (PHPStan/Pint), "Pest wiring and DB guard"; licence `AGPL-3.0-only` (Open Question 3) |
| Skeleton merge (app, bootstrap, config, routes, public) | mixed | n/a | Pattern 11 merge recipe |
| Edited package migrations, `number_sequences`, `kokpit_guard_frozen_row()` | migration | CRUD / DDL | Pattern 1 edit table; Pattern 3 table; Pattern 6 SQL |
| Package-model subclasses, morph map provider, `KokpitModel` | model / provider | CRUD | Pattern 1 "Subclass recipe" and morph map |
| `Money`, `MoneyCast` | utility | transform | Pattern 4 |
| `SequenceAllocator` + `tests/Concurrency/worker.php` | service | CRUD (row lock) | Pattern 3 allocator and harness |
| `PartnerContext`, `PartnerScope`, `PartnerIsolated`, `IsolatesPartners`, `DeniesPartners`, `NotPartnerScoped`, `KokpitPolicy`, `AccessRule`, `Audience` | scope / policy / attribute | request-response | Pattern 5 (register `PartnerContext` with `scoped`) |
| `InstallCommand`, `ResetAdminTwoFactorCommand`, `EnsureAdminHasTwoFactor`, `AdminPanelProvider`, `User` model | command / middleware / provider | request-response | Pattern 7 (custom middleware, not an `isRequired` closure; explicit `$fillable`, never `$guarded = []`) |
| Localisation (`lang/cs`, `AppServiceProvider` formats, enum labels) | config / provider | transform | Pattern 8 |
| `tests/Support/{PgSchema,RawSql,CanaryRecord,CanaryRegistry}.php`, all Pest tests | test | mixed | Pattern 2 (schema SQL), Pattern 5 (canary), Pattern 6 (`RawSql`), Validation Architecture map |

## Metadata

**Analog search scope:** whole repository, tracked files only (`git ls-files`, 30 files outside `.planning/`): `.github/`, `scripts/`, `scripts/tests/`, `lefthook.yml`, `.gitignore`, `.gitleaks.toml`, `CONTRIBUTING.md`, `LICENSE`.
**Files read:** `hygiene.yml`, `run.sh`, `lib.sh` (head), `test-gitignore.sh` (head), `lefthook.yml`, `.gitignore`, `sensitive-allowlist.txt`, `CODEOWNERS`, `dependabot.yml`, CONTRIBUTING headings, CONTEXT.md, RESEARCH.md.
**Pattern extraction date:** 2026-10-07
