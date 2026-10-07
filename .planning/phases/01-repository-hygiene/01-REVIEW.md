---
phase: 01-repository-hygiene
reviewed: 2026-10-07T22:30:00Z
depth: standard
files_reviewed: 26
files_reviewed_list:
  - .claude/CLAUDE.md
  - .github/CODEOWNERS
  - .github/dependabot.yml
  - .github/workflows/hygiene.yml
  - .gitignore
  - .gitleaks.toml
  - CONTRIBUTING.md
  - lefthook.yml
  - scripts/check-sensitive.sh
  - scripts/install-hooks.sh
  - scripts/lib/diff2tsv.awk
  - scripts/lib/fold.awk
  - scripts/lib/scan.awk
  - scripts/sensitive-allowlist.txt
  - scripts/tests/lib.sh
  - scripts/tests/run-in-ubuntu.sh
  - scripts/tests/run.sh
  - scripts/tests/test-attributes.sh
  - scripts/tests/test-check-sensitive.sh
  - scripts/tests/test-content.sh
  - scripts/tests/test-denylist.sh
  - scripts/tests/test-docs.sh
  - scripts/tests/test-gitignore.sh
  - scripts/tests/test-gitleaks.sh
  - scripts/tests/test-lefthook.sh
  - scripts/tests/test-modes.sh
findings:
  critical: 0
  warning: 10
  info: 13
  total: 23
status: issues_found
---

# Phase 1: Code Review Report (re-review after Phase 2 changes to the hygiene files)

**Reviewed:** 2026-10-07T22:30:00Z
**Depth:** standard
**Files Reviewed:** 26
**Status:** issues_found

## Summary

All 26 files were read in full, plus `scripts/tests/test-workflow.sh` and `scripts/boot-from-env-example.sh` for context. Every previously open finding was re-probed in scratch repositories (all values fictional, none written into this report). The full self-test suite passes (10 files, 0 failures), `shellcheck -x` over `scripts/` is clean, and `scripts/check-sensitive.sh --all` and `--history` are clean on the real repository.

**Fixed since the last report:** WR-14 (the Laravel storage placeholders are now trackable, 11 `.gitignore` placeholder files are tracked and pinned by `test-gitignore.sh`). The Phase 2 additions to `hygiene.yml` (tests, static-analysis, dependencies jobs, `CI Passed` aggregator guarded by `test-workflow.sh`) and to `.gitignore` are sound: all jobs use SHA-pinned actions, `persist-credentials: false`, least-privilege tokens and a pipefail-safe licence check.

**Still reproducing (IDs kept):** WR-03, WR-07, WR-10, WR-11, WR-12, WR-13, WR-15, IN-01, IN-02, IN-04, IN-05, IN-06, IN-07, IN-08, IN-09 (narrowed), IN-10, IN-11, IN-12, IN-13.

**New in this pass:** WR-16 (a file that both layers are blind to), WR-17 (the Phase 2 `composer.lock` allowlist entries are rule-wide switches and sit outside CODEOWNERS), WR-18 (DDEV snapshot and local-config protection depends on an untracked file), IN-14 (overstated "everything is pinned" claim).

No critical issue was found. The scanner core (diff parser, NUL handling, fold, masking, exit codes, trap/cleanup, read-only guarantees) held up against every adversarial input tried. The weaknesses are coverage gaps in the heuristic rules, a denylist that fails open, and exemption design.

## Warnings

### WR-03: Right-hand boundary of `company-id`, `public-ip` and `cz-account` hides real values

