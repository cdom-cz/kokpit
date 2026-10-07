# Contributing to Kokpit

Kokpit is a public AGPL-3.0 project (SPDX `AGPL-3.0-only`). This guide covers repository hygiene, the development setup and the conventions every change follows. Reporting a vulnerability is described in `SECURITY.md`; installing the application is described in `README.md`.

## Repository hygiene

The rule is: **Fictional data only.** Never put client names, prices, rates, invoice or production data, real e-mail addresses, company IDs, bank accounts, IP addresses, hostnames, tokens or personal absolute paths into code, tests, fixtures, docs, planning docs under `.planning/`, or commit messages.

Use `example.com` addresses, the placeholder company ID `12345678`, paths like `/Users/example/`, and test fakes assembled at runtime from fragments, so that no single line of a test file looks like a real value.

Review procedure before every commit:

1. `git status`
2. `git diff --staged`
3. `scripts/check-sensitive.sh`

Commit author name and e-mail are Git metadata and are not scanned. Each contributor decides what identity to commit with.

## Setup

Required tools: lefthook and gitleaks. Optional: shellcheck (lints the scripts).

Tested versions: lefthook 2.1.17 (minimum 2.1.0) and gitleaks 8.30.1.

Install with either of:

    brew install lefthook gitleaks
    mise use -g lefthook@2.1.17 gitleaks@8.30.1

Do not use the npm package for lefthook; use one of the commands above.

Run `scripts/install-hooks.sh` after every fresh clone. It checks both tools, refuses a `core.hooksPath` override, runs `lefthook install` and verifies the hook is in place. It is idempotent.

The pre-commit hook runs two jobs in order: `sensitive-content` (`scripts/check-sensitive.sh` over the staged changes), then `gitleaks` over the staged changes. If lefthook is not installed, the generated hook aborts the commit instead of skipping it.

Run the self-tests with `scripts/tests/run.sh` (`--quick` skips the tests that need lefthook or gitleaks). `scripts/tests/run-in-ubuntu.sh` repeats the quick suite in a container with the awk and grep flavours CI uses.

## Development

The application runs in a DDEV project (PHP 8.5, PostgreSQL 18, Redis, RustFS, Mailpit, a queue worker and a scheduler). `README.md` has the install sequence; day to day:

    ddev start
    ddev composer install
    ddev artisan migrate
    ddev exec vendor/bin/pest --filter=<name>

Run every PHP command through DDEV (`ddev composer ...`, `ddev artisan ...`, `ddev exec vendor/bin/pest ...`). The tests use the separate `kokpit_test` database that DDEV creates on start; `tests/TestCase.php` refuses any database whose name does not end in `_test`.

Composer scripts (the PHP gates; `composer ci` runs the first four in order):

| Script | What it runs |
|---|---|
| `composer test` | Pest: the Unit, Feature, Arch, Concurrency and Isolation suites |
| `composer lint` | Pint in check mode (`--test`); run `vendor/bin/pint` to fix |
| `composer stan` | Larastan level 8 |
| `composer check-licenses` | the AGPL-compatible licence allowlist over `composer.lock` |
| `composer ci` | all of the above, the same gates CI runs |

The bash self-tests of the hygiene tooling are a host command: `bash scripts/tests/run.sh`. All gates must be green before a pull request.

### Conventions

Each convention is enforced by a test, so a violation fails `composer test` and not only the review.