**File:** `scripts/lib/scan.awk:149-153`
**Issue:** A match followed by `-` or `_` is rejected (`rbad` is `[0-9A-Za-z_-]`). Re-reproduced: a file name such as `invoice-<8 digits>-final.pdf`, a path `clients/<8 digits>_<name>/...`, `<8 digits>-<name>` and a public IPv4 address followed by `-<word>` are all reported clean in staged mode. A folder named `<company id>-<client name>` is a very common way to store client material. The exclusion exists for UUID first groups (test `(s)`), but it is far broader than that need, and CONTRIBUTING line 93 suggests these shapes are covered.
**Fix:** Keep the UUID exemption but make it specific, and drop `-` and `_` from `rbad` for the three rules:
```awk
scan_re("company-id", "[0-9]{8}", "[0-9A-Za-z]", "[0-9A-Za-z]", text)
# in scan_re(): if (rule == "company-id" && ca == "-" && substr(s, en + 1, 5) ~ /^[0-9A-Fa-f]{4}-$/) ok = 0
```
Add one positive test per shape and keep test `(s)` as the negative.

### WR-07: Planning invoice exemption hides any real company ID starting 202 or 203

**File:** `scripts/sensitive-allowlist.txt:24`
**Issue:** `company-id ;; ^\.planning/ ;; ^20[23][0-9]{5}$` is a value-class exemption for a whole directory, not for the invoice-number examples it exists for. Real company IDs in those ranges exist, and `.planning/` is where free-text notes live and where the project rules say real data must not appear.
**Fix:** Write `YYYYNNNN` in planning documents and delete the entry. If it must stay, scope it to one named file and to the years actually used.

### WR-10: The local denylist is silently inactive in the hook, and a mistyped path fails open

**File:** `scripts/check-sensitive.sh:89-96`, `lefthook.yml:6-8`, `scripts/install-hooks.sh`, `scripts/tests/test-denylist.sh:(e)`
**Issue:** An unset variable and a variable pointing at a missing file both print one stderr notice and exit 0 with "clean" (re-verified: a non-existent path gives `clean (staged)`, exit 0). `lefthook.yml` uses `output: [failure, summary]`, which hides the notice of a successful job, so the developer never sees that the only control for client names (HYG-03) is off. This is the normal state for commits from an IDE or GUI client that does not read the shell profile (the repository has an `.idea` directory). `test-denylist.sh` case (e) pins the fail-open behaviour as correct.
**Fix:** Treat a set-but-missing path as exit 2 and keep "unset" as the generic-only CI path. For the hook, add `success` to `output:` for the `sensitive-content` job, or make `install-hooks.sh` warn when `KOKPIT_DENYLIST` is unset, and document that GUI clients need the variable in their own environment. Change case (e) to expect exit 2.

### WR-11: Commit messages are in the hygiene rule's scope but no layer scans them

**File:** `scripts/check-sensitive.sh:201-203`, `lefthook.yml:9-23`, `CONTRIBUTING.md:7,17`
**Issue:** The rule lists "commit messages" explicitly. `--history` and gitleaks scan patches only and the hook has no `commit-msg` job. CONTRIBUTING line 17 says author name and e-mail are not scanned but is silent on message text, so the documentation overstates the coverage.
**Fix:** Add a `commit-msg` job running a new `scripts/check-sensitive.sh --message "$1"` mode, and have `--history` also emit `git log --all --format='%H%x09%B'` rows under a pseudo path. At minimum state in CONTRIBUTING that message text is not scanned.

### WR-12: Home paths in JSON-escaped, WSL and msys spellings are not reported

**File:** `scripts/lib/scan.awk:164-169`, `.gitleaks.toml:36,59`
**Issue:** Re-reproduced as clean: a personal path with escaped slashes (PHP `json_encode` default, so Laravel log excerpts and JSON fixtures) and the WSL form `/mnt/c/Users/<name>`. A letter before the slash is a rejected left boundary, and the Windows rule needs a drive letter plus a colon. Neither layer fires.
**Fix:** Scan the home-path rule also on a copy of the line in which `\/` becomes `/`, and accept `/mnt/<letter>` and `/<letter>` as left context for `Users`. Mirror it in `.gitleaks.toml` with `(?:\\?/)` between segments. Add tests for the three shapes.

### WR-13: A VAT number (`CZ` plus the company ID) is not reported by either layer

**File:** `scripts/lib/scan.awk:149`, `.gitleaks.toml:73`
**Issue:** `company-id` rejects eight digits preceded by a letter, so the Czech VAT form (`CZ` directly followed by the company ID) and a `DIC:`-prefixed line are clean (re-verified). The VAT number contains the company ID in full, and on an invoice both appear together.
**Fix:** In `scan_re`, when `rule == "company-id"` and the two characters before the match are `CZ` or `SK` (any case) and the character before those is not alphanumeric, accept. Add a `DIC`/`CZ` alternative to the gitleaks rule and a positive test in both suites.

### WR-15: A UTF-8 byte order mark silently disables the first denylist term

**File:** `scripts/check-sensitive.sh:111-121`
**Issue:** The denylist copy strips CR, NUL, surrounding blank space, blank lines and comments but not a leading BOM. Re-reproduced: a two-term list saved with a BOM reports a line holding the second term and reports `clean (staged)` for a line holding the first. No notice is printed. A BOM also turns a leading `# comment` line into a "term" that never matches anything.
**Fix:** Strip the three BOM bytes from the first line before trimming (`LC_ALL=C sed -e '1s/^\xEF\xBB\xBF//'` is not portable to BSD sed; use `awk 'NR == 1 { sub(/^\357\273\277/, "") } { print }'`). Add a BOM-prefixed list to `test-denylist.sh`.

### WR-16: `.gitleaks.toml` is invisible to both scanning layers (a secret can be hidden in it)

**File:** `scripts/check-sensitive.sh:285-294`, `.gitleaks.toml:10-11`
**Issue:** The shell scanner exempts `.gitleaks.toml` from every generic rule by exact path (D-05), and with `useDefault = true` the upstream global path allowlist makes gitleaks skip any path containing `gitleaks.toml` (unanchored, so also `foo/gitleaks.toml.bak` and any file with that substring). Reproduced in a scratch repository with the repository's own config: a GitHub-token-shaped value planted in `.gitleaks.toml` is reported by neither layer, while the same value in `notes.txt` is. Only the denylist (instance terms) still sees the file. The file is also the one a contributor edits when they want a finding to go away. CODEOWNERS protects it only if the ruleset requires code-owner review, which is still an open owner decision (CONTRIBUTING line 203). The same default allowlist also hides text-file `*.svg` and `*.png` content from gitleaks (the shell scanner still sees it).
**Fix:** Make the shell exemption per rule instead of per path: let `.gitleaks.toml` skip only `home-path`, `company-id`, `hosting-host` and `public-ip` (the shapes it legitimately contains) and keep `key-prefix`, `email`, `iban` and `cz-account` active for it. Document the inherited gitleaks path allowlist, or add a CI step that runs `gitleaks dir --no-git --config <default-only config>` over `.gitleaks.toml`. Add a test planting a key-shaped fake in `.gitleaks.toml`.

### WR-17: The `composer.lock` allowlist entries switch two rules off for that file, and `composer.json` and `composer.lock` have no code owner

**File:** `scripts/sensitive-allowlist.txt:32,36`, `.github/CODEOWNERS`
**Issue:** `email ;; ^composer\.lock$ ;; ^[^@ ]+@[^@ ]+$` and `public-ip ;; ^composer\.lock$ ;; ^[0-9.]+$` test the matched value against a pattern that every possible match satisfies, so they are not "anchored value patterns" as the file header demands (D-04) but a per-file off switch. Verified: a root `composer.lock` containing a non-example e-mail address and a public IPv4 address is `clean`, while the same content in `pkg/composer.lock` is reported. A path-repository package or a custom `dist` URL in the lock is a realistic carrier for an author address or an instance host. Neither `/composer.json` nor `/composer.lock` appears in CODEOWNERS, although they now carry scanner exemptions and `composer.lock` is the input of the licence gate (`scripts/check-licenses.php`), so the exempted file can change with no required review.
**Fix:** Narrow the e-mail entry to the maintainer metadata actually present (an exact list of anchored addresses, or a domain-less shape such as `^[^@ ]+@users\.noreply\.github\.com$`) and the IP entry to the concrete version-string shape, for example `^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$` restricted by an anchored path and only for `"version"` rows (or remove it and add the few strings individually). Add `/composer.json`, `/composer.lock`, `/phpstan.neon`, `/pint.json`, `/phpunit.xml` and `/tests/` (CiParityTest and the isolation canaries are security gates) to CODEOWNERS.