- **Keys and time.** Every table has a UUID v7 primary key with the `uuidv7()` column default; foreign keys, morph `*_id` columns and package tables are `uuid` too. Every timestamp is `timestamptz` (`timestampsTz()`, never `timestamps()`); storage is UTC. The schema tests (R1 to R9) read the PostgreSQL catalogue and name the offending column; an exception goes into `PgSchema::EXEMPT` with a reason.
- **Morph aliases.** Polymorphic relations store a short snake_case alias, never a class name. Aliases live only in `MorphMap`; the morph map is enforced, so an unmapped model fails loudly.
- **Money.** Money is the `Money` value object with a `<name>_minor` (bigint, minor units) and a `<name>_currency` (char(3)) column pair and `MoneyCast`. There is no float money. `Money::fromExactMinor` is the single rounding point (half up); `tests/Arch/MoneyBoundaryTest` guards the boundary.
- **Numbering.** Gap-free, duplicate-free numbers (tasks, invoices) come only from `SequenceAllocator`, called inside the caller's own transaction so a rolled-back caller gives the number back. The year is part of the scope key; never hand-roll `max(number) + 1`.
- **Immutability.** Frozen records (issued invoices, billed time entries) are protected by database triggers built with `Immutability`; a forbidden change raises SQLSTATE `KP001`. Application code is not the only guard.
- **Localisation.** Every user-facing string lives in `lang/cs` (the interface is Czech, the fallback locale is English); no literal Czech or English text in classes or views. Enum labels are translated too. Times show as `j. n. Y H:i` in Europe/Prague, from the defaults set once in `LocalisationServiceProvider`.
- **Ordering.** A list that sorts by a timestamp breaks ties by `id`, so pagination is stable. Text that is sorted for people (names, titles) uses a Czech collation instead of the database default; no sorted text column exists yet, so the first one defines the mechanism and adds the test.
- **Isolation.** A Partner must never see another client's data, and the rule is enforced in the data layer, not by hiding UI. Every model implements `PartnerIsolated` (with `IsolatesPartners`, and a `KokpitPolicy` subclass that grants Partners explicitly; `DeniesPartners` for Admin-only data) or carries `NotPartnerScoped` with a reason. Every Filament resource, page, widget and relation manager carries an `AccessRule` attribute (`Audience::AdminOnly` or `Audience::PartnerAllowed`, with a reason). Every new `PartnerIsolated` model gets one fixture line in `CanaryRegistry` (`tests/Support/CanaryRegistry.php`); the canary tests search every Partner-visible surface for another client's canary string.

### Add-a-model checklist

1. Extend `KokpitModel` (`HasUuids`); create the migration with a uuid primary key defaulting to `uuidv7()`, `timestampsTz()` and database constraints (foreign keys, unique and check constraints, partial indexes) rather than only validation.
2. Add one line to `MorphMap`.
3. Money columns as a `_minor` and `_currency` pair with `MoneyCast`; numbers through `SequenceAllocator`; frozen states through `Immutability`.
4. Declare isolation: implement `PartnerIsolated` and use `IsolatesPartners` (or `DeniesPartners`), or add `NotPartnerScoped` with a reason.
5. Write the policy as a `KokpitPolicy` subclass that grants Partners explicitly, or register `AdminOnlyPolicy`.
6. Put `AccessRule` on every Filament class you add, and add its labels and messages to `lang/cs`.
7. Add one line to `CanaryRegistry` for a `PartnerIsolated` model.
8. Run `ddev composer ci`.

## What the checker flags

`scripts/check-sensitive.sh` reports the following categories. The rule name is what appears in a finding.

| Rule | What it flags |
|---|---|
| `key-prefix` | Secret-key and token prefixes with a body: Stripe, GitHub and AWS style keys (the AKIA and ASIA prefixes) |
| `email` | E-mail addresses outside `example.com`, `example.org`, `example.net`, `.test`, `.invalid`, `.localhost` |
| `company-id` | 8-digit company-ID-like numbers (the placeholders `12345678` and `00000000` are allowed). The number is also found after `-`, `_` or `/`, so write dates with separators (for example 2026-10-07) and not as one run of digits |
| `iban` | IBANs with a valid country length and checksum, also in groups of four and also in lower case |
| `cz-account` | Czech account numbers, with or without prefix, also with one space on either side of the slash |
| `public-ip` | Public IPv4 addresses, also after `-` or `_` (private, loopback, link-local, documentation and similar ranges are allowed) |
| `hosting-host` | Zerops and S3-provider endpoint hostnames, in any letter case |
| `home-path` | Personal absolute home paths: `/Users/<name>` and `/home/<name>` with or without a trailing slash, also quoted or at the end of a line, and the Windows form `C:\Users\<name>` (any slash style and letter case). Placeholder names are allowed: `example`, `user`, `username`, `name` and `you`, for the Unix form also `runner`, `vagrant`, `ubuntu`, `www-data`, `ddev` and `Shared`, and for the Windows form `Public`, `Default`, `All` and `runneradmin`. A `home` or `Users` segment inside a URL or a relative path is not a home path |
| `git-attributes` | A `-diff`, `binary` or `filter=` attribute in any `.gitattributes`. Such an attribute hides content from gitleaks in the hook, or stores something other than the file in git. The rule is checked in staged, `--all` and explicit-file mode, never in `--history`, so a removed line cannot keep CI red. A deliberate use (for example Git LFS) needs a reviewed path-scoped allowlist entry for this rule |
| `denylist` | Terms from your local denylist, see below |