### WR-18: Protection of DDEV snapshots and local DDEV config depends on an untracked, self-ignoring file

**File:** `.gitignore` (no `.ddev` rules), `.ddev/.gitignore` (generated, not tracked)
**Issue:** `.ddev/db_snapshots/` holds database dumps, and `.ddev/config.local.yaml` and `.ddev/.homeadditions/` hold instance-specific values. The only rules covering them live in `.ddev/.gitignore`, which is `#ddev-generated`, ignores itself on its first line and is not in `git ls-files`. Checked against the repository's `.gitignore` alone (`git check-ignore --no-index`): `.ddev/db_snapshots/x.gz`, `.ddev/db_snapshots/s.tar.gz`, `.ddev/config.local.yaml` and `.ddev/.homeadditions/x` are trackable. A fresh clone before the first `ddev start`, a changed DDEV version, or a developer who removes the generated header leaves the repository's own rules as the only barrier, which contradicts the project constraint of no real data in git.
**Fix:** Add a backstop to the root `.gitignore` and matching rows to `test-gitignore.sh`:
```gitignore
/.ddev/db_snapshots/
/.ddev/config.local.y*ml
/.ddev/config.*.local.y*ml
/.ddev/.homeadditions/
```

## Info

### IN-01: Tool versions are duplicated in five places with no consistency check

**File:** `.github/workflows/hygiene.yml:24`, `scripts/install-hooks.sh:10-11`, `lefthook.yml:2`, `CONTRIBUTING.md:23`, `scripts/tests/test-docs.sh`
**Issue:** `test-docs.sh` only checks that the documentation contains the literal tested versions, so the workflow can be bumped while the installer, the lefthook header and the docs drift.
**Fix:** Add a test that extracts the gitleaks version from `hygiene.yml` and compares it with the other places.

### IN-02: Feature-branch pushes are not scanned and `main` runs can be cancelled

**File:** `.github/workflows/hygiene.yml:5-14`
**Issue:** Only pushes to `main` and pull requests run; `cancel-in-progress: true` also cancels a superseded `main` run. Accepted in the disposition; recorded so the decision stays visible.
**Fix:** `cancel-in-progress: ${{ github.event_name == 'pull_request' }}` and document the reliance on push protection.

### IN-04: No IPv6 rule

**File:** `scripts/lib/scan.awk:150`
**Issue:** A routable IPv6 address is reported clean (re-verified with a global-unicast sample).
**Fix:** Add an IPv6 rule excluding `::1`, `fe80::/10`, `fc00::/7` and the documentation prefix, or record the omission in CONTRIBUTING.

### IN-05: `*.sql` and `*.csv` ignore rules will hide legitimate tracked files

**File:** `.gitignore:27-30`
**Issue:** Laravel's `php artisan schema:dump` writes `database/schema/pgsql-schema.sql`, which is ignored by the current rules (verified). The Phase 2 migrations use database-level constraints and triggers, so a schema dump is a likely future file. Fixture CSVs under `tests/` are hidden the same way.
**Fix:** Add `!/database/schema/*.sql` and `!/tests/**/fixtures/*.csv` with `test-gitignore.sh` rows when the first one is needed.

### IN-06: Container test helper uses an unpinned image tag

**File:** `scripts/tests/run-in-ubuntu.sh:17`
**Issue:** `ubuntu:24.04` is a moving tag and packages are installed unpinned.
**Fix:** Pin by digest or state in the header that it is a convenience, not a trust boundary.

### IN-07: A retina image name (name, at-sign, 2x, extension) is reported as an e-mail address

**File:** `scripts/lib/scan.awk:146`
**Issue:** Re-reproduced. Retina asset names and `<pkg>@<version>` strings match the e-mail shape. Front-end code (Filament, Vite) will hit this constantly, and every false positive pushes contributors toward broad allowlist entries.
**Fix:** In `email_ok`, accept domains whose last label is a common asset extension (`png`, `jpg`, `jpeg`, `webp`, `gif`, `svg`, `avif`) when the domain starts with a digit and `x`.

### IN-08: File paths are never checked by the generic rules

**File:** `scripts/check-sensitive.sh:285-298`
**Issue:** Only the denylist sees the path. A file named after a company ID or personal path component passes every generic rule; the content of a binary asset rarely reveals its name.
**Fix:** Feed the path through `scan.awk` as an extra row with line `0`, or document that file names are covered by the denylist only.

### IN-09: The gitleaks company-ID rule needs `:` or `=` after the keyword (narrowed)

**File:** `.gitleaks.toml:73`
**Issue:** `kokpit-ico-like` requires a separator, so the usual invoice layout `IČO <8 digits>` produces no gitleaks finding; only the shell scanner catches it. The inherited path allowlist part of the old finding is now WR-16.
**Fix:** Allow whitespace as the separator: `(?:ičo|ico|ič|ic|company[_ -]?id)["']?\s*[:=]?\s*["']?(\d{8})\b`.

### IN-10: Remaining credential-shaped file names are not ignored

**File:** `.gitignore:66-83`
**Issue:** Verified trackable: `.htpasswd`, `terraform.tfvars`, `.aws/credentials`, `kubeconfig`, `.pypirc`, `.mcp.json` (project MCP configs commonly carry tokens), and editor leftovers such as `*.swp` and `*.bak`.
**Fix:** Add them with matching `test-gitignore.sh` rows.

### IN-11: Allowlist entry exists only to excuse a sample in a previous review document

**File:** `scripts/sensitive-allowlist.txt:26-28`, `scripts/tests/test-check-sensitive.sh:(B9)`
**Issue:** `hosting-host` is exempted for one value in one planning file because an earlier review quoted a fictional host. This report does not contain it, so the entry is a standing exemption with no user, keyed to a mutable planning artifact.
**Fix:** Remove the entry and test (B9); describe such reproductions without a host-shaped value.

### IN-12: File mode bits of the test scripts are inconsistent

**File:** `scripts/tests/test-denylist.sh`, `test-gitignore.sh`, `test-gitleaks.sh`, `test-modes.sh`, `test-workflow.sh`, `lib.sh` (`git ls-files -s`)
**Issue:** These are stored as `100644`, the other test files as `100755`. `run.sh` calls them through `$BASH`, so nothing breaks, but running one directly (as the header comments suggest) fails with "permission denied".
**Fix:** `git update-index --chmod=+x` for the five runnable files (not `lib.sh`), or drop the executable bit everywhere.

### IN-13: Paths that git quotes are reported with the quotes and the `b/` prefix

**File:** `scripts/lib/diff2tsv.awk:35`
**Issue:** Re-reproduced: a file name containing a double quote is printed as `"b/<name>":1: ...`. Such a path never matches the exact-path exemptions, the path-scoped allowlist entries or the `/.gitattributes` suffix test, so a `.gitattributes` in such a directory is not checked for the `git-attributes` rule.
**Fix:** When the path starts with a double quote, strip the quotes and the `b/` prefix and unescape `\"`, `\\`, `\t` and octal sequences, or run the diff with `-z`.

### IN-14: CONTRIBUTING claims everything is pinned, but several inputs are not

**File:** `CONTRIBUTING.md:181`, `.github/workflows/hygiene.yml:115-137,19`
**Issue:** "Everything is pinned" is stated for the whole pipeline, but the `postgres:18` and `redis:7` service images are tag-only, the runner image is a moving label, and `shivammathur/setup-php` fetches an unpinned Composer at run time. The claim sets a reader's expectation of a supply-chain guarantee that does not hold for the `tests` job, which runs PR code with database access.
**Fix:** Pin the service images by digest (Dependabot's `docker` ecosystem does not read workflow `services`, so add a manual-bump note) or narrow the sentence to "actions and downloaded release binaries are pinned".

---

_Reviewed: 2026-10-07T22:30:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