Modes:

- no argument (or `--staged`): the added lines of the staged diff. This is what the hook runs.
- `--all`: every tracked file in the index, read in full. CI runs it.
- `--history`: every added line in `git log --all`; findings name the introducing commit as `path:line@sha`. It covers every commit, including content introduced by a merge commit (diffed against its first parent, `--diff-merges=first-parent`) and renamed files (shown in full). CI runs it over the full history.
- `FILE...`: explicit files (use `--` before a name that starts with a dash). Every non-empty operand is scanned; there is no binary skip.

Every mode reads the full content of every file, including files git treats as binary (NUL bytes, a `-diff` or `binary` attribute) and UTF-16 text. Diffs are forced to text, textconv drivers are ignored, and NUL bytes are read twice, once as a space and once removed. Each finding is printed once.

Exit codes: `0` clean, `1` findings, `2` usage error, not a git repository, an unreadable or in-repository denylist, or a scanner error. Matched values are always masked: a finding prints the rule, the file and the line, plus the first characters and the length of the value, never the value. The same is true for the denylist stage: the term and the line text are never printed. A file path that itself contains a denylist term is printed as the finding path, because the path is the finding.

## Exemptions

Reviewed exemptions live in two files, both covered by code owners and reviewed in pull requests:

- `scripts/sensitive-allowlist.txt`: one entry per line, `rule ;; path-ERE ;; matched-value-ERE`. Make each entry as narrow as possible: one rule, the smallest path scope, an anchored value pattern. The value pattern is tested against the matched value, not the whole line.
- `.gitleaks.toml`: the allowlists of the gitleaks configuration.

Exactly five files are exempt from the generic rules, by exact path: `scripts/check-sensitive.sh`, `scripts/lib/scan.awk`, `scripts/lib/diff2tsv.awk`, `scripts/sensitive-allowlist.txt` and `.gitleaks.toml`. The denylist still scans them. Binary assets (images, fonts, PDFs) are scanned too. A false positive in one gets a narrow, path-scoped allowlist entry.

There are no inline ignore comments, no environment variable and no flag that disables or redirects a rule. gitleaks runs with `--ignore-gitleaks-allow`, so a `gitleaks:allow` comment in a file has no effect.

## Local denylist

Terms specific to your own instance (client names, internal project names, company IDs) go into a denylist that never enters the repository:

    export KOKPIT_DENYLIST="$HOME/.config/kokpit/denylist.txt"

The file lives in your home configuration directory. The script refuses a path inside the repository.

Format: one term per line. Blank lines and lines starting with `#` are ignored. Terms are fixed strings. Use terms of at least 3 characters: very short terms match too much.

Term and text are folded the same way: ASCII letters and the accented Latin letters from U+00C0 to U+017F (Czech, Slovak, German, Polish and more) become lower-case base letters, and combining marks are removed. A term written with diacritics therefore matches the same text without them, the reverse also holds, and composed and decomposed spellings match. Matching is byte-wise after folding and does not depend on the system locale. Letters of other scripts are compared exactly as written. Accented letters in UTF-16 or in legacy single-byte encodings such as Windows-1250 are not folded; only their ASCII letters match.

A fictional example file:

    # fictional example terms
    Acme Fictional Ltd
    Project Bluebird

Behaviour:

- `KOKPIT_DENYLIST` unset, or the file does not exist: one notice on stderr, then only the generic rules run. The exit code is unaffected. This is also how CI runs.
- the file exists but is not readable: exit `2`.
- the file is inside the repository: exit `2`, so a real list can never be committed through this tool.

## Never bypass

`git commit --no-verify` and `LEFTHOOK=0` skip the hook. Do not use either: CI rescans the whole tree and the whole history and fails the pull request, so a bypass only moves the finding to a later and more public place.

A path-limited commit (`git commit -- <path>`) scans only the committed paths, not the whole index.

## If something leaks

If something sensitive was committed or pushed:

1. Rotate or revoke it first. A secret that was ever pushed is compromised, whatever happens to the history afterwards.
2. Remove it from history. Before the first push, a local history rewrite is enough. After a push, contact the owner: the organisation ruleset blocks force-pushes to `main`, so the repair needs a decision.
3. Notify the affected parties.

The same runbook, together with how to report a vulnerability privately, is in `SECURITY.md`.

## CI

The workflow `Hygiene` runs on pushes to `main` and on pull requests, never on `pull_request_target`. Its jobs:

- `scan`: the hygiene self-tests, `--all`, `--history` and gitleaks.
- `workflow-lint`: actionlint and zizmor.
- `tests`: Pest on PostgreSQL 18 and Redis services, after booting the application from `.env.example` alone (copy, `key:generate`, `migrate`, `kokpit:install`).
- `static-analysis`: Pint and Larastan level 8.
- `dependencies`: `composer validate --strict`, `composer audit --locked` and the licence allowlist.
- `CI Passed`: the aggregator and the only status check the organisation ruleset requires. It waits for every other job, and `scripts/tests/test-workflow.sh` fails when a job is missing from its `needs` list.

Run the PHP gates locally inside DDEV with `ddev composer ci` (tests, formatting, static analysis and the licence check; `ddev composer check-licenses` runs the last one alone). The bash self-tests stay a host command: `bash scripts/tests/run.sh`.

The licence check passes a package when at least one of its declared licences is AGPL-3.0-compatible (a dual-licensed package lists its alternatives separately) and fails on `GPL-2.0-only`, on proprietary terms and on a package with no licence. A failure is a prompt to decide, not to extend the list in `scripts/check-licenses.php` silently; the change needs maintainer review.

The gitleaks step runs with `--log-opts="--all --diff-merges=first-parent --text --no-textconv"`, so it also reads merge commits and content git treats as binary. UTF-16 content stays invisible to gitleaks and is covered by the shell scan. In the pre-commit hook gitleaks cannot read binary-classified content; the `sensitive-content` job before it does.

Everything is pinned. Actions are pinned by full commit SHA and bumped by Dependabot. The tools gitleaks, actionlint and zizmor are downloaded as a release tarball by version and verified against a SHA-256 hard-coded in the workflow. Never trust a checksums file taken from the same release.

Manual bump procedure for a tool:

1. Read the new version and its asset digest: `gh api repos/<owner>/<repo>/releases/latest --jq '.assets[]|[.name,.digest]|@tsv'`.
2. Verify the digest against the upstream checksums.
3. Update the version and the digest in the workflow together, in one change, and update the tested versions in the Setup section.

## GitHub settings checklist (maintainer, manual)

These settings cannot be enforced from code or from CI (reading them needs an admin token), so the maintainer confirms them by hand and re-checks after GitHub UI changes:

- [ ] secret scanning enabled
- [ ] push protection enabled (Settings -> Advanced Security -> Secret Protection); review every push protection bypass alert
- [ ] non-provider patterns and validity checks enabled
- [ ] "Require actions to be pinned to a full-length commit SHA" enabled
- [ ] "Allow GitHub Actions to create and approve pull requests" disabled
- [ ] workflow approval required for outside contributors
- [ ] Dependabot alerts and security updates enabled
- [ ] two-factor authentication on the maintainer account
- [ ] optional: e-mail privacy settings for commit author metadata ("Keep my email addresses private" and "Block command line pushes that expose my email")
- [ ] the organisation ruleset on `main`: block deletion, block force push, require a pull request, required status check `CI Passed`. The first push of a new branch goes through a pull request.
- [ ] decide whether the ruleset on `main` uses "Require review from Code Owners". Without it, `.github/CODEOWNERS` only requests a review. A solo maintainer cannot approve their own pull request, so turning it on blocks the maintainer's own pull requests unless the ruleset grants the maintainer a bypass. The decision is the owner's. CODEOWNERS covers `/.github/`, `/scripts/`, `/lefthook.yml`, `/.gitleaks.toml`, `/.gitleaksignore`, `/.gitattributes`, `/.gitignore`, `/CONTRIBUTING.md` and `/.claude/`.

A read-only audit of the repository-level values, run by the maintainer (never in CI):

    gh api repos/<owner>/<repo> --jq .security_and_analysis

## Per-phase .gitignore review

Review .gitignore at the start of every phase. For each new tool or framework output that the phase introduces (DDEV in Phase 2, Laravel storage, coverage reports, build output), add the ignore rule and a matching case to `scripts/tests/test-gitignore.sh` in the same change. The test asks `git check-ignore --no-index` about paths that do not exist on disk, so the verdicts hold for an empty checkout.

Only `.claude/CLAUDE.md` is versioned below `.claude/`. The directory itself must never be ignored (`.claude/`): git cannot re-include a file below an excluded parent. The rule is `.claude/*` followed by `!.claude/CLAUDE.md`, in that order, and the test fails if the order is reversed.
